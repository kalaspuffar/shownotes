<?php
declare(strict_types=1);
// Audience view entry point.
// The host's recording mode opens this page via window.open(), then navigates
// the window directly with audienceWindow.location.href = url for each item.
// This page is only shown momentarily before the first article loads.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cozy News Corner — Audience View</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: #1a1a2e;
            color: #8892a4;
            font-family: system-ui, -apple-system, sans-serif;
        }

        #waiting-msg {
            position: fixed;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 20px;
            font-size: 1.2rem;
        }

        .waiting-dot {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 4px solid #e94560;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div id="waiting-msg">
        <div class="waiting-dot" aria-hidden="true"></div>
        <span>Waiting for host…</span>
    </div>
</body>
</html>
