<?php

declare(strict_types=1);

// All responses from this file are JSON.
header('Content-Type: application/json');

// -------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------

// Mutations are POST-only; the read-only actions (get_episode, list_items)
// are GET-only and read their parameters from the query string.
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST' && $method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$config = require __DIR__ . '/../etc/config.php';
require_once __DIR__ . '/../include/Database.php';

// Read actions need only Database; the mutation path additionally requires
// the Scraper and Generator classes (pulled in the POST branch below), so
// read requests never load the scraper chain.
$db = Database::getInstance();

// -------------------------------------------------------------------------
// API token gate
// -------------------------------------------------------------------------
//
// When $config['api_token'] is set (non-empty), every request to this endpoint
// must carry a matching X-API-Token header. The token gate applies to writes
// and reads alike, so automation that sets a token must send X-API-Token on
// its GET requests as well; in the shipped default (empty token) the gate is
// a no-op and the existing LAN-only behaviour is preserved unchanged.
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

// Read-only actions. GET-only, parameters from the query string. No
// mutations and no scraper — a GET request must never leave the database
// read path, which keeps reads uniformly cheap and side-effect free.
$READ_ACTIONS = ['get_episode', 'list_items', 'list_candidates'];

// Action handlers (jsonError/jsonSuccess + the handle*() functions) live in
// api_handlers.php, kept out of this entry point so it stays short. They
// expect $config, $db, $scraper, $body and $action to already be in scope.
// Loaded here, after the token gate, so an unauthorised request never pulls
// in the handler chain.
require_once __DIR__ . '/api_handlers.php';

// -------------------------------------------------------------------------
// Action dispatch
// -------------------------------------------------------------------------

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');

    if (!in_array($action, $READ_ACTIONS, true)) {
        echo json_encode(jsonError('Unknown action', 400));
        exit;
    }

    try {
        $response = match ($action) {
            'get_episode'     => handleGetEpisode($db),
            'list_items'      => handleListItems($db),
            'list_candidates' => handleListCandidates($db),
        };
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    echo json_encode($response);
    exit;
}

// POST path: mutations only.
if (in_array((string) ($_GET['action'] ?? ''), $READ_ACTIONS, true)) {
    echo json_encode(jsonError('Read-only action — use GET', 405));
    exit;
}

// POST path: mutations only. Mutation handlers may reference the Scraper and
// MarkdownGenerator classes, so their definitions are pulled in here on the
// mutation path only — read requests never load the scraper chain.
require_once __DIR__ . '/../include/Scraper.php';
require_once __DIR__ . '/../include/Generator.php';

$scraper = new Scraper($config);
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// Reads travel as GET (query-string action). If one arrives in a POST body
// it is the wrong method for the action — say so explicitly rather than
// reporting it as an unknown mutation.
if (in_array((string) $action, $READ_ACTIONS, true)) {
    echo json_encode(jsonError('Read-only action — use GET', 405));
    exit;
}

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
        // M3 — candidate pool (writes).
        'push_candidates'        => handlePushCandidates($body, $db),
        'select_candidate'       => handleSelectCandidate($body, $db),
        'reject_candidate'       => handleRejectCandidate($body, $db),
        // M4 — research context.
        'update_item_context'    => handleUpdateItemContext($body, $db),
        default                  => jsonError('Unknown action', 400),
    };
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

echo json_encode($response);
