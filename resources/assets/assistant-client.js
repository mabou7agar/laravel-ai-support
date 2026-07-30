const DEFAULT_EVENTS = [
    'assistant.acknowledged',
    'transcription.partial',
    'transcription.final',
    'rag.started',
    'rag.sources_found',
    'tool.started',
    'tool.progress',
    'tool.completed',
    'assistant.delta',
    'assistant.completed',
    'assistant.cancelled',
    'assistant.failed',
    'run.completed',
    'run.failed',
    'run.cancelled',
];

export function createAssistantClient(options = {}) {
    const listeners = new Map();
    let stream = null;
    let controller = null;
    let cancelUrl = null;
    let assistantText = '';
    const consumedRealtimeEvents = new Set();
    const completedResponses = new Set();

    const emit = (name, payload = {}) => {
        for (const listener of listeners.get(name) || []) listener(payload);
        for (const listener of listeners.get('*') || []) listener({ name, payload });
    };

    const on = (name, listener) => {
        const current = listeners.get(name) || new Set();
        current.add(listener);
        listeners.set(name, current);
        return () => current.delete(listener);
    };

    const connectStream = (url) => {
        if (!url || typeof EventSource === 'undefined') return null;
        if (stream) stream.close();
        stream = new EventSource(url, { withCredentials: options.withCredentials !== false });
        for (const name of options.events || DEFAULT_EVENTS) {
            stream.addEventListener(name, (event) => {
                let payload = {};
                try { payload = JSON.parse(event.data || '{}'); } catch { payload = { text: event.data }; }
                emit(name, payload);
            });
        }
        stream.onerror = (error) => emit('transport.error', { error });
        return stream;
    };

    const request = async (url, payload) => {
        controller?.abort();
        controller = new AbortController();
        const response = await fetch(url, {
            method: 'POST',
            credentials: options.withCredentials === false ? 'same-origin' : 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) },
            body: JSON.stringify(payload),
            signal: controller.signal,
        });
        const body = await response.json();
        if (!response.ok) {
            const error = new Error(body?.message || `Assistant request failed (${response.status})`);
            error.response = body;
            throw error;
        }
        const streamUrl = body?.data?.stream_url || body?.stream_url;
        if (streamUrl) connectStream(streamUrl);
        const runId = body?.data?.agent_run_id || body?.agent_run_id;
        cancelUrl = body?.data?.cancel_url
            || body?.cancel_url
            || (runId ? `/api/v1/ai/agent-runs/${encodeURIComponent(runId)}/cancel` : null);
        return body;
    };

    const send = (message, payload = {}) => {
        assistantText = '';
        cancelUrl = null;
        emit('assistant.acknowledged', { message, local: true });
        return request(options.chatEndpoint || '/api/v1/agent/chat', {
            ...payload,
            message,
        });
    };

    const createRealtimeSession = (payload = {}) => request(
        options.realtimeEndpoint || '/api/v1/ai/realtime/sessions',
        payload,
    );

    const consumeRealtimeEvent = (event) => {
        const type = event?.type || '';
        const eventId = event?.event_id || event?.id;
        if (eventId && consumedRealtimeEvents.has(eventId)) return;
        if (eventId) {
            consumedRealtimeEvents.add(eventId);
            if (consumedRealtimeEvents.size > 500) {
                consumedRealtimeEvents.delete(consumedRealtimeEvents.values().next().value);
            }
        }

        if (type === 'response.created') assistantText = '';
        const text = event?.delta || event?.transcript || event?.text || '';
        if (type.includes('input_audio_transcription.delta')) emit('transcription.partial', { text, event });
        if (type.includes('input_audio_transcription.completed')) emit('transcription.final', { text, event });
        if (type.includes('audio_transcript.delta') || type === 'response.text.delta') {
            assistantText += text;
            emit('assistant.delta', { text, transcript: assistantText, event });
        }
        if (type.includes('audio_transcript.done') && text) assistantText = text;
        if (type === 'response.done') {
            const responseId = event?.response?.id || eventId || `anonymous:${assistantText}`;
            if (!completedResponses.has(responseId)) {
                completedResponses.add(responseId);
                emit('assistant.completed', { text: assistantText || text, event });
            }
        }
    };

    const cancel = async (payload = {}) => {
        controller?.abort();
        stream?.close();
        controller = null;
        stream = null;
        emit('assistant.cancelled', {});
        if (!cancelUrl) return null;

        const url = cancelUrl;
        cancelUrl = null;
        try {
            return await fetch(url, {
                method: 'POST',
                credentials: options.withCredentials === false ? 'same-origin' : 'include',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) },
                body: JSON.stringify(payload),
            });
        } catch (error) {
            emit('transport.error', { error });
            return null;
        }
    };

    return { on, send, connectStream, createRealtimeSession, consumeRealtimeEvent, cancel };
}
