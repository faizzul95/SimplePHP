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
#myth-telemetry-bar{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#e6e6e6;background:#16181d;border-top:1px solid #2c3039;box-shadow:0 -2px 12px rgba(0,0,0,.35);max-height:70vh;display:flex;flex-direction:column}
#myth-telemetry-bar[hidden]{display:none}
#myth-telemetry-bar .mt-tabs{display:flex;align-items:center;gap:2px;padding:4px 6px;flex-wrap:wrap;border-bottom:1px solid #2c3039;flex:0 0 auto}
#myth-telemetry-bar .mt-tab{background:transparent;border:1px solid transparent;color:#9aa4b2;padding:3px 9px;border-radius:5px;cursor:pointer;font:inherit;white-space:nowrap}
#myth-telemetry-bar .mt-tab:hover{background:#1d2027;color:#e6e6e6}
#myth-telemetry-bar .mt-tab.is-active{background:#2b6cb0;border-color:#2b6cb0;color:#fff}
#myth-telemetry-bar .mt-count{display:inline-block;min-width:16px;padding:0 4px;margin-left:5px;border-radius:8px;background:#2c3039;color:#cbd5e0;font-size:10px;text-align:center}
#myth-telemetry-bar .mt-tab.is-active .mt-count{background:rgba(255,255,255,.25);color:#fff}
#myth-telemetry-bar .mt-count.is-warn{background:#c05621;color:#fff}
#myth-telemetry-bar .mt-count.is-error{background:#c53030;color:#fff}
#myth-telemetry-bar .mt-spacer{flex:1 1 auto}
#myth-telemetry-bar .mt-search{background:#0f1115;border:1px solid #2c3039;color:#e6e6e6;border-radius:5px;padding:3px 8px;font:inherit;min-width:120px;max-width:200px}
#myth-telemetry-bar .mt-btn{background:#1d2027;border:1px solid #2c3039;color:#cbd5e0;border-radius:5px;padding:3px 9px;cursor:pointer;font:inherit}
#myth-telemetry-bar .mt-btn:hover{color:#fff;border-color:#4a5568}
#myth-telemetry-bar .mt-body{overflow:auto;flex:1 1 auto;min-height:0}
#myth-telemetry-bar.is-collapsed .mt-body{display:none}
#myth-telemetry-bar table{width:100%;border-collapse:collapse}
#myth-telemetry-bar td{padding:4px 8px;border-bottom:1px solid #22262e;vertical-align:top;word-break:break-word}
#myth-telemetry-bar tr:hover td{background:#1b1e25}
#myth-telemetry-bar .mt-type{width:74px;color:#7f8ea3;text-transform:uppercase;font-size:10px;letter-spacing:.04em;white-space:nowrap}
#myth-telemetry-bar .mt-ms{width:62px;text-align:right;color:#9aa4b2;white-space:nowrap}
#myth-telemetry-bar .mt-ms.is-slow{color:#f6ad55;font-weight:700}
#myth-telemetry-bar .mt-main{white-space:pre-wrap;font-family:inherit}
#myth-telemetry-bar .mt-sub{color:#718096;font-size:11px;margin-top:2px}
#myth-telemetry-bar .mt-row-error .mt-main{color:#fc8181}
#myth-telemetry-bar .mt-empty{padding:14px;color:#718096;text-align:center}
#myth-telemetry-bar details>summary{cursor:pointer;color:#63b3ed;list-style:none}
#myth-telemetry-bar details pre{margin:6px 0 0;padding:8px;background:#0f1115;border-radius:5px;overflow:auto;max-height:260px;white-space:pre-wrap}
#myth-telemetry-toggle{position:fixed;right:12px;bottom:12px;z-index:2147483000;background:#2b6cb0;color:#fff;border:0;border-radius:999px;padding:7px 13px;cursor:pointer;font:12px/1 ui-monospace,Menlo,Consolas,monospace;box-shadow:0 2px 10px rgba(0,0,0,.4)}
#myth-telemetry-toggle[hidden]{display:none}
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
    var active = 'all';
    var search = '';
    var open = false;
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
            open = saved.open === true;
            active = typeof saved.active === 'string' ? saved.active : 'all';
        } catch (e) { /* private mode, or blocked storage */ }
    }

    function writeState() {
        try { localStorage.setItem(STORAGE, JSON.stringify({ open: open, active: active })); } catch (e) {}
    }

    var toggle = document.createElement('button');
    toggle.id = 'myth-telemetry-toggle';
    toggle.type = 'button';
    toggle.textContent = 'debug';
    toggle.addEventListener('click', function () { setOpen(!open); });
    document.body.appendChild(toggle);

    function setOpen(next) {
        open = next;
        mount.hidden = !open;
        toggle.hidden = open;
        writeState();
        if (open) { poll(); }
    }

    function label(entry) {
        var p = entry.payload || {};
        switch (entry.type) {
            case 'query':   return p.sql || '';
            case 'request': return (p.method || '') + ' ' + (p.path || '') + '  → ' + (p.status || '');
            case 'mail':    return (p.sent ? 'sent' : 'FAILED') + '  ' + (p.subject || '') + '  → ' + ((p.to || []).join(', '));
            case 'queue':   return (p.job || p.class || 'job') + '  ' + (p.status || '');
            case 'exception': return (p.class || 'Exception') + ': ' + (p.message || '');
            case 'log':     return '[' + (p.level || 'log') + '] ' + (p.message || '');
            case 'http':    return (p.method || '') + ' ' + (p.url || '');
            case 'dump':    return (p.label ? p.label + ': ' : '') + preview(p.value);
            case 'timer':   return p.kind === 'counter'
                                ? p.name + ' × ' + p.count
                                : p.name + (p.kind === 'unclosed' ? '  (never stopped)' : '');
            default:        return p.message || p.name || entry.type;
        }
    }

    /* A one-line summary. The full value is in the payload fold. */
    function preview(value) {
        if (value === null || value === undefined) { return String(value); }
        if (typeof value !== 'object') { return String(value); }

        var json = JSON.stringify(value);
        if (json === undefined) { return '[unserialisable]'; }
        return json.length > 160 ? json.slice(0, 160) + '…' : json;
    }

    function subtitle(entry) {
        var p = entry.payload || {};
        if (entry.type === 'query') { return p.origin || p.connection || ''; }
        if (entry.type === 'exception') { return (p.file || '') + ':' + (p.line || ''); }
        if (entry.type === 'dump') { return (p.type || '') + (p.origin ? '  ' + p.origin : ''); }
        if (entry.type === 'timer') { return p.origin || p.note || ''; }
        if (entry.type === 'mail') { return 'driver: ' + (p.driver || '') + (p.error ? '  ' + p.error : ''); }
        if (entry.type === 'request') {
            var bits = [];
            if (p.ip) { bits.push(p.ip); }
            if (p.ajax) { bits.push('ajax'); }
            if (p.query_count) { bits.push(p.query_count + ' queries in ' + p.query_time_ms + 'ms'); }
            bits.push((p.memory_mb || 0) + 'MB');
            return bits.join('  ');
        }
        return '';
    }

    /* Group queries by shape so a repeat count is visible at a glance.
       Literals are already replaced server-side in the request summary; this
       does the same locally so the view works across requests. */
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
            if (!groups[key]) {
                groups[key] = { sql: key, count: 0, total: 0, origins: Object.create(null) };
            }

            groups[key].count++;
            groups[key].total += entry.duration_ms || 0;
            var origin = (entry.payload || {}).origin;
            if (origin) { groups[key].origins[origin] = true; }
        });

        return Object.keys(groups)
            .map(function (k) { return groups[k]; })
            .sort(function (a, b) { return b.count - a.count || b.total - a.total; });
    }

    function visible() {
        var needle = search.toLowerCase();
        return entries.filter(function (entry) {
            if (active !== 'all' && entry.type !== active) { return false; }
            if (!needle) { return true; }
            return (label(entry) + ' ' + subtitle(entry)).toLowerCase().indexOf(needle) !== -1;
        });
    }

    function render() {
        var rows = visible();
        var counts = { all: entries.length };
        entries.forEach(function (e) { counts[e.type] = (counts[e.type] || 0) + 1; });

        var types = ['all', 'request', 'query', 'dump', 'timer', 'mail', 'queue', 'exception', 'log'];
        var tabs = types.map(function (type) {
            var n = counts[type] || 0;
            if (type !== 'all' && n === 0) { return ''; }
            var cls = 'mt-count' + (type === 'exception' && n ? ' is-error' : '');
            return '<button type="button" class="mt-tab' + (active === type ? ' is-active' : '') +
                '" data-type="' + esc(type) + '">' + esc(type) +
                '<span class="' + cls + '">' + n + '</span></button>';
        }).join('');

        /* The N+1 view: one row per query shape, busiest first. */
        var grouped = groupedQueries();
        var worst = grouped.length ? grouped[0].count : 0;
        if (counts.query) {
            tabs += '<button type="button" class="mt-tab' + (active === 'grouped' ? ' is-active' : '') +
                '" data-type="grouped">duplicates<span class="mt-count' +
                (worst >= 10 ? ' is-error' : worst > 1 ? ' is-warn' : '') + '">' +
                (worst > 1 ? '×' + worst : '0') + '</span></button>';
        }

        var body;

        if (active === 'grouped') {
            var repeats = grouped.filter(function (g) { return g.count > 1; });
            body = repeats.length === 0
                ? '<div class="mt-empty">No query ran more than once this page. Nothing that looks like an N+1.</div>'
                : '<table><tbody>' + repeats.map(function (g) {
                    var origins = Object.keys(g.origins);
                    return '<tr class="' + (g.count >= 10 ? 'mt-row-error' : '') + '">' +
                        '<td class="mt-type">×' + g.count + '</td>' +
                        '<td><div class="mt-main">' + esc(g.sql) + '</div>' +
                        (origins.length
                            ? '<div class="mt-sub">' + esc(origins.slice(0, 3).join('  ')) +
                              (origins.length > 3 ? '  +' + (origins.length - 3) + ' more' : '') + '</div>'
                            : '') +
                        '</td>' +
                        '<td class="mt-ms' + (g.total >= 100 ? ' is-slow' : '') + '">' +
                        (Math.round(g.total * 100) / 100) + 'ms</td>' +
                        '</tr>';
                }).join('') + '</tbody></table>';

            mount.innerHTML =
                '<div class="mt-tabs">' + tabs +
                '<span class="mt-spacer"></span>' +
                '<button type="button" class="mt-btn" data-action="clear">clear</button>' +
                '<button type="button" class="mt-btn" data-action="close">hide</button>' +
                '</div><div class="mt-body">' + body + '</div>';
            return;
        }

        body = rows.length === 0
            ? '<div class="mt-empty">No entries yet. Interact with the page — AJAX calls appear here too.</div>'
            : '<table><tbody>' + rows.map(function (entry) {
                var ms = entry.duration_ms;
                var slow = (entry.tags || []).indexOf('slow') !== -1;
                var bad = entry.type === 'exception' || (entry.tags || []).indexOf('server-error') !== -1;
                return '<tr class="' + (bad ? 'mt-row-error' : '') + '">' +
                    '<td class="mt-type">' + esc(entry.type) + '</td>' +
                    '<td><div class="mt-main">' + esc(label(entry)) + '</div>' +
                    (subtitle(entry) ? '<div class="mt-sub">' + esc(subtitle(entry)) + '</div>' : '') +
                    '<details><summary>payload</summary><pre>' +
                    esc(JSON.stringify(entry.payload, null, 2)) + '</pre></details></td>' +
                    '<td class="mt-ms' + (slow ? ' is-slow' : '') + '">' +
                    (ms === null || ms === undefined ? '' : (Math.round(ms * 100) / 100) + 'ms') + '</td>' +
                    '</tr>';
            }).join('') + '</tbody></table>';

        mount.innerHTML =
            '<div class="mt-tabs">' + tabs +
            '<span class="mt-spacer"></span>' +
            '<input class="mt-search" type="search" placeholder="filter" value="' + esc(search) + '">' +
            '<button type="button" class="mt-btn" data-action="clear">clear</button>' +
            '<button type="button" class="mt-btn" data-action="close">hide</button>' +
            '</div><div class="mt-body">' + body + '</div>';
    }

    mount.addEventListener('click', function (event) {
        var tab = event.target.closest('.mt-tab');
        if (tab) { active = tab.dataset.type; writeState(); render(); return; }

        var action = event.target.closest('[data-action]');
        if (!action) { return; }
        if (action.dataset.action === 'close') { setOpen(false); }
        if (action.dataset.action === 'clear') { entries = []; seen = Object.create(null); render(); }
    });

    mount.addEventListener('input', function (event) {
        if (event.target.classList.contains('mt-search')) { search = event.target.value; render(); }
    });

    function poll() {
        if (!open) { return; }

        fetch(endpoint + '?after=' + encodeURIComponent(after), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !Array.isArray(data.entries)) { return; }

                data.entries.forEach(function (entry) {
                    if (seen[entry.id]) { return; }
                    seen[entry.id] = true;
                    entries.unshift(entry);
                    if (entry.recorded_at > after) { after = entry.recorded_at; }
                });

                if (entries.length > 500) { entries.length = 500; }
                render();
            })
            .catch(function () { /* the endpoint being down is not worth a console error per second */ });
    }

    readState();
    setOpen(open);
    render();
    timer = setInterval(poll, 2000);
    window.addEventListener('beforeunload', function () { clearInterval(timer); });
})();
JS;
    }
}
