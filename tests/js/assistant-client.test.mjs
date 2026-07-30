import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../resources/assets/assistant-client.js', import.meta.url), 'utf8');
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const { createAssistantClient } = await import(moduleUrl);

test('headless client normalizes realtime captions and response deltas', () => {
    const client = createAssistantClient();
    const events = [];
    client.on('*', (event) => events.push(event));

    client.consumeRealtimeEvent({ type: 'conversation.item.input_audio_transcription.delta', delta: 'مرح' });
    client.consumeRealtimeEvent({ type: 'conversation.item.input_audio_transcription.completed', transcript: 'مرحبا' });
    client.consumeRealtimeEvent({ type: 'response.audio_transcript.delta', delta: 'أهلًا' });
    client.consumeRealtimeEvent({ type: 'response.audio_transcript.done', transcript: 'أهلًا' });
    client.consumeRealtimeEvent({ type: 'response.done', event_id: 'done-1', response: { id: 'response-1' } });
    client.consumeRealtimeEvent({ type: 'response.done', event_id: 'done-2', response: { id: 'response-1' } });

    assert.deepEqual(events.map((event) => event.name), [
        'transcription.partial',
        'transcription.final',
        'assistant.delta',
        'assistant.completed',
    ]);
    assert.equal(events.at(-1).payload.text, 'أهلًا');
});

test('cancel emits a terminal event without requiring an active request', () => {
    const client = createAssistantClient();
    let cancelled = false;
    client.on('assistant.cancelled', () => { cancelled = true; });

    client.cancel();

    assert.equal(cancelled, true);
});

test('send acknowledges immediately before waiting for the server', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => new Response(JSON.stringify({ success: true }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });

    try {
        const client = createAssistantClient();
        const names = [];
        client.on('*', ({ name }) => names.push(name));

        await client.send('hello');

        assert.equal(names[0], 'assistant.acknowledged');
    } finally {
        globalThis.fetch = originalFetch;
    }
});
