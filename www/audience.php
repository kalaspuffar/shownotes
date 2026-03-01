<?php
declare(strict_types=1);
require_once '../etc/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cozy News Corner — Audience View</title>
    <style>
        /* Reset */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: #000;
            color: #e8eaf6;
            font-family: system-ui, -apple-system, sans-serif;
            overflow: hidden;
        }

        /* Full-viewport iframe — hidden until first navigate message */
        #audience-iframe {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            border: none;
            display: none;
        }

        /* Centred waiting message shown before any article is received */
        #waiting-msg {
            position: fixed;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            color: #8892a4;
            font-size: 1.2rem;
        }

        #waiting-msg .waiting-dot {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 4px solid #e94560;
            border-top-color: transparent;
            animation: audienceWaitSpin 1s linear infinite;
        }

        @keyframes audienceWaitSpin {
            to { transform: rotate(360deg); }
        }

        /* Animated progress bar at the top — shown while iframe is loading */
        #loading-bar {
            position: fixed;
            top: 0;
            left: 0;
            height: 4px;
            width: 100%;
            background: linear-gradient(90deg, #e94560 0%, #8892a4 50%, #e94560 100%);
            background-size: 200% 100%;
            animation: audienceLoading 1.4s linear infinite;
            display: none;
            z-index: 10;
        }

        @keyframes audienceLoading {
            from { background-position: 100% 0; }
            to   { background-position: -100% 0; }
        }

        /* Fallback notification — appears if iframe is blocked after 3 s */
        #fallback-msg {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(30, 30, 50, 0.95);
            border: 1px solid rgba(233, 69, 96, 0.5);
            border-radius: 8px;
            padding: 16px 24px;
            font-size: 1rem;
            color: #e8eaf6;
            max-width: 480px;
            text-align: center;
            z-index: 20;
            transition: opacity 0.6s;
        }

        /* Pulsing disconnect indicator — bottom-right corner */
        #disconnect-dot {
            position: fixed;
            bottom: 16px;
            right: 16px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #e74c3c;
            z-index: 30;
        }

        #disconnect-dot.disconnected {
            animation: audiencePulse 1.4s ease-in-out infinite;
        }

        @keyframes audiencePulse {
            0%,100% { opacity: 1; transform: scale(1); }
            50%      { opacity: 0.4; transform: scale(1.4); }
        }
    </style>
</head>
<body>

<div id="waiting-msg">
    <div class="waiting-dot" aria-hidden="true"></div>
    <span>Waiting for host…</span>
</div>

<div id="loading-bar" aria-hidden="true"></div>

<iframe
    id="audience-iframe"
    sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-presentation"
    title="Current article"
></iframe>

<div id="fallback-msg" hidden>
    This site blocks embedding. Opening in a new tab…
</div>

<div id="disconnect-dot" hidden aria-label="Disconnected from host"></div>

<script>
const WS_PORT = <?= (int)$config['ws_port'] ?>;

(function () {
    'use strict';

    const WS_URL        = `ws://127.0.0.1:${WS_PORT}`;
    const iframe        = document.getElementById('audience-iframe');
    const waitingMsg    = document.getElementById('waiting-msg');
    const loadingBar    = document.getElementById('loading-bar');
    const fallbackMsg   = document.getElementById('fallback-msg');
    const disconnectDot = document.getElementById('disconnect-dot');

    let ws              = null;
    let currentUrl      = null;
    let fallbackTimer   = null;
    let fallbackFadeTimer = null;
    let intentionalClose  = false;

    /* ---- WebSocket client ---- */

    function connect() {
        intentionalClose = false;
        try {
            ws = new WebSocket(WS_URL);

            ws.addEventListener('open', () => {
                ws.send(JSON.stringify({ action: 'hello', role: 'audience' }));
                disconnectDot.hidden = true;
                disconnectDot.classList.remove('disconnected');
            });

            ws.addEventListener('message', (event) => {
                let msg;
                try { msg = JSON.parse(event.data); } catch { return; }
                if (msg.action === 'navigate' && msg.url) {
                    loadUrl(msg.url);
                }
            });

            ws.addEventListener('close', () => {
                disconnectDot.hidden = false;
                disconnectDot.classList.add('disconnected');
                if (!intentionalClose) {
                    setTimeout(connect, 3000);
                }
            });

            ws.addEventListener('error', (e) => {
                console.error('[audience] WebSocket error', e);
            });
        } catch (e) {
            console.error('[audience] Failed to create WebSocket', e);
            setTimeout(connect, 3000);
        }
    }

    /* ---- URL loading with fallback ---- */

    function loadUrl(url) {
        currentUrl = url;

        // Clear any previous fallback timers
        clearTimeout(fallbackTimer);
        clearTimeout(fallbackFadeTimer);
        fallbackMsg.hidden = true;
        fallbackMsg.style.opacity = '';

        // Show loading bar, hide waiting message, reveal iframe
        waitingMsg.style.display  = 'none';
        loadingBar.style.display  = 'block';
        iframe.style.display      = 'block';
        iframe.src                = url;

        // After 3 s without a load event, assume the iframe is blocked
        fallbackTimer = setTimeout(() => {
            loadingBar.style.display = 'none';
            fallbackMsg.hidden = false;
            fallbackMsg.style.opacity = '1';

            window.open(url);

            // Fade out the fallback message after 5 s
            fallbackFadeTimer = setTimeout(() => {
                fallbackMsg.style.opacity = '0';
                setTimeout(() => { fallbackMsg.hidden = true; }, 600);
            }, 5000);
        }, 3000);
    }

    /* ---- iframe load event ---- */

    iframe.addEventListener('load', () => {
        clearTimeout(fallbackTimer);
        loadingBar.style.display = 'none';
    });

    /* ---- Boot ---- */

    connect();
}());
</script>

</body>
</html>
