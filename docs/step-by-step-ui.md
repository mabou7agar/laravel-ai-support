# Showing step-by-step activity (Claude-Code style)

Stream what the agent is doing in real time — `Thinking… → Searching for customer… → Creating invoice… → Done` — by consuming the agent-run event stream. The engine already emits every step; you just render it.

## 1. Run the turn as a durable async run

```http
POST /api/v1/agent/chat
{ "message": "create an invoice for Acme", "session_id": "abc", "execution_mode": "async" }
```

The response (202) returns the run id + the SSE stream URL for that run. Each turn becomes an `AIAgentRun` whose events you subscribe to.

## 2. Subscribe to the SSE stream

Every event the engine emits is delivered as an SSE frame. The engine attaches a ready-to-render `activity` object to each event so you don't re-implement label mapping:

```json
{
  "id": "…",
  "name": "tool.started",
  "payload": { "tool_name": "find_customer" },
  "activity": { "icon": "🔎", "label": "Searching for customer", "phase": "acting", "terminal": false }
}
```

### Minimal vanilla-JS consumer

```js
const es = new EventSource(streamUrl); // streamUrl from the 202 response

const line = document.getElementById('activity');   // the live "what's happening" line
const log  = document.getElementById('timeline');   // the scrollback list

es.onmessage = (e) => {
  const event = JSON.parse(e.data);
  const a = event.activity; // { icon, label, phase, terminal }

  // Live line (Claude-Code-style spinner): show the current step.
  line.textContent = `${a.icon} ${a.label}${a.terminal ? '' : '…'}`;

  // Append non-noisy steps to a timeline.
  if (['acting', 'searching', 'waiting', 'done', 'error'].includes(a.phase)) {
    const li = document.createElement('li');
    li.dataset.phase = a.phase;
    li.textContent = `${a.icon} ${a.label}`;
    log.appendChild(li);
  }

  if (a.phase === 'waiting' && event.name === 'run.waiting_input') {
    // The agent is asking the user for something — show the assistant's question
    // (it's in run.waiting_input / the final response) and re-enable the input box.
  }

  if (a.terminal) es.close(); // run.completed / run.failed / run.cancelled
};
```

That's the whole integration. A typical "create an invoice" turn renders:

```
✶ Thinking
🔎 Searching for customer        (tool.started find_customer)
✓ Searching for customer — done  (tool.completed)
⏳ Waiting for your input         (run.waiting_input — "Which products?")
✚ Creating invoice               (next turn, tool.started create_invoice)
✓ Done
```

## 3. Phases (for styling / grouping)

`start · thinking · searching · acting · writing · waiting · done · error`

Group or color by `phase`; treat `terminal: true` as the end of the run.

## 4. Full event vocabulary

The complete ordered lifecycle a frontend can subscribe to (`AgentRunEventStreamService` constants):

```
run.started
routing.stage_started · routing.stage_abstained · routing.decided
rag.started · rag.sources_found · rag.completed
tool.started · tool.progress · tool.completed · tool.failed
sub_agent.started · sub_agent.completed
approval.required · approval.resolved
artifact.created
final_response.token_streamed · final_response.stream_completed
run.waiting_input · run.waiting_approval
run.completed · run.failed · run.cancelled · run.expired
```

## 5. Server-side label mapping (if you don't use SSE)

If you poll instead of streaming, map events to labels yourself with the same presenter:

```php
$activity = app(\LaravelAIEngine\Services\Agent\AgentActivityPresenter::class)
    ->describe($event['name'], $event['payload'] ?? []);
// => ['label' => 'Searching for customer', 'icon' => '🔎', 'phase' => 'acting', 'terminal' => false]
```

The tool→label mapping humanizes the verb + entity automatically:
`find_*` → "Searching for …", `create_*` → "Creating …", `update_*/modify_*` → "Modifying …",
`enhance_*/upscale_*` → "Enhancing …", `delete_*` → "Removing …", plus friendly names for
`data_query`, `run_skill`, `run_sub_agent`.
