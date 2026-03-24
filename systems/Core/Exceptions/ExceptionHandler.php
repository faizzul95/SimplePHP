<?php

namespace Core\Exceptions;

use Throwable;

class ExceptionHandler
{
    /**
     * Handle the exception and render the appropriate error page.
     */
    public static function handle(Throwable $e, ?bool $debug = null): void
    {
        error_log("Unhandled Exception: " . $e->getMessage());
        $statusCode = $e->getCode();
        if ($statusCode < 100 || $statusCode >= 600) {
            $statusCode = 500;
        }
        if (!headers_sent()) {
            http_response_code($statusCode);
        }

        $isDebug = $debug ?? (function_exists('env') ? (env('APP_DEBUG') === 'true' || env('APP_DEBUG') === true || env('APP_DEBUG') === '1' || env('APP_DEBUG') === 1) : false);

        if ($isDebug) {
            self::renderDebug($e);
        } else {
            self::renderProduction($e, $statusCode);
        }
    }

    private static function renderDebug(Throwable $e): void
    {
        ob_start();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $message = $e->getMessage() ?: 'Unknown Error';
        $exceptionClass = get_class($e);
        $statusCode = self::normalizeStatusCode((int) $e->getCode());
        $frames = self::getFrames($e);
        $globals = self::getGlobalsHtml();
        $phpVersion = phpversion();

        $memory = function_exists('memory_get_usage') ? round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB' : 'Unknown';
        $time = isset($_SERVER['REQUEST_TIME_FLOAT']) ? round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . ' ms' : 'Unknown';
        $files = count(get_included_files());
        $responseInfo = self::getResponseDetails($e, $statusCode, $time, $memory, $files, $frames);

        ?>
        <!DOCTYPE html>
        <html lang="en" class="h-full antialiased">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= htmlspecialchars($exceptionClass) ?></title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
            <script>
                const t = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', t);
            </script>
            <style>
                :root {
                    --bg: #ffffff;
                    --surface: #f9fafb;
                    --border: #e5e7eb;
                    --text: #111827;
                    --text-muted: #6b7280;
                    --accent: #ef4444;
                    --accent-light: #fee2e2;
                    --accent-hover: #fca5a5;
                    --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
                    --font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
                    
                    /* Code Syntax - Light */
                    --c-var: #e11d48;
                    --c-key: #9333ea;
                    --c-str: #16a34a;
                    --c-com: #6b7280;
                    --c-num: #d97706;
                    --c-tag: #2563eb;
                    --c-bg: #ffffff;
                    --c-err: rgba(239, 68, 68, 0.1);
                    --c-hov: rgba(0, 0, 0, 0.03);
                }
                html[data-theme="dark"] {
                    --bg: #0b0f19;
                    --surface: #111827;
                    --border: #1f2937;
                    --text: #f3f4f6;
                    --text-muted: #9ca3af;
                    --accent: #f87171;
                    --accent-light: rgba(248, 113, 113, 0.1);
                    --accent-hover: rgba(248, 113, 113, 0.2);
                    
                    /* Code Syntax - Dark */
                    --c-var: #e06c75;
                    --c-key: #c678dd;
                    --c-str: #98c379;
                    --c-com: #5c6370;
                    --c-num: #d19a66;
                    --c-tag: #61afef;
                    --c-bg: #0b0f19;
                    --c-err: rgba(248, 113, 113, 0.1);
                    --c-hov: rgba(255, 255, 255, 0.02);
                }

                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: var(--font-sans);
                    background-color: var(--bg);
                    color: var(--text);
                    min-height: 100vh;
                    display: flex;
                    flex-direction: column;
                    overflow-x: hidden;
                    font-size: 14px;
                    transition: background-color 0.2s, color 0.2s;
                }
                
                /* Layout */
                .header {
                    background-color: var(--surface);
                    border-bottom: 1px solid var(--border);
                    padding: 2rem 3rem;
                    flex-shrink: 0;
                    transition: background-color 0.2s, border-color 0.2s;
                }
                .main {
                    display: flex;
                    flex: 1;
                    min-height: 0;
                }
                .sidebar {
                    width: 340px;
                    background-color: var(--bg);
                    border-right: 1px solid var(--border);
                    overflow-y: auto;
                    transition: background-color 0.2s, border-color 0.2s;
                }
                .content {
                    flex: 1;
                    background-color: var(--surface);
                    overflow-y: auto;
                    padding: 3rem;
                    transition: background-color 0.2s;
                }
                .stack-title {
                    display: none;
                    padding: 1rem 1.5rem 0.5rem;
                    font-size: 11px;
                    letter-spacing: 0.08em;
                    text-transform: uppercase;
                    color: var(--text-muted);
                    font-family: var(--font-mono);
                }

                /* Header details */
                .h-flex {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    gap: 1rem;
                }
                .badge {
                    color: var(--accent);
                    font-family: var(--font-mono);
                    font-size: 12px;
                    font-weight: 500;
                    margin-bottom: 0.5rem;
                    display: inline-block;
                    padding: 0.25rem 0.5rem;
                    background: var(--accent-light);
                    border-radius: 4px;
                }
                .title {
                    font-size: 1.5rem;
                    font-weight: 600;
                    line-height: 1.4;
                    margin-bottom: 1rem;
                    word-wrap: break-word;
                }
                .meta {
                    font-family: var(--font-mono);
                    font-size: 12px;
                    color: var(--text-muted);
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    gap: 0.75rem;
                }
                .meta b { color: var(--text); font-weight: 500; }
                .divider { color: var(--border); opacity: 0.8; user-select: none; }

                .theme-btn {
                    background: transparent;
                    border: 1px solid var(--border);
                    color: var(--text-muted);
                    padding: 0.5rem;
                    border-radius: 6px;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.15s;
                }
                .theme-btn:hover {
                    background: var(--bg);
                    color: var(--text);
                    border-color: var(--text-muted);
                }

                .summary-grid {
                    display: grid;
                    grid-template-columns: repeat(4, minmax(0, 1fr));
                    gap: 0.75rem;
                    margin-top: 1.25rem;
                }
                .summary-card {
                    border: 1px solid var(--border);
                    background: var(--bg);
                    border-radius: 10px;
                    padding: 0.9rem 1rem;
                }
                .summary-label {
                    display: block;
                    font-size: 11px;
                    font-family: var(--font-mono);
                    text-transform: uppercase;
                    letter-spacing: 0.06em;
                    color: var(--text-muted);
                    margin-bottom: 0.45rem;
                }
                .summary-value {
                    font-size: 14px;
                    font-weight: 600;
                    color: var(--text);
                    word-break: break-word;
                }

                /* Stack Trace */
                .frame {
                    padding: 1rem 1.5rem;
                    cursor: pointer;
                    border-left: 2px solid transparent;
                    transition: background 0.1s;
                }
                .frame:hover { background: var(--c-hov); }
                .frame.active {
                    background: var(--surface);
                    border-left-color: var(--accent);
                }
                .f-func { font-family: var(--font-mono); font-size: 13px; color: var(--text); word-break: break-all; margin-bottom: 0.25rem; }
                .f-func span { color: var(--text-muted); }
                .f-loc { font-size: 12px; color: var(--text-muted); word-break: break-all; }

                .tab-bar {
                    display: flex;
                    gap: 0.5rem;
                    margin-bottom: 1rem;
                    border-bottom: 1px solid var(--border);
                    padding-bottom: 0.75rem;
                    position: sticky;
                    top: 0;
                    background: var(--surface);
                    z-index: 5;
                }
                .tab-btn {
                    border: 1px solid var(--border);
                    background: transparent;
                    color: var(--text-muted);
                    padding: 0.55rem 0.85rem;
                    border-radius: 999px;
                    font: inherit;
                    font-family: var(--font-mono);
                    font-size: 12px;
                    cursor: pointer;
                    white-space: nowrap;
                }
                .tab-btn.active {
                    color: var(--text);
                    background: var(--bg);
                    border-color: var(--accent);
                }
                .panel {
                    display: none;
                }
                .panel.active {
                    display: block;
                }

                /* Code Context */
                .code-block {
                    display: none;
                    background: var(--c-bg);
                    border: 1px solid var(--border);
                    border-radius: 8px;
                    padding: 1.5rem 0;
                    margin-bottom: 3rem;
                    overflow-x: auto;
                    font-family: var(--font-mono);
                    font-size: 13px;
                    line-height: 1.6;
                    transition: background-color 0.2s, border-color 0.2s;
                }
                .code-block.active { display: block; }
                
                .c-row { display: flex; padding: 0 1.5rem; transition: background 0.1s; }
                .c-row:hover { background: var(--c-hov); }
                .c-row.err { background: var(--c-err); }
                .c-num { color: var(--text-muted); width: 3rem; text-align: right; margin-right: 1.5rem; user-select: none; }
                .c-row.err .c-num { color: var(--accent); font-weight: 500; }
                .c-line { color: var(--text); white-space: pre; }
                .code-empty {
                    color: var(--text-muted);
                    padding: 1.5rem;
                    font-family: var(--font-mono);
                }

                /* Settings Config */
                .section { margin-bottom: 3rem; }
                .s-title { font-weight: 600; font-size: 16px; margin-bottom: 1rem; color: var(--text); }
                
                .table-wrap {
                    border: 1px solid var(--border);
                    border-radius: 8px;
                    overflow: hidden;
                    background: var(--bg);
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    font-family: var(--font-mono);
                    font-size: 13px;
                }
                th, td {
                    padding: 1rem 1.5rem;
                    text-align: left;
                    vertical-align: top;
                    border-bottom: 1px solid var(--border);
                }
                th {
                    color: var(--text-muted);
                    font-weight: 400;
                    width: 30%;
                    background: rgba(125,125,125,0.03);
                }
                tr:last-child th, tr:last-child td { border-bottom: none; }
                .v-protected { color: var(--text-muted); font-style: italic; }

                .subsection {
                    margin-top: 1rem;
                }
                .subsection:first-child {
                    margin-top: 0;
                }
                .subsection-title {
                    font-size: 12px;
                    letter-spacing: 0.06em;
                    text-transform: uppercase;
                    color: var(--text-muted);
                    font-family: var(--font-mono);
                    margin-bottom: 0.6rem;
                }
                .stack-list {
                    display: grid;
                    gap: 0.9rem;
                }
                .stack-card {
                    border: 1px solid var(--border);
                    border-radius: 10px;
                    background: var(--bg);
                    padding: 1rem;
                }
                .stack-card-title {
                    font-family: var(--font-mono);
                    font-size: 13px;
                    color: var(--text);
                    margin-bottom: 0.35rem;
                    word-break: break-word;
                }
                .stack-card-meta {
                    font-family: var(--font-mono);
                    font-size: 12px;
                    color: var(--text-muted);
                    margin-bottom: 0.75rem;
                    word-break: break-word;
                }
                .stack-card-actions {
                    display: flex;
                    gap: 0.5rem;
                    flex-wrap: wrap;
                }
                .mini-chip {
                    border: 1px solid var(--border);
                    border-radius: 999px;
                    padding: 0.3rem 0.55rem;
                    font-family: var(--font-mono);
                    font-size: 11px;
                    color: var(--text-muted);
                    background: rgba(125,125,125,0.03);
                }

                /* Scrollbar */
                ::-webkit-scrollbar { width: 8px; height: 8px; }
                ::-webkit-scrollbar-track { background: transparent; }
                ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
                ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

                @media (max-width: 960px) {
                    .header {
                        padding: 1.25rem 1rem;
                    }
                    .h-flex {
                        flex-direction: column;
                        align-items: stretch;
                    }
                    .theme-btn {
                        align-self: flex-end;
                    }
                    .summary-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                    }
                    .main {
                        flex-direction: column;
                    }
                    .sidebar {
                        width: 100%;
                        border-right: 0;
                        border-bottom: 1px solid var(--border);
                        overflow-x: auto;
                        overflow-y: hidden;
                    }
                    .stack-title {
                        display: block;
                    }
                    .content {
                        padding: 1rem;
                    }
                    .sidebar-track {
                        display: flex;
                        min-width: max-content;
                        padding: 0.25rem 0.5rem 0.75rem;
                    }
                    .frame {
                        min-width: 240px;
                        max-width: 240px;
                        border-left-width: 0;
                        border-bottom: 2px solid transparent;
                        border-radius: 10px;
                    }
                    .frame.active {
                        border-left-color: transparent;
                        border-bottom-color: var(--accent);
                    }
                    .tab-bar {
                        overflow-x: auto;
                        padding-top: 0.25rem;
                    }
                    .code-block {
                        padding: 1rem 0;
                    }
                    .c-row {
                        padding: 0 0.9rem;
                    }
                    .c-num {
                        width: 2.4rem;
                        margin-right: 0.75rem;
                    }
                    .c-line {
                        white-space: pre-wrap;
                        word-break: break-word;
                    }
                    th,
                    td {
                        display: block;
                        width: 100%;
                    }
                    th {
                        border-bottom: 0;
                        padding-bottom: 0.2rem;
                    }
                    td {
                        padding-top: 0.2rem;
                    }
                }

                @media (max-width: 640px) {
                    .title {
                        font-size: 1.15rem;
                    }
                    .meta {
                        gap: 0.45rem;
                    }
                    .summary-grid {
                        grid-template-columns: 1fr;
                    }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="h-flex">
                    <div>
                        <span class="badge"><?= htmlspecialchars($exceptionClass) ?></span>
                        <h1 class="title"><?= htmlspecialchars($message) ?></h1>
                        <div class="meta">
                            <span><b><?= htmlspecialchars($method) ?></b> <?= htmlspecialchars($uri) ?></span>
                            <span class="divider">/</span>
                            <span>PHP <?= htmlspecialchars($phpVersion) ?></span>
                            <span class="divider">/</span>
                            <span><?= $time ?></span>
                            <span class="divider">/</span>
                            <span><?= $memory ?></span>
                            <span class="divider">/</span>
                            <span><?= $files ?> files</span>
                        </div>
                        <div class="summary-grid">
                            <div class="summary-card">
                                <span class="summary-label">Status</span>
                                <span class="summary-value"><?= htmlspecialchars((string) $responseInfo['status']) ?></span>
                            </div>
                            <div class="summary-card">
                                <span class="summary-label">Source</span>
                                <span class="summary-value"><?= htmlspecialchars($responseInfo['source']) ?></span>
                            </div>
                            <div class="summary-card">
                                <span class="summary-label">Line</span>
                                <span class="summary-value"><?= htmlspecialchars((string) $responseInfo['line']) ?></span>
                            </div>
                            <div class="summary-card">
                                <span class="summary-label">Trace Depth</span>
                                <span class="summary-value"><?= htmlspecialchars((string) $responseInfo['trace_depth']) ?></span>
                            </div>
                        </div>
                    </div>
                    <button class="theme-btn" onclick="toggleTheme()" id="theme-btn" title="Toggle Theme"></button>
                </div>
            </div>

            <div class="main">
                <div class="sidebar">
                    <div class="stack-title">Stack Trace</div>
                    <div class="sidebar-track">
                        <?php foreach($frames as $index => $frame): ?>
                            <div class="frame <?= $index === 0 ? 'active' : '' ?>" id="btn-frame-<?= $index ?>" onclick="selectFrame(<?= $index ?>)">
                                <div class="f-func">
                                    <?php if(isset($frame['class']) && $frame['class'] !== ''): ?>
                                        <span><?= htmlspecialchars($frame['class'].$frame['type']) ?></span><?= htmlspecialchars($frame['function']) ?>()
                                    <?php else: ?>
                                        <?= htmlspecialchars($frame['function']) ?>()
                                    <?php endif; ?>
                                </div>
                                <div class="f-loc">
                                    <?= htmlspecialchars(basename($frame['file'] ?? 'Unknown')) ?>:<?= $frame['line'] ?? '?' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="content">
                    <?php foreach($frames as $index => $frame): ?>
                        <div class="code-block <?= $index === 0 ? 'active' : '' ?>" id="frame-<?= $index ?>">
                            <?php if (isset($frame['file']) && is_file($frame['file'])): ?>
                                <?= self::getCodeHtml($frame['file'], $frame['line']) ?>
                            <?php else: ?>
                                <div class="code-empty">Source is unavailable for this stack frame.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="tab-bar" role="tablist" aria-label="Debug details tabs">
                        <button class="tab-btn active" type="button" id="tab-request-btn" onclick="switchTab('request')">Request</button>
                        <button class="tab-btn" type="button" id="tab-response-btn" onclick="switchTab('response')">Response</button>
                        <button class="tab-btn" type="button" id="tab-environment-btn" onclick="switchTab('environment')">Environment</button>
                        <button class="tab-btn" type="button" id="tab-stack-btn" onclick="switchTab('stack')">Stack</button>
                    </div>

                    <div class="panel active" id="panel-request">
                        <div class="section">
                            <h2 class="s-title">Request</h2>
                            <div class="subsection">
                                <div class="subsection-title">Headers</div>
                                <?= self::renderKeyValueTable($globals['headers']) ?>
                            </div>
                            <div class="subsection">
                                <div class="subsection-title">Query</div>
                                <?= self::renderKeyValueTable($globals['get']) ?>
                            </div>
                            <div class="subsection">
                                <div class="subsection-title">Body</div>
                                <?= self::renderKeyValueTable($globals['post']) ?>
                            </div>
                            <div class="subsection">
                                <div class="subsection-title">Cookies</div>
                                <?= self::renderKeyValueTable($globals['cookies']) ?>
                            </div>
                            <div class="subsection">
                                <div class="subsection-title">Session</div>
                                <?= self::renderKeyValueTable($globals['session']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="panel" id="panel-response">
                        <div class="section">
                            <h2 class="s-title">Response</h2>
                            <?= self::renderKeyValueTable($responseInfo) ?>
                        </div>
                    </div>

                    <div class="panel" id="panel-environment">
                        <div class="section">
                            <h2 class="s-title">Environment</h2>
                            <div class="subsection">
                                <div class="subsection-title">Application</div>
                                <?= self::renderKeyValueTable($globals['env']) ?>
                            </div>
                            <div class="subsection">
                                <div class="subsection-title">Server</div>
                                <?= self::renderKeyValueTable($globals['server']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="panel" id="panel-stack">
                        <div class="section">
                            <h2 class="s-title">Stack Details</h2>
                            <div class="stack-list">
                                <?php foreach($frames as $index => $frame): ?>
                                    <div class="stack-card">
                                        <div class="stack-card-title">
                                            #<?= $index ?>
                                            <?php if(isset($frame['class']) && $frame['class'] !== ''): ?>
                                                <?= htmlspecialchars($frame['class'] . $frame['type'] . $frame['function']) ?>()
                                            <?php else: ?>
                                                <?= htmlspecialchars($frame['function'] ?? 'unknown') ?>()
                                            <?php endif; ?>
                                        </div>
                                        <div class="stack-card-meta">
                                            <?= htmlspecialchars($frame['file'] ?? 'Internal / unavailable source') ?><?php if (isset($frame['line'])): ?>:<?= (int) $frame['line'] ?><?php endif; ?>
                                        </div>
                                        <div class="stack-card-actions">
                                            <span class="mini-chip">Frame <?= $index + 1 ?> of <?= count($frames) ?></span>
                                            <?php if (!empty($frame['type'])): ?><span class="mini-chip"><?= htmlspecialchars($frame['type']) ?></span><?php endif; ?>
                                            <?php if (!empty($frame['class'])): ?><span class="mini-chip"><?= htmlspecialchars($frame['class']) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                function updateThemeIcon(t) {
                    const btn = document.getElementById('theme-btn');
                    if (t === 'dark') {
                        btn.innerHTML = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>';
                    } else {
                        btn.innerHTML = '<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>';
                    }
                }

                updateThemeIcon(document.documentElement.getAttribute('data-theme'));

                function toggleTheme() {
                    const html = document.documentElement;
                    const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    html.setAttribute('data-theme', next);
                    localStorage.setItem('theme', next);
                    updateThemeIcon(next);
                }

                function selectFrame(idx) {
                    document.querySelectorAll('.frame').forEach(el => el.classList.remove('active'));
                    document.querySelectorAll('.code-block').forEach(el => el.classList.remove('active'));
                    
                    const btn = document.getElementById('btn-frame-' + idx);
                    const blk = document.getElementById('frame-' + idx);
                    
                    if (btn) btn.classList.add('active');
                    if (blk) blk.classList.add('active');
                }

                function switchTab(name) {
                    document.querySelectorAll('.tab-btn').forEach((el) => el.classList.remove('active'));
                    document.querySelectorAll('.panel').forEach((el) => el.classList.remove('active'));

                    const button = document.getElementById('tab-' + name + '-btn');
                    const panel = document.getElementById('panel-' + name);

                    if (button) button.classList.add('active');
                    if (panel) panel.classList.add('active');
                }
            </script>
        </body>
        </html>
        <?php
        $output = ob_get_clean();
        echo $output;
    }

    private static function renderProduction(Throwable $e, int $statusCode): void
    {
        $m = [400=>"Bad Request", 401=>"Unauthorized", 403=>"Forbidden", 404=>"Not Found", 500=>"Server Error"];
        $title = $m[$statusCode] ?? "Something went wrong";
        $descriptions = [
            400 => 'The request could not be processed. Please review the submitted data and try again.',
            401 => 'Authentication is required before this page can be accessed.',
            403 => 'You do not have permission to access this resource.',
            404 => 'The page you are looking for could not be found or may have been moved.',
            500 => 'An unexpected error occurred while processing your request.',
        ];
        $description = $descriptions[$statusCode] ?? 'An unexpected error occurred while processing your request.';
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= $statusCode ?> | <?= $title ?></title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
            <style>
                :root {
                    --bg: #f5f7fb;
                    --surface: rgba(255, 255, 255, 0.84);
                    --surface-border: rgba(148, 163, 184, 0.16);
                    --text: #0f172a;
                    --muted: #66758f;
                    --accent: #0f766e;
                    --accent-soft: rgba(15, 118, 110, 0.08);
                    --shadow: 0 30px 80px rgba(15, 23, 42, 0.10);
                    --status-fade: #dce5f0;
                }
                * { box-sizing: border-box; }
                body {
                    margin: 0;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 2rem;
                    overflow: hidden;
                    font-family: 'Inter', sans-serif;
                    color: var(--text);
                    background:
                        radial-gradient(circle at 15% 15%, rgba(59, 130, 246, 0.08), transparent 24%),
                        radial-gradient(circle at 85% 82%, rgba(16, 185, 129, 0.08), transparent 22%),
                        linear-gradient(180deg, #fbfcfe 0%, var(--bg) 100%);
                }
                .orb {
                    position: fixed;
                    border-radius: 999px;
                    filter: blur(32px);
                    pointer-events: none;
                    opacity: 0.7;
                }
                .orb-a {
                    width: 280px;
                    height: 280px;
                    top: -70px;
                    right: 10%;
                    background: rgba(59, 130, 246, 0.10);
                }
                .orb-b {
                    width: 320px;
                    height: 320px;
                    bottom: -120px;
                    left: 5%;
                    background: rgba(16, 185, 129, 0.08);
                }
                .shell {
                    position: relative;
                    width: 100%;
                    max-width: 920px;
                }
                .card {
                    position: relative;
                    overflow: hidden;
                    border: 1px solid var(--surface-border);
                    border-radius: 30px;
                    padding: 2.2rem;
                    background: var(--surface);
                    backdrop-filter: blur(14px);
                    box-shadow: var(--shadow);
                    display: grid;
                    grid-template-columns: minmax(0, 1.2fr) minmax(220px, 0.8fr);
                    gap: 2rem;
                    min-height: 420px;
                }
                .card::before {
                    content: '';
                    position: absolute;
                    inset: 0;
                    background: linear-gradient(135deg, rgba(255,255,255,0.34), transparent 44%);
                    pointer-events: none;
                }
                .card::after {
                    content: '';
                    position: absolute;
                    inset: 0;
                    background: linear-gradient(90deg, rgba(15, 23, 42, 0.02), transparent 32%);
                    pointer-events: none;
                }
                .main-copy,
                .status-panel {
                    position: relative;
                    z-index: 1;
                }
                .eyebrow {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.45rem;
                    padding: 0.42rem 0.78rem;
                    border-radius: 999px;
                    background: var(--accent-soft);
                    color: var(--accent);
                    font-size: 0.74rem;
                    font-weight: 600;
                    letter-spacing: 0.12em;
                    text-transform: uppercase;
                }
                .title-wrap {
                    margin-top: 1.35rem;
                }
                .title {
                    margin: 0;
                    font-size: clamp(2rem, 5vw, 3.2rem);
                    line-height: 0.98;
                    font-weight: 600;
                    letter-spacing: -0.06em;
                    max-width: 10ch;
                }
                .description {
                    margin: 1rem 0 0;
                    max-width: 32rem;
                    color: var(--muted);
                    font-size: 1rem;
                    line-height: 1.8;
                }
                .meta {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.75rem;
                    margin-top: 1.6rem;
                }
                .meta-chip {
                    padding: 0.68rem 0.92rem;
                    border-radius: 14px;
                    border: 1px solid rgba(148, 163, 184, 0.12);
                    background: rgba(255, 255, 255, 0.56);
                    color: var(--muted);
                    font-size: 0.88rem;
                }
                .meta-chip strong {
                    color: var(--text);
                    font-weight: 600;
                }
                .footnote {
                    margin-top: 2rem;
                    padding-top: 1rem;
                    border-top: 1px solid rgba(148, 163, 184, 0.14);
                    color: var(--muted);
                    font-size: 0.9rem;
                    line-height: 1.65;
                    max-width: 34rem;
                }
                .status-panel {
                    display: flex;
                    align-items: flex-end;
                    justify-content: flex-end;
                    min-height: 100%;
                }
                .status-card {
                    width: 100%;
                    max-width: 280px;
                    min-height: 100%;
                    border-left: 1px solid rgba(148, 163, 184, 0.12);
                    padding-left: 2rem;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    align-items: flex-end;
                    text-align: right;
                }
                .status-kicker {
                    font-size: 0.78rem;
                    letter-spacing: 0.14em;
                    text-transform: uppercase;
                    color: var(--muted);
                }
                .status {
                    margin: 0;
                    font-size: clamp(5.5rem, 14vw, 8rem);
                    line-height: 0.86;
                    font-weight: 700;
                    letter-spacing: -0.08em;
                    color: var(--status-fade);
                }
                .status-label {
                    margin-top: 0.8rem;
                    font-size: 1rem;
                    color: var(--text);
                    font-weight: 600;
                }
                .status-subtle {
                    margin-top: 0.35rem;
                    font-size: 0.88rem;
                    color: var(--muted);
                    line-height: 1.6;
                    max-width: 15rem;
                }
                .micro-grid {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: 0.75rem;
                    width: 100%;
                }
                .micro-card {
                    border-radius: 16px;
                    background: rgba(255, 255, 255, 0.44);
                    border: 1px solid rgba(148, 163, 184, 0.10);
                    padding: 0.9rem;
                }
                .micro-label {
                    font-size: 0.72rem;
                    letter-spacing: 0.12em;
                    text-transform: uppercase;
                    color: var(--muted);
                    margin-bottom: 0.35rem;
                }
                .micro-value {
                    font-size: 1rem;
                    font-weight: 600;
                    color: var(--text);
                }
                @media (max-width: 860px) {
                    .card {
                        grid-template-columns: 1fr;
                        min-height: 0;
                        gap: 1.4rem;
                    }
                    .status-panel {
                        justify-content: flex-start;
                    }
                    .status-card {
                        max-width: none;
                        border-left: 0;
                        border-top: 1px solid rgba(148, 163, 184, 0.12);
                        padding-left: 0;
                        padding-top: 1.4rem;
                        align-items: flex-start;
                        text-align: left;
                    }
                    .micro-grid {
                        max-width: 360px;
                    }
                }
                @media (max-width: 640px) {
                    body {
                        padding: 1rem;
                    }
                    .card {
                        padding: 1.35rem;
                        border-radius: 24px;
                    }
                    .title {
                        max-width: none;
                    }
                    .meta {
                        flex-direction: column;
                    }
                    .meta-chip {
                        width: 100%;
                    }
                    .micro-grid {
                        grid-template-columns: 1fr;
                    }
                }
            </style>
        </head>
        <body>
            <div class="orb orb-a"></div>
            <div class="orb orb-b"></div>
            <div class="shell">
                <section class="card" aria-labelledby="error-title">
                    <div class="main-copy">
                        <div class="eyebrow">Application Error</div>
                        <div class="title-wrap">
                            <h1 class="title" id="error-title"><?= htmlspecialchars($title) ?></h1>
                        </div>
                        <p class="description"><?= htmlspecialchars($description) ?></p>

                        <div class="meta">
                            <div class="meta-chip"><strong>Status</strong> <?= $statusCode ?></div>
                            <div class="meta-chip"><strong>Type</strong> <?= htmlspecialchars($title) ?></div>
                        </div>

                        <p class="footnote">This page hides internal exception details in production. If the problem persists, contact the administrator and include the status code above.</p>
                    </div>

                    <div class="status-panel" aria-hidden="true">
                        <div class="status-card">
                            <div>
                                <div class="status-kicker">Response</div>
                                <div class="status"><?= $statusCode ?></div>
                                <div class="status-label"><?= htmlspecialchars($title) ?></div>
                                <div class="status-subtle">A clean production-safe fallback designed to remain readable without exposing application internals.</div>
                            </div>
                            <div class="micro-grid">
                                <div class="micro-card">
                                    <div class="micro-label">Code</div>
                                    <div class="micro-value"><?= $statusCode ?></div>
                                </div>
                                <div class="micro-card">
                                    <div class="micro-label">Mode</div>
                                    <div class="micro-value">Production</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </body>
        </html>
        <?php
    }

    private static function getFrames(Throwable $e): array
    {
        $frames = [['file'=>$e->getFile(), 'line'=>$e->getLine(), 'function'=>'throw exception', 'class'=>'', 'type'=>'']];
        return array_merge($frames, $e->getTrace());
    }

    private static function getCodeHtml(?string $file, ?int $line): string
    {
        if (!$file || !$line || !is_file($file)) return "";
        
        $src = file_get_contents($file);
        $tokens = token_get_all($src);
        
        $htmlLines = [''];
        $lineIndex = 0;
        
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;
            
            $style = '';
            if ($id === T_VARIABLE) $style = 'color:var(--c-var);';
            elseif (in_array($id, [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_CLASS, T_FUNCTION, T_RETURN, T_IF, T_ELSE, T_ELSEIF, T_TRY, T_CATCH, T_THROW, T_NAMESPACE, T_USE, T_NEW, T_STATIC, T_VAR, T_WHILE, T_FOR, T_FOREACH, T_AS, T_ECHO, T_SWITCH, T_CASE, T_DEFAULT, T_BREAK, T_CONTINUE])) $style = 'color:var(--c-key);';
            elseif ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) $style = 'color:var(--c-str);';
            elseif (in_array($id, [T_COMMENT, T_DOC_COMMENT])) $style = 'color:var(--c-com); font-style:italic;';
            elseif (in_array($id, [T_LNUMBER, T_DNUMBER])) $style = 'color:var(--c-num);';
            elseif ($id === T_STRING) $style = 'color:var(--c-tag);';
            
            $text = htmlspecialchars((string)$text);
            $parts = explode("\n", $text);
            
            foreach ($parts as $i => $lineText) {
                if ($i > 0) {
                    $lineIndex++;
                    $htmlLines[$lineIndex] = '';
                }
                if ($lineText !== '') {
                    $htmlLines[$lineIndex] .= $style ? "<span style='{$style}'>{$lineText}</span>" : $lineText;
                }
            }
        }
        
        $start = max(0, $line - 12);
        $end = min(count($htmlLines), $line + 12);
        $html = '';
        for ($i = $start; $i < $end; $i++) {
            $l = $i + 1;
            $isErr = ($l === $line) ? 'err' : '';
            $text = $htmlLines[$i] ?? '';
            if (trim(strip_tags($text)) === '') $text = '&#8203;';
            $html .= "<div class='c-row {$isErr}'><div class='c-num'>{$l}</div><div class='c-line'>{$text}</div></div>";
        }
        
        return $html;
    }

    private static function getGlobalsHtml(): array
    {
        $headers = [];
        $sensitiveFilter = ['PASSWORD', 'TOKEN', 'KEY', 'SECRET', 'PASS', 'DATABASE_URL', 'AUTH', 'API', 'SALT', 'DB_', 'STRIPE', 'PAYPAL'];

        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $hdr = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$hdr] = $v;
            }
        }
        
        $filterData = function($source) use ($sensitiveFilter) {
            $filtered = [];
            foreach ($source as $k => $v) {
                $isSensitive = false;
                foreach ($sensitiveFilter as $term) {
                    if (stripos((string)$k, $term) !== false) $isSensitive = true;
                }
                $filtered[$k] = $isSensitive ? '_PROTECTED_VALUE_' : $v;
            }
            return $filtered;
        };

        return [
            'headers' => $filterData($headers),
            'env' => $filterData($_ENV),
            'server' => $filterData($_SERVER),
            'post' => $filterData($_POST),
            'get' => $filterData($_GET),
            'cookies' => $filterData($_COOKIE ?? []),
            'session' => $filterData($_SESSION ?? [])
        ];
    }

    private static function renderKeyValueTable(array $data): string
    {
        if ($data === []) {
            return '<div class="table-wrap"><table><tr><td colspan="2"><span class="v-protected">No data available.</span></td></tr></table></div>';
        }

        $html = '<div class="table-wrap"><table>';
        foreach ($data as $key => $value) {
            $html .= '<tr>';
            $html .= '<th>' . htmlspecialchars((string) $key) . '</th>';
            $html .= '<td>' . self::renderValue($value) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table></div>';

        return $html;
    }

    private static function renderValue(mixed $value): string
    {
        if ($value === '_PROTECTED_VALUE_') {
            return '<span class="v-protected">** protected **</span>';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '<span class="v-protected">null</span>';
        }

        if (is_scalar($value)) {
            return htmlspecialchars((string) $value);
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '[unserializable value]';
        }

        return nl2br(htmlspecialchars($json));
    }

    private static function getResponseDetails(Throwable $e, int $statusCode, string $time, string $memory, int $files, array $frames): array
    {
        return [
            'status' => $statusCode,
            'exception' => get_class($e),
            'message' => $e->getMessage() ?: 'Unknown Error',
            'source' => $e->getFile(),
            'line' => $e->getLine(),
            'trace_depth' => count($frames),
            'execution_time' => $time,
            'memory_usage' => $memory,
            'loaded_files' => $files,
            'previous_exception' => $e->getPrevious() ? get_class($e->getPrevious()) : 'none',
        ];
    }

    private static function normalizeStatusCode(int $statusCode): int
    {
        if ($statusCode < 100 || $statusCode >= 600) {
            return 500;
        }

        return $statusCode;
    }
}