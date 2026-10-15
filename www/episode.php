<?php
declare(strict_types=1);
// M11 — one finished episode: the per-episode show-notes view.
// Read-only over the M9 episode_archive snapshot (items_json + markdown).
// Server-rendered, no JS app state — only a one-line Copy button below.
require_once __DIR__ . '/../include/Database.php';

$config = require __DIR__ . '/../etc/config.php';
$db     = Database::getInstance();

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function episodeWeek(array $ep): string
{
    $label = trim((string) ($ep['week_label'] ?? ''));
    $week  = (int) ($ep['iso_week'] ?? 0);
    return $label !== '' ? $label : ($week > 0 ? 'Week ' . $week : 'Week');
}

/** Title fallback for a broken row: URL host, then the URL (Generator parity). */
function linkTitle(array $row): string
{
    $t = trim((string) ($row['title'] ?? ''));
    if ($t !== '') {
        return $t;
    }
    $url  = (string) ($row['url'] ?? '');
    $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
    return $host !== '' ? $host : $url;
}

/** Non-empty research-context lines (Generator parity: trim, drop blanks). */
function contextLines(array $row): array
{
    $raw   = trim((string) ($row['my_context'] ?? ''));
    $lines = [];
    foreach (preg_split("/\r?\n/", $raw) as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
}

/** Author attribution, or null when there is no name (never an empty By:). */
function author(array $row): ?array
{
    $name    = trim((string) ($row['author_name'] ?? ''));
    $profile = trim((string) ($row['author_url'] ?? ''));
    return $name === '' ? null : ['name' => $name, 'url' => $profile];
}

$showTitle = htmlspecialchars($config['show_title'] ?? 'Cozy News Corner', ENT_QUOTES, 'UTF-8');
$sections  = (array) ($config['sections'] ?? []);

/** Short readable error body (missing id → 400, unknown id → 404). */
function archiveError(string $message, int $code): never
{
    http_response_code($code);
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<title>Episode error</title><link rel="stylesheet" href="css/style.css"></head>'
       . '<body class="archive-page"><main class="arc-main"><p class="archive-error">'
       . $m . '</p><p><a class="arc-back" href="episodes.php">&larr; Past episodes</a> '
       . '<a class="arc-back" href="index.php">Prep view</a></p></main></body></html>';
    exit;
}

$rawId = (string) ($_GET['id'] ?? '');
if ($rawId === '' || !preg_match('/^[0-9]{1,9}$/', $rawId) || (int) $rawId < 1) {
    archiveError('Missing episode id — open episodes.php to pick one.', 400);
}

$ep = $db->getEpisodeArchiveById((int) $rawId);
if ($ep === null) {
    archiveError('No archived episode with that id — it may not be finalised yet.', 404);
}

$rows = json_decode((string) ($ep['items_json'] ?? '[]'), true);
if (!is_array($rows)) {
    $rows = []; // corrupt snapshot: the markdown column below still stands in
}

// Group the flat snapshot into story blocks: primary + its secondaries.
// fetchNewsItemsOrdered stored primaries interleaved with their secondaries,
// so a secondary simply attaches to the block opened by the last primary.
// An orphaned secondary (no primary seen yet) opens its own block.
$blocks = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $pid = $row['parent_id'] ?? null;
    $isSecondary = $pid !== null && $pid !== '';

    if ($isSecondary) {
        if ($blocks !== []) {
            $blocks[count($blocks) - 1]['secondaries'][] = $row;
        } else {
            $blocks[] = ['primary' => null, 'secondaries' => [$row]];
        }
        continue;
    }
    $blocks[] = ['primary' => $row, 'secondaries' => []];
}

$youtube = trim((string) ($ep['youtube_url'] ?? ''));
$count   = (int) ($ep['item_count'] ?? 0);
$markdown = (string) ($ep['markdown'] ?? '');
$dateSec = strtotime((string) ($ep['created_at'] ?? ''));
$dateText = $dateSec !== false ? date('Y-m-d', $dateSec) : (string) ($ep['created_at'] ?? '');

$renderItem = function (array $row) use ($sections): string
{
    $url     = trim((string) ($row['url'] ?? ''));
    $title   = esc(linkTitle($row));
    $a       = author($row);
    $sec     = (string) ($row['section'] ?? '');
    $secName = isset($sections[$sec]) ? esc((string) $sections[$sec]) : esc($sec);
    $ctx     = contextLines($row);

    $out = '<div class="story-block">';
    $out .= '<div class="story-head">';
    if ($secName !== '') {
        $out .= '<span class="story-chip">' . $secName . '</span>';
    }
    $out .= '</div>';
    $out .= '<h3 class="story-title">';
    $out .= $url !== ''
        ? '<a href="' . esc($url) . '" target="_blank" rel="noopener">' . $title . '</a>'
        : $title;
    $out .= '</h3>';
    if ($a !== null) {
        $out .= '<p class="story-by">By: ';
        $out .= $a['url'] !== ''
            ? '<a href="' . esc($a['url']) . '" target="_blank" rel="noopener">' . esc($a['name']) . '</a>'
            : esc($a['name']);
        $out .= '</p>';
    }
    if ($url !== '') {
        $out .= '<p class="story-url"><a href="' . esc($url) . '" target="_blank" rel="noopener">' . esc($url) . '</a></p>';
    }
    foreach ($ctx as $line) {
        $out .= '<p class="story-context">' . esc($line) . '</p>';
    }
    $out .= '</div>';
    return $out;
};

$renderSecondary = function (array $row): string
{
    $url   = trim((string) ($row['url'] ?? ''));
    $title = esc(linkTitle($row));
    $a     = author($row);

    $out = '<p class="story-secondary">Also: ';
    $out .= $url !== ''
        ? '<a href="' . esc($url) . '" target="_blank" rel="noopener">' . $title . '</a>'
        : $title;
    if ($a !== null) {
        $out .= ' &mdash; By: ';
        $out .= $a['url'] !== ''
            ? '<a href="' . esc($a['url']) . '" target="_blank" rel="noopener">' . esc($a['name']) . '</a>'
            : esc($a['name']);
    }
    $out .= '</p>';
    return $out;
};

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $showTitle ?> &mdash; <?= esc(episodeWeek($ep)) ?><?= (int) ($ep['year'] ?? 0) > 0 ? ', ' . (int) $ep['year'] : '' ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="archive-page">

<header class="arc-top">
    <a class="arc-back" href="episodes.php" aria-label="Back to past episodes">&#8592; Past episodes</a>
    <h1><?= esc(episodeWeek($ep)) ?><?= (int) ($ep['year'] ?? 0) > 0 ? ', ' . (int) $ep['year'] : '' ?></h1>
    <span class="arc-count">
        <?= $count ?> <?= $count === 1 ? 'story' : 'stories' ?> &middot; archived <?= esc($dateText) ?>
    </span>
</header>

<main class="arc-main">
    <?php if ($youtube !== ''): ?>
        <p class="ep-youtube">
            <a href="<?= esc($youtube) ?>" target="_blank" rel="noopener">Watch on YouTube &#8599;</a>
        </p>
    <?php endif; ?>

    <section class="stories" aria-label="Stories">
        <?php if ($blocks === []): ?>
            <p class="archive-note">No story list was captured for this episode &mdash; the show-notes source below is preserved as is.</p>
        <?php endif; ?>

        <?php foreach ($blocks as $block): ?>
            <article class="story-group">
                <?php if ($block['primary'] !== null): ?>
                    <?= $renderItem($block['primary']) ?>
                <?php endif; ?>
                <?php foreach ($block['secondaries'] as $sec): ?>
                    <?= $renderSecondary($sec) ?>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <?php if ($markdown !== ''): ?>
        <section class="md-source" aria-label="Show notes Markdown source">
            <div class="md-source-head">
                <h2>Show notes (Markdown)</h2>
                <button type="button" id="btn-copy-md">Copy</button>
            </div>
            <pre class="md-pre" id="md-source"><?= esc($markdown) ?></pre>
        </section>
    <?php endif; ?>

    <p class="arc-hint"><a class="arc-back" href="index.php">Back to the prep view</a></p>
</main>

<script>
// M11 — one-line copy of the archived Markdown (clipboard API, legacy fallback).
(function () {
    var btn = document.getElementById('btn-copy-md');
    if (!btn) { return; }
    var source = document.getElementById('md-source');

    function flash(ok) {
        btn.textContent = ok ? 'Copied!' : 'Copy failed';
        setTimeout(function () { btn.textContent = 'Copy'; }, 2000);
    }

    function legacy() {
        var ta = document.createElement('textarea');
        ta.value = source.innerText;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        flash(ok);
    }

    btn.addEventListener('click', function () {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(source.innerText).then(
                function () { flash(true); },
                function () { legacy(); }
            );
        } else {
            legacy();
        }
    });
})();
</script>

</body>
</html>
