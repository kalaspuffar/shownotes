<?php

declare(strict_types=1);

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

/**
 * GET get_episode
 *
 * Returns the single episode row. Reads only — no parameters.
 */
function handleGetEpisode(Database $db): array
{
    return jsonSuccess(['episode' => $db->getEpisode()]);
}

/**
 * GET list_items[&section=vulnerability|news]
 *
 * Returns all items grouped by section, or a single section's list when the
 * `section` parameter names one. The `section` parameter is validated against
 * the same allow-list used by writes elsewhere in this file.
 */
function handleListItems(Database $db): array
{
    $rawSection = (string) ($_GET['section'] ?? '');

    if ($rawSection !== '') {
        $allowed = ['vulnerability', 'news'];

        if (!in_array($rawSection, $allowed, true)) {
            return jsonError('section must be "vulnerability" or "news"');
        }

        return jsonSuccess(['items' => $db->getItems()[$rawSection]]);
    }

    return jsonSuccess(['items' => $db->getItems()]);
}

/**
 * POST push_candidates
 *
 * Automation's entry point. Pushes a batch of candidates into the pool in
 * one call. Each entry must carry a valid URL (http/https); everything else
 * is optional and defaults to empty. Re-pushing an existing URL refreshes
 * its metadata and re-offers it (status → 'pending'), so a weekly cron can
 * re-offer the same story without piling up duplicates.
 *
 * Batch semantics: entries are upserted one by one; a single invalid entry
 * does not abort the batch, it is reported in `errors` while the valid
 * ones still land. (The batch is per-entry, not transactional — partial
 * success with a precise error list is more useful to automation than an
 * all-or-nothing failure.)
 */
function handlePushCandidates(array $body, Database $db): array
{
    $rawCandidates = $body['candidates'] ?? [];
    $source        = is_string($body['source'] ?? null) ? mb_substr(trim($body['source']), 0, 64) : 'manual';

    if (!is_array($rawCandidates) || $rawCandidates === []) {
        return jsonError('candidates must be a non-empty array of {url, ...} entries');
    }

    $pushed = [];
    $errors = [];

    foreach ($rawCandidates as $i => $raw) {
        if (!is_array($raw)) {
            $errors[] = "candidates[$i]: must be an object";
            continue;
        }

        $url = (string) ($raw['url'] ?? '');
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        if ($url === '' || $host === null || !in_array($scheme, ['http', 'https'], true)) {
            $errors[] = "candidates[$i].url: must be an absolute http(s) URL";
            continue;
        }

        $section    = (string) ($raw['section'] ?? 'news');
        $title      = (string) ($raw['title'] ?? '');
        $authorName = (string) ($raw['author_name'] ?? '');
        $authorUrl  = (string) ($raw['author_url'] ?? '');
        $notes      = (string) ($raw['notes'] ?? '');

        // M6 — corroborating articles: [{url, title, author_name, author_url}].
        // Only array entries carry a non-empty http(s) url; everything else is
        // dropped, so a bad record can never poison the group on selection.
        $corroborations = null;
        if (isset($raw['corroborations']) && is_array($raw['corroborations'])) {
            $clean = [];
            foreach ($raw['corroborations'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $eu = trim((string) ($entry['url'] ?? ''));
                if ($eu === '' || !preg_match('#^https?://#i', $eu)) {
                    continue;
                }
                $clean[] = [
                    'url'         => $eu,
                    'title'       => (string) ($entry['title'] ?? ''),
                    'author_name' => (string) ($entry['author_name'] ?? ''),
                    'author_url'  => (string) ($entry['author_url'] ?? ''),
                ];
            }
            $corroborations = $clean !== [] ? $clean : null;
        }

        $row = $db->upsertCandidate($url, $title, $authorName, $authorUrl, $source, $notes, $section, $corroborations);

        // upsertCandidate returns null only if the row vanished after insert
        // (not possible in this process); keep the call site honest either way.
        if ($row !== null) {
            $pushed[] = $row;
        }
    }

    $data = ['count' => count($pushed), 'candidates' => $pushed];

    if ($errors !== []) {
        // Partial success: valid entries landed, invalid ones are listed.
        $data['errors'] = $errors;
    }

    return jsonSuccess($data);
}

/**
 * M6 — scrapes a URL with the app's own Scraper and returns the attribution
 * fields it found. Any fetch failure yields empty strings instead of
 * errors: enrichment is best-effort and must never fail selection.
 */
function enrichUrlAttribution(string $url, Scraper $scraper, Database $db): array
{
    $out = ['title' => '', 'author_name' => '', 'author_url' => ''];
    try {
        $result = $scraper->scrape($url);
    } catch (\Throwable $e) {
        return $out;
    }
    if ($result['fetch_failed']) {
        return $out;
    }
    $out['title']       = (string) ($result['title'] ?? '');
    $out['author_name'] = (string) ($result['author_name'] ?? '');
    $out['author_url']  = (string) ($result['author_url'] ?? '');

    // Same author-history enrichment scrape_url uses: known profile URL for
    // a name we've already stored.
    if ($out['author_name'] !== '' && $out['author_url'] === '') {
        $domain = extractDomain($url);
        if ($domain !== '') {
            $stored = $db->getAuthorUrl($domain, $out['author_name']);
            if ($stored !== '') {
                $out['author_url'] = $stored;
            }
        }
    }
    return $out;
}

/**
 * POST select_candidate
 *
 * Promotes one candidate into the episode. `url` is required; `section`
 * is optional and defaults to the candidate's own section (falls back to
 * 'news'). The promotion reuses Database::addItem(), so the new item is
 * identical to one added from the UI.
 *
 * M6 enrichment, in order, all best-effort:
 *   1. Primary item author/title: the app's Scraper fills whatever the
 *      candidate row is missing (attribution for the reporter/journalist).
 *   2. Corroborating articles: each stored corroborator URL is scraped for
 *      its own title/author and added as a secondary below the primary
 *      (news section only), so the generated show notes carry every source.
 * The response carries the full story group (`group`) for UI re-render.
 */
function handleSelectCandidate(array $body, Scraper $scraper, Database $db): array
{
    $url = (string) ($body['url'] ?? '');

    if ($url === '') {
        return jsonError('url is required');
    }

    $candidate = $db->getCandidateByUrl($url);

    if ($candidate === null) {
        return jsonError('No candidate with that url', 404);
    }

    $rawSection = (string) ($body['section'] ?? $candidate['section']);
    if (!in_array($rawSection, ['vulnerability', 'news'], true)) {
        return jsonError('section must be "vulnerability" or "news"');
    }

    // 1 — enrich the primary row (candidate + its future item) with scraped
    // attribution before anything is written.
    $title       = (string) $candidate['title'];
    $authorName  = (string) $candidate['author_name'];
    $authorUrl   = (string) $candidate['author_url'];

    $needsScrape = $title === '' || $authorName === '' || $authorUrl === '';
    if ($needsScrape) {
        $enriched = enrichUrlAttribution((string) $candidate['url'], $scraper, $db);
        if ($title === '' && $enriched['title'] !== '') {
            $title = $enriched['title'];
        }
        if ($authorName === '' && $enriched['author_name'] !== '') {
            $authorName = $enriched['author_name'];
        }
        if ($authorUrl === '' && $enriched['author_url'] !== '') {
            $authorUrl = $enriched['author_url'];
        }
    }

    $result = $db->selectCandidate(
        (string) $candidate['url'],
        $rawSection,
        $title,
        $authorName,
        $authorUrl
    );

    $item = $result['item'];

    // Author-history enrichment for the primary (same as add_item).
    if ($authorName !== '') {
        $domain = extractDomain((string) $candidate['url']);
        if ($domain !== '') {
            $db->upsertAuthorHistory($domain, $authorName, $authorUrl);
        }
    }

    // 2 — corroborating articles as secondaries (news groups only).
    $group = [];
    if ($rawSection === 'news') {
        $corrRaw = (string) ($candidate['corroborations'] ?? '');
        $corr = $corrRaw !== '' ? (json_decode($corrRaw, true) ?: []) : [];

        $position = 0;
        foreach ($corr as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $corrUrl = trim((string) ($entry['url'] ?? ''));
            if ($corrUrl === '' || $corrUrl === (string) $candidate['url']) {
                continue; // never self-nest
            }
            if (!$db->itemUrlExists($corrUrl)) {
                $corrTitle   = trim((string) ($entry['title'] ?? ''));
                $corrAuthor  = trim((string) ($entry['author_name'] ?? ''));
                $corrProfile = trim((string) ($entry['author_url'] ?? ''));

                if ($corrTitle === '' || $corrAuthor === '' || $corrProfile === '') {
                    $enriched = enrichUrlAttribution($corrUrl, $scraper, $db);
                    if ($corrTitle === '' && $enriched['title'] !== '') {
                        $corrTitle = $enriched['title'];
                    }
                    if ($corrAuthor === '' && $enriched['author_name'] !== '') {
                        $corrAuthor = $enriched['author_name'];
                    }
                    if ($corrProfile === '' && $enriched['author_url'] !== '') {
                        $corrProfile = $enriched['author_url'];
                    }
                }

                try {
                    $sec = $db->addSecondary($item['id'], $corrUrl, $corrTitle, $corrAuthor, $corrProfile);
                } catch (\Throwable $e) {
                    $sec = null; // one bad corroborator must not sink the selection
                }
                if ($sec !== null) {
                    $group[] = $sec;
                    // Attribution learned here feeds future suggestions.
                    if ($corrAuthor !== '') {
                        $corrDomain = extractDomain($corrUrl);
                        if ($corrDomain !== '') {
                            $db->upsertAuthorHistory($corrDomain, $corrAuthor, $corrProfile);
                        }
                    }
                }
            }
            // else: a corroborator already in the episode — leave it where
            // Daniel put it; no re-parenting on re-selection.
            $position++;
        }
    }

    // Full group for the UI to splice into state: primary first, then its
    // secondaries in insertion order. Reading from the DB (not the loop's
    // $group only) also covers the dedupe path, where corroborators already
    // exist as rows and were not re-created this call.
    if ($rawSection === 'news') {
        $flat = $db->getItemsFlat()['news'] ?? [];
        $children = array_values(array_filter($flat,
            fn($i) => (int) ($i['parent_id'] ?? 0) === (int) $item['id']));
        $group = array_merge([$item], $children);
    }

    return jsonSuccess([
        'candidate' => $result['candidate'],
        'item'      => $item,
        'group'     => $group,
    ]);
}

/**
 * POST unselect_candidate
 *
 * M6 — reverses a selection: the promoted item (and its corroborating
 * secondaries, if any) is removed from the episode and the candidate row
 * is re-offered ('pending'), so it reappears in the pool. This is the
 * undo for a misclicked News/Vulnerability button.
 */
function handleUnselectCandidate(array $body, Database $db): array
{
    $url = (string) ($body['url'] ?? '');

    if ($url === '') {
        return jsonError('url is required');
    }

    $candidate = $db->getCandidateByUrl($url);

    if ($candidate === null) {
        return jsonError('No candidate with that url', 404);
    }

    if ($candidate['status'] !== 'selected') {
        return jsonError('Candidate is not selected', 400);
    }

    $reverted = $db->unselectCandidate((string) $candidate['url']);

    if ($reverted === false) {
        return jsonError('No promoted item to remove for this candidate', 400);
    }

    return jsonSuccess([
        'candidate' => $reverted,
        'episode'   => $db->getEpisode(),
        'items'     => $db->getItems(),
        'candidates' => $db->getCandidates('pending'),
    ]);
}

/**
 * POST reject_candidate
 *
 * Marks a candidate rejected. The row is kept (not deleted) for provenance;
 * a later push of the same URL re-offers it.
 */
function handleRejectCandidate(array $body, Database $db): array
{
    $url = (string) ($body['url'] ?? '');

    if ($url === '') {
        return jsonError('url is required');
    }

    $candidate = $db->rejectCandidate($url);

    if ($candidate === null) {
        return jsonError('No candidate with that url', 404);
    }

    return jsonSuccess(['candidate' => $candidate]);
}

/**
 * GET list_candidates
 *
 * Returns the pending candidate pool, oldest push first (the order automation
 * will select from). Reads only — safe to poll.
 */
function handleListCandidates(Database $db): array
{
    return jsonSuccess(['candidates' => $db->getCandidates('pending')]);
}

/**
 * M6 — GET list_selected_candidates
 *
 * Returns candidates currently promoted (status 'selected'). Read-only;
 * powers the "Return to pool" affordance on item rows that came from the
 * pool (the UI matches by URL).
 */
function handleSelectedCandidates(Database $db): array
{
    return jsonSuccess(['selected' => $db->getCandidates('selected')]);
}

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

/**
 * POST update_item_context
 *
 * M4 — writes the research context onto an existing item. `status` and
 * `my_context` are both optional per call; the omitted one is left
 * untouched. Used by automation (research pass) so the host sees the
 * context in the app (rendered on the item row) and in the generated
 * Markdown (emitted when non-empty).
 */
function handleUpdateItemContext(array $body, Database $db): array
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if ($id === false || $id === null || $id <= 0) {
        return jsonError('id must be a positive integer');
    }

    // At least one field must be provided — an empty call tells the caller
    // what they did wrong instead of silently returning the row.
    $hasStatus     = array_key_exists('status', $body);
    $hasMy_context = array_key_exists('my_context', $body);

    if (!$hasStatus && !$hasMy_context) {
        return jsonError('at least one of "status" or "my_context" must be provided');
    }

    if ($hasStatus && !is_string($body['status'])) {
        return jsonError('status must be a string');
    }
    if ($hasMy_context && !is_string($body['my_context'])) {
        return jsonError('my_context must be a string');
    }

    $status    = $hasStatus ? (string) $body['status'] : null;
    $myContext = $hasMy_context ? (string) $body['my_context'] : null;

    $item = $db->updateItemContext($id, $status, $myContext);

    if ($item === false) {
        return jsonError('No item with that id', 404);
    }

    return jsonSuccess(['item' => $item]);
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
    $section       = $body['section'] ?? '';
    $url           = $body['url'] ?? '';
    $title         = $body['title'] ?? '';
    $authorName    = $body['author_name'] ?? '';
    $authorUrl     = $body['author_url'] ?? '';
    $rawPoints     = $body['talking_points'] ?? '';

    if (!in_array($section, $allowedSections, true)) {
        return jsonError('section must be "vulnerability" or "news"');
    }
    if (!is_string($url) || $url === '') {
        return jsonError('url is required');
    }

    // Strip empty lines and trim whitespace from talking points.
    if (is_string($rawPoints) && $rawPoints !== '') {
        $lines = array_filter(
            array_map('trim', explode("\n", $rawPoints)),
            fn(string $line) => $line !== ''
        );
        $talkingPoints = implode("\n", $lines);
    } else {
        $talkingPoints = '';
    }

    $item = $db->addItem($section, $url, (string) $title, (string) $authorName, (string) $authorUrl, $talkingPoints);

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

    // For the news section this endpoint only reorders top-level items; secondaries
    // are reordered within their group via reorder_group. Filter accordingly so the
    // membership check and the completeness count both work with the right set.
    if ($section === 'news') {
        $sectionIds = array_map(
            'intval',
            array_column(
                array_filter($items[$section], fn($item) => $item['parent_id'] === null),
                'id'
            )
        );
    } else {
        $sectionIds = array_map('intval', array_column($items[$section], 'id'));
    }

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
    $episode    = $db->resetEpisode();
    $items      = $db->getItems();
    $candidates = $db->getCandidates('pending');

    // M3: reset returns promoted candidates to the pool — the UI re-renders
    // the pool from this response so re-offered stories reappear.
    return jsonSuccess(['episode' => $episode, 'items' => $items, 'candidates' => $candidates]);
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
