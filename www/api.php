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

// -------------------------------------------------------------------------
// API token gate
// -------------------------------------------------------------------------
//
// When $config['api_token'] is set (non-empty), every request to this endpoint
// must carry a matching X-API-Token header. The in-app UI is updated separately
// (M3) to present the token; anything that doesn't know it gets a 401. When
// the token is an empty string (the shipped default in config.php.example)
// the gate is a no-op and the existing LAN-only behaviour is preserved
// unchanged.
//
// We compare the hash of the presented token against the hash of the
// configured one with hash_equals so the comparison is constant-time and the
// response body never contains either value.
//
// 401 is the deliberate code here: it tells the caller the request was
// understood but is not authorised — distinct from the 400 / 405 / 50x codes
// already in use by the action handlers.

$expectedToken = (string) ($config['api_token'] ?? '');
$presentedToken = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');

if ($expectedToken !== '' && !hash_equals(
    hash('sha256', $expectedToken),
    hash('sha256', $presentedToken)
)) {
    http_response_code(401);
    header('WWW-Authenticate: Token');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Decode the JSON request body.
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// Action handlers (jsonError/jsonSuccess + the handle*() functions) live in
// api_handlers.php, kept out of this entry point so it stays short and so a
// future GET read endpoint can reuse the same set of helpers. They expect
// $config, $db, $scraper, $body and $action to already be in scope. Loaded
// here, after the token gate, so an unauthorised request never pulls in the
// handler/scraper dependency chain.
require_once __DIR__ . '/api_handlers.php';

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
