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

$pageTitle = htmlspecialchars($config['show_title'], ENT_QUOTES, 'UTF-8');

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
</header>

<main>
    <section id="episode-meta" aria-label="Episode metadata">
        <p>Episode metadata fields will go here.</p>
    </section>

    <div id="workspace">
        <aside id="add-item-panel" aria-label="Add item">
            <p>Add item panel will go here.</p>
        </aside>

        <div id="item-lists">
            <section id="vulnerability-list" aria-label="<?= htmlspecialchars($config['sections']['vulnerability'], ENT_QUOTES, 'UTF-8') ?>">
                <h2><?= htmlspecialchars($config['sections']['vulnerability'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p>Vulnerability items will be rendered here.</p>
            </section>

            <section id="news-list" aria-label="<?= htmlspecialchars($config['sections']['news'], ENT_QUOTES, 'UTF-8') ?>">
                <h2><?= htmlspecialchars($config['sections']['news'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p>News items will be rendered here.</p>
            </section>
        </div>
    </div>

    <section id="output-panel" aria-label="Generated output">
        <p>Generated Markdown output will appear here.</p>
    </section>
</main>

<footer>
    <p>Episode management controls will go here.</p>
</footer>

<script>
const INITIAL_STATE = <?= json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="js/app.js"></script>

</body>
</html>
