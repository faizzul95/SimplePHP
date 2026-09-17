<?php

declare(strict_types=1);

namespace Core\Telemetry;

/**
 * Renders the floating debug bar and injects it into an HTML response.
 *
 * Self-contained markup with no build step and no CDN: the bar has to work on
 * a page whose CSP forbids external scripts, and on a page that is broken,
 * which is when you need it most. Everything is scoped under one id and one
 * class prefix so it cannot inherit or leak page styles.
 *
 * Layout is a summary strip, a tab row, and a split list/detail pane. The
 * strip is the part you read without opening anything — total time, query
 * count and cost, memory, cache hit rate, error count — because most of the
 * time that is the whole question.
 */
final class Bar
{
    private const MOUNT_ID = 'myth-telemetry-bar';

    /** @param array<string, mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    /**
     * Put the bar before </body>.
     *
     * Returns the html untouched when it is not an HTML document, when the bar
     * is already there, or when there is no </body> to insert before — a
     * partial rendered for an AJAX swap must not get a debug bar stitched into
     * the middle of it.
     */
    public function inject(string $html, string $requestId): string
    {
        if ($html === '' || str_contains($html, self::MOUNT_ID)) {
            return $html;
        }

        $position = strripos($html, '</body>');
        if ($position === false) {
            return $html;
        }

        return substr($html, 0, $position) . $this->render($requestId) . substr($html, $position);
    }

    public function render(string $requestId): string
    {
        $endpoint = $this->escape($this->endpoint());
        $requestId = $this->escape($requestId);

        return <<<HTML
<div id="myth-telemetry-bar" data-endpoint="{$endpoint}" data-request-id="{$requestId}" hidden></div>
<style>{$this->css()}</style>
<script>{$this->js()}</script>
HTML;
    }

    private function endpoint(): string
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '/_telemetry/entries');

        return '/' . ltrim($endpoint, '/');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function css(): string
    {
        return <<<'CSS'
#myth-telemetry-bar{--mt-bg:#14161a;--mt-bg2:#1a1d23;--mt-line:#282c34;--mt-fg:#dfe3e8;--mt-dim:#8b95a3;--mt-accent:#4d9de0;--mt-warn:#e8a33d;--mt-err:#e5534b;--mt-ok:#57ab5a;
position:fixed;left:0;right:0;bottom:0;z-index:2147483000;height:340px;
font:12px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--mt-fg);background:var(--mt-bg);
border-top:1px solid var(--mt-line);box-shadow:0 -4px 24px rgba(0,0,0,.45);display:flex;flex-direction:column;overflow:hidden}
#myth-telemetry-bar[hidden]{display:none}
#myth-telemetry-bar *{box-sizing:border-box}

/* drag-to-resize */
#myth-telemetry-bar .mt-grip{position:absolute;top:0;left:0;right:0;height:5px;cursor:ns-resize;z-index:3}
#myth-telemetry-bar .mt-grip:hover{background:var(--mt-accent)}

/* summary strip */
#myth-telemetry-bar .mt-sum{display:flex;align-items:center;gap:0;flex:0 0 auto;border-bottom:1px solid var(--mt-line);background:var(--mt-bg2);padding-left:4px}
#myth-telemetry-bar .mt-stat{display:flex;align-items:baseline;gap:5px;padding:7px 11px;white-space:nowrap;border-right:1px solid var(--mt-line)}
#myth-telemetry-bar .mt-stat b{font-weight:700;font-size:13px}
#myth-telemetry-bar .mt-stat s{text-decoration:none;color:var(--mt-dim);font-size:10px;text-transform:uppercase;letter-spacing:.06em}
#myth-telemetry-bar .mt-stat.is-warn b{color:var(--mt-warn)}
#myth-telemetry-bar .mt-stat.is-err b{color:var(--mt-err)}
#myth-telemetry-bar .mt-stat.is-ok b{color:var(--mt-ok)}
#myth-telemetry-bar .mt-sum .mt-spacer{flex:1 1 auto;border:0}

/* tabs */
#myth-telemetry-bar .mt-tabs{display:flex;align-items:center;gap:2px;padding:5px 7px;flex-wrap:wrap;border-bottom:1px solid var(--mt-line);flex:0 0 auto}
#myth-telemetry-bar .mt-tab{background:transparent;border:1px solid transparent;color:var(--mt-dim);padding:3px 9px;border-radius:5px;cursor:pointer;font:inherit;white-space:nowrap;display:inline-flex;align-items:center;gap:5px}
#myth-telemetry-bar .mt-tab:hover{background:#22262e;color:var(--mt-fg)}
#myth-telemetry-bar .mt-tab.is-active{background:var(--mt-accent);border-color:var(--mt-accent);color:#08121c;font-weight:700}
#myth-telemetry-bar .mt-count{min-width:16px;padding:0 5px;border-radius:9px;background:#2b3039;color:#b8c2cf;font-size:10px;text-align:center;font-weight:700}
#myth-telemetry-bar .mt-tab.is-active .mt-count{background:rgba(0,0,0,.28);color:#08121c}
#myth-telemetry-bar .mt-count.is-warn{background:var(--mt-warn);color:#231802}
#myth-telemetry-bar .mt-count.is-err{background:var(--mt-err);color:#fff}

#myth-telemetry-bar .mt-search{background:#0e1014;border:1px solid var(--mt-line);color:var(--mt-fg);border-radius:5px;padding:3px 9px;font:inherit;min-width:130px}
#myth-telemetry-bar .mt-search:focus{outline:none;border-color:var(--mt-accent)}
#myth-telemetry-bar .mt-btn{background:#20242b;border:1px solid var(--mt-line);color:var(--mt-dim);border-radius:5px;padding:3px 9px;cursor:pointer;font:inherit}
#myth-telemetry-bar .mt-btn:hover{color:var(--mt-fg);border-color:#3d4450}
#myth-telemetry-bar .mt-btn.is-on{background:var(--mt-accent);border-color:var(--mt-accent);color:#08121c;font-weight:700}

/* split panes */
#myth-telemetry-bar .mt-panes{display:flex;flex:1 1 auto;min-height:0}
#myth-telemetry-bar .mt-list{flex:1 1 58%;overflow:auto;min-width:0;border-right:1px solid var(--mt-line)}
#myth-telemetry-bar .mt-detail{flex:1 1 42%;overflow:auto;min-width:0;padding:11px 13px;background:#0f1115}
#myth-telemetry-bar .mt-detail h4{margin:0 0 3px;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--mt-dim);font-weight:700}
#myth-telemetry-bar .mt-detail pre{margin:0 0 13px;white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:12px;color:#c8d2de}
#myth-telemetry-bar .mt-detail .mt-hint{color:var(--mt-dim)}

#myth-telemetry-bar table{width:100%;border-collapse:collapse;table-layout:fixed}
#myth-telemetry-bar td{padding:5px 9px;border-bottom:1px solid #1e2127;vertical-align:top;word-break:break-word}
#myth-telemetry-bar tr{cursor:pointer}
#myth-telemetry-bar tr:hover td{background:#1b1f26}
#myth-telemetry-bar tr.is-sel td{background:#1d2a38}
#myth-telemetry-bar tr.is-sel td:first-child{box-shadow:inset 3px 0 0 var(--mt-accent)}
#myth-telemetry-bar .mt-type{width:66px;color:var(--mt-dim);text-transform:uppercase;font-size:9.5px;letter-spacing:.05em;white-space:nowrap;padding-top:7px}
#myth-telemetry-bar .mt-ms{width:66px;text-align:right;color:var(--mt-dim);white-space:nowrap}
#myth-telemetry-bar .mt-ms.is-slow{color:var(--mt-warn);font-weight:700}
#myth-telemetry-bar .mt-main{white-space:pre-wrap;font-family:inherit}
#myth-telemetry-bar .mt-sub{color:#6b7684;font-size:11px;margin-top:2px}
#myth-telemetry-bar tr.is-err .mt-main{color:#f08c85}
#myth-telemetry-bar .mt-empty{padding:26px 16px;color:var(--mt-dim);text-align:center;line-height:1.7}

/* group headers */
#myth-telemetry-bar tr.mt-group td{background:#191d24;color:var(--mt-dim);font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;border-top:1px solid var(--mt-line);cursor:default;padding:5px 9px}
#myth-telemetry-bar tr.mt-group:hover td{background:#191d24}

/* the launcher */
#myth-telemetry-toggle{position:fixed;right:14px;bottom:14px;z-index:2147483000;background:#14161a;color:#dfe3e8;border:1px solid #333944;border-radius:8px;padding:7px 13px;cursor:pointer;
font:12px/1 ui-monospace,Menlo,Consolas,monospace;box-shadow:0 3px 14px rgba(0,0,0,.5);display:flex;align-items:center;gap:8px}
#myth-telemetry-toggle:hover{border-color:#4d9de0}
#myth-telemetry-toggle[hidden]{display:none}
#myth-telemetry-toggle .mt-dot{width:7px;height:7px;border-radius:50%;background:#57ab5a}
#myth-telemetry-toggle .mt-dot.is-err{background:#e5534b}
#myth-telemetry-toggle .mt-dot.is-warn{background:#e8a33d}
CSS;
    }

    private function js(): string
    {
        return <<<'JS'
(function () {
    var mount = document.getElementById('myth-telemetry-bar');
    if (!mount || mount.dataset.booted) { return; }
    mount.dataset.booted = '1';

    var endpoint = mount.dataset.endpoint;
    var STORAGE = 'myth-telemetry-ui';

    var entries = [];
    var seen = Object.create(null);
    var after = 0;
    var state = { open: false, tab: 'all', height: 340, grouped: true, selected: null };
    var search = '';
    var timer = null;

    /* Everything from the server is data. It is rendered as text, never as
       markup: these payloads are request bodies and SQL, which is exactly the
       content an attacker controls. */
    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
    }

    function readState() {
        try {
            var saved = JSON.parse(localStorage.getItem(STORAGE) || '{}');
            if (typeof saved.open === 'boolean') { state.open = saved.open; }
            if (typeof saved.tab === 'string') { state.tab = saved.tab; }
            if (typeof saved.grouped === 'boolean') { state.grouped = saved.grouped; }
            if (typeof saved.height === 'number') { state.height = Math.max(140, Math.min(saved.height, 900)); }
        } catch (e) { /* private mode, or blocked site data */ }
    }

    function writeState() {
        try {
            localStorage.setItem(STORAGE, JSON.stringify({
                open: state.open, tab: state.tab, grouped: state.grouped, height: state.height
            }));
        } catch (e) {}
    }

    // ── launcher ────────────────────────────────────────────────────
    var toggle = document.createElement('button');
    toggle.id = 'myth-telemetry-toggle';
    toggle.type = 'button';
    toggle.innerHTML = '<span class="mt-dot"></span><span class="mt-label">debug</span>';
    toggle.addEventListener('click', function () { setOpen(!state.open); });
    document.body.appendChild(toggle);

    function setOpen(next) {
        state.open = next;
        mount.hidden = !next;
        toggle.hidden = next;
        mount.style.height = state.height + 'px';
        writeState();
        if (next) { poll(); render(); }
    }

    /* Ctrl+Shift+D, and Escape to close. Chosen to avoid the browser's own
       shortcuts and anything a page form is likely to want. */
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.shiftKey && (e.key === 'D' || e.key === 'd')) {
            e.preventDefault();
            setOpen(!state.open);
        } else if (e.key === 'Escape' && state.open) {
            setOpen(false);
        }
    });

    // ── labels ──────────────────────────────────────────────────────
    function label(entry) {
        var p = entry.payload || {};
        switch (entry.type) {
            case 'query':   return p.sql || '';
            case 'request': return (p.method || '') + ' ' + (p.path || '') + '  → ' + (p.status || '');
            case 'mail':    return (p.sent ? 'sent' : 'FAILED') + '  ' + (p.subject || '') + '  → ' + ((p.to || []).join(', '));
            case 'queue':   return (p.job || p.class || 'job') + '  ' + (p.status || '');
            case 'exception': return (p.class || 'Exception') + ': ' + (p.message || '');
            case 'log':     return '[' + (p.level || 'log') + '] ' + (p.message || '');
            case 'http':    return (p.method || '') + ' ' + (p.url || '') + '  → ' + (p.status || (p.error ? 'failed' : ''));
            case 'cache':   return (p.operation || '') + '  ' + (p.key || '');
            case 'dump':    return (p.label ? p.label + ': ' : '') + preview(p.value);
            case 'event':   return (p.event || '') + '  ' + shortClass(p.model) + (p.key ? ' #' + p.key : '');
            case 'timer':   return p.kind === 'counter'
                                ? p.name + ' × ' + p.count
                                : p.name + (p.kind === 'unclosed' ? '  (never stopped)' : '');
            default:        return p.message || p.name || entry.type;
        }
    }

    function shortClass(name) {
        var parts = String(name || '').split('\\');
        return parts[parts.length - 1] || '';
    }

    function preview(value) {
        if (value === null || value === undefined) { return String(value); }
        if (typeof value !== 'object') { return String(value); }
        var json = JSON.stringify(value);
        if (json === undefined) { return '[unserialisable]'; }
        return json.length > 140 ? json.slice(0, 140) + '…' : json;
    }

    function subtitle(entry) {
        var p = entry.payload || {};
        if (entry.type === 'query') { return p.origin || p.connection || ''; }
        if (entry.type === 'exception') { return (p.file || '') + ':' + (p.line || ''); }
        if (entry.type === 'dump') { return (p.type || '') + (p.origin ? '  ' + p.origin : ''); }
        if (entry.type === 'timer') { return p.origin || p.note || ''; }
        if (entry.type === 'event') { return (p.columns || []).slice(0, 6).join(', '); }
        if (entry.type === 'cache') { return 'store: ' + (p.store || '') + (p.ttl ? '  ttl ' + p.ttl + 's' : ''); }
        if (entry.type === 'http') { return (p.ip || '') + (p.bytes ? '  ' + p.bytes + ' bytes' : '') + (p.error ? '  ' + p.error : ''); }
        if (entry.type === 'request') {
            var bits = [];
            if (p.ip) { bits.push(p.ip); }
            if (p.ajax) { bits.push('ajax'); }
            bits.push((p.memory_mb || 0) + 'MB');
            return bits.join('  ');
        }
        return '';
    }

    function isBad(entry) {
        return entry.type === 'exception' || (entry.tags || []).indexOf('server-error') !== -1;
    }

    // ── query shapes, for the duplicates view ───────────────────────
    function fingerprint(sql) {
        return String(sql || '')
            .replace(/\s+/g, ' ')
            .replace(/'[^']*'/g, '?')
            .replace(/"[^"]*"/g, '?')
            .replace(/\b\d+\b/g, '?')
            .replace(/\(\s*\?(?:\s*,\s*\?)+\s*\)/g, '(?)')
            .trim();
    }

    function groupedQueries() {
        var groups = Object.create(null);
        entries.forEach(function (entry) {
            if (entry.type !== 'query') { return; }
            var key = fingerprint((entry.payload || {}).sql);
            if (!groups[key]) { groups[key] = { sql: key, count: 0, total: 0, origins: Object.create(null) }; }
            groups[key].count++;
            groups[key].total += entry.duration_ms || 0;
            var o = (entry.payload || {}).origin;
            if (o) { groups[key].origins[o] = true; }
        });
        return Object.keys(groups).map(function (k) { return groups[k]; })
            .sort(function (a, b) { return b.count - a.count || b.total - a.total; });
    }

    // ── the summary strip ───────────────────────────────────────────
    function summary() {
        var latest = null;
        for (var i = 0; i < entries.length; i++) {
            if (entries[i].type === 'request' && !(entries[i].payload || {}).ajax) { latest = entries[i]; break; }
        }

        var queries = entries.filter(function (e) { return e.type === 'query'; });
        var queryMs = queries.reduce(function (t, e) { return t + (e.duration_ms || 0); }, 0);
        var errors = entries.filter(isBad).length;

        var reads = entries.filter(function (e) {
            var op = (e.payload || {}).operation;
            return e.type === 'cache' && (op === 'hit' || op === 'miss');
        });
        var hits = reads.filter(function (e) { return e.payload.operation === 'hit'; }).length;
        var rate = reads.length ? Math.round((hits / reads.length) * 100) : null;

        var worst = 0;
        groupedQueries().forEach(function (g) { if (g.count > worst) { worst = g.count; } });

        var lp = (latest && latest.payload) || {};
        var stats = [];

        if (latest) {
            stats.push(stat('time', Math.round(latest.duration_ms || 0) + 'ms',
                (latest.duration_ms || 0) > 1000 ? 'is-warn' : ''));
            stats.push(stat('memory', (lp.memory_mb || 0) + 'MB', (lp.memory_mb || 0) > 64 ? 'is-warn' : ''));
            stats.push(stat('status', lp.status || '—',
                lp.status >= 500 ? 'is-err' : lp.status >= 400 ? 'is-warn' : 'is-ok'));
        }

        stats.push(stat('queries', queries.length + ' · ' + Math.round(queryMs) + 'ms',
            worst >= 10 ? 'is-err' : worst > 1 ? 'is-warn' : ''));

        if (rate !== null) { stats.push(stat('cache', rate + '%', rate < 50 ? 'is-warn' : 'is-ok')); }
        if (errors) { stats.push(stat('errors', errors, 'is-err')); }
        if (worst > 1) { stats.push(stat('dup query', '×' + worst, worst >= 10 ? 'is-err' : 'is-warn')); }

        // The launcher dot mirrors the worst thing in here.
        var dot = toggle.querySelector('.mt-dot');
        dot.className = 'mt-dot' + (errors ? ' is-err' : worst >= 10 ? ' is-warn' : '');
        toggle.querySelector('.mt-label').textContent = errors
            ? 'debug · ' + errors + ' error' + (errors > 1 ? 's' : '')
            : 'debug';

        return '<div class="mt-sum">' + stats.join('') +
            '<span class="mt-spacer"></span>' +
            '<span class="mt-stat"><s>ctrl+shift+d</s></span>' +
            '</div>';
    }

    function stat(name, value, cls) {
        return '<span class="mt-stat ' + cls + '"><b>' + esc(value) + '</b><s>' + esc(name) + '</s></span>';
    }

    // ── rendering ───────────────────────────────────────────────────
    function visible() {
        var needle = search.toLowerCase();
        return entries.filter(function (entry) {
            if (state.tab !== 'all' && state.tab !== 'duplicates' && entry.type !== state.tab) { return false; }
            if (!needle) { return true; }
            return (label(entry) + ' ' + subtitle(entry)).toLowerCase().indexOf(needle) !== -1;
        });
    }

    function tabsHtml(counts) {
        var order = ['all', 'request', 'query', 'event', 'cache', 'http', 'dump', 'timer', 'mail', 'queue', 'exception', 'log'];
        var html = order.map(function (type) {
            var n = counts[type] || 0;
            if (type !== 'all' && n === 0) { return ''; }

            var badge = String(n);
            var cls = 'mt-count' + (type === 'exception' && n ? ' is-err' : '');

            if (type === 'cache') {
                var reads = entries.filter(function (e) {
                    var op = (e.payload || {}).operation;
                    return e.type === 'cache' && (op === 'hit' || op === 'miss');
                });
                if (reads.length) {
                    var hits = reads.filter(function (e) { return e.payload.operation === 'hit'; }).length;
                    var rate = Math.round((hits / reads.length) * 100);
                    badge = rate + '%';
                    cls += rate < 50 ? ' is-warn' : '';
                }
            }

            return '<button type="button" class="mt-tab' + (state.tab === type ? ' is-active' : '') +
                '" data-type="' + esc(type) + '">' + esc(type) +
                '<span class="' + cls + '">' + esc(badge) + '</span></button>';
        }).join('');

        if (counts.query) {
            var worst = 0;
            groupedQueries().forEach(function (g) { if (g.count > worst) { worst = g.count; } });
            html += '<button type="button" class="mt-tab' + (state.tab === 'duplicates' ? ' is-active' : '') +
                '" data-type="duplicates">duplicates<span class="mt-count' +
                (worst >= 10 ? ' is-err' : worst > 1 ? ' is-warn' : '') + '">' +
                (worst > 1 ? '×' + worst : '0') + '</span></button>';
        }

        return html;
    }

    function listHtml(rows) {
        if (state.tab === 'duplicates') {
            var repeats = groupedQueries().filter(function (g) { return g.count > 1; });
            if (!repeats.length) {
                return '<div class="mt-empty">No query ran more than once.<br>Nothing here looks like an N+1.</div>';
            }
            return '<table><tbody>' + repeats.map(function (g) {
                var origins = Object.keys(g.origins);
                return '<tr class="' + (g.count >= 10 ? 'is-err' : '') + '">' +
                    '<td class="mt-type">×' + g.count + '</td>' +
                    '<td><div class="mt-main">' + esc(g.sql) + '</div>' +
                    (origins.length ? '<div class="mt-sub">' + esc(origins.slice(0, 2).join('  ')) +
                        (origins.length > 2 ? '  +' + (origins.length - 2) : '') + '</div>' : '') + '</td>' +
                    '<td class="mt-ms' + (g.total >= 100 ? ' is-slow' : '') + '">' +
                    (Math.round(g.total * 100) / 100) + 'ms</td></tr>';
            }).join('') + '</tbody></table>';
        }

        if (!rows.length) {
            return '<div class="mt-empty">Nothing recorded yet.<br>Interact with the page — AJAX calls land here too.</div>';
        }

        var html = '<table><tbody>';
        var lastRequest = null;

        rows.forEach(function (entry) {
            if (state.grouped && entry.request_id !== lastRequest) {
                lastRequest = entry.request_id;
                var head = entries.filter(function (e) {
                    return e.type === 'request' && e.request_id === entry.request_id;
                })[0];
                var title = head
                    ? (head.payload.method || '') + ' ' + (head.payload.path || '') +
                      ((head.payload.ajax) ? '  · ajax' : '')
                    : 'request ' + entry.request_id.slice(0, 8);
                html += '<tr class="mt-group"><td colspan="3">' + esc(title) + '</td></tr>';
            }

            var ms = entry.duration_ms;
            var slow = (entry.tags || []).indexOf('slow') !== -1;

            html += '<tr data-id="' + esc(entry.id) + '" class="' +
                (isBad(entry) ? 'is-err ' : '') + (state.selected === entry.id ? 'is-sel' : '') + '">' +
                '<td class="mt-type">' + esc(entry.type) + '</td>' +
                '<td><div class="mt-main">' + esc(label(entry)) + '</div>' +
                (subtitle(entry) ? '<div class="mt-sub">' + esc(subtitle(entry)) + '</div>' : '') + '</td>' +
                '<td class="mt-ms' + (slow ? ' is-slow' : '') + '">' +
                (ms === null || ms === undefined ? '' : (Math.round(ms * 100) / 100) + 'ms') + '</td></tr>';
        });

        return html + '</tbody></table>';
    }

    function detailHtml() {
        var entry = entries.filter(function (e) { return e.id === state.selected; })[0];
        if (!entry) {
            return '<div class="mt-hint">Select a row to see its full payload.</div>';
        }

        var p = entry.payload || {};
        var html = '<h4>' + esc(entry.type) + '</h4><pre>' + esc(label(entry)) + '</pre>';

        if (entry.duration_ms !== null && entry.duration_ms !== undefined) {
            html += '<h4>duration</h4><pre>' + (Math.round(entry.duration_ms * 100) / 100) + ' ms</pre>';
        }
        if (p.origin) { html += '<h4>origin</h4><pre>' + esc(p.origin) + '</pre>'; }
        if (entry.tags && entry.tags.length) { html += '<h4>tags</h4><pre>' + esc(entry.tags.join(', ')) + '</pre>'; }

        if (entry.type === 'exception' && Array.isArray(p.trace)) {
            html += '<h4>trace</h4><pre>' + esc(p.trace.map(function (f) {
                return (f.class ? f.class + '::' : '') + f.function + '\n    ' + f.file + ':' + f.line;
            }).join('\n')) + '</pre>';
        }

        html += '<h4>payload</h4><pre>' + esc(JSON.stringify(p, null, 2)) + '</pre>';
        html += '<h4>request</h4><pre>' + esc(entry.request_id) + '</pre>';

        return html;
    }

    function render() {
        if (!state.open) { return; }

        var counts = { all: entries.length };
        entries.forEach(function (e) { counts[e.type] = (counts[e.type] || 0) + 1; });

        mount.innerHTML =
            '<div class="mt-grip"></div>' +
            summary() +
            '<div class="mt-tabs">' + tabsHtml(counts) +
            '<span class="mt-spacer" style="flex:1 1 auto"></span>' +
            '<input class="mt-search" type="search" placeholder="filter" value="' + esc(search) + '">' +
            '<button type="button" class="mt-btn' + (state.grouped ? ' is-on' : '') + '" data-action="group">group</button>' +
            '<button type="button" class="mt-btn" data-action="clear">clear</button>' +
            '<button type="button" class="mt-btn" data-action="close">hide</button>' +
            '</div>' +
            '<div class="mt-panes">' +
            '<div class="mt-list">' + listHtml(visible()) + '</div>' +
            '<div class="mt-detail">' + detailHtml() + '</div>' +
            '</div>';

        var box = mount.querySelector('.mt-search');
        if (box && search) { box.focus(); box.setSelectionRange(search.length, search.length); }
    }

    // ── interaction ─────────────────────────────────────────────────
    mount.addEventListener('click', function (event) {
        var tab = event.target.closest('.mt-tab');
        if (tab) { state.tab = tab.dataset.type; state.selected = null; writeState(); render(); return; }

        var action = event.target.closest('[data-action]');
        if (action) {
            var name = action.dataset.action;
            if (name === 'close') { setOpen(false); }
            if (name === 'group') { state.grouped = !state.grouped; writeState(); render(); }
            if (name === 'clear') {
                entries = []; seen = Object.create(null); state.selected = null; render();
            }
            return;
        }

        var row = event.target.closest('tr[data-id]');
        if (row) { state.selected = row.dataset.id; render(); }
    });

    mount.addEventListener('input', function (event) {
        if (event.target.classList.contains('mt-search')) { search = event.target.value; render(); }
    });

    // drag the top edge to resize
    mount.addEventListener('mousedown', function (event) {
        if (!event.target.classList.contains('mt-grip')) { return; }
        event.preventDefault();

        var startY = event.clientY;
        var startH = mount.offsetHeight;

        function move(e) {
            state.height = Math.max(140, Math.min(startH + (startY - e.clientY), window.innerHeight - 60));
            mount.style.height = state.height + 'px';
        }
        function up() {
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
            writeState();
        }

        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
    });

    // ── polling ─────────────────────────────────────────────────────
    function poll() {
        if (!state.open) { return; }

        fetch(endpoint + '?after=' + encodeURIComponent(after), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                var list = data && (data.entries || (data.data && data.data.entries));
                if (!Array.isArray(list) || !list.length) { return; }

                list.forEach(function (entry) {
                    if (seen[entry.id]) { return; }
                    seen[entry.id] = true;
                    entries.unshift(entry);
                    if (entry.recorded_at > after) { after = entry.recorded_at; }
                });

                if (entries.length > 800) { entries.length = 800; }
                render();
            })
            .catch(function () { /* a down endpoint is not worth an error per second */ });
    }

    readState();
    mount.style.height = state.height + 'px';
    setOpen(state.open);
    timer = setInterval(poll, 2000);
    window.addEventListener('beforeunload', function () { clearInterval(timer); });
})();
JS;
    }
}
