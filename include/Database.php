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

    /** Returns the single episode row. */
    public function getEpisode(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM episodes WHERE id = 1');
        $stmt->execute();

        return $stmt->fetch();
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
}
