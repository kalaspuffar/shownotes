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
     * Returns all items grouped by section, ordered by sort_order ascending.
     *
     * @return array{vulnerability: list<array>, news: list<array>}
     */
    public function getItems(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM items ORDER BY section, sort_order ASC'
        );
        $stmt->execute();

        $groups = ['vulnerability' => [], 'news' => []];

        foreach ($stmt->fetchAll() as $row) {
            $groups[$row['section']][] = $row;
        }

        return $groups;
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

    /** Updates editable fields on an existing item; returns the updated row. */
    public function updateItem(int $id, string $url, string $title, string $authorName, string $authorUrl): array
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
     * Deletes an item and resequences sort_order within its section.
     *
     * Both the delete and the resequence run inside a single transaction so
     * sort_order values are always contiguous after the operation.
     */
    public function deleteItem(int $id): bool
    {
        // Fetch the section before deleting so we know what to resequence.
        $sectionStmt = $this->pdo->prepare('SELECT section FROM items WHERE id = :id');
        $sectionStmt->execute([':id' => $id]);
        $section = $sectionStmt->fetchColumn();

        if ($section === false) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('DELETE FROM items WHERE id = :id')
                      ->execute([':id' => $id]);

            // Re-number the remaining items in the section: 0, 1, 2, …
            $remainingStmt = $this->pdo->prepare(
                'SELECT id FROM items WHERE section = :section ORDER BY sort_order ASC'
            );
            $remainingStmt->execute([':section' => $section]);
            $remaining = $remainingStmt->fetchAll(\PDO::FETCH_COLUMN);

            $reorderStmt = $this->pdo->prepare(
                'UPDATE items SET sort_order = :order WHERE id = :id'
            );
            foreach ($remaining as $position => $itemId) {
                $reorderStmt->execute([':order' => $position, ':id' => $itemId]);
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
     * @param list<int> $orderedIds
     */
    public function reorderItems(string $section, array $orderedIds): bool
    {
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

        $domainStmt = $this->pdo->prepare(
            "SELECT author_name, author_url, use_count
             FROM author_history
             WHERE domain = :domain $likeClause
             ORDER BY use_count DESC, last_used_at DESC
             LIMIT 10"
        );
        $domainStmt->execute($params);

        $otherStmt = $this->pdo->prepare(
            "SELECT author_name, author_url, use_count
             FROM author_history
             WHERE domain != :domain $likeClause
             ORDER BY use_count DESC, last_used_at DESC
             LIMIT 5"
        );
        $otherStmt->execute($params);

        return [
            'domain_authors' => $domainStmt->fetchAll(),
            'other_authors'  => $otherStmt->fetchAll(),
        ];
    }
}
