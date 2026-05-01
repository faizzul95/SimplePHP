<?php
$pageTitle = isset($title) && is_string($title) && $title !== '' ? $title : 'Maintenance Mode';
$pageMessage = isset($message) && is_string($message) && $message !== '' ? $message : 'Service Unavailable';
$pageRetryAfter = isset($retryAfterSeconds) && is_int($retryAfterSeconds) && $retryAfterSeconds > 0
    ? $retryAfterSeconds
    : null;
$pageRefreshAfter = isset($refreshAfterSeconds) && is_int($refreshAfterSeconds) && $refreshAfterSeconds > 0
    ? $refreshAfterSeconds
    : null;
$pageStatusCode = isset($statusCode) && is_int($statusCode) ? $statusCode : 503;
$applicationName = defined('APP_NAME') ? APP_NAME : 'Application';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($applicationName . ' | ' . $pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5efe6;
            --panel: #fffaf2;
            --text: #201a12;
            --muted: #66594a;
            --accent: #9d5c0d;
            --accent-soft: rgba(157, 92, 13, 0.14);
            --border: rgba(32, 26, 18, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Georgia, "Times New Roman", serif;
            background:
                radial-gradient(circle at top left, rgba(157, 92, 13, 0.18), transparent 35%),
                linear-gradient(135deg, #f7f1e7 0%, #efe2d1 100%);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .panel {
            width: min(680px, 100%);
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px 32px;
            box-shadow: 0 24px 80px rgba(32, 26, 18, 0.12);
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 18px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(36px, 8vw, 56px);
            line-height: 0.95;
        }

        p {
            margin: 0;
            font-size: 18px;
            line-height: 1.6;
            color: var(--muted);
        }

        .meta {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .meta-item {
            padding: 10px 14px;
            border-radius: 14px;
            background: #ffffff;
            border: 1px solid var(--border);
            color: var(--text);
            font-size: 14px;
        }
    </style>
</head>
<body>
    <main class="panel">
        <div class="eyebrow">Temporarily Offline</div>
        <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($pageMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="meta">
            <div class="meta-item">HTTP <?= htmlspecialchars((string) $pageStatusCode, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="meta-item"><?= htmlspecialchars($applicationName, ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($pageRetryAfter !== null): ?>
                <div class="meta-item">Retry after <?= htmlspecialchars((string) $pageRetryAfter, ENT_QUOTES, 'UTF-8') ?> seconds</div>
            <?php endif; ?>
            <?php if ($pageRefreshAfter !== null): ?>
                <div class="meta-item">Refresh after <?= htmlspecialchars((string) $pageRefreshAfter, ENT_QUOTES, 'UTF-8') ?> seconds</div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>