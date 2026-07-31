import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const assistantSource = await readFile(
    new URL('../../resources/assets/assistant-client.js', import.meta.url),
    'utf8',
);
const assistantUrl = `data:text/javascript;base64,${Buffer.from(assistantSource).toString('base64')}`;
const voiceSource = (
    await readFile(new URL('../../resources/assets/assistant-voice-client.js', import.meta.url), 'utf8')
).replace("'./assistant-client.js'", JSON.stringify(assistantUrl));
const voiceUrl = `data:text/javascript;base64,${Buffer.from(voiceSource).toString('base64')}`;
const {
    createSemanticVad,
    createServerVad,
    createRealtimeVoiceClient,
    normalizeRealtimeSdp,
    realtimeToolCalls,
    realtimeVoiceState,
} = await import(voiceUrl);

class FakeChannel {
    constructor() {
        this.readyState = 'connecting';
        this.sent = [];
        this.listeners = new Map();
        this.onmessage = null;
        this.onclose = null;
        this.onerror = null;
    }

    addEventListener(name, listener) {
        const listeners = this.listeners.get(name) || new Set();
        listeners.add(listener);
        this.listeners.set(name, listeners);
    }

    removeEventListener(name, listener) {
        this.listeners.get(name)?.delete(listener);
    }

    dispatch(name, payload = {}) {
        for (const listener of this.listeners.get(name) || []) listener(payload);
        this[`on${name}`]?.(payload);
    }

    open() {
        this.readyState = 'open';
        this.dispatch('open');
    }

    send(payload) {
        this.sent.push(JSON.parse(payload));
    }

    close() {
        this.readyState = 'closed';
    }
}

class FakePeerConnection {
    static instances = [];

    constructor() {
        this.channel = new FakeChannel();
        this.localDescription = null;
        this.remoteDescription = null;
        this.tracks = [];
        this.closed = false;
        FakePeerConnection.instances.push(this);
    }

    createDataChannel() {
        return this.channel;
    }

    addTrack(track) {
        this.tracks.push(track);
    }

    async createOffer() {
        return { type: 'offer', sdp: 'offer-sdp' };
    }

    async setLocalDescription(offer) {
        this.localDescription = offer;
    }

    async setRemoteDescription(answer) {
        this.remoteDescription = answer;
        this.channel.open();
    }

    close() {
        this.closed = true;
    }
}

function browserFixture(fetchImpl) {
    const track = {
        enabled: true,
        stopped: false,
        stop() { this.stopped = true; },
    };
    const stream = {
        getTracks: () => [track],
        getAudioTracks: () => [track],
    };
    const audio = {
        autoplay: false,
        playsInline: false,
        srcObject: null,
        play: async () => {},
        pause: () => {},
    };
    const client = createRealtimeVoiceClient({
        fetch: fetchImpl,
        mediaDevices: { getUserMedia: async () => stream },
        RTCPeerConnection: FakePeerConnection,
        createAudio: () => audio,
        csrfToken: 'csrf',
        sdpEndpoint: '/realtime/sdp',
        toolEndpoint: '/realtime/tools',
        sessionId: 'voice-session',
        userId: 'user-1',
    });

    return { client, track, stream, audio };
}

test('voice helpers normalize SDP, state, and provider tool calls', () => {
    assert.equal(normalizeRealtimeSdp('one\ntwo'), 'one\r\ntwo\r\n');
    assert.equal(realtimeVoiceState({ type: 'input_audio_buffer.speech_stopped' }), 'processing');
    assert.deepEqual(
        realtimeToolCalls({
            type: 'response.function_call_arguments.done',
            call_id: 'call-1',
            name: 'agent_chat',
            arguments: '{"message":"مرحبا"}',
        }).map(({ id, name }) => ({ id, name })),
        [{ id: 'call-1', name: 'agent_chat' }],
    );
    assert.deepEqual(createSemanticVad(), {
        type: 'semantic_vad',
        eagerness: 'low',
        create_response: true,
        interrupt_response: true,
    });
    assert.equal(createServerVad().silence_duration_ms, 900);
});

test('headless voice client connects, mutes, interrupts, and disconnects', async () => {
    const requests = [];
    const { client, track } = browserFixture(async (url, request) => {
        requests.push({ url, request, body: JSON.parse(request.body) });
        return new Response(JSON.stringify({
            success: true,
            data: { session: { provider: 'openai', sdp: { answer: 'answer-sdp' } } },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    });
    const states = [];
    const phases = [];
    let disconnected = 0;
    client.on('voice.state', ({ state }) => states.push(state));
    client.on('voice.phase', ({ phase }) => phases.push(phase));
    client.on('voice.disconnected', () => { disconnected += 1; });

    const descriptor = await client.connect({ provider: 'openai', voice: 'marin' });

    assert.equal(descriptor.provider, 'openai');
    assert.equal(requests[0].body.sdp, 'offer-sdp');
    assert.equal(requests[0].body.transport, 'webrtc');
    assert.equal(requests[0].request.headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(client.isConnected(), true);
    assert.equal(client.getState(), 'listening');
    assert.deepEqual(states.slice(0, 3), ['requesting_microphone', 'connecting', 'listening']);
    assert.deepEqual(phases, ['creating_connection', 'negotiating', 'securing']);

    client.mute();
    assert.equal(track.enabled, false);
    assert.equal(client.getState(), 'muted');
    client.unmute();
    assert.equal(track.enabled, true);

    client.interrupt();
    const peer = FakePeerConnection.instances.at(-1);
    assert.deepEqual(peer.channel.sent.slice(-2).map(({ type }) => type), [
        'response.cancel',
        'output_audio_buffer.clear',
    ]);

    await client.disconnect();
    assert.equal(track.stopped, true);
    assert.equal(peer.closed, true);
    assert.equal(client.getState(), 'idle');
    assert.equal(disconnected, 1);
});

test('SDP negotiation has its own bounded timeout and aborts the request', async () => {
    let aborted = false;
    const track = {
        enabled: true,
        stopped: false,
        stop() { this.stopped = true; },
    };
    const timedClient = createRealtimeVoiceClient({
        fetch: async (_url, request) => new Promise((_resolve, reject) => {
            request.signal.addEventListener('abort', () => {
                aborted = true;
                const error = new Error('aborted');
                error.name = 'AbortError';
                reject(error);
            });
        }),
        mediaDevices: {
            getUserMedia: async () => ({
                getTracks: () => [track],
                getAudioTracks: () => [track],
            }),
        },
        RTCPeerConnection: FakePeerConnection,
        createAudio: () => ({}),
        negotiationTimeoutMs: 1,
        setTimeout: (callback) => {
            queueMicrotask(callback);
            return 1;
        },
        clearTimeout: () => {},
    });

    await assert.rejects(
        timedClient.connect(),
        (error) => error?.code === 'negotiation_timeout',
    );
    assert.equal(aborted, true);
    assert.equal(track.stopped, true);
    assert.equal(timedClient.getState(), 'failed');
});

test('remote audio can be retried after browser autoplay is unlocked', async () => {
    let playCalls = 0;
    let blocked = 0;
    const audio = {
        play: async () => {
            playCalls += 1;
            if (playCalls === 1) throw new Error('autoplay blocked');
        },
        pause: () => {},
        srcObject: null,
    };
    const client = createRealtimeVoiceClient({
        fetch: async () => new Response(JSON.stringify({
            success: true,
            data: { session: { provider: 'openai', sdp: { answer: 'answer-sdp' } } },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
        mediaDevices: {
            getUserMedia: async () => ({
                getTracks: () => [],
                getAudioTracks: () => [],
            }),
        },
        RTCPeerConnection: FakePeerConnection,
        createAudio: () => audio,
    });
    client.on('voice.audio_blocked', () => { blocked += 1; });
    await client.connect();

    const peer = FakePeerConnection.instances.at(-1);
    peer.ontrack({ streams: [{}] });
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(blocked, 1);

    assert.equal(await client.resumeAudio(), true);
    assert.equal(playCalls, 2);
});

test('speech only interrupts an active provider response', async () => {
    const { client } = browserFixture(async () => new Response(JSON.stringify({
        success: true,
        data: { session: { provider: 'openai', sdp: { answer: 'answer-sdp' } } },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    await client.connect();
    const channel = FakePeerConnection.instances.at(-1).channel;

    client.consumeRealtimeEvent({ type: 'input_audio_buffer.speech_started' });
    assert.equal(channel.sent.length, 0);

    client.consumeRealtimeEvent({ type: 'response.created' });
    client.consumeRealtimeEvent({ type: 'input_audio_buffer.speech_started' });
    assert.deepEqual(channel.sent.map(({ type }) => type), [
        'response.cancel',
        'output_audio_buffer.clear',
    ]);
});

test('disconnect cancels a pending microphone request without reconnecting', async () => {
    let releaseMicrophone;
    const track = {
        stopped: false,
        stop() { this.stopped = true; },
    };
    const microphone = new Promise((resolve) => {
        releaseMicrophone = () => resolve({ getTracks: () => [track] });
    });
    const client = createRealtimeVoiceClient({
        fetch: async () => {
            throw new Error('SDP must not be requested after cancellation.');
        },
        mediaDevices: { getUserMedia: () => microphone },
        RTCPeerConnection: FakePeerConnection,
        createAudio: () => ({}),
    });

    const connection = client.connect();
    await client.disconnect();
    releaseMicrophone();

    assert.equal(await connection, null);
    assert.equal(track.stopped, true);
    assert.equal(client.getState(), 'idle');
});

test('provider tool calls dispatch once and return authoritative output to realtime', async () => {
    let toolRequests = 0;
    const { client } = browserFixture(async (url) => {
        if (url === '/realtime/sdp') {
            return new Response(JSON.stringify({
                success: true,
                data: { session: { provider: 'openai', sdp: { answer: 'answer-sdp' } } },
            }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        }
        toolRequests += 1;
        return new Response(JSON.stringify({
            success: true,
            message: 'تم',
            data: {
                result: {
                    success: true,
                    status: 'completed',
                    message: 'تم إنشاء الدورة.',
                    output: { message: 'تم إنشاء الدورة.' },
                },
            },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    });
    await client.connect();
    const completed = new Promise((resolve) => client.on('tool.completed', resolve));
    const functionCall = {
        type: 'response.function_call_arguments.done',
        call_id: 'call-42',
        name: 'agent_chat',
        arguments: '{"message":"أنشئ دورة"}',
    };

    client.consumeRealtimeEvent(functionCall);
    client.consumeRealtimeEvent(functionCall);
    const result = await completed;

    assert.equal(toolRequests, 1);
    assert.equal(result.text, 'تم إنشاء الدورة.');
    const sent = FakePeerConnection.instances.at(-1).channel.sent;
    assert.equal(sent.some((event) => event.type === 'conversation.item.create'), true);
    assert.equal(sent.some((event) => event.type === 'response.create'), true);
});
