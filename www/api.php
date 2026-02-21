<?php

declare(strict_types=1);

// All responses from this file are JSON.
header('Content-Type: application/json');

// -------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------

// Reject non-POST requests immediately.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$config = require __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/../include/Database.php';
require_once __DIR__ . '/../include/Scraper.php';
require_once __DIR__ . '/../include/Generator.php';

$db      = Database::getInstance();
$scraper = new Scraper($config);

// Decode the JSON request body.
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// -------------------------------------------------------------------------
// Action dispatch
// -------------------------------------------------------------------------

try {
    $response = match ($action) {
        'update_episode'        => handleUpdateEpisode($body, $db),
        'scrape_url'            => handleScrapeUrl($body, $scraper),
        'add_item'              => handleAddItem($body, $db),
        'update_item'           => handleUpdateItem($body, $db),
        'delete_item'           => handleDeleteItem($body, $db),
        'reorder_items'         => handleReorderItems($body, $db),
        'reset_episode'         => handleResetEpisode($db),
        'get_author_suggestions' => handleGetAuthorSuggestions($body, $db),
        'generate_markdown'     => handleGenerateMarkdown($db, $config),
        default                 => jsonError('Unknown action', 400),
    };
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

echo json_encode($response);

// -------------------------------------------------------------------------
// Helper functions
// -------------------------------------------------------------------------

/** Returns a JSON-serialisable error array and sets the HTTP response code. */
function jsonError(string $message, int $code = 400): array
{
    http_response_code($code);
    return ['success' => false, 'error' => $message];
}

/** Returns a JSON-serialisable success array. */
function jsonSuccess(array $data): array
{
    return ['success' => true, 'data' => $data];
}

// -------------------------------------------------------------------------
// Action handlers
// -------------------------------------------------------------------------

function handleUpdateEpisode(array $body, Database $db): array
{
    $week       = filter_var($body['week_number'] ?? null, FILTER_VALIDATE_INT);
    $year       = filter_var($body['year'] ?? null, FILTER_VALIDATE_INT);
    $youtubeUrl = $body['youtube_url'] ?? null;

    if ($week === false || $week === null || $week < 1 || $week > 53) {
        return jsonError('week_number must be an integer between 1 and 53');
    }
    if ($year === false || $year === null || $year < 2020) {
        return jsonError('year must be an integer >= 2020');
    }
    if (!is_string($youtubeUrl)) {
        return jsonError('youtube_url must be a string');
    }

    $episode = $db->updateEpisode($week, $year, $youtubeUrl);

    return jsonSuccess(['episode' => $episode]);
}

function handleScrapeUrl(array $body, Scraper $scraper): array
{
    $url = $body['url'] ?? '';

    if (!is_string($url) || $url === '') {
        return jsonError('url is required');
    }

    $result = $scraper->scrape($url);

    // Hard failure: SSRF validation rejected the URL, or the HTTP fetch itself
    // failed (timeout, cURL error, HTTP 4xx/5xx). The fetch_failed flag is set
    // by Scraper in both of these cases, making the distinction unambiguous.
    if ($result['fetch_failed']) {
        return jsonError($result['error']);
    }

    return jsonSuccess([
        'title'        => $result['title'],
        'author_name'  => $result['author_name'],
        'author_url'   => $result['author_url'],
        'domain'       => $result['domain'],
        'scrape_error' => $result['error'],
    ]);
}

function handleAddItem(array $body, Database $db): array
{
    $allowedSections = ['vulnerability', 'news'];
    $section    = $body['section'] ?? '';
    $url        = $body['url'] ?? '';
    $title      = $body['title'] ?? '';
    $authorName = $body['author_name'] ?? '';
    $authorUrl  = $body['author_url'] ?? '';

    if (!in_array($section, $allowedSections, true)) {
        return jsonError('section must be "vulnerability" or "news"');
    }
    if (!is_string($url) || $url === '') {
        return jsonError('url is required');
    }

    $item = $db->addItem($section, $url, (string) $title, (string) $authorName, (string) $authorUrl);

    if ($authorName !== '') {
        $domain = extractDomain($url);
        if ($domain !== '') {
            $db->upsertAuthorHistory($domain, (string) $authorName, (string) $authorUrl);
        }
    }

    return jsonSuccess(['item' => $item]);
}

function handleUpdateItem(array $body, Database $db): array
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if ($id === false || $id === null || $id <= 0) {
        return jsonError('id must be a positive integer');
    }

    $url        = $body['url'] ?? '';
    $title      = $body['title'] ?? '';
    $authorName = $body['author_name'] ?? '';
    $authorUrl  = $body['author_url'] ?? '';

    $item = $db->updateItem($id, (string) $url, (string) $title, (string) $authorName, (string) $authorUrl);

    if ($item === false) {
        return jsonError('Item not found', 400);
    }

    if ($authorName !== '' && $url !== '') {
        $domain = extractDomain((string) $url);
        if ($domain !== '') {
            $db->upsertAuthorHistory($domain, (string) $authorName, (string) $authorUrl);
        }
    }

    return jsonSuccess(['item' => $item]);
}

function handleDeleteItem(array $body, Database $db): array
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if ($id === false || $id === null || $id <= 0) {
        return jsonError('id must be a positive integer');
    }

    $deleted = $db->deleteItem($id);

    if (!$deleted) {
        return jsonError('Item not found', 400);
    }

    return jsonSuccess(['deleted_id' => $id]);
}

function handleReorderItems(array $body, Database $db): array
{
    $allowedSections = ['vulnerability', 'news'];
    $section = $body['section'] ?? '';
    $order   = $body['order'] ?? null;

    if (!in_array($section, $allowedSections, true)) {
        return jsonError('section must be "vulnerability" or "news"');
    }
    if (!is_array($order) || count($order) === 0) {
        return jsonError('order must be a non-empty array of integers');
    }

    // Validate that all provided IDs are integers.
    $orderedIds = array_map('intval', $order);
    foreach ($orderedIds as $itemId) {
        if ($itemId <= 0) {
            return jsonError('order contains an invalid item ID');
        }
    }

    // Verify all IDs belong to the given section.
    $items = $db->getItems();
    $sectionIds = array_map('intval', array_column($items[$section], 'id'));
    foreach ($orderedIds as $itemId) {
        if (!in_array($itemId, $sectionIds, true)) {
            return jsonError("Item ID $itemId does not belong to section \"$section\"", 400);
        }
    }

    // Verify the submitted list is complete — a partial list would create sort_order collisions.
    if (count($orderedIds) !== count($sectionIds)) {
        return jsonError('order must contain every item in the section', 400);
    }

    $db->reorderItems($section, $orderedIds);

    return jsonSuccess(['section' => $section, 'order' => $orderedIds]);
}

function handleResetEpisode(Database $db): array
{
    $episode = $db->resetEpisode();
    $items   = $db->getItems();

    return jsonSuccess(['episode' => $episode, 'items' => $items]);
}

function handleGetAuthorSuggestions(array $body, Database $db): array
{
    $domain = $body['domain'] ?? '';
    $query  = $body['query'] ?? '';

    if (!is_string($domain)) {
        $domain = '';
    }
    if (!is_string($query)) {
        $query = '';
    }

    $suggestions = $db->getAuthorSuggestions($domain, $query);

    return jsonSuccess($suggestions);
}

function handleGenerateMarkdown(Database $db, array $config): array
{
    $episode   = $db->getEpisode();
    $items     = $db->getItems();
    $generator = new Generator();
    $markdown  = $generator->generate($episode, $items, $config);

    $data = ['markdown' => $markdown];

    if ($episode['youtube_url'] === '') {
        $data['warnings'] = ['YouTube URL is empty — the embed line will be blank in the output'];
    }

    return jsonSuccess($data);
}

// -------------------------------------------------------------------------
// Shared utilities
// -------------------------------------------------------------------------

/**
 * Extracts the bare hostname from a URL, stripping the www. prefix.
 *
 * Delegates to Scraper::extractDomain() so the logic lives in exactly one place.
 */
function extractDomain(string $url): string
{
    return Scraper::extractDomain($url);
}
