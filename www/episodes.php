<?php
declare(strict_types=1);
// M11 — Past episodes: the browse list over the M9 episode_archive.
// Read-only, server-rendered (no JS app state). Newest first. Empty state
// when no episode has been finalised yet — nothing to fabricate.
require_once __DIR__ . '/../include/Database.php';

$config = require __DIR__ . '/../etc/config.php';
$db     = Database::getInstance();

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Week cell: the free-form label verbatim when present, else "Week N". */
function episodeWeek(array $ep): string
{
    $label = trim((string) ($ep['week_label'] ?? ''));
    $week  = (int) ($ep['iso_week'] ?? 0);
    return $label !== '' ? $label : ($week > 0 ? 'Week ' . $week : 'Week');
}

/** YYYY-MM-D from the ISO-8601 created_at; raw value as a last resort. */
function archiveDate(string $iso): string
{
    $ts = strtotime($iso);
    return $ts !== false ? date('Y-m-d', $ts) : $iso;
}

$showTitle = htmlspecialchars($config['show_title'] ?? 'Cozy News Corner', ENT_QUOTES, 'UTF-8');
$episodes  = $db->getEpisodesArchive();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $showTitle ?> — Past Episodes</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="archive-page">

<header class="arc-top">
    <a class="arc-back" href="index.php" aria-label="Back to the prep view">&#8592; Prep</a>
    <h1>Past Episodes</h1>
    <span class="arc-count" aria-live="polite">
        <?= count($episodes) ?> finished
    </span>
</header>

<main class="arc-main">
    <?php if ($episodes === []): ?>
        <p class="arc-empty">
            No past episodes yet. When you press <strong>New Episode</strong> in the
            prep view after a recording, that episode is archived here — its show notes
            and full story list included.
        </p>
    <?php else: ?>
        <ul class="episodes-list">
            <?php foreach ($episodes as $ep): ?>
                <?php
                $id        = (int) $ep['id'];
                $count     = (int) $ep['item_count'];
                $youtube   = (string) ($ep['youtube_url'] ?? '');
                $weekLabel = esc(episodeWeek($ep));
                $year      = (int) ($ep['year'] ?? 0);
                $date      = esc(archiveDate((string) ($ep['created_at'] ?? '')));
                ?>
                <li class="episode-list-item">
                    <a class="episode-link" href="episode.php?id=<?= $id ?>">
                        <span class="episode-week"><?= $weekLabel ?><?= $year > 0 ? ', ' . $year : '' ?></span>
                        <span class="episode-meta">
                            <span class="episode-count"><?= $count ?> <?= $count === 1 ? 'story' : 'stories' ?></span>
                            <span class="episode-sep" aria-hidden="true">&middot;</span>
                            <span class="episode-date">archived <?= $date ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="arc-hint">Open an episode to read its show notes and copy the Markdown back to the blog.</p>
    <?php endif; ?>
</main>

</body>
</html>
