<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning;

use LaravelAIEngine\DTOs\LearnedDesignGenerationRequest;
use LaravelAIEngine\DTOs\LearningSearchResult;

class LearnedDesignHtmlComposer
{
    /**
     * @param array<int, LearningSearchResult> $matches
     */
    public function compose(LearnedDesignGenerationRequest $request, array $matches, string $generatedContent = ''): string
    {
        if (trim($generatedContent) !== '') {
            return $generatedContent;
        }

        $tokens = $this->tokens($matches);
        $copy = $this->copy($request->prompt);
        $heroBackground = $this->heroBackground($request->mediaUrl);

        return '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $this->e($copy['title']) . '</title>
    <style>
        :root {
            --canvas: ' . $tokens['canvas'] . ';
            --surface: ' . $tokens['surface'] . ';
            --surface-soft: ' . $tokens['surface_soft'] . ';
            --hairline: ' . $tokens['hairline'] . ';
            --ink: ' . $tokens['ink'] . ';
            --body: ' . $tokens['body'] . ';
            --muted: ' . $tokens['muted'] . ';
            --accent-a: ' . $tokens['accent_a'] . ';
            --accent-b: ' . $tokens['accent_b'] . ';
            --accent-c: ' . $tokens['accent_c'] . ';
            --font: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--canvas); color: var(--ink); font-family: var(--font); }
        button, input { font: inherit; }
        .topbar { height: 64px; display: flex; align-items: center; justify-content: space-between; gap: 24px; padding: 0 40px; background: var(--canvas); border-bottom: 1px solid var(--hairline); }
        .brand { display: flex; align-items: center; gap: 14px; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0; }
        .brand-mark, .stripe { display: grid; grid-template-columns: repeat(3, 1fr); }
        .brand-mark { width: 32px; height: 24px; }
        .brand-mark span:nth-child(1), .stripe span:nth-child(1) { background: var(--accent-a); }
        .brand-mark span:nth-child(2), .stripe span:nth-child(2) { background: var(--accent-b); }
        .brand-mark span:nth-child(3), .stripe span:nth-child(3) { background: var(--accent-c); }
        .nav { display: flex; gap: 24px; color: var(--body); font-size: 13px; text-transform: uppercase; }
        .button, .icon { border: 1px solid var(--ink); border-radius: 0; background: transparent; color: var(--ink); min-height: 44px; text-transform: uppercase; font-size: 13px; font-weight: 800; letter-spacing: 1.5px; }
        .button { padding: 0 22px; }
        .icon { width: 44px; border-radius: 999px; border-color: var(--hairline); background: var(--surface); }
        .hero { min-height: 520px; display: grid; align-items: end; padding: 96px 40px 56px; border-bottom: 1px solid var(--hairline); ' . $heroBackground . ' }
        .hero-inner, .content { width: min(100%, 1200px); margin: 0 auto; }
        .stripe { width: 168px; height: 4px; margin-bottom: 28px; }
        h1 { max-width: 820px; margin: 0; font-size: 80px; line-height: 1; font-weight: 850; text-transform: uppercase; }
        .hero p { max-width: 590px; margin: 24px 0 0; color: var(--body); font-size: 16px; line-height: 1.55; font-weight: 300; }
        .metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); max-width: 820px; margin-top: 48px; border: 1px solid var(--hairline); background: rgba(0,0,0,.6); }
        .metric { padding: 24px; border-right: 1px solid var(--hairline); }
        .metric:last-child { border-right: 0; }
        .metric strong { display: block; font-size: 32px; line-height: 1.1; }
        .metric span { display: block; margin-top: 8px; color: var(--muted); font-size: 12px; text-transform: uppercase; }
        .content { padding: 64px 40px 96px; }
        .section-head { display: flex; align-items: end; justify-content: space-between; gap: 24px; margin-bottom: 24px; }
        .kicker { margin: 0 0 10px; color: var(--muted); font-size: 12px; text-transform: uppercase; }
        h2 { margin: 0; font-size: 40px; line-height: 1.1; font-weight: 850; text-transform: uppercase; }
        .grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(360px, .65fr); gap: 24px; align-items: start; }
        .board, .panel, .signal { background: var(--surface-soft); border: 1px solid var(--hairline); }
        .toolbar { display: flex; justify-content: space-between; gap: 16px; padding: 20px 24px; border-bottom: 1px solid var(--hairline); }
        .tabs { display: flex; gap: 24px; color: var(--muted); font-size: 12px; font-weight: 800; text-transform: uppercase; }
        .tabs span:first-child { color: var(--ink); padding-bottom: 8px; border-bottom: 2px solid var(--ink); }
        .row { display: grid; grid-template-columns: 1.35fr 1fr .75fr .75fr 44px; gap: 16px; align-items: center; padding: 18px 24px; border-bottom: 1px solid var(--hairline); }
        .row:last-child { border-bottom: 0; }
        .row strong { font-size: 18px; }
        .meta, .label { color: var(--muted); font-size: 12px; text-transform: uppercase; }
        .status { justify-self: start; padding: 7px 10px; border: 1px solid var(--hairline); color: var(--body); font-size: 12px; text-transform: uppercase; }
        .panel-head { padding: 24px; border-bottom: 1px solid var(--hairline); }
        .panel-head h3, .signal h3 { margin: 0; font-size: 24px; line-height: 1.25; text-transform: uppercase; }
        .panel-head p, .signal p { margin: 10px 0 0; color: var(--body); font-size: 14px; line-height: 1.5; }
        .thread, .suggestions, .composer { padding: 24px; }
        .thread { display: grid; gap: 12px; }
        .message, .suggestion, input { border: 1px solid var(--hairline); background: var(--surface); color: var(--body); }
        .message { padding: 14px 16px; font-size: 14px; line-height: 1.45; }
        .message:first-child { border-color: var(--ink); color: var(--ink); background: transparent; }
        .suggestions { display: grid; gap: 10px; padding-top: 0; }
        .suggestion { display: flex; justify-content: space-between; gap: 12px; padding: 14px 16px; font-size: 13px; text-transform: uppercase; }
        .composer { display: grid; grid-template-columns: 1fr auto; gap: 10px; border-top: 1px solid var(--hairline); }
        input { min-height: 44px; padding: 0 14px; color: var(--ink); border-radius: 0; }
        .signals { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; margin-top: 24px; }
        .signal { padding: 24px; }
        .signal strong { display: block; margin-top: 22px; font-size: 32px; }
        @media (max-width: 980px) { .nav { display: none; } .hero { padding: 80px 24px 48px; } h1 { font-size: 56px; } .content { padding: 48px 24px 72px; } .grid, .signals { grid-template-columns: 1fr; } .row { grid-template-columns: 1fr .8fr .7fr 44px; } .row .label { display: none; } }
        @media (max-width: 640px) { .topbar { padding: 0 24px; } .button.hide-sm { display: none; } h1 { font-size: 40px; } .metrics { grid-template-columns: 1fr; } .metric { border-right: 0; border-bottom: 1px solid var(--hairline); } .metric:last-child { border-bottom: 0; } .section-head, .toolbar { align-items: start; flex-direction: column; } h2 { font-size: 32px; } .row, .composer { grid-template-columns: 1fr; } .row .icon { width: 100%; border-radius: 0; } }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand"><span class="brand-mark"><span></span><span></span><span></span></span>' . $this->e($copy['title']) . '</div>
        <nav class="nav"><span>Records</span><span>Entities</span><span>Signals</span><span>AI Console</span></nav>
        <button class="button hide-sm">Audit Trail</button>
    </header>
    <section class="hero">
        <div class="hero-inner">
            <div class="stripe"><span></span><span></span><span></span></div>
            <h1>' . $this->e($copy['hero']) . '</h1>
            <p>' . $this->e($copy['summary']) . '</p>
            <div class="metrics"><div class="metric"><strong>86%</strong><span>Ready state</span></div><div class="metric"><strong>17</strong><span>Items need review</span></div><div class="metric"><strong>4.8h</strong><span>AI time saved</span></div></div>
        </div>
    </section>
    <main class="content">
        <div class="section-head"><div><p class="kicker">Workspace / learned design preview</p><h2>Review, Ask, Create</h2></div><button class="button hide-sm">Create</button></div>
        <div class="grid">
            <section class="board"><div class="toolbar"><div class="tabs"><span>Priority</span><span>Drafts</span><span>Overdue</span></div><button class="button">Export</button></div>
                ' . $this->recordRows($copy) . '
            </section>
            <aside class="panel"><div class="panel-head"><h3>AI Command</h3><p>Scoped suggestions are surfaced from learned rules, tools, skills, and current workspace state.</p></div><div class="thread"><div class="message">' . $this->e($copy['sample_user']) . '</div><div class="message">' . $this->e($copy['sample_ai']) . '</div></div><div class="suggestions"><button class="suggestion"><span>Prepare draft</span><span>→</span></button><button class="suggestion"><span>Send follow-up</span><span>→</span></button><button class="suggestion"><span>Explain risk</span><span>→</span></button></div><form class="composer"><input value="' . $this->e($copy['input']) . '" aria-label="Ask AI"><button class="button" type="button">Send</button></form></aside>
        </div>
        <section class="signals"><article class="signal"><h3>Review Load</h3><p>Grouped records reveal entities that need a scoped follow-up.</p><strong>7</strong></article><article class="signal"><h3>Auto Actions</h3><p>Drafts can be completed from conversation, matched tools, and stored profiles.</p><strong>12</strong></article><article class="signal"><h3>Missing Inputs</h3><p>Fields the agent still needs before it can execute confirmed work.</p><strong>5</strong></article></section>
    </main>
</body>
</html>';
    }

    protected function heroBackground(?string $mediaUrl): string
    {
        if ($mediaUrl !== null && filter_var($mediaUrl, FILTER_VALIDATE_URL) !== false) {
            return 'background-image: linear-gradient(90deg, rgba(0,0,0,.96), rgba(0,0,0,.68), rgba(0,0,0,.20)), url("' . $this->css($mediaUrl) . '"); background-size: cover; background-position: center;';
        }

        return 'background: linear-gradient(90deg, rgba(0,0,0,.94), rgba(0,0,0,.72), rgba(0,0,0,.20)), radial-gradient(circle at 72% 24%, rgba(255,255,255,.18), transparent 28%), linear-gradient(135deg, #111, #000 55%, #2b2b2b);';
    }

    protected function copy(string $prompt): array
    {
        return [
            'title' => 'AI Command',
            'hero' => 'Work Under Command',
            'summary' => 'A learned design translation for an AI workspace: sharp surfaces, restrained controls, full-context review, and chat that turns conversation into confirmed actions.',
            'sample_user' => 'Create a new workspace task from this conversation.',
            'sample_ai' => 'I found related records and tools. Confirm the missing fields before I prepare the action.',
            'input' => 'Add missing details',
        ];
    }

    protected function recordRows(array $copy): string
    {
        $rows = [
            ['Priority Record', 'REC-1024 · needs review', 'Matched rules and owner', 'High', 'Review'],
            ['Launch Queue', 'TASK-917 · ready', 'Tools available', 'Ready', 'Ready'],
            ['Risk Signal', 'CASE-661 · unresolved', 'Follow-up suggested', 'Watch', 'Watch'],
            ['Draft Action', 'Draft · missing input', 'Needs confirmation', 'Input', 'Input'],
        ];

        return implode('', array_map(fn (array $row): string => '<article class="row"><div><strong>' . $this->e($row[0]) . '</strong><div class="meta">' . $this->e($row[1]) . '</div></div><span class="label">' . $this->e($row[2]) . '</span><strong>' . $this->e($row[3]) . '</strong><span class="status">' . $this->e($row[4]) . '</span><button class="icon" aria-label="Open">→</button></article>', $rows));
    }

    /**
     * @param array<int, LearningSearchResult> $matches
     * @return array<string, string>
     */
    protected function tokens(array $matches): array
    {
        $source = implode("\n", array_map(static fn (LearningSearchResult $match): string => $match->source->content . "\n" . $match->item->content, $matches));

        return [
            'canvas' => $this->token($source, 'canvas', '#000000'),
            'surface' => $this->token($source, 'surface-card', '#1a1a1a'),
            'surface_soft' => $this->token($source, 'surface-soft', '#0d0d0d'),
            'hairline' => $this->token($source, 'hairline', '#3c3c3c'),
            'ink' => $this->token($source, 'ink', '#ffffff'),
            'body' => $this->token($source, 'body', '#bbbbbb'),
            'muted' => $this->token($source, 'muted', '#7e7e7e'),
            'accent_a' => $this->tokenFrom($source, ['accent-a', 'accent-primary', 'primary'], '#0066b1'),
            'accent_b' => $this->tokenFrom($source, ['accent-b', 'accent-secondary', 'secondary'], '#1c69d4'),
            'accent_c' => $this->tokenFrom($source, ['accent-c', 'accent-danger', 'danger'], '#e22718'),
        ];
    }

    /**
     * @param array<int, string> $names
     */
    protected function tokenFrom(string $source, array $names, string $default): string
    {
        foreach ($names as $name) {
            $value = $this->token($source, $name, '');
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    protected function token(string $source, string $name, string $default): string
    {
        if (preg_match('/' . preg_quote($name, '/') . ':\s*[\'"]?(#[0-9a-f]{6})[\'"]?/i', $source, $matches) === 1) {
            return $matches[1];
        }

        return $default;
    }

    protected function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function css(string $value): string
    {
        return str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\"', '', ''], $value);
    }
}
