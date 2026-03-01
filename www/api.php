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
        'update_episode'         => handleUpdateEpisode($body, $db),
        'scrape_url'             => handleScrapeUrl($body, $scraper, $db),
        'add_item'               => handleAddItem($body, $db),
        'update_item'            => handleUpdateItem($body, $db),
        'delete_item'            => handleDeleteItem($body, $db),
        'reorder_items'          => handleReorderItems($body, $db),
        'reset_episode'          => handleResetEpisode($db),
        'get_author_suggestions' => handleGetAuthorSuggestions($body, $db),
        'generate_markdown'      => handleGenerateMarkdown($db, $config),
        'update_talking_points'  => handleUpdateTalkingPoints($body, $db),
        'nest_item'              => handleNestItem($body, $db),
        'extract_item'           => handleExtractItem($body, $db),
        'reorder_group'          => handleReorderGroup($body, $db),
        default                  => jsonError('Unknown action', 400),
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

function handleScrapeUrl(array $body, Scraper $scraper, Database $db): array
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

    // Enrich: if the page metadata gave us an author name but no profile URL,
    // look the URL up from our history so the user doesn't have to type it in.
    if ($result['author_name'] !== '' && $result['author_url'] === '' && $result['domain'] !== '') {
        $storedUrl = $db->getAuthorUrl($result['domain'], $result['author_name']);
        if ($storedUrl !== '') {
            $result['author_url'] = $storedUrl;
        }
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
    $items     = $db->getItemsFlat();
    $generator = new MarkdownGenerator();
    $markdown  = $generator->generate($episode, $items, $config);

    $data = ['markdown' => $markdown];

    if ($episode['youtube_url'] === '') {
        $data['warnings'] = ['YouTube URL is empty — the embed line will be blank in the output'];
    }

    return jsonSuccess($data);
}

function handleUpdateTalkingPoints(array $body, Database $db): array
{
    $itemId = filter_var($body['itemId'] ?? null, FILTER_VALIDATE_INT);

    if ($itemId === false || $itemId === null || $itemId <= 0) {
        return jsonError('itemId must be a positive integer');
    }
    if (!array_key_exists('talkingPoints', $body) || !is_string($body['talkingPoints'])) {
        return jsonError('talkingPoints must be a string');
    }

    $talkingPoints = $body['talkingPoints'];

    try {
        $item = $db->updateTalkingPoints($itemId, $talkingPoints);
    } catch (\InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'not found')) {
            return jsonError('Item not found', 404);
        }
        return jsonError('Talking points can only be set on primary or standalone items.');
    }

    return jsonSuccess(['item' => $item]);
}

function handleNestItem(array $body, Database $db): array
{
    $itemId   = filter_var($body['itemId']   ?? null, FILTER_VALIDATE_INT);
    $targetId = filter_var($body['targetId'] ?? null, FILTER_VALIDATE_INT);

    if ($itemId === false || $itemId === null || $itemId <= 0) {
        return jsonError('itemId must be a positive integer');
    }
    if ($targetId === false || $targetId === null || $targetId <= 0) {
        return jsonError('targetId must be a positive integer');
    }

    // Guard: self-nesting — reject before any DB work.
    if ($itemId === $targetId) {
        return jsonError('itemId and targetId must be different items');
    }

    $transferTalkingPoints = isset($body['transferTalkingPoints'])
        ? (bool) $body['transferTalkingPoints']
        : false;

    // Fetch all items once so we can locate both the source and the target for
    // pre-flight validation without an extra round-trip later.
    $allItems   = $db->getItemsFlat();
    $sourceItem = null;
    $targetItem = null;
    foreach (array_merge($allItems['vulnerability'] ?? [], $allItems['news'] ?? []) as $candidate) {
        if ((int) $candidate['id'] === $itemId) {
            $sourceItem = $candidate;
        }
        if ((int) $candidate['id'] === $targetId) {
            $targetItem = $candidate;
        }
        if ($sourceItem !== null && $targetItem !== null) {
            break;
        }
    }

    if ($sourceItem === null) {
        return jsonError('Item not found', 400);
    }

    // Guard: only news section items may be nested (spec §5.2).
    if ($sourceItem['section'] !== 'news') {
        return jsonError('Only news items can be nested into story groups');
    }

    // Guard: circular reference — target must not already be a secondary of itemId.
    if (
        $targetItem !== null
        && $targetItem['parent_id'] !== null
        && (int) $targetItem['parent_id'] === $itemId
    ) {
        return jsonError('Cannot nest an item under its own secondary (circular reference)');
    }

    $existingTalkingPoints = $sourceItem['talking_points'] ?? '';

    if ($existingTalkingPoints !== '' && !$transferTalkingPoints) {
        http_response_code(409);
        return [
            'success'              => false,
            'requiresConfirmation' => true,
            // Spec §5.2: this copy is shown directly in the frontend confirmation dialog.
            'warning'              => 'This item has recording notes. If you continue, those notes will be '
                                    . 'transferred to the new primary link. The item will lose its notes.',
            'fromItemId'           => $itemId,
            'toItemId'             => $targetId,
        ];
    }

    // Pass the talking points into nestItem() so clearing the source, performing
    // the nest, and writing to the target all happen inside a single transaction.
    // Passing null means no talking-points work is done.
    $talkingPointsToTransfer = ($transferTalkingPoints && $existingTalkingPoints !== '')
        ? $existingTalkingPoints
        : null;

    try {
        $db->nestItem($itemId, $targetId, $talkingPointsToTransfer);
    } catch (\InvalidArgumentException $e) {
        return jsonError($e->getMessage());
    }

    $newsItems = $db->getItemsFlat()['news'] ?? [];

    return jsonSuccess(['items' => ['news' => $newsItems]]);
}

function handleExtractItem(array $body, Database $db): array
{
    $itemId = filter_var($body['itemId'] ?? null, FILTER_VALIDATE_INT);

    if ($itemId === false || $itemId === null || $itemId <= 0) {
        return jsonError('itemId must be a positive integer');
    }

    $newTopLevelOrder = $body['newTopLevelOrder'] ?? null;

    if (!is_array($newTopLevelOrder) || count($newTopLevelOrder) === 0) {
        return jsonError('newTopLevelOrder must be a non-empty array of integers');
    }

    $orderedIds = array_map('intval', $newTopLevelOrder);

    if (!in_array($itemId, $orderedIds, true)) {
        return jsonError('newTopLevelOrder must contain itemId');
    }

    // Guard: no duplicate IDs (spec §5.3).
    if (count($orderedIds) !== count(array_unique($orderedIds))) {
        return jsonError('newTopLevelOrder must not contain duplicate IDs');
    }

    // Guard: completeness — the list must be a complete permutation of the
    // post-extraction top-level set (all current top-level news IDs plus itemId,
    // which is being promoted from secondary). Mirrors the check in handleReorderItems.
    $allItems = $db->getItemsFlat();
    $currentTopLevelIds = array_map(
        'intval',
        array_column(
            array_filter($allItems['news'] ?? [], fn($n) => $n['parent_id'] === null),
            'id'
        )
    );
    // itemId is currently a secondary; add it to the expected post-extraction set.
    $expectedIds  = $currentTopLevelIds;
    $expectedIds[] = $itemId;
    $expectedIds  = array_values(array_unique($expectedIds));
    sort($expectedIds);
    $submittedSorted = $orderedIds;
    sort($submittedSorted);
    if ($submittedSorted !== $expectedIds) {
        return jsonError('newTopLevelOrder must contain every top-level news item ID after extraction', 400);
    }

    try {
        $db->extractItem($itemId, $orderedIds);
    } catch (\InvalidArgumentException $e) {
        return jsonError($e->getMessage());
    }

    $newsItems = $db->getItemsFlat()['news'] ?? [];

    return jsonSuccess(['items' => ['news' => $newsItems]]);
}

function handleReorderGroup(array $body, Database $db): array
{
    $primaryId = filter_var($body['primaryId'] ?? null, FILTER_VALIDATE_INT);

    if ($primaryId === false || $primaryId === null || $primaryId <= 0) {
        return jsonError('primaryId must be a positive integer');
    }

    $newSecondaryOrder = $body['newSecondaryOrder'] ?? null;

    if (!is_array($newSecondaryOrder) || count($newSecondaryOrder) === 0) {
        return jsonError('newSecondaryOrder must be a non-empty array of integers');
    }

    $orderedIds = array_map('intval', $newSecondaryOrder);

    // Guard: no duplicate IDs (spec §5.4).
    if (count($orderedIds) !== count(array_unique($orderedIds))) {
        return jsonError('newSecondaryOrder must not contain duplicate IDs');
    }

    try {
        $db->reorderGroupItems($primaryId, $orderedIds);
    } catch (\InvalidArgumentException $e) {
        return jsonError($e->getMessage());
    }

    $newsItems = $db->getItemsFlat()['news'] ?? [];

    return jsonSuccess(['items' => ['news' => $newsItems]]);
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
