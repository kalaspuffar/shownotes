<?php

declare(strict_types=1);

/**
 * Singleton PDO wrapper for the SQLite database.
 *
 * Initialises the schema and seeds the episode row on first connection.
 * All SQL lives here; no other file executes queries directly.
 */
class Database
{
    private static ?Database $instance = null;

    private PDO $pdo;

    private function __construct()
    {
        $config = require __DIR__ . '/../etc/config.php';

        // Fail early with a clear message rather than a cryptic PDOException.
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException(
                'The pdo_sqlite PHP extension is not installed. '
                . 'Install it with: sudo apt install php8.4-sqlite3'
            );
        }

        $this->pdo = new PDO('sqlite:' . $config['db_path'], options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // WAL mode improves write reliability; foreign keys are off by default in SQLite.
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->initSchema();
        $this->seedEpisode();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Schema initialisation
    // -------------------------------------------------------------------------

    private function initSchema(): void
    {
        // Single-row episode table; the CHECK constraint enforces id = 1 always.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS episodes (
                id          INTEGER PRIMARY KEY CHECK (id = 1),
                week_number INTEGER NOT NULL,
                year        INTEGER NOT NULL,
                youtube_url TEXT    NOT NULL DEFAULT ''
            )
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS items (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                section     TEXT    NOT NULL CHECK (section IN ('vulnerability', 'news')),
                url         TEXT    NOT NULL DEFAULT '',
                title       TEXT    NOT NULL DEFAULT '',
                author_name TEXT    NOT NULL DEFAULT '',
                author_url  TEXT    NOT NULL DEFAULT '',
                sort_order  INTEGER NOT NULL DEFAULT 0
            )
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_items_section_order
                ON items (section, sort_order)
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS author_history (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                domain       TEXT    NOT NULL,
                author_name  TEXT    NOT NULL,
                author_url   TEXT    NOT NULL DEFAULT '',
                use_count    INTEGER NOT NULL DEFAULT 1,
                last_used_at TEXT    NOT NULL,
                UNIQUE (domain, author_name, author_url)
            )
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_author_history_domain
                ON author_history (domain, use_count DESC, last_used_at DESC)
        SQL);

        // Guarded migrations: SQLite < 3.37 has no ADD COLUMN IF NOT EXISTS,
        // so we wrap each ALTER in its own try/catch and swallow the duplicate-column error.
        try {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN talking_points TEXT');
        } catch (\PDOException $e) {
            // Column already exists — safe to continue.
        }

        try {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN parent_id INTEGER REFERENCES items(id) ON DELETE SET NULL');
        } catch (\PDOException $e) {
            // Column already exists — safe to continue.
        }

        // M4 — research context. Both nullable TEXT, matching the talking_points
        // precedent; a missing/NULL value is normalised to '' via COALESCE on
        // read. `status` is a free-form workflow word (e.g. 'researched',
        // 'pending'); `my_context` holds the research block automation writes
        // so the host has talking points and provenance for the story.
        try {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN status TEXT');
        } catch (\PDOException $e) {
            // Column already exists — safe to continue.
        }

        try {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN my_context TEXT');
        } catch (\PDOException $e) {
            // Column already exists — safe to continue.
        }

        // M7 — per-story hook. A short, punchy "one line for the open" that
        // Daniel writes while researching (it lives in the research-brief
        // template under **Hook**). Promoted in the UI into the presenter
        // view (intro + "This week we cover" list) and the audience intro
        // card. Nullable so existing episodes are untouched; normalised to
        // '' via COALESCE on read, same as talking_points / my_context.
        try {
            $this->pdo->exec('ALTER TABLE items ADD COLUMN hook TEXT');
        } catch (\PDOException $e) {
            // Column already exists — safe to continue.
        }

        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_items_parent ON items (parent_id, sort_order)
        SQL);

        // M3 — candidate pool: a staging area for automation (cron, agent
        // tooling) to propose stories without touching the live episode.
        // Deliberately a separate table from items: thin weeks borrow from the
        // backlog, and a candidate can sit untouched for several weeks.
        //
        // `source` records who pushed the candidate (e.g. the cron job name)
        // for auditability and the UI provenance badge. `selected_section`
        // records which section (vulnerability|news) the candidate was
        // promoted into by select_candidate, so the same candidate URL can be
        // re-pushed later (e.g. a weekly re-offer) without losing provenance.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS candidates (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                section        TEXT    NOT NULL DEFAULT 'news' CHECK (section IN ('vulnerability', 'news')),
                url            TEXT    NOT NULL,
                title          TEXT    NOT NULL DEFAULT '',
                author_name    TEXT    NOT NULL DEFAULT '',
                author_url     TEXT    NOT NULL DEFAULT '',
                source         TEXT    NOT NULL DEFAULT 'manual',
                notes          TEXT    NOT NULL DEFAULT '',
                selected_section TEXT,
                status         TEXT    NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'rejected', 'selected')),
                pushed_at      TEXT    NOT NULL,
                UNIQUE (url)
            )
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_candidates_url ON candidates (url)
        SQL);
    }

    private function seedEpisode(): void
    {
        // M6 migration — pre-M6 candidate pools lack the new columns
        // (CREATE TABLE IF NOT EXISTS does not add columns to existing tables).
        // Note: FETCH_COLUMN index 1 = the column NAME (column 0 is cid).
        $cols = $this->pdo->query('PRAGMA table_info(candidates)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('corroborations', $cols, true)) {
            $this->pdo->exec("ALTER TABLE candidates ADD COLUMN corroborations TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('selected_item_id', $cols, true)) {
            $this->pdo->exec("ALTER TABLE candidates ADD COLUMN selected_item_id INTEGER");
        }

        // INSERT OR IGNORE is a no-op when the row already exists.
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO episodes (id, week_number, year, youtube_url)
             VALUES (1, :week, :year, \'\')'
        );
        $stmt->execute([
            ':week' => idate('W'),
            ':year' => (int) date('Y'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Read methods (used by index.php to build INITIAL_STATE)
    // -------------------------------------------------------------------------

    /** Returns the single episode row, falling back to safe defaults if the seed failed. */
    public function getEpisode(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM episodes WHERE id = 1');
        $stmt->execute();

        return $stmt->fetch() ?: [
            'id'          => 1,
            'week_number' => idate('W'),
            'year'        => (int) date('Y'),
            'youtube_url' => '',
        ];
    }

    /**
     * Returns all items grouped by section.
     *
     * Vulnerability items are ordered by sort_order. News items are ordered
     * so each primary appears before its secondaries and groups follow their
     * primary's sort_order (see fetchNewsItemsOrdered()).
     *
     * @return array{vulnerability: list<array>, news: list<array>}
     */
    public function getItems(): array
    {
        return [
            'vulnerability' => $this->fetchVulnerabilityItems(),
            'news'          => $this->fetchNewsItemsOrdered(),
        ];
    }

    /**
     * Returns all vulnerability items ordered by sort_order.
     *
     * @return list<array>
     */
    private function fetchVulnerabilityItems(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, section, url, title, author_name, author_url, sort_order,
                    COALESCE(talking_points, '') AS talking_points,
                    COALESCE(status, '') AS status,
                    COALESCE(my_context, '') AS my_context,
                    COALESCE(hook, '') AS hook,
                    parent_id
             FROM items
             WHERE section = 'vulnerability'
             ORDER BY sort_order ASC"
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Returns news items interleaved in group order: primary, then its secondaries,
     * repeated for each group in ascending primary sort_order.
     *
     * A CTE resolves each item's primary sort_order in a single query, avoiding
     * an N+1 loop over groups.
     *
     * @return list<array>
     */
    private function fetchNewsItemsOrdered(): array
    {
        $stmt = $this->pdo->prepare(
            "WITH primary_order AS (
                 SELECT id, sort_order AS primary_sort
                 FROM items
                 WHERE section = 'news' AND parent_id IS NULL
             )
             SELECT
                 i.id,
                 i.section,
                 i.url,
                 i.title,
                 i.author_name,
                 i.author_url,
                 i.sort_order,
                 COALESCE(i.talking_points, '') AS talking_points,
                 COALESCE(i.status, '') AS status,
                 COALESCE(i.my_context, '') AS my_context,
                 COALESCE(i.hook, '') AS hook,
                 i.parent_id
             FROM items i
             LEFT JOIN primary_order po ON po.id = COALESCE(i.parent_id, i.id)
             WHERE i.section = 'news'
             ORDER BY po.primary_sort ASC, i.parent_id IS NOT NULL ASC, i.sort_order ASC"
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Write methods — used by api.php handlers
    // -------------------------------------------------------------------------

    /** Updates episode metadata (week, year, YouTube URL); returns the updated row. */
    public function updateEpisode(int $week, int $year, string $youtubeUrl): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE episodes SET week_number = :week, year = :year, youtube_url = :url WHERE id = 1'
        );
        $stmt->execute([':week' => $week, ':year' => $year, ':url' => $youtubeUrl]);

        return $this->getEpisode();
    }

    /** Inserts a new item; assigns the next sort_order within the section; returns the new row. */
    public function addItem(string $section, string $url, string $title, string $authorName, string $authorUrl, string $talkingPoints = '', ?string $hook = null): array
    {
        // Compute the next sort_order for this section (0 if the section is empty).
        $maxStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order) + 1, 0) AS next_order FROM items WHERE section = :section'
        );
        $maxStmt->execute([':section' => $section]);
        $nextOrder = (int) $maxStmt->fetchColumn();

        // M7 — hook is optional at creation; null clears it to the column default.
        $hookValue = ($hook !== null && trim($hook) !== '') ? trim($hook) : null;

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO items (section, url, title, author_name, author_url, sort_order, talking_points, hook)
             VALUES (:section, :url, :title, :author_name, :author_url, :sort_order, :talking_points, :hook)'
        );
        $insertStmt->execute([
            ':section'        => $section,
            ':url'            => $url,
            ':title'          => $title,
            ':author_name'    => $authorName,
            ':author_url'     => $authorUrl,
            ':sort_order'     => $nextOrder,
            ':talking_points' => $talkingPoints !== '' ? $talkingPoints : null,
            ':hook'           => $hookValue,
        ]);

        $newId   = (int) $this->pdo->lastInsertId();
        $rowStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $rowStmt->execute([':id' => $newId]);

        return $rowStmt->fetch();
    }

    /**
     * Updates editable fields on an existing item; returns the updated row,
     * or false if no item with the given ID exists.
     *
     * @return array|false
     */
    public function updateItem(int $id, string $url, string $title, string $authorName, string $authorUrl): array|false
    {
        $stmt = $this->pdo->prepare(
            'UPDATE items
             SET url = :url, title = :title, author_name = :author_name, author_url = :author_url
             WHERE id = :id'
        );
        $stmt->execute([
            ':url'         => $url,
            ':title'       => $title,
            ':author_name' => $authorName,
            ':author_url'  => $authorUrl,
            ':id'          => $id,
        ]);

        $rowStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $rowStmt->execute([':id' => $id]);

        return $rowStmt->fetch();
    }

    /**
     * Deletes an item and maintains a consistent group structure.
     *
     * Three cases, all within a single transaction:
     *
     * - **Primary with secondaries**: promotes the first secondary (lowest sort_order)
     *   to primary — it inherits the deleted item's sort_order and talking_points,
     *   and all other secondaries are re-parented to it.
     * - **Secondary**: removed; remaining siblings in the group are resequenced.
     * - **Standalone**: removed directly.
     *
     * In all cases, top-level sort_order values in the section are resequenced last.
     */
    public function deleteItem(int $id): bool
    {
        $itemStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $itemStmt->execute([':id' => $id]);
        $item = $itemStmt->fetch();

        if ($item === false) {
            return false;
        }

        $section = $item['section'];

        $this->pdo->beginTransaction();

        try {
            // Determine children (secondaries whose parent_id points at this item).
            $childrenStmt = $this->pdo->prepare(
                'SELECT * FROM items WHERE parent_id = :id ORDER BY sort_order ASC'
            );
            $childrenStmt->execute([':id' => $id]);
            $children = $childrenStmt->fetchAll();

            if (!empty($children)) {
                // Primary with secondaries: promote the first secondary.
                $promoted  = $children[0];
                $remaining = array_slice($children, 1);

                $this->pdo->prepare(
                    'UPDATE items
                     SET parent_id = NULL, sort_order = :sort_order, talking_points = :talking_points
                     WHERE id = :id'
                )->execute([
                    ':sort_order'     => $item['sort_order'],
                    ':talking_points' => $item['talking_points'],
                    ':id'             => $promoted['id'],
                ]);

                // Re-parent all other secondaries to the promoted item.
                if (!empty($remaining)) {
                    $reparentStmt = $this->pdo->prepare(
                        'UPDATE items SET parent_id = :new_parent WHERE id = :id'
                    );
                    foreach ($remaining as $sibling) {
                        $reparentStmt->execute([':new_parent' => $promoted['id'], ':id' => $sibling['id']]);
                    }
                }
            }

            // Delete the item (promotion is done; FK ON DELETE SET NULL is bypassed by our logic).
            $this->pdo->prepare('DELETE FROM items WHERE id = :id')
                      ->execute([':id' => $id]);

            // For a deleted secondary, resequence its former siblings within the group.
            if (empty($children) && $item['parent_id'] !== null) {
                $siblingsStmt = $this->pdo->prepare(
                    'SELECT id FROM items WHERE parent_id = :parent_id ORDER BY sort_order ASC'
                );
                $siblingsStmt->execute([':parent_id' => $item['parent_id']]);
                $siblings = $siblingsStmt->fetchAll(\PDO::FETCH_COLUMN);

                $resequenceSiblingStmt = $this->pdo->prepare(
                    'UPDATE items SET sort_order = :order WHERE id = :id'
                );
                foreach ($siblings as $position => $siblingId) {
                    $resequenceSiblingStmt->execute([':order' => $position, ':id' => $siblingId]);
                }
            }

            // Always resequence top-level items in the section so sort_order stays contiguous.
            $topLevelStmt = $this->pdo->prepare(
                'SELECT id FROM items WHERE section = :section AND parent_id IS NULL ORDER BY sort_order ASC'
            );
            $topLevelStmt->execute([':section' => $section]);
            $topLevelIds = $topLevelStmt->fetchAll(\PDO::FETCH_COLUMN);

            $reorderTopStmt = $this->pdo->prepare(
                'UPDATE items SET sort_order = :order WHERE id = :id'
            );
            foreach ($topLevelIds as $position => $itemId) {
                $reorderTopStmt->execute([':order' => $position, ':id' => $itemId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Sets sort_order to array index for each ID in the provided order.
     *
     * For the news section, all IDs must be top-level (`parent_id IS NULL`);
     * an exception is thrown if any secondary ID is present so that no changes
     * are committed.
     *
     * @param list<int> $orderedIds
     */
    public function reorderItems(string $section, array $orderedIds): bool
    {
        if ($section === 'news' && !empty($orderedIds)) {
            $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
            $checkStmt    = $this->pdo->prepare(
                "SELECT COUNT(*) FROM items
                 WHERE id IN ($placeholders)
                   AND (parent_id IS NOT NULL OR section != 'news')"
            );
            $checkStmt->execute(array_values($orderedIds));

            if ((int) $checkStmt->fetchColumn() > 0) {
                throw new \InvalidArgumentException(
                    'reorderItems: only top-level news section IDs may be reordered via this path'
                );
            }
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE items SET sort_order = :order WHERE id = :id AND section = :section'
            );
            foreach ($orderedIds as $position => $itemId) {
                $stmt->execute([
                    ':order'   => $position,
                    ':id'      => $itemId,
                    ':section' => $section,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Updates talking_points for the given item and returns the updated row.
     *
     * Only primary or standalone news items (parent_id IS NULL) may have talking
     * points; calling this on a secondary throws an InvalidArgumentException.
     */
    public function updateTalkingPoints(int $id, string $talkingPoints): array
    {
        $itemStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $itemStmt->execute([':id' => $id]);
        $item = $itemStmt->fetch();

        if ($item === false) {
            throw new \InvalidArgumentException("Item {$id} not found");
        }

        if ($item['parent_id'] !== null) {
            throw new \InvalidArgumentException(
                'Talking points may only be set on primary or standalone items (parent_id must be NULL)'
            );
        }

        $this->pdo->prepare(
            'UPDATE items SET talking_points = :talking_points WHERE id = :id'
        )->execute([':talking_points' => $talkingPoints, ':id' => $id]);

        // No trigger can transform talking_points, so we can return the row we
        // already have rather than issuing a third SELECT round-trip.
        $item['talking_points'] = $talkingPoints;

        return $item;
    }

    /**
     * M4 — Update the research-context fields on an item.
     *
     * Both `$status` and `$my_context` are optional per call: pass `null` to
     * leave a field untouched (e.g. an agent writes research context but does
     * not yet know the workflow status). When both are null the call is a
     * validation-only no-op that still returns the current row.
     *
     * `$my_context` is a free-form block of text (markdown or plain). `$status`
     * is a short workflow word; it is not validated against an allow-list so
     * callers can use whatever fits their pipeline — the app treats it as a
     * label, not a state machine.
     *
     * Returns the updated row on success, or false if no item has the given ID.
     *
     * @param string|null $status     New value for items.status, or null to leave unchanged
     * @param string|null $myContext  New value for items.my_context, or null to leave unchanged
     * @return array|false
     */
    public function updateItemContext(int $id, ?string $status, ?string $myContext): array|false
    {
        $itemStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $itemStmt->execute([':id' => $id]);
        $item = $itemStmt->fetch();

        if ($item === false) {
            return false;
        }

        $sets   = [];
        $params = [':id' => $id];

        if ($status !== null) {
            $sets[]   = 'status = :status';
            $params[':status'] = $status;
        }
        if ($myContext !== null) {
            $sets[]   = 'my_context = :my_context';
            $params[':my_context'] = $myContext;
        }

        if ($sets !== []) {
            $sql = 'UPDATE items SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $this->pdo->prepare($sql)->execute($params);

            $itemStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
            $itemStmt->execute([':id' => $id]);
            $item = $itemStmt->fetch();
        }

        return $item;
    }

    /**
     * M7 — Set the per-story hook for an item.
     *
     * The hook is a short "one line for the open" written by the host while
     * researching. It is shown in the presenter intro, the "This week we
     * cover" list, and the audience intro card. It does NOT feed the
     * generated Markdown (show notes are unchanged).
     *
     * A single-field write modelled on the updateItemContext() pattern (same
     * nullable-TEXT + COALESCE-on-read convention). `''` clears the hook.
     *
     * @return array|false the updated row, or false if no such id exists
     */
    public function updateHook(int $id, string $hook): array|false
    {
        $itemStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $itemStmt->execute([':id' => $id]);
        $item = $itemStmt->fetch();

        if ($item === false) {
            return false;
        }

        // `''` (or whitespace-only) clears the hook → NULL, matching the
        // addItem() storage convention.
        $hookValue = (trim($hook) === '') ? null : trim($hook);

        $this->pdo->prepare('UPDATE items SET hook = :hook WHERE id = :id')
            ->execute([':hook' => $hookValue, ':id' => $id]);

        // The hook is a plain scalar: patch the row in memory rather than
        // issuing a third SELECT round-trip (matches updateTalkingPoints()).
        $item['hook'] = $hookValue ?? '';

        return $item;
    }

    /**
     * M7 — Ordered list of the episode's filled hooks in run order.
     *
     * Returns one entry per top-level item (vulnerabilities first, then news,
     * each section in sort_order) whose `hook` is non-empty:
     *
     *   [ { id, section, title, hook }, … ]
     *
     * The presenter view uses this to build the "This week we cover" list and
     * the intro; a host without a browser can still read the same data via the
     * API. Items with an empty hook are skipped, so a partially-researched
     * episode shows only what is ready to open with.
     *
     * @return list<array{id:int, section:string, title:string, hook:string}>
     */
    public function getHooks(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, section, title, hook
             FROM items
             WHERE parent_id IS NULL
               AND hook IS NOT NULL
               AND TRIM(hook) != ''
             ORDER BY section = 'vulnerability' DESC, sort_order ASC, id ASC"
        );
        $stmt->execute();

        $rows  = $stmt->fetchAll();
        return array_map(static function (array $r) {
            return [
                'id'      => (int) $r['id'],
                'section' => $r['section'],
                'title'   => (string) $r['title'],
                'hook'    => trim((string) $r['hook']),
            ];
        }, $rows);
    }

    /**
     * Makes $itemId a secondary under $targetId (nesting).
     *
     * Validation:
     * - $targetId must be a top-level news item (section='news', parent_id IS NULL)
     * - $itemId must be in the news section and not equal $targetId
     *
     * If $itemId currently has children (it was a primary), those children are
     * re-parented to $targetId before $itemId is nested, so no orphans are created.
     *
     * If $talkingPointsToTransfer is non-null, the source item's talking_points are
     * cleared and the provided content is written to the target — all within this
     * method's transaction so the transfer is atomic with the nesting itself.
     *
     * @return array{primary: array, secondaries: list<array>}
     */
    public function nestItem(int $itemId, int $targetId, ?string $talkingPointsToTransfer = null): array
    {
        if ($itemId === $targetId) {
            throw new \InvalidArgumentException('An item cannot be nested under itself');
        }

        $fetchStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');

        $fetchStmt->execute([':id' => $targetId]);
        $target = $fetchStmt->fetch();

        $fetchStmt->execute([':id' => $itemId]);
        $item = $fetchStmt->fetch();

        if ($target === false || $item === false) {
            throw new \InvalidArgumentException('One or both item IDs not found');
        }

        if ($target['section'] !== 'news' || $target['parent_id'] !== null) {
            throw new \InvalidArgumentException(
                'Target must be a top-level news item (section=\'news\', parent_id IS NULL)'
            );
        }

        if ($item['section'] !== 'news') {
            throw new \InvalidArgumentException('Item to nest must belong to the news section');
        }

        $this->pdo->beginTransaction();

        try {
            // Clear talking points on the source before nesting. Once $itemId becomes
            // a secondary, updateTalkingPoints() would rightly reject writes to it, so
            // the clear must happen first. Both this and the target write below are
            // inside this transaction so the transfer is atomic with the nest itself.
            if ($talkingPointsToTransfer !== null) {
                $this->pdo->prepare(
                    'UPDATE items SET talking_points = :tp WHERE id = :id'
                )->execute([':tp' => '', ':id' => $itemId]);
            }

            // If $itemId has children of its own, re-parent them to $targetId first.
            $childrenStmt = $this->pdo->prepare(
                'SELECT id FROM items WHERE parent_id = :id'
            );
            $childrenStmt->execute([':id' => $itemId]);
            $childIds = $childrenStmt->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($childIds)) {
                $reparentStmt = $this->pdo->prepare(
                    'UPDATE items SET parent_id = :new_parent WHERE id = :id'
                );
                foreach ($childIds as $childId) {
                    $reparentStmt->execute([':new_parent' => $targetId, ':id' => $childId]);
                }
            }

            // Resequence all current secondaries of $targetId (including any just re-parented)
            // so their sort_orders are contiguous from 0 with no collisions before we append.
            $existingStmt = $this->pdo->prepare(
                'SELECT id FROM items WHERE parent_id = :target_id ORDER BY sort_order ASC'
            );
            $existingStmt->execute([':target_id' => $targetId]);
            $existingIds = $existingStmt->fetchAll(\PDO::FETCH_COLUMN);

            $reseqStmt = $this->pdo->prepare(
                'UPDATE items SET sort_order = :order WHERE id = :id'
            );
            foreach ($existingIds as $pos => $eid) {
                $reseqStmt->execute([':order' => $pos, ':id' => $eid]);
            }

            // Next available slot is simply the count of existing secondaries.
            $nextOrder = count($existingIds);

            $this->pdo->prepare(
                'UPDATE items SET parent_id = :parent_id, sort_order = :sort_order WHERE id = :id'
            )->execute([
                ':parent_id'  => $targetId,
                ':sort_order' => $nextOrder,
                ':id'         => $itemId,
            ]);

            // Write the transferred talking points to the target (now the group primary).
            if ($talkingPointsToTransfer !== null) {
                $this->pdo->prepare(
                    'UPDATE items SET talking_points = :tp WHERE id = :id'
                )->execute([':tp' => $talkingPointsToTransfer, ':id' => $targetId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $fetchStmt->execute([':id' => $targetId]);
        $primary = $fetchStmt->fetch();

        $secondariesStmt = $this->pdo->prepare(
            'SELECT * FROM items WHERE parent_id = :id ORDER BY sort_order ASC'
        );
        $secondariesStmt->execute([':id' => $targetId]);

        return [
            'primary'     => $primary,
            'secondaries' => $secondariesStmt->fetchAll(),
        ];
    }

    /**
     * Extracts a secondary item back to a standalone top-level item.
     *
     * The caller provides the desired new top-level ordering as $newTopLevelOrder
     * (which must include $itemId's id since it is now top-level). Remaining
     * secondaries in the former primary's group are resequenced from 0.
     *
     * @param list<int> $newTopLevelOrder
     */
    public function extractItem(int $itemId, array $newTopLevelOrder): bool
    {
        $itemStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $itemStmt->execute([':id' => $itemId]);
        $item = $itemStmt->fetch();

        if ($item === false || $item['parent_id'] === null) {
            throw new \InvalidArgumentException(
                'Item must be a secondary (parent_id IS NOT NULL) to be extracted'
            );
        }

        $oldParentId = $item['parent_id'];

        $this->pdo->beginTransaction();

        try {
            // Detach from group — item becomes a standalone top-level item.
            $this->pdo->prepare(
                'UPDATE items SET parent_id = NULL WHERE id = :id'
            )->execute([':id' => $itemId]);

            // Apply the caller-supplied top-level ordering (mirrors reorderItems logic;
            // inlined here to avoid a nested transaction).
            $reorderStmt = $this->pdo->prepare(
                "UPDATE items SET sort_order = :order WHERE id = :id AND section = 'news' AND parent_id IS NULL"
            );
            foreach ($newTopLevelOrder as $position => $topId) {
                $reorderStmt->execute([':order' => $position, ':id' => $topId]);
            }

            // Resequence the remaining secondaries in the former primary's group.
            $siblingsStmt = $this->pdo->prepare(
                'SELECT id FROM items WHERE parent_id = :parent_id ORDER BY sort_order ASC'
            );
            $siblingsStmt->execute([':parent_id' => $oldParentId]);
            $remainingSiblings = $siblingsStmt->fetchAll(\PDO::FETCH_COLUMN);

            $resequenceStmt = $this->pdo->prepare(
                'UPDATE items SET sort_order = :order WHERE id = :id'
            );
            foreach ($remainingSiblings as $position => $siblingId) {
                $resequenceStmt->execute([':order' => $position, ':id' => $siblingId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Reorders the secondaries within a group by assigning sort_order = array index.
     *
     * All IDs in $orderedSecondaryIds must belong to $primaryId (parent_id = $primaryId).
     * An exception is thrown — and no changes committed — if any foreign ID is present.
     *
     * @param list<int> $orderedSecondaryIds
     */
    public function reorderGroupItems(int $primaryId, array $orderedSecondaryIds): bool
    {
        if (!empty($orderedSecondaryIds)) {
            $placeholders = implode(',', array_fill(0, count($orderedSecondaryIds), '?'));
            // Detect IDs that are not secondaries of $primaryId (either top-level or from another group).
            $checkStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM items
                 WHERE id IN ($placeholders) AND (parent_id IS NULL OR parent_id != ?)"
            );
            $checkStmt->execute(array_merge(array_values($orderedSecondaryIds), [$primaryId]));

            if ((int) $checkStmt->fetchColumn() > 0) {
                throw new \InvalidArgumentException(
                    'reorderGroupItems: all IDs must be secondaries of the specified primary'
                );
            }
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE items SET sort_order = ? WHERE id = ?'
            );
            foreach ($orderedSecondaryIds as $position => $itemId) {
                $stmt->execute([$position, $itemId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Returns all items grouped by section in a flat array format.
     *
     * Identical to getItems() in ordering and shape; provided so Generator.php
     * can receive items without being modified — it ignores the extra talking_points
     * and parent_id fields as unknown columns.
     *
     * @return array{vulnerability: list<array>, news: list<array>}
     */
    public function getItemsFlat(): array
    {
        return [
            'vulnerability' => $this->fetchVulnerabilityItems(),
            'news'          => $this->fetchNewsItemsOrdered(),
        ];
    }

    /**
     * Deletes all items and resets the episode to the current week/year defaults.
     *
     * Author history is intentionally preserved — it accumulates across episodes.
     *
     * M3: candidates that were promoted (status 'selected') are flipped back to
     * 'pending' — their target items were deleted, so the pool re-offers them.
     * Rejected candidates stay rejected; a re-offer comes from a new push.
     */
    public function resetEpisode(): array
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec('DELETE FROM items');

            // M3 — return promoted candidates to the pending pool.
            $this->pdo->exec("UPDATE candidates SET status = 'pending' WHERE status = 'selected'");

            $stmt = $this->pdo->prepare(
                "UPDATE episodes SET week_number = :week, year = :year, youtube_url = '' WHERE id = 1"
            );
            $stmt->execute([':week' => idate('W'), ':year' => (int) date('Y')]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->getEpisode();
    }

    /** Inserts a new author history record or increments use_count and updates last_used_at. */
    public function upsertAuthorHistory(string $domain, string $authorName, string $authorUrl): void
    {
        $now  = date('c'); // ISO 8601 datetime
        $stmt = $this->pdo->prepare(
            'INSERT INTO author_history (domain, author_name, author_url, use_count, last_used_at)
             VALUES (:domain, :author_name, :author_url, 1, :now)
             ON CONFLICT(domain, author_name, author_url)
             DO UPDATE SET use_count = use_count + 1, last_used_at = :now'
        );
        $stmt->execute([
            ':domain'      => $domain,
            ':author_name' => $authorName,
            ':author_url'  => $authorUrl,
            ':now'         => $now,
        ]);
    }

    /**
     * Returns domain-specific authors first, then others, filtered by an optional query string.
     *
     * Results are deduplicated by author_name: when the same name has been saved with
     * different URLs (e.g. once with a profile link, once without), the row with the
     * non-empty URL is preferred and use_counts are summed.
     *
     * @return array{domain_authors: list<array>, other_authors: list<array>}
     */
    public function getAuthorSuggestions(string $domain, string $query): array
    {
        $params = [':domain' => $domain];
        $likeClause = '';

        if ($query !== '') {
            $params[':like'] = '%' . $query . '%';
            $likeClause = 'AND author_name LIKE :like';
        }

        // GROUP BY author_name so duplicate entries (same name, different URLs) are
        // collapsed into one row.  COALESCE(MAX(CASE … END), '') picks the non-empty
        // URL when one exists; SUM(use_count) accumulates usage across all variants.
        $domainStmt = $this->pdo->prepare(
            "SELECT
                 author_name,
                 COALESCE(MAX(CASE WHEN author_url != '' THEN author_url ELSE NULL END), '') AS author_url,
                 SUM(use_count) AS use_count
             FROM author_history
             WHERE domain = :domain $likeClause
             GROUP BY author_name
             ORDER BY SUM(use_count) DESC, MAX(last_used_at) DESC
             LIMIT 10"
        );
        $domainStmt->execute($params);

        $otherStmt = $this->pdo->prepare(
            "SELECT
                 author_name,
                 COALESCE(MAX(CASE WHEN author_url != '' THEN author_url ELSE NULL END), '') AS author_url,
                 SUM(use_count) AS use_count
             FROM author_history
             WHERE domain != :domain $likeClause
             GROUP BY author_name
             ORDER BY SUM(use_count) DESC, MAX(last_used_at) DESC
             LIMIT 5"
        );
        $otherStmt->execute($params);

        return [
            'domain_authors' => $domainStmt->fetchAll(),
            'other_authors'  => $otherStmt->fetchAll(),
        ];
    }

    /**
     * Looks up the best known profile URL for a given author name on a domain.
     *
     * Used to enrich scrape results when the page provides a name but no URL:
     * if the author has been seen before with a URL, that URL is returned so
     * the user does not have to enter it manually.
     *
     * Returns an empty string when no matching record with a non-empty URL exists.
     */
    public function getAuthorUrl(string $domain, string $authorName): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT author_url
             FROM author_history
             WHERE domain = :domain
               AND author_name = :author_name
               AND author_url != ''
             ORDER BY use_count DESC, last_used_at DESC
             LIMIT 1"
        );
        $stmt->execute([':domain' => $domain, ':author_name' => $authorName]);

        return $stmt->fetchColumn() ?: '';
    }

    // -------------------------------------------------------------------------
    // M3 — candidate pool
    // -------------------------------------------------------------------------

    /**
     * Upserts a candidate by URL.
     *
     * New URL → row inserted with status 'pending'.
     * Existing URL → metadata (section, title, author, source, notes) refreshed
     * and the row re-offered ('pending'), so automation can re-push a story
     * each week without duplicate accumulation. The UNIQUE(url) constraint is
     * the dedupe mechanism; re-offering replaces the previous offer.
     *
     * @return array the candidate row that was inserted or updated
     */
    public function upsertCandidate(string $url, string $title, string $authorName, string $authorUrl, string $source, string $notes, string $section = 'news', ?array $corroborations = null): array
    {
        $section = in_array($section, ['vulnerability', 'news'], true) ? $section : 'news';
        $now     = date('c');
        $corrJson = (is_array($corroborations) && $corroborations !== [])
            ? (json_encode($corroborations, JSON_UNESCAPED_SLASHES) ?: '')
            : '';

        $stmt = $this->pdo->prepare(
            'INSERT INTO candidates (section, url, title, author_name, author_url, source, notes, corroborations, status, pushed_at)
             VALUES (:section, :url, :title, :author_name, :author_url, :source, :notes, :corr, \'pending\', :now)
             ON CONFLICT(url)
             DO UPDATE SET section = :section,
                           title = :title,
                           author_name = :author_name,
                           author_url = :author_url,
                           source = :source,
                           notes = :notes,
                           corroborations = :corr,
                           pushed_at = :now'
        );
        $stmt->execute([
            ':section'          => $section,
            ':url'              => $url,
            ':title'            => $title,
            ':author_name'      => $authorName,
            ':author_url'       => $authorUrl,
            ':source'           => $source,
            ':notes'            => $notes,
            ':corr'             => $corrJson,
            ':now'              => $now,
        ]);

        return $this->getCandidateByUrl($url);
    }

    /** Returns all candidate rows for a status ('pending', 'selected', 'rejected'). */
    public function getCandidates(string $status = 'pending'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM candidates WHERE status = :status ORDER BY pushed_at ASC, id ASC'
        );
        $stmt->execute([':status' => $status]);

        return $stmt->fetchAll();
    }

    /**
     * Returns a candidate row by URL, or null if absent.
     *
     * @return array|null
     */
    public function getCandidateByUrl(string $url): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM candidates WHERE url = :url');
        $stmt->execute([':url' => $url]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Promotes a candidate into the episode.
     *
     * Adds the item via the existing addItem() path (so sort_order, author
     * history enrichment, and the section allow-list are all preserved), then
     * records the promotion on the candidate row.
     *
     * Guarded: a candidate that is already selected and promoted into the
     * target section (item exists) is not duplicated. If the item was later
     * deleted, the candidate re-offers cleanly.
     *
     * @return array{candidate: array, item: array}
     */
    public function selectCandidate(string $url, string $targetSection, string $title = '', string $authorName = '', string $authorUrl = ''): array
    {
        $targetSection = in_array($targetSection, ['vulnerability', 'news'], true) ? $targetSection : 'news';

        $this->pdo->beginTransaction();

        try {
            $candidate = $this->getCandidateByUrl($url);

            if ($candidate === null) {
                $this->pdo->rollBack();
                throw new \RuntimeException("Unknown candidate: $url");
            }

            // M6 — the caller passes resolved attribution (candidate row
            // values overlaid with any scraped enrichment). Fall back to the
            // stored row values so a caller that omits them still works.
            $finalTitle   = $title       !== '' ? $title       : (string) $candidate['title'];
            $finalAuthor  = $authorName  !== '' ? $authorName  : (string) $candidate['author_name'];
            $finalProfile = $authorUrl   !== '' ? $authorUrl   : (string) $candidate['author_url'];

            // Dedupe: if an item with this URL already exists in the target
            // section, return the existing row — do not create a second one —
            // but refresh its attribution with the (possibly enriched) values.
            $dupeStmt = $this->pdo->prepare(
                'SELECT * FROM items WHERE section = :section AND url = :url LIMIT 1'
            );
            $dupeStmt->execute([':section' => $targetSection, ':url' => $url]);
            $existing = $dupeStmt->fetch();

            if ($existing === false) {
                $item = $this->addItem(
                    $targetSection,
                    (string) $candidate['url'],
                    $finalTitle,
                    $finalAuthor,
                    $finalProfile,
                    (string) $candidate['notes']
                );
            } else {
                $this->updateItem(
                    (int) $existing['id'],
                    (string) $existing['url'],
                    $finalTitle,
                    $finalAuthor,
                    $finalProfile
                );
                $item = $this->getItemById((int) $existing['id']);
            }

            // Record the promotion and cache the item id so unselectCandidate
            // can find the row even after re-nesting/re-ordering.
            $markStmt = $this->pdo->prepare(
                "UPDATE candidates
                     SET status = 'selected',
                         selected_section = :ss,
                         selected_item_id = :itemid,
                         author_name = :an,
                         author_url  = :au
                   WHERE url = :url"
            );
            $markStmt->execute([
                ':ss'       => $targetSection,
                ':itemid'   => (int) $item['id'],
                ':an'       => $finalAuthor,
                ':au'       => $finalProfile,
                ':url'      => $url,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'candidate' => $this->getCandidateByUrl($url),
            'item'      => $item,
        ];
    }

    /** Reverses a selection: removes the promoted item and its corroborating
     *  secondaries, then re-offers the candidate ('pending').
     *
     *  Order matters: secondaries are removed FIRST. deleteItem() treats a
     *  primary that still has children as "demote the first secondary to
     *  primary" — deleting the group would resurrect the corroborator as a
     *  standalone story. With the secondaries gone, the primary deletes
     *  cleanly. No nested transaction: deleteItem() opens its own.
     */
    public function unselectCandidate(string $url): array|false
    {
        $candidate = $this->getCandidateByUrl($url);

        if ($candidate === null || $candidate['status'] !== 'selected') {
            return false;
        }

        if ($candidate['selected_item_id'] !== null) {
            $itemId = (int) $candidate['selected_item_id'];

            // Remove corroborating secondaries first (see the note above).
            $childStmt = $this->pdo->prepare(
                'DELETE FROM items WHERE parent_id = :pid'
            );
            $childStmt->execute([':pid' => $itemId]);

            $this->deleteItem($itemId);
        }

        $stmt = $this->pdo->prepare(
            "UPDATE candidates
                 SET status = 'pending', selected_section = NULL, selected_item_id = NULL
               WHERE url = :url"
        );
        $stmt->execute([':url' => $url]);

        return $this->getCandidateByUrl($url);
    }

    /** True when any item row already carries this URL. */
    public function itemUrlExists(string $url): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM items WHERE url = :url LIMIT 1');
        $stmt->execute([':url' => $url]);

        return $stmt->fetch() !== false;
    }

    /**
     * Adds a secondary (corroborating) item directly under a news primary.
     * Equivalent to the nest flow minus the transfer dance: section 'news',
     * parent_id = the primary item, first free slot in the group's order.
     */
    public function addSecondary(int $parentId, string $url, string $title, string $authorName, string $authorUrl): array
    {
        $parentStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $parentStmt->execute([':id' => $parentId]);
        $parent = $parentStmt->fetch();

        if ($parent === false) {
            throw new \InvalidArgumentException('Parent item not found');
        }

        // First free sort_order inside the group (secondaries share the
        // primary's id space per the existing query convention).
        $posStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM items WHERE parent_id = :pid'
        );
        $posStmt->execute([':pid' => $parentId]);
        $sortOrder = (int) $posStmt->fetch();

        $stmt = $this->pdo->prepare(
            "INSERT INTO items (section, url, title, author_name, author_url, sort_order, parent_id)
             VALUES ('news', :url, :title, :author_name, :author_url, :sort_order, :parent_id)"
        );
        $stmt->execute([
            ':url'          => $url,
            ':title'        => $title,
            ':author_name'  => $authorName,
            ':author_url'   => $authorUrl,
            ':sort_order'   => $sortOrder,
            ':parent_id'    => $parentId,
        ]);

        return $this->getItemById((int) $this->pdo->lastInsertId());
    }

    /** Returns one item row by id (for handler responses). */
    public function getItemById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Marks a candidate as rejected. Keeps the row (provenance + re-offer)
     * rather than deleting it, so the audit trail of what automation offered
     * over time is preserved.
     */
    public function rejectCandidate(string $url): ?array
    {
        $stmt = $this->pdo->prepare("UPDATE candidates SET status = 'rejected' WHERE url = :url");
        $stmt->execute([':url' => $url]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->getCandidateByUrl($url);
    }
}
