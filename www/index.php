<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/Database.php';

$config = require __DIR__ . '/../etc/config.php';
$db     = Database::getInstance();

$state = [
    'episode' => $db->getEpisode(),
    'items'   => $db->getItems(),
    'config'  => [
        'show_title'  => $config['show_title'],
        'show_tagline' => $config['show_tagline'],
        'sections'    => $config['sections'],
    ],
];

$pageTitle        = htmlspecialchars($config['show_title'], ENT_QUOTES, 'UTF-8');
$vulnLabel        = htmlspecialchars($config['sections']['vulnerability'], ENT_QUOTES, 'UTF-8');
$newsLabel        = htmlspecialchars($config['sections']['news'], ENT_QUOTES, 'UTF-8');
$epWeek           = (int) ($state['episode']['week_number'] ?? 1);
$epYear           = (int) ($state['episode']['year'] ?? date('Y'));
$epYoutube        = htmlspecialchars($state['episode']['youtube_url'] ?? '', ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<header>
    <h1><?= $pageTitle ?></h1>
    <span id="status-indicator" aria-live="polite" aria-label="Save status"></span>
</header>

<main>
    <section id="episode-meta" aria-label="Episode metadata">
        <div class="episode-meta-fields">
            <label for="ep-week">Week</label>
            <input type="number" id="ep-week" name="week_number" min="1" max="53"
                   value="<?= $epWeek ?>" aria-label="Week number">

            <label for="ep-year">Year</label>
            <input type="number" id="ep-year" name="year" min="2020"
                   value="<?= $epYear ?>" aria-label="Year">

            <label for="ep-youtube">YouTube URL</label>
            <input type="url" id="ep-youtube" name="youtube_url"
                   value="<?= $epYoutube ?>" placeholder="https://www.youtube.com/watch?v=..." aria-label="YouTube URL">
        </div>
    </section>

    <div id="workspace">
        <aside id="add-item-panel" aria-label="Add item">
            <h2>Add Item</h2>

            <div class="form-group">
                <label for="add-url">URL</label>
                <input type="url" id="add-url" name="url" placeholder="https://example.com/article">
            </div>

            <div class="form-group">
                <label for="add-section">Section</label>
                <select id="add-section" name="section">
                    <?php foreach ($config['sections'] as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="button" id="btn-fetch" disabled>Fetch Metadata</button>

            <div id="scraped-fields">
                <div class="form-group">
                    <label for="add-title">Title</label>
                    <input type="text" id="add-title" name="title" placeholder="Article title">
                </div>

                <div class="form-group">
                    <label for="add-author-name">Author name</label>
                    <input type="text" id="add-author-name" name="author_name" placeholder="Author name">
                </div>

                <div class="form-group">
                    <label for="add-author-url">Author URL</label>
                    <input type="url" id="add-author-url" name="author_url" placeholder="https://example.com/author">
                </div>
            </div>

            <button type="button" id="btn-add" disabled>Add Item</button>
        </aside>

        <div id="item-lists">
            <section id="vulnerability-list" aria-label="<?= $vulnLabel ?>">
                <h2><?= $vulnLabel ?></h2>
            </section>

            <section id="news-list" aria-label="<?= $newsLabel ?>">
                <h2><?= $newsLabel ?></h2>
            </section>
        </div>
    </div>

    <section id="output-panel" aria-label="Generated Markdown output" hidden>
        <h2>Generated Markdown</h2>
        <textarea readonly id="output-markdown" aria-label="Generated Markdown content" rows="20"></textarea>
        <button type="button" id="btn-copy">Copy</button>
    </section>
</main>

<footer>
    <button type="button" id="btn-new-episode">New Episode</button>
    <span id="footer-status" aria-live="polite"></span>
</footer>

<script>
const INITIAL_STATE = <?= json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="js/app.js"></script>

</body>
</html>
