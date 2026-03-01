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
            $this->pdo->exec(
                'ALTER TABLE items ADD COLUMN parent_id INTEGER REFERENCES items(id) ON DELETE SET NULL'
            );
        } catch (\PDOException $e) {
            // Column already exists — safe to continue.
        }

        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_items_parent ON items (parent_id, sort_order)
        SQL);
    }

    private function seedEpisode(): void
    {
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
        $vulnStmt = $this->pdo->prepare(
            "SELECT id, section, url, title, author_name, author_url, sort_order,
                    COALESCE(talking_points, '') AS talking_points, parent_id
             FROM items
             WHERE section = 'vulnerability'
             ORDER BY sort_order ASC"
        );
        $vulnStmt->execute();

        return [
            'vulnerability' => $vulnStmt->fetchAll(),
            'news'          => $this->fetchNewsItemsOrdered(),
        ];
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
                 i.parent_id
             FROM items i
             JOIN primary_order po ON po.id = COALESCE(i.parent_id, i.id)
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
    public function addItem(string $section, string $url, string $title, string $authorName, string $authorUrl): array
    {
        // Compute the next sort_order for this section (0 if the section is empty).
        $maxStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order) + 1, 0) AS next_order FROM items WHERE section = :section'
        );
        $maxStmt->execute([':section' => $section]);
        $nextOrder = (int) $maxStmt->fetchColumn();

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO items (section, url, title, author_name, author_url, sort_order)
             VALUES (:section, :url, :title, :author_name, :author_url, :sort_order)'
        );
        $insertStmt->execute([
            ':section'     => $section,
            ':url'         => $url,
            ':title'       => $title,
            ':author_name' => $authorName,
            ':author_url'  => $authorUrl,
            ':sort_order'  => $nextOrder,
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
                "SELECT COUNT(*) FROM items WHERE id IN ($placeholders) AND parent_id IS NOT NULL"
            );
            $checkStmt->execute(array_values($orderedIds));

            if ((int) $checkStmt->fetchColumn() > 0) {
                throw new \InvalidArgumentException(
                    'reorderItems: only top-level (parent_id IS NULL) IDs may be reordered in the news section'
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

        $rowStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
        $rowStmt->execute([':id' => $id]);

        return $rowStmt->fetch();
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
     * @return array{primary: array, secondaries: list<array>}
     */
    public function nestItem(int $itemId, int $targetId): array
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

            // Compute next sort_order among all of $targetId's current secondaries
            // (which now includes any re-parented children from above).
            $maxStmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(sort_order) + 1, 0) FROM items WHERE parent_id = :target_id'
            );
            $maxStmt->execute([':target_id' => $targetId]);
            $nextOrder = (int) $maxStmt->fetchColumn();

            $this->pdo->prepare(
                'UPDATE items SET parent_id = :parent_id, sort_order = :sort_order WHERE id = :id'
            )->execute([
                ':parent_id'  => $targetId,
                ':sort_order' => $nextOrder,
                ':id'         => $itemId,
            ]);

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
        $vulnStmt = $this->pdo->prepare(
            "SELECT id, section, url, title, author_name, author_url, sort_order,
                    COALESCE(talking_points, '') AS talking_points, parent_id
             FROM items
             WHERE section = 'vulnerability'
             ORDER BY sort_order ASC"
        );
        $vulnStmt->execute();

        return [
            'vulnerability' => $vulnStmt->fetchAll(),
            'news'          => $this->fetchNewsItemsOrdered(),
        ];
    }

    /**
     * Deletes all items and resets the episode to the current week/year defaults.
     *
     * Author history is intentionally preserved — it accumulates across episodes.
     */
    public function resetEpisode(): array
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec('DELETE FROM items');

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
}
