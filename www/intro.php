<?php
declare(strict_types=1);
// M7 — Audience intro card, shown on the second monitor at the open of the
// show. Reads the live episode + filled hooks server-side so the audience
// screen always matches the prep view (no WebSocket round-trip needed for
// this one screen). The host view's intro is the human-facing readout.
//
// Flow: presenter "Start Recording" → navigate(0) sets audienceWindow to
// /intro.php → host reads the title + week + "this week we cover" list →
// opens with "This week we will cover…" (Next flips the audience to the
// first story).
require_once __DIR__ . '/../include/Database.php';

$config = require __DIR__ . '/../etc/config.php';
$db     = Database::getInstance();
$ep     = $db->getEpisode();
$hooks  = $db->getHooks();

$showTitle = htmlspecialchars($config['show_title'] ?? 'Cozy News Corner', ENT_QUOTES, 'UTF-8');
$showTag   = htmlspecialchars($config['show_tagline'] ?? '', ENT_QUOTES, 'UTF-8');
$week      = (int) ($ep['week_number'] ?? 0);
$year      = (int) ($ep['year'] ?? (int) date('Y'));
$weekLabel = $week > 0 ? ($week > 52 ? "Week $week of $year" : "Week $week, $year") : '';

function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $showTitle ?> — This Week</title>
    <style>
        :root {
            --bg:      #1a1a2e;
            --surface: #16213e;
            --ink:     #eaeaea;
            --ink-2:   #c9d1e0;
            --muted:   #8892a4;
            --accent:  #e94560;
            --line:    rgba(255,255,255,0.08);
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 800px at 15% 10%, rgba(233,69,96,0.12), transparent 60%),
                radial-gradient(1000px 700px at 90% 90%, rgba(42,42,74,0.6), transparent 65%),
                linear-gradient(160deg, var(--bg), #10101e);
            min-height: 100%;
            overflow: hidden;
        }
        .wrap {
            height: 100vh;
            display: flex;
            align-items: center;
            padding: clamp(40px, 7vw, 120px);
            gap: clamp(32px, 5vw, 88px);
        }
        .left { flex: 0 0 auto; }
        .label {
            font-size: 15px;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            color: var(--accent);
            margin-bottom: 16px;
            font-weight: 700;
        }
        .title {
            font-size: clamp(44px, 6.4vw, 92px);
            font-weight: 800;
            line-height: 1.02;
            margin: 0 0 14px;
            letter-spacing: -0.01em;
        }
        .tagline {
            font-size: clamp(20px, 2vw, 30px);
            color: var(--ink-2);
            margin: 0 0 22px;
            font-weight: 500;
        }
        .week {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: clamp(18px, 1.6vw, 24px);
            color: var(--ink);
            padding: 10px 20px;
            border: 1px solid var(--line);
            border-radius: 100px;
            background: rgba(255,255,255,0.04);
        }
        .week .dot {
            width: 9px; height: 9px; border-radius: 50%;
            background: var(--accent);
        }
        .right { flex: 1 1 auto; min-width: 0; }
        .lead {
            font-size: clamp(22px, 2.2vw, 34px);
            color: var(--ink);
            font-weight: 600;
            margin: 0 0 26px;
        }
        .hook-list {
            list-style: none;
            margin: 0; padding: 0;
            display: grid;
            gap: 16px;
        }
        .hook-list li {
            display: flex;
            align-items: baseline;
            gap: 16px;
            padding: 18px 22px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-left: 4px solid var(--accent);
            border-radius: 12px;
            min-width: 0;
        }
        .hook-num {
            flex: 0 0 auto;
            font-weight: 800;
            font-size: clamp(18px, 1.6vw, 22px);
            color: var(--accent);
            font-variant-numeric: tabular-nums;
            min-width: 2.2ch;
        }
        .hook-text {
            flex: 1 1 auto;
            font-size: clamp(19px, 1.7vw, 25px);
            color: var(--ink);
            line-height: 1.35;
            font-weight: 500;
        }
        .hook-title {
            display: block;
            margin-top: 8px;
            font-size: clamp(13px, 1.1vw, 15px);
            color: var(--muted);
            font-weight: 500;
            letter-spacing: 0.02em;
        }
        .empty {
            font-size: clamp(18px, 1.6vw, 24px);
            color: var(--muted);
            padding: 30px 22px;
            background: var(--surface);
            border: 1px dashed var(--line);
            border-radius: 12px;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="left">
            <h1 class="title"><?= $showTitle ?></h1>
            <?php if ($showTag !== ''): ?>
                <p class="tagline"><?= $showTag ?></p>
            <?php endif; ?>
            <?php if ($weekLabel !== ''): ?>
                <div class="week"><span class="dot" aria-hidden="true"></span><?= esc($weekLabel) ?></div>
            <?php endif; ?>
        </div>
        <div class="right">
            <p class="lead">This week we&rsquo;ll cover&hellip;</p>
            <?php if ($hooks === []): ?>
                <p class="empty">Hooks go here — set each story&rsquo;s open in the prep view.</p>
            <?php else: ?>
                <ul class="hook-list">
                <?php foreach ($hooks as $i => $h): ?>
                    <li>
                        <span class="hook-num" aria-hidden="true"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <span>
                            <span class="hook-text"><?= esc($h['hook']) ?></span>
                            <?php if (!empty($h['title'])): ?>
                                <span class="hook-title"><?= esc($h['title']) ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
