# Project Specification: Cozy News Corner — Show Notes Generator

**Version:** 1.0
**Date:** 2026-02-21
**Author:** Solution Architect (Claude Code)
**Status:** Draft — Pending Review
**Based on:** REQUIREMENTS.md v1.1

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Overview](#2-architecture-overview)
3. [System Components](#3-system-components)
4. [Data Architecture](#4-data-architecture)
5. [API Specifications](#5-api-specifications)
6. [Security Architecture](#6-security-architecture)
7. [Infrastructure and Deployment](#7-infrastructure-and-deployment)
8. [Integration Points](#8-integration-points)
9. [Testing Strategy](#9-testing-strategy)
10. [Implementation Plan](#10-implementation-plan)
11. [Risks and Mitigations](#11-risks-and-mitigations)
12. [Appendices](#12-appendices)

---

## 1. Executive Summary

### Project Overview

The Cozy News Corner Show Notes Generator is a personal, self-hosted PHP web application that automates the creation of weekly podcast show notes in a specific WordPress-compatible Markdown format. The application eliminates the repetitive manual process of copying article titles, author bylines, and URLs from browser tabs into a structured document.

### Key Objectives

- Provide a fast, reliable URL metadata scraper that populates editable fields with a single action
- Support structured organization of news items across two fixed sections (Vulnerability, News)
- Allow drag-and-drop reordering and inline editing of all fields
- Persist episode state automatically to SQLite so that work survives page refreshes
- Generate correctly formatted Markdown output with one click and copy it to the clipboard

### Success Criteria

| Criterion | Measure |
|-----------|---------|
| Speed | Active user time from links to complete show notes under 5 minutes |
| Formatting | Generated Markdown matches the required format in Appendix A of REQUIREMENTS.md exactly |
| Reliability | All in-progress items survive page refresh; no data loss |
| Scraping | Article title successfully retrieved for the majority of URLs submitted |
| Author assistance | Domain-aware author history reduces manual author entry over time |

---

## 2. Architecture Overview

### High-Level Architecture

The application follows a classic server-rendered + AJAX hybrid pattern. The initial page load is server-rendered (PHP injects the current episode state into the HTML), and all subsequent mutations are performed via AJAX calls to a JSON API endpoint. There is no client-side routing framework; the entire UI lives on a single page (`index.php`).

```
┌─────────────────────────────────────────────────────────┐
│                   Browser (Desktop)                      │
│  ┌────────────────────────────────────────────────────┐  │
│  │  HTML/CSS/Vanilla JS (www/index.php + app.js)      │  │
│  │  - Renders initial state from PHP-injected JSON    │  │
│  │  - Sends AJAX mutations to api.php                 │  │
│  │  - Updates DOM on response                         │  │
│  └────────────────────────────────────────────────────┘  │
└───────────────────────────┬─────────────────────────────┘
                            │ HTTP (local network)
┌───────────────────────────▼─────────────────────────────┐
│                Apache (mod_php / PHP-FPM)                │
│  DocumentRoot: /path/to/project/www/                     │
│                                                          │
│  ┌────────────────┐   ┌──────────────────────────────┐  │
│  │  www/index.php │   │  www/api.php                 │  │
│  │  (page render) │   │  (JSON API — all actions)    │  │
│  └───────┬────────┘   └──────────────┬───────────────┘  │
│          │                           │                   │
│          └──────────┬────────────────┘                   │
│                     │ require/include                    │
│  ┌──────────────────▼───────────────────────────────┐   │
│  │  include/ (PHP classes)                          │   │
│  │  ├── Database.php   — PDO SQLite CRUD            │   │
│  │  ├── Scraper.php    — URL fetch + HTML parse     │   │
│  │  └── Generator.php  — Markdown generation        │   │
│  └──────────────────────────┬────────────────────────┘  │
│                             │ PDO SQLite                 │
│  ┌──────────────────────────▼────────────────────────┐  │
│  │  var/shownotes.sqlite  (outside web root)         │  │
│  └───────────────────────────────────────────────────┘  │
│                                                          │
│  etc/config.php  (static configuration)                  │
└─────────────────────────────────────────────────────────┘
                            │ outbound HTTP
                    ┌───────▼────────┐
                    │  External URLs  │
                    │  (article sites)│
                    └────────────────┘
```

### Key Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Single-page application | Server-rendered HTML + AJAX mutations | Eliminates framework overhead; fits single-developer, single-user context perfectly |
| API design | Single `api.php` file with `action` parameter | No URL routing needed; Apache serves `api.php` directly; simple to maintain |
| State hydration | PHP injects `INITIAL_STATE` JSON into page `<script>` block | Avoids loading flash; no API call needed on page load |
| Database | SQLite via PHP PDO | Zero server configuration; adequate for single-user, low-volume use |
| Dependency policy | Zero Composer dependencies; no JS frameworks | Matches constraint; reduces maintenance surface; all features achievable with stdlib |
| Drag-and-drop | Native HTML5 DnD API | Avoids external JS library per user preference |
| Auto-save | Immediate AJAX on every structural change; 800 ms debounce on text field edits | Balances data safety with server request frequency |
| PHP version | 8.4 (Debian Trixie) | Enables modern PHP syntax: `match`, null-safe operator, named arguments, readonly properties, property hooks |

---

## 3. System Components

### 3.1 Configuration — `etc/config.php`

**Purpose:** Centralises all static, site-specific values so that the rest of the codebase contains no hard-coded strings.

**Responsibilities:**
- Define show title, tagline, and section names
- Define the SQLite database file path
- Define scraping timeout and other tuneable constants

**Interface:** Returns a PHP associative array (or defines PHP constants) consumed by all other PHP files via `require_once`.

**Contents:**

```php
<?php
return [
    'show_title'     => 'Cozy News Corner',
    'show_tagline'   => 'Your source for Open Source news',
    'sections'       => [
        'vulnerability' => 'Vulnerability',
        'news'          => 'News',
    ],
    'db_path'        => __DIR__ . '/../var/shownotes.sqlite',
    'scrape_timeout' => 5,          // seconds
    'scrape_useragent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                        . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'max_redirects'  => 5,
];
```

**Acceptance Criteria:**
- All values in this file are the sole source of truth for their respective settings
- No other PHP file hard-codes show title, tagline, section names, or DB path
- Changing `show_title` in this file changes the generated Markdown heading

---

### 3.2 Database Layer — `include/Database.php`

**Purpose:** Provides a single, reusable PDO connection to the SQLite database and encapsulates all SQL queries. Initialises the database schema on first connection.

**Class:** `Database`

**Responsibilities:**
- Open (or create) the SQLite file at the configured path
- Create all three tables if they do not exist (`episodes`, `items`, `author_history`)
- Seed the single `episodes` row on first run (with current ISO week and year defaults)
- Provide typed methods for all CRUD operations required by `api.php` and `index.php`

**Public Methods:**

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `getInstance` | `static getInstance(): self` | `Database` | Singleton accessor |
| `getEpisode` | `getEpisode(): array` | `array` | Current episode row |
| `updateEpisode` | `updateEpisode(int $week, int $year, string $youtubeUrl): array` | `array` | Update episode metadata; returns updated row |
| `getItems` | `getItems(): array` | `['vulnerability'=>[], 'news'=>[]]` | All items grouped by section, ordered by `sort_order` |
| `addItem` | `addItem(string $section, string $url, string $title, string $authorName, string $authorUrl): array` | `array` | Insert item; assigns next `sort_order` within section; returns new item row |
| `updateItem` | `updateItem(int $id, string $url, string $title, string $authorName, string $authorUrl): array` | `array` | Update item fields; returns updated row |
| `deleteItem` | `deleteItem(int $id): bool` | `bool` | Delete item; resequences `sort_order` within its section |
| `reorderItems` | `reorderItems(string $section, array $orderedIds): bool` | `bool` | Set `sort_order` to array index for each id |
| `resetEpisode` | `resetEpisode(): array` | `array` | Delete all items; reset episode to current week/year defaults; return new episode row |
| `upsertAuthorHistory` | `upsertAuthorHistory(string $domain, string $authorName, string $authorUrl): void` | `void` | Insert or increment `use_count` and update `last_used_at` |
| `getAuthorSuggestions` | `getAuthorSuggestions(string $domain, string $query): array` | `['domain'=>[], 'other'=>[]]` | Domain-specific authors first, then others; both filtered by `$query` if non-empty |

**Implementation Notes:**
- PDO is configured with `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` and `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
- `PRAGMA journal_mode = WAL;` is set on connection open for write reliability
- `PRAGMA foreign_keys = ON;` is set
- Schema creation uses `CREATE TABLE IF NOT EXISTS` — safe to run on every request
- `reorderItems` uses a single transaction wrapping N `UPDATE` statements
- `deleteItem` wraps delete + resequence in a transaction

**Acceptance Criteria:**
- Application functions correctly after deleting `var/shownotes.sqlite` (schema auto-recreates)
- Concurrent writes are handled gracefully by WAL mode (not a real risk for single-user, but correct nonetheless)
- All queries use prepared statements — no string interpolation of user data into SQL

---

### 3.3 URL Scraper — `include/Scraper.php`

**Purpose:** Given a URL, fetches the remote page via cURL and extracts article metadata (title, author name, author profile URL) using a defined priority cascade.

**Class:** `Scraper`

**Public Methods:**

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `scrape` | `scrape(string $url): array` | `['title'=>string, 'author_name'=>string, 'author_url'=>string, 'domain'=>string, 'error'=>string\|null]` | Validates URL, fetches page, extracts fields. Any field that cannot be determined is returned as empty string. |

**URL Validation (SSRF Protection — see §6):**

Before making any network request, `scrape()` validates the URL:
1. Parse with `parse_url()`; reject if scheme is not `http` or `https`
2. Reject if host is empty, is an IP literal, or resolves to a private/reserved IP
3. Reject if port is specified as a non-standard port (optional hardening)

**HTTP Fetch — cURL Configuration:**

```
CURLOPT_URL              = <validated URL>
CURLOPT_RETURNTRANSFER   = true
CURLOPT_TIMEOUT          = config[scrape_timeout]  (5s)
CURLOPT_CONNECTTIMEOUT   = 5
CURLOPT_FOLLOWLOCATION   = true
CURLOPT_MAXREDIRS        = config[max_redirects]   (5)
CURLOPT_USERAGENT        = config[scrape_useragent]
CURLOPT_SSL_VERIFYPEER   = true
CURLOPT_ENCODING         = ""    (accept-encoding: any; auto-decompress)
CURLOPT_HTTPHEADER       = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9']
```

If cURL returns an error or HTTP status >= 400, the method returns all fields empty with an error message.

**Metadata Extraction — Priority Cascade:**

The HTML is parsed using PHP's `DOMDocument` and `DOMXPath` (with libxml error suppression via `libxml_use_internal_errors(true)`).

Extraction is attempted in this order for each field:

**Title:**
1. `<meta property="og:title" content="...">` — Open Graph
2. `<meta name="twitter:title" content="...">` — Twitter Card
3. `<title>...</title>` — Fallback

**Author Name:**
1. `<meta property="og:author" content="...">` — Open Graph (non-standard but used by some sites)
2. `<meta name="author" content="...">` — Standard meta
3. `<meta name="article:author" content="...">` — Open Graph article
4. Schema.org JSON-LD: find `<script type="application/ld+json">` blocks, parse JSON, look for `author.name` within `Article`, `NewsArticle`, or `BlogPosting` types
5. HTML byline heuristics: first element matching CSS class patterns `author`, `byline`, `article-author`, `entry-author`, `post-author` — extract `textContent`, strip whitespace

**Author URL:**
1. Schema.org JSON-LD: `author.url` or `author.sameAs`
2. `<link rel="author" href="...">` — HTML link tag
3. Anchor tag inside a byline element (matching the byline class patterns above) — extract `href`

**Domain Extraction:**

The domain is extracted with `parse_url($url, PHP_URL_HOST)`, stripping the `www.` prefix if present, for use with author history.

**Acceptance Criteria:**
- Returns all empty strings (no exception/fatal) when a URL is unreachable or times out
- Returns error message in `error` field for invalid URLs (non-HTTP scheme, private IP, etc.)
- Title extraction succeeds for pages that set `og:title` or `<title>`
- Author extraction is best-effort; empty strings are acceptable for sites without standard markup
- Function completes within the configured timeout (5 seconds)

---

### 3.4 Markdown Generator — `include/Generator.php`

**Purpose:** Given the current episode state (episode metadata + ordered item lists), produces the exact Markdown output required by the WordPress blog.

**Class:** `Generator`

**Public Methods:**

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `generate` | `generate(array $episode, array $items, array $config): string` | `string` | Produces complete Markdown output |

**Generation Rules:**

```
Line 1:   # {show_title} for Week {week_number} of {year} - {show_tagline}
Line 2:   (blank)
Line 3:   [youtube {youtube_url}]
Line 4:   (blank)
Line 5:   ### {sections['vulnerability']}
Line 6:   (blank)
Lines 7…: - [{title}]({url})   ← one per vulnerability item, NO blank lines between
Line N:   (blank after last vulnerability item)
Line N+1: ### {sections['news']}
Line N+2: (blank)
Lines N+3…: Three-line block per news item:
              Title: {title}
              By: [{author_name}]({author_url})
              [{url}]({url})
           Followed by blank line between items (NO trailing blank line after last item)
```

**Implementation Notes:**
- Sections with zero items are still rendered (heading + blank line, no items)
- `youtube_url` is used verbatim as provided (no modification or validation at generation time)
- All field values are used as-is; no HTML escaping (the output is Markdown, not HTML)
- The generator does not validate completeness; it is the UI's responsibility to warn the user of missing fields

**Acceptance Criteria:**
- Output matches Appendix A of REQUIREMENTS.md character-for-character when given equivalent input
- Vulnerability section items are consecutive lines (no blank lines between items)
- News section items are separated by exactly one blank line
- No trailing newline issues that would cause WordPress rendering problems

---

### 3.5 Main Application Page — `www/index.php`

**Purpose:** Serves the single-page application. Renders the full HTML shell, injects the current application state as JSON into a `<script>` block, and links CSS and JS assets.

**Responsibilities:**
- `require_once` config, Database, and load current state
- Compute ISO week defaults for new episodes: `idate('W')` and `date('Y')`
- Output `<!DOCTYPE html>` ... full HTML structure
- Inject `<script>const INITIAL_STATE = <?= json_encode($state, JSON_HEX_TAG) ?>;</script>`
- Link `css/style.css` and `js/app.js`

**Initial State Shape:**

```json
{
  "episode": {
    "week_number": 8,
    "year": 2026,
    "youtube_url": ""
  },
  "items": {
    "vulnerability": [
      { "id": 1, "section": "vulnerability", "url": "...", "title": "...", "author_name": "", "author_url": "", "sort_order": 0 }
    ],
    "news": [
      { "id": 2, "section": "news", "url": "...", "title": "...", "author_name": "...", "author_url": "...", "sort_order": 0 }
    ]
  },
  "config": {
    "show_title": "Cozy News Corner",
    "show_tagline": "Your source for Open Source news",
    "sections": { "vulnerability": "Vulnerability", "news": "News" }
  }
}
```

**HTML Structure (semantic outline):**

```html
<header>   — App title + version
<main>
  <section id="episode-meta">    — Week, Year, YouTube URL fields
  <div id="workspace">
    <aside id="add-item-panel">  — URL input, section select, fetch button,
                                    scraped fields, Add button
    <div id="item-lists">
      <section id="vulnerability-list">  — Section heading + item list
      <section id="news-list">           — Section heading + item list
    </div>
  </div>
  <section id="output-panel">    — Generate button, markdown textarea, copy button
</main>
<footer>   — Reset episode button, status indicator
```

**Acceptance Criteria:**
- Page renders correctly without JavaScript (items are visible in the HTML)
- `INITIAL_STATE` JSON is valid and parseable by `JSON.parse()`
- No PHP errors or warnings on page load with an empty database

---

### 3.6 API Handler — `www/api.php`

**Purpose:** Handles all AJAX mutations from the frontend. Accepts POST requests with a JSON body, dispatches to the appropriate handler, and returns a JSON response.

**Request Format (all endpoints):**

```
POST /api.php
Content-Type: application/json

{ "action": "<action_name>", ...fields }
```

**Response Format (all endpoints):**

```json
{ "success": true, "data": { ... } }
{ "success": false, "error": "Human-readable error message" }
```

**Action Routing:**

```php
$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';

match($action) {
    'update_episode'       => handleUpdateEpisode($body, $db, $config),
    'scrape_url'           => handleScrapeUrl($body, $config),
    'add_item'             => handleAddItem($body, $db),
    'update_item'          => handleUpdateItem($body, $db),
    'delete_item'          => handleDeleteItem($body, $db),
    'reorder_items'        => handleReorderItems($body, $db),
    'reset_episode'        => handleResetEpisode($db, $config),
    'get_author_suggestions' => handleGetAuthorSuggestions($body, $db),
    'generate_markdown'    => handleGenerateMarkdown($db, $config),
    default                => jsonError('Unknown action', 400),
};
```

**Implementation Notes:**
- `Content-Type: application/json` header is set at the top of `api.php` before any output
- All handler functions validate required input fields and return a 400 error response if missing
- Exceptions from Database or Scraper are caught and returned as `success: false` error responses
- HTTP status codes: 200 for success, 400 for client error (bad input), 500 for server error

**Acceptance Criteria:**
- All actions return valid JSON
- Invalid or missing `action` values return a 400 error JSON (not a PHP error page)
- All required fields for each action are validated before processing

---

### 3.7 Frontend JavaScript — `www/js/app.js`

**Purpose:** Manages all client-side interactivity: rendering, inline editing, drag-and-drop, AJAX calls, and clipboard operations.

**Architecture:** Single vanilla JS module. No frameworks. State is maintained in a module-scoped `state` object that mirrors `INITIAL_STATE` and is updated on every successful API response.

**Module Structure:**

```
State management
  ├── state object (in-memory mirror of server state)
  └── setState(patch) — merges patch, re-renders affected components

Rendering
  ├── renderEpisodeMeta()
  ├── renderVulnerabilityList()
  ├── renderNewsList()
  ├── renderItem(item)        — generates item DOM for both section types
  └── renderOutputPanel(markdown)

API layer
  └── apiCall(action, payload) → Promise — wraps fetch(), handles errors

Event handlers
  ├── Episode meta: input[debounce 800ms] → apiCall('update_episode')
  ├── Add Item form:
  │     ├── URL input + section select
  │     ├── [Fetch] → apiCall('scrape_url') → show scraped fields
  │     └── [Add] → apiCall('add_item') → upsert author history → re-render list
  ├── Inline edit (item fields):
  │     ├── click → replace text with <input>
  │     ├── blur/Enter[debounce 800ms for text] → apiCall('update_item')
  │     └── Escape → cancel edit
  ├── Delete button → confirm dialog → apiCall('delete_item') → re-render
  ├── Drag-and-drop → see DnD section below
  ├── [Generate] → apiCall('generate_markdown') → show output panel
  ├── [Copy] → navigator.clipboard.writeText() → show "Copied!" state
  └── [New Episode] → confirm dialog → apiCall('reset_episode') → full re-render

Author history dropdown
  ├── focus on author_name input → apiCall('get_author_suggestions', {domain, query:''})
  ├── input on author_name → apiCall('get_author_suggestions', {domain, query}) [debounce 300ms]
  ├── render suggestion list (domain-specific group + other group)
  └── click suggestion → fill author_name + author_url; dismiss dropdown
```

**Drag-and-Drop Implementation (HTML5 DnD API):**

Each item element has `draggable="true"` and a visible drag handle (⠿ or ≡ icon).

Events bound on each item:
- `dragstart` — set `dataTransfer.effectAllowed = 'move'`; store dragged item `id` and `section` in `dataTransfer.setData('text/plain', id)`; add `.dragging` class to element
- `dragend` — remove `.dragging` class

Events bound on each section list container:
- `dragover` — `e.preventDefault()`; `e.dataTransfer.dropEffect = 'move'`; determine insertion position from mouse Y vs. item midpoints; show a drop-indicator bar between items
- `dragleave` — remove drop indicator
- `drop` — `e.preventDefault()`; compute new order of IDs; `apiCall('reorder_items', {section, order: [id,…]})`; update state + re-render

**State Update Rules:**
- Every `apiCall` that succeeds patches the local `state` and calls the relevant render function
- Failed API calls show a non-blocking error notification (toast) and do not change state
- Debounce timer: 800 ms for episode meta text fields and item text field edits; 300 ms for author suggestion filtering

**Acceptance Criteria:**
- All UI actions produce the correct API call
- Item list re-renders correctly after every mutation without full page reload
- Drag-and-drop reorder is reflected immediately in the DOM and saved to the server
- Copy-to-clipboard shows "Copied!" for 2 seconds then reverts
- Error notifications are non-blocking and auto-dismiss after 4 seconds
- Works correctly in Chrome (primary) and Firefox (secondary)

---

### 3.8 Stylesheet — `www/css/style.css`

**Purpose:** Defines the visual presentation of the application.

**Design Principles:**
- Desktop-first (minimum viewport: 1024 px wide)
- CSS custom properties (variables) for all colours, spacing, and typography
- No CSS framework (vanilla CSS)
- Readable, functional aesthetic appropriate for a productivity tool

**Layout:**

```
┌──────────────────────────────────────────────────────────────┐
│ HEADER: App name + auto-save indicator                       │
├──────────────────────────────────────────────────────────────┤
│ EPISODE META BAR: Week | Year | YouTube URL | [saved status] │
├──────────────────────────────────────────────────────────────┤
│ WORKSPACE (CSS Grid, two columns)                            │
│ ┌───────────────────────┐  ┌──────────────────────────────┐ │
│ │ ADD ITEM PANEL        │  │ VULNERABILITY SECTION        │ │
│ │  URL input            │  │  Drag-and-drop item list     │ │
│ │  Section select       │  ├──────────────────────────────┤ │
│ │  [Fetch Metadata]     │  │ NEWS SECTION                 │ │
│ │  ─────────────────    │  │  Drag-and-drop item list     │ │
│ │  Scraped fields area  │  │                              │ │
│ │  [Add to Section]     │  │                              │ │
│ └───────────────────────┘  └──────────────────────────────┘ │
├──────────────────────────────────────────────────────────────┤
│ ACTION BAR: [Generate Show Notes]       [New Episode]        │
├──────────────────────────────────────────────────────────────┤
│ OUTPUT PANEL (shown after generate)                          │
│  <textarea readonly> Markdown output </textarea>             │
│  [📋 Copy to Clipboard]   ✓ Copied!                         │
└──────────────────────────────────────────────────────────────┘
```

**Key UI Components and Their States:**

| Component | States |
|-----------|--------|
| Add Item form | idle / fetching / fetched-success / fetched-error |
| Item row | default / edit-mode / dragging / drag-over |
| Fetch button | default / loading (spinner) / disabled |
| Add button | default / disabled (when URL empty) |
| Generate button | default / loading |
| Copy button | default / copied (2s) |
| Episode meta fields | default / unsaved (highlighted border) / saved |
| Toast notification | hidden / visible-error / visible-success |

**CSS Custom Properties:**

```css
:root {
  --color-bg:           #f5f5f0;
  --color-surface:      #ffffff;
  --color-border:       #d0cec8;
  --color-primary:      #4a7c59;    /* action buttons */
  --color-primary-dark: #3a6148;
  --color-danger:       #c0392b;
  --color-text:         #2c2c2c;
  --color-text-muted:   #6b6b6b;
  --color-drag-handle:  #b0aca6;
  --color-drag-over:    #4a7c59;    /* drop indicator line */
  --radius:             6px;
  --spacing-sm:         8px;
  --spacing-md:         16px;
  --spacing-lg:         24px;
  --font-mono:          'Courier New', Courier, monospace;
  --font-sans:          system-ui, -apple-system, sans-serif;
}
```

**Acceptance Criteria:**
- Layout is usable and clear at 1280 × 800 viewport and above
- All interactive states (hover, focus, active, drag) have visible visual feedback
- Focus indicators meet WCAG AA contrast requirements (4.5:1 minimum)
- Drag handles are visually distinct and obviously interactive
- The Markdown output area uses a monospace font and is easily readable

---

## 4. Data Architecture

### 4.1 Data Models

#### Episode

Represents the single in-progress podcast episode. There is always exactly one row in this table (id = 1).

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `id` | INTEGER | PRIMARY KEY | Always 1 |
| `week_number` | INTEGER | NOT NULL | ISO 8601 week number (1–53) |
| `year` | INTEGER | NOT NULL | Four-digit year |
| `youtube_url` | TEXT | NOT NULL DEFAULT '' | Full YouTube watch URL |

**Business rules:**
- `week_number` defaults to `idate('W')` on the date the episode row is initialised
- `year` defaults to `date('Y')` on the same date
- The episode row is created on first database initialisation and never deleted; `reset_episode` updates the fields rather than deleting and re-inserting

#### Item

Represents a single article link in either the Vulnerability or News section.

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT | Stable identifier used by frontend |
| `section` | TEXT | NOT NULL, CHECK IN ('vulnerability','news') | Section assignment |
| `url` | TEXT | NOT NULL DEFAULT '' | Full article URL |
| `title` | TEXT | NOT NULL DEFAULT '' | Article/page title |
| `author_name` | TEXT | NOT NULL DEFAULT '' | Author display name (News only; empty for Vulnerability) |
| `author_url` | TEXT | NOT NULL DEFAULT '' | Author profile URL (News only; empty for Vulnerability) |
| `sort_order` | INTEGER | NOT NULL DEFAULT 0 | Position within section; 0-indexed, contiguous |

**Business rules:**
- `sort_order` values within a section are always resequenced to 0, 1, 2, … after every delete or reorder
- On `add_item`, `sort_order` is set to `MAX(sort_order) + 1` within the target section
- Vulnerability items may have `author_name` and `author_url` stored (scraper always attempts extraction) but they are not used in Markdown generation and are not displayed in the UI

#### AuthorHistory

Persistent lookup table of all author name + profile URL pairs ever used. Grows passively; never deleted by normal operation.

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT | Internal ID |
| `domain` | TEXT | NOT NULL | Hostname of article URL, `www.` stripped (e.g., `9to5linux.com`) |
| `author_name` | TEXT | NOT NULL | Author display name |
| `author_url` | TEXT | NOT NULL DEFAULT '' | Author profile URL |
| `use_count` | INTEGER | NOT NULL DEFAULT 1 | Incremented each time this author is saved |
| `last_used_at` | TEXT | NOT NULL | ISO 8601 datetime string; updated each use |

**Business rules:**
- UNIQUE constraint on `(domain, author_name, author_url)` — prevents duplicates
- On `add_item` or `update_item` where `author_name` is non-empty, `upsertAuthorHistory` is called with the item's domain, `author_name`, and `author_url`
- `get_author_suggestions` returns:
  - **Domain group:** rows where `domain = ?` AND (`author_name LIKE ?` OR query empty), ordered by `use_count DESC, last_used_at DESC`, limit 10
  - **Other group:** rows where `domain != ?` AND (`author_name LIKE ?` OR query empty), ordered by `use_count DESC, last_used_at DESC`, limit 5

---

### 4.2 Database Schema

```sql
-- Enable WAL mode for better write reliability
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- Single-row episode table
CREATE TABLE IF NOT EXISTS episodes (
    id          INTEGER PRIMARY KEY CHECK (id = 1),
    week_number INTEGER NOT NULL,
    year        INTEGER NOT NULL,
    youtube_url TEXT    NOT NULL DEFAULT ''
);

-- Ensure the single episode row exists (INSERT OR IGNORE)
INSERT OR IGNORE INTO episodes (id, week_number, year, youtube_url)
VALUES (1, strftime('%W', 'now'), strftime('%Y', 'now'), '');

-- Items table
CREATE TABLE IF NOT EXISTS items (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    section     TEXT    NOT NULL CHECK (section IN ('vulnerability', 'news')),
    url         TEXT    NOT NULL DEFAULT '',
    title       TEXT    NOT NULL DEFAULT '',
    author_name TEXT    NOT NULL DEFAULT '',
    author_url  TEXT    NOT NULL DEFAULT '',
    sort_order  INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_items_section_order
    ON items (section, sort_order);

-- Author history table
CREATE TABLE IF NOT EXISTS author_history (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    domain       TEXT    NOT NULL,
    author_name  TEXT    NOT NULL,
    author_url   TEXT    NOT NULL DEFAULT '',
    use_count    INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT    NOT NULL,
    UNIQUE (domain, author_name, author_url)
);

CREATE INDEX IF NOT EXISTS idx_author_history_domain
    ON author_history (domain, use_count DESC, last_used_at DESC);
```

**Notes:**
- `strftime('%W', 'now')` in SQLite returns 0-padded ISO week. PHP should use `idate('W')` to insert the correct ISO week on first PHP-side initialisation rather than relying on SQLite's strftime.
- The schema SQL above is used for the SQLite initialisation seed; PHP's `Database::init()` runs these statements on every connection open via `CREATE TABLE IF NOT EXISTS`.

---

## 5. API Specifications

All requests are `POST /api.php` with `Content-Type: application/json`.
All responses have `Content-Type: application/json`.
HTTP status codes: 200 (success), 400 (bad request), 500 (server error).

---

### 5.1 Action: `update_episode`

**Description:** Updates the episode metadata (week number, year, YouTube URL).

**Request:**
```json
{
  "action": "update_episode",
  "week_number": 8,
  "year": 2026,
  "youtube_url": "https://www.youtube.com/watch?v=JjXNspLI2XU"
}
```

**Validation:**
- `week_number`: integer, 1–53
- `year`: integer, 4 digits (≥ 2020)
- `youtube_url`: string, may be empty

**Response (success):**
```json
{
  "success": true,
  "data": {
    "episode": { "id": 1, "week_number": 8, "year": 2026, "youtube_url": "https://..." }
  }
}
```

---

### 5.2 Action: `scrape_url`

**Description:** Fetches a URL and extracts article metadata. Does NOT save anything to the database. The frontend uses the response to populate the Add Item form for user review before submission.

**Request:**
```json
{
  "action": "scrape_url",
  "url": "https://9to5linux.com/vim-9-2-is-out"
}
```

**Validation:**
- `url`: non-empty string; must pass SSRF validation in Scraper

**Response (success):**
```json
{
  "success": true,
  "data": {
    "title": "Vim 9.2 Is Out with Comprehensive Completion, Wayland Support, and More",
    "author_name": "Marcus Nestor",
    "author_url": "https://9to5linux.com/author/admin",
    "domain": "9to5linux.com",
    "scrape_error": null
  }
}
```

**Response (URL fetched but scraping partial):**
```json
{
  "success": true,
  "data": {
    "title": "Vim 9.2 Is Out...",
    "author_name": "",
    "author_url": "",
    "domain": "9to5linux.com",
    "scrape_error": "Author metadata not found — please enter manually"
  }
}
```

**Response (SSRF validation failure or network error):**
```json
{
  "success": false,
  "error": "Could not fetch URL: connection refused"
}
```

**Notes:**
- A partial scrape (title found, author not found) returns `success: true` with `scrape_error` set — the frontend shows the error as a warning but still populates available fields
- A complete failure (unreachable host, timeout, invalid URL) returns `success: false`

---

### 5.3 Action: `add_item`

**Description:** Saves a new item to the database. Also upserts `author_history` if `author_name` is non-empty.

**Request:**
```json
{
  "action": "add_item",
  "section": "news",
  "url": "https://9to5linux.com/vim-9-2-is-out",
  "title": "Vim 9.2 Is Out with Comprehensive Completion, Wayland Support, and More",
  "author_name": "Marcus Nestor",
  "author_url": "https://9to5linux.com/author/admin"
}
```

**Validation:**
- `section`: must be `vulnerability` or `news`
- `url`: non-empty string
- `title`, `author_name`, `author_url`: strings (may be empty)

**Response (success):**
```json
{
  "success": true,
  "data": {
    "item": {
      "id": 7,
      "section": "news",
      "url": "https://...",
      "title": "Vim 9.2 Is Out...",
      "author_name": "Marcus Nestor",
      "author_url": "https://...",
      "sort_order": 4
    }
  }
}
```

---

### 5.4 Action: `update_item`

**Description:** Updates one or more fields of an existing item. Also upserts `author_history` if `author_name` is non-empty.

**Request:**
```json
{
  "action": "update_item",
  "id": 7,
  "url": "https://9to5linux.com/vim-9-2-is-out",
  "title": "Vim 9.2 Is Out — Updated Title",
  "author_name": "Marcus Nestor",
  "author_url": "https://9to5linux.com/author/admin"
}
```

**Validation:**
- `id`: integer, must exist in `items`
- All other string fields are optional; missing fields are not updated (PATCH semantics)

**Response (success):**
```json
{
  "success": true,
  "data": {
    "item": { "id": 7, "section": "news", "url": "...", "title": "...", "author_name": "...", "author_url": "...", "sort_order": 4 }
  }
}
```

---

### 5.5 Action: `delete_item`

**Description:** Deletes an item and resequences `sort_order` within its section.

**Request:**
```json
{
  "action": "delete_item",
  "id": 7
}
```

**Validation:**
- `id`: integer, must exist in `items`

**Response (success):**
```json
{
  "success": true,
  "data": { "deleted_id": 7 }
}
```

---

### 5.6 Action: `reorder_items`

**Description:** Sets the `sort_order` for all items in a section based on the provided ordered array of IDs.

**Request:**
```json
{
  "action": "reorder_items",
  "section": "news",
  "order": [3, 7, 1, 5, 2]
}
```

**Validation:**
- `section`: `vulnerability` or `news`
- `order`: non-empty array of integers; all IDs must belong to the given section

**Response (success):**
```json
{
  "success": true,
  "data": { "section": "news", "order": [3, 7, 1, 5, 2] }
}
```

---

### 5.7 Action: `reset_episode`

**Description:** Deletes all items and resets the episode row to defaults (current ISO week and year, empty YouTube URL). Author history is **not** cleared.

**Request:**
```json
{
  "action": "reset_episode"
}
```

**Response (success):**
```json
{
  "success": true,
  "data": {
    "episode": { "id": 1, "week_number": 9, "year": 2026, "youtube_url": "" },
    "items": { "vulnerability": [], "news": [] }
  }
}
```

---

### 5.8 Action: `get_author_suggestions`

**Description:** Returns ranked author suggestions for the author history dropdown. Domain-specific authors are returned first.

**Request:**
```json
{
  "action": "get_author_suggestions",
  "domain": "9to5linux.com",
  "query": "Mar"
}
```

**Validation:**
- `domain`: string (may be empty — returns only `other` group)
- `query`: string (may be empty — returns all matching authors)

**Response (success):**
```json
{
  "success": true,
  "data": {
    "domain_authors": [
      { "author_name": "Marcus Nestor", "author_url": "https://9to5linux.com/author/admin", "use_count": 12 }
    ],
    "other_authors": [
      { "author_name": "Martin Wimpress", "author_url": "https://ubuntu.com/blog/author/martin", "use_count": 3 }
    ]
  }
}
```

---

### 5.9 Action: `generate_markdown`

**Description:** Loads the current episode state from the database and generates the complete Markdown output.

**Request:**
```json
{
  "action": "generate_markdown"
}
```

**Response (success):**
```json
{
  "success": true,
  "data": {
    "markdown": "# Cozy News Corner for Week 8 of 2026 - Your source for Open Source news\n\n[youtube https://...]\n\n..."
  }
}
```

**Response (validation warning — YouTube URL missing):**
```json
{
  "success": true,
  "data": {
    "markdown": "...",
    "warnings": ["YouTube URL is empty — the embed line will be blank in the output"]
  }
}
```

---

## 6. Security Architecture

### 6.1 SSRF Protection

The `Scraper` class must prevent requests to private/internal network resources.

**URL validation sequence (before any cURL request):**

```
1. parse_url() — extract scheme, host, port
2. Reject if scheme ∉ {http, https}
3. Reject if host is empty
4. Reject if host matches IPv4 literal (basic regex: /^\d+\.\d+\.\d+\.\d+$/)
5. Reject if host matches IPv6 literal (contains ':')
6. Resolve hostname: $ip = gethostbyname($host)
   — gethostbyname() returns the hostname unchanged if resolution fails
   — Reject if $ip === $host (resolution failed)
7. Validate resolved IP:
   filter_var($ip, FILTER_VALIDATE_IP,
     FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
   — Reject (return error) if filter_var returns false
8. Proceed with cURL request
```

**Blocked IP ranges (covered by PHP filter flags):**
- `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` (private)
- `127.0.0.0/8` (loopback)
- `0.0.0.0/8`, `169.254.0.0/16` (reserved/link-local)
- `::1`, `fc00::/7` (IPv6 — blocked by rejecting IPv6 literals at step 5)

### 6.2 SQL Injection Prevention

- All database queries use PDO prepared statements with bound parameters
- No SQL string interpolation of user-supplied values anywhere in the codebase

### 6.3 XSS Prevention

- All user-supplied data echoed into HTML is escaped with `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`
- The `INITIAL_STATE` JSON block uses `json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)` to prevent script injection
- The Markdown output textarea is rendered with `htmlspecialchars()` if rendered server-side; if rendered client-side via `textContent`, no escaping is needed

### 6.4 File System Security

- `var/shownotes.sqlite` is stored outside the web root (`www/`) and is therefore not directly accessible via HTTP
- `include/` and `etc/` are also outside the web root
- The Apache VirtualHost `DocumentRoot` points only to `www/`

### 6.5 Input Validation

- All API action payloads are validated for required fields and type correctness before processing
- Integer fields (`week_number`, `year`, `id`) are cast and range-checked
- Enum fields (`section`) are checked against an allow-list

### 6.6 No Authentication

Per requirements, authentication is handled at the network level (homelab LAN). The application itself has no login mechanism. No session tokens, cookies, or CSRF protection are required (the application is not accessible from the public internet and has no state-changing GET requests).

### 6.7 HTTP Headers (`www/.htaccess`)

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "no-referrer"
</IfModule>

# Prevent directory listing
Options -Indexes

# Deny access to hidden files
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
```

---

## 7. Infrastructure and Deployment

### 7.1 Server Requirements

| Requirement | Specification |
|-------------|---------------|
| OS | Debian Trixie (13) |
| PHP | 8.4 with extensions: `pdo`, `pdo_sqlite`, `curl`, `dom`, `json`, `libxml` |
| Web server | Apache 2.4 with `mod_rewrite`, `mod_headers`, `mod_php` (or `php-fpm`) |
| Disk | ~10 MB for application; SQLite file grows negligibly (single episode) |
| Network | Outbound HTTP/HTTPS from server to article URLs |

### 7.2 Directory Structure

```
show-notes-generator/
├── bin/                        # Reserved for CLI scripts (future use)
├── etc/
│   └── config.php              # Application configuration
├── include/
│   ├── Database.php            # PDO SQLite wrapper and CRUD
│   ├── Scraper.php             # URL fetch and metadata extraction
│   └── Generator.php           # Markdown generation
├── var/
│   └── shownotes.sqlite        # SQLite database (created on first run)
├── www/                        # Apache DocumentRoot
│   ├── .htaccess               # Security headers, deny hidden files
│   ├── index.php               # Main page (server-renders initial state)
│   ├── api.php                 # JSON API handler
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── app.js
└── docs/
    └── setup.md                # Installation and configuration guide
```

### 7.3 Apache VirtualHost Configuration

```apache
<VirtualHost *:80>
    ServerName shownotes.local
    DocumentRoot /srv/show-notes-generator/www

    <Directory /srv/show-notes-generator/www>
        AllowOverride All
        Require all granted
    </Directory>

    # Explicitly deny access to directories outside web root
    <Directory /srv/show-notes-generator/include>
        Require all denied
    </Directory>
    <Directory /srv/show-notes-generator/etc>
        Require all denied
    </Directory>
    <Directory /srv/show-notes-generator/var>
        Require all denied
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/shownotes-error.log
    CustomLog ${APACHE_LOG_DIR}/shownotes-access.log combined
</VirtualHost>
```

### 7.4 File Permissions

```bash
# Application files: owned by www-data (or deploy user), readable by web server
chown -R www-data:www-data /srv/show-notes-generator
chmod -R 755 /srv/show-notes-generator

# var/ directory: web server needs write access for SQLite
chmod 775 /srv/show-notes-generator/var
chmod 664 /srv/show-notes-generator/var/shownotes.sqlite  # after first run
```

### 7.5 Installation Sequence

1. Clone/copy project to `/srv/show-notes-generator/`
2. Configure Apache VirtualHost (see §7.3)
3. Enable site: `a2ensite shownotes`; reload Apache
4. Ensure `var/` is writable by the web server user
5. Configure `etc/config.php` if customisation is needed
6. Navigate to `http://shownotes.local` — database is created automatically on first request

### 7.6 Monitoring and Logging

- Apache access/error logs cover all HTTP-level issues
- PHP errors should be logged to Apache error log (`log_errors = On`, `display_errors = Off` in production `php.ini`)
- No application-level logging is required for a single-user personal tool

---

## 8. Integration Points

### 8.1 External Article URLs (Scraping)

- **Direction:** Outbound HTTP GET from PHP server to public article URLs
- **Protocol:** HTTP/HTTPS; follows redirects (max 5)
- **Timeout:** 5 seconds (configurable in `etc/config.php`)
- **User-Agent:** Realistic Chrome User-Agent string to avoid bot-detection blocks
- **Error handling:** Timeouts and HTTP errors return partial/empty data; frontend shows warning

### 8.2 WordPress Blog

- **Direction:** None (copy-paste workflow)
- **Format:** Generated Markdown uses WordPress shortcode syntax `[youtube URL]`
- **No API integration** — user copies output from the textarea and pastes into WordPress editor

### 8.3 Future: Browser Extension API (Out of Scope — Design Consideration)

The architecture does not preclude adding a `POST /api.php` action of `add_item_from_extension` in a future phase. The existing `api.php` action routing pattern and `add_item` logic would be reused. No changes to the current design are required to support this future path.

---

## 9. Testing Strategy

Given the single-user, personal-productivity nature of this application, testing is pragmatic rather than comprehensive. The focus is on correctness of the Markdown output format and reliability of the scraping and persistence layers.

### 9.1 Manual Functional Testing (Primary)

Before considering each implementation phase complete, manually verify:

**Phase 1 — Setup:**
- [ ] Navigating to the app creates `var/shownotes.sqlite` with correct schema
- [ ] Page loads with current ISO week and year pre-populated

**Phase 2 — Episode Metadata:**
- [ ] Week and year fields accept edits and auto-save
- [ ] YouTube URL field accepts and saves a standard YouTube URL

**Phase 3 — Add Item:**
- [ ] Pasting a URL and clicking Fetch populates title (and author where available)
- [ ] Fetch shows loading state during request
- [ ] Fetch failure shows clear error and leaves fields editable
- [ ] Adding item saves it and renders it in the correct section list

**Phase 4 — Item Management:**
- [ ] Inline edit of title, author name, author URL works for News items
- [ ] Inline edit of title, URL works for Vulnerability items
- [ ] Delete shows confirmation prompt; removes item on confirm; cancels on dismiss
- [ ] All changes survive page refresh

**Phase 5 — Drag and Drop:**
- [ ] Items can be dragged within Vulnerability section
- [ ] Items can be dragged within News section
- [ ] Drag does not allow cross-section drops
- [ ] New order persists after page refresh

**Phase 6 — Author History:**
- [ ] Adding a News item with an author saves to author_history
- [ ] Focusing the author name field in the Add Item form shows suggestions
- [ ] Domain-specific authors appear before others
- [ ] Selecting a suggestion fills both name and URL fields

**Phase 7 — Markdown Generation:**
- [ ] Generated output matches the format in Appendix A exactly
- [ ] Copy button copies full output to clipboard
- [ ] "Copied!" state shown for 2 seconds then reverts
- [ ] New Episode clears all items after confirmation

### 9.2 Markdown Output Verification

Verify the generator output against the exact expected format by testing with known inputs:

| Input | Expected output segment |
|-------|------------------------|
| Week 8, 2026, YouTube `?v=JjXNspLI2XU` | `# Cozy News Corner for Week 8 of 2026 - Your source for Open Source news` + `[youtube https://www.youtube.com/watch?v=JjXNspLI2XU]` |
| 2 vulnerability items | Tight bulleted list, no blank lines between items |
| 3 news items | Three-line blocks separated by single blank lines |
| Empty vulnerability section | `### Vulnerability` heading present; no items listed |

### 9.3 Scraper Testing

Test the scraper against a variety of page types:

| Test case | Expected behaviour |
|-----------|-------------------|
| Standard news article with `og:title` | Title extracted correctly |
| Article with `<meta name="author">` | Author name extracted |
| Article with Schema.org JSON-LD | Author name + URL extracted |
| Article with no author metadata | Empty author fields; no error |
| URL that times out (5s) | Returns empty fields with error message within 6s |
| `http://localhost/` (SSRF) | Rejected before any network request |
| `http://192.168.1.1/` (SSRF) | Rejected after DNS resolution |
| Invalid URL (no scheme) | Rejected with clear error |

### 9.4 Browser Compatibility

Test in:
- Chrome (latest stable) — primary
- Firefox (latest stable) — secondary

Test focus:
- Drag-and-drop (HTML5 DnD API behaves differently across browsers)
- `navigator.clipboard.writeText()` (requires secure context or user permission in Firefox)
- Inline edit UX (contenteditable vs input swap)

---

## 10. Implementation Plan

Implementation is structured as five sequential feature branches. Each branch represents a logical unit of work that can be reviewed and merged independently. Branch naming follows `feature/`, `fix/`, or `style/` prefixes. No merges to `main` without human review.

---

### Phase 1: Foundation — `feature/foundation`

**Goal:** Working skeleton with database, config, and blank page.

**Tasks:**
1. Create directory structure (`bin/`, `etc/`, `include/`, `var/`, `www/css/`, `www/js/`, `docs/`)
2. Write `etc/config.php` with all configurable values
3. Write `include/Database.php` — PDO connection, schema creation, episode seed
4. Write `www/index.php` — minimal HTML shell; loads config + DB; outputs `INITIAL_STATE` JSON
5. Write `www/.htaccess` — security headers, deny hidden files
6. Write `docs/setup.md` — installation steps, Apache config, permissions

**Acceptance Criteria:**
- Navigating to `index.php` returns HTTP 200 with valid HTML
- `var/shownotes.sqlite` is created on first request
- `INITIAL_STATE` JSON is present in page source and parseable
- No PHP errors or warnings

**Dependencies:** None

---

### Phase 2: Backend Core — `feature/backend-core`

**Goal:** All server-side logic implemented and testable via direct HTTP calls.

**Tasks:**
1. Write `include/Scraper.php` — SSRF validation, cURL fetch, DOMDocument parsing, priority cascade
2. Write `include/Generator.php` — Markdown assembly from episode + items
3. Write `www/api.php` — action router + all nine action handlers
4. Implement all `Database.php` CRUD methods required by API handlers

**Acceptance Criteria:**
- `POST api.php {"action":"scrape_url","url":"https://example-news-site.com/article"}` returns title and (where available) author data
- `POST api.php {"action":"add_item",...}` saves item and returns it with an ID
- `POST api.php {"action":"generate_markdown"}` returns correctly formatted Markdown
- SSRF validation rejects `http://localhost/` and `http://192.168.1.1/`
- All endpoints return JSON (no PHP error pages)

**Dependencies:** Phase 1

---

### Phase 3: Core UI — `feature/core-ui`

**Goal:** Fully interactive single-page UI for episode management, item add/edit/delete.

**Tasks:**
1. Write `www/css/style.css` — full layout (CSS Grid), component styles, all states, custom properties
2. Write `www/js/app.js` — state management, `apiCall()`, initial render from `INITIAL_STATE`
3. Implement episode meta bar with debounced auto-save
4. Implement Add Item panel: URL input → Fetch → scraped fields → Add
5. Implement section lists: render items, inline edit (click-to-edit), delete with confirmation
6. Implement error toast notifications

**Acceptance Criteria:**
- Episode week/year/YouTube fields save automatically within 800 ms of last keystroke
- Adding a URL fetches metadata and shows a loading state during fetch
- Scraped fields are pre-populated and editable before adding
- Added items appear in the correct section list immediately
- Inline edit works for all editable fields (title, URL, author name, author URL)
- Delete shows a browser `confirm()` dialog; cancelling does not delete
- Error toast appears on API failure and auto-dismisses after 4 seconds
- All items survive page refresh

**Dependencies:** Phase 1, Phase 2

---

### Phase 4: Drag-and-Drop and Author History — `feature/dnd-author`

**Goal:** Reordering via drag-and-drop, and domain-aware author suggestion dropdown.

**Tasks:**
1. Implement HTML5 DnD on item lists in `app.js`:
   - Add `draggable="true"` and drag handle to item elements
   - Bind `dragstart`, `dragend`, `dragover`, `dragleave`, `drop` on list containers
   - Show drop indicator bar between items on `dragover`
   - Call `apiCall('reorder_items', ...)` on drop; update state
2. Implement author suggestion dropdown for the Add Item form:
   - Fetch suggestions on author_name field focus
   - Filter suggestions on keyup (300 ms debounce)
   - Show domain group / other group with visual separator
   - Click suggestion → fill both name and URL fields
3. Implement author suggestion dropdown for inline-edit author_name in News item list

**Acceptance Criteria:**
- Items can be dragged and dropped within each section
- Drop indicator line is visible between items during drag
- Cross-section drops are rejected (drop target does not accept the item)
- New order persists after page refresh
- Author suggestions appear when focusing author_name field
- Domain-specific suggestions are visually separated from others
- Selecting a suggestion fills both author_name and author_url

**Dependencies:** Phase 3

---

### Phase 5: Output and Polish — `feature/output-polish`

**Goal:** Markdown generation, copy-to-clipboard, New Episode, and final polish.

**Tasks:**
1. Implement Generate button: `apiCall('generate_markdown')` → render output panel with `<textarea readonly>`
2. Implement Copy to Clipboard: `navigator.clipboard.writeText()` → 2-second "Copied!" state
3. Implement New Episode button: `confirm()` → `apiCall('reset_episode')` → full state reset + re-render
4. Final CSS polish: consistent spacing, hover/focus states, drag handle appearance
5. Test across Chrome and Firefox; fix any DnD or clipboard compatibility issues
6. Update `docs/setup.md` with any final configuration notes

**Acceptance Criteria:**
- Generated Markdown matches required format exactly (verified against test cases in §9.2)
- Clipboard copy works in Chrome and Firefox (secure context / localhost assumed)
- New Episode resets all items and episode metadata to fresh defaults
- Full functional walkthrough (add URLs → generate → copy) completes in < 5 minutes
- No console errors in Chrome DevTools during normal operation

**Dependencies:** Phase 4

---

## 11. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Scraping blocked by target news sites (403, bot-detection) | Medium | Medium | Realistic Chrome User-Agent; editable fallback fields; author history reduces impact on repeat sites |
| Author metadata absent from most articles | High | Low | Best-effort extraction; author history dropdown is primary author-assistance mechanism; manual entry is fast |
| HTML5 DnD API behaves inconsistently between Chrome and Firefox | Medium | Medium | Test against both in Phase 5; documented known workarounds (e.g., `dataTransfer.setData` must use `text/plain` for Firefox compatibility) |
| `navigator.clipboard.writeText()` blocked outside secure context | Low | Low | Localhost is considered secure; fall back to `document.execCommand('copy')` on a hidden `<textarea>` if needed |
| SQLite file permissions prevent write on Debian | Low | High | `docs/setup.md` documents correct `chown`/`chmod` steps; Database class provides a clear error if write fails |
| PHP `dom` extension not installed | Low | High | Noted as prerequisite in `docs/setup.md`; Scraper degrades gracefully if DOMDocument unavailable |
| cURL extension not installed | Low | High | Noted as prerequisite; `phpinfo()` check in setup doc |
| Large article pages (> 1 MB HTML) cause slow scraping | Low | Low | cURL `CURLOPT_MAXFILESIZE` can be set (e.g., 2 MB) to prevent downloading enormous pages |

---

## 12. Appendices

### Appendix A: File Inventory

| File | Purpose | Phase |
|------|---------|-------|
| `etc/config.php` | Application configuration | 1 |
| `include/Database.php` | PDO SQLite wrapper | 1–2 |
| `include/Scraper.php` | URL metadata extractor | 2 |
| `include/Generator.php` | Markdown generator | 2 |
| `www/index.php` | Main page + state hydration | 1, 3 |
| `www/api.php` | JSON API handler | 2 |
| `www/.htaccess` | Apache security config | 1 |
| `www/css/style.css` | Stylesheet | 3, 5 |
| `www/js/app.js` | Frontend JS | 3, 4, 5 |
| `docs/setup.md` | Installation guide | 1, 5 |

### Appendix B: API Action Summary

| Action | Mutates DB? | Calls Scraper? | Trigger |
|--------|------------|----------------|---------|
| `update_episode` | ✅ | ❌ | Episode meta field blur |
| `scrape_url` | ❌ | ✅ | Fetch button click |
| `add_item` | ✅ | ❌ | Add button click |
| `update_item` | ✅ | ❌ | Item field blur |
| `delete_item` | ✅ | ❌ | Delete confirm |
| `reorder_items` | ✅ | ❌ | Drag-and-drop |
| `reset_episode` | ✅ | ❌ | New Episode confirm |
| `get_author_suggestions` | ❌ | ❌ | Author field focus/input |
| `generate_markdown` | ❌ | ❌ | Generate button click |

### Appendix C: INITIAL_STATE Full Shape Reference

```json
{
  "episode": {
    "id": 1,
    "week_number": 8,
    "year": 2026,
    "youtube_url": "https://www.youtube.com/watch?v=JjXNspLI2XU"
  },
  "items": {
    "vulnerability": [
      {
        "id": 1,
        "section": "vulnerability",
        "url": "https://example.com/cve-article",
        "title": "Page Title of CVE Article",
        "author_name": "",
        "author_url": "",
        "sort_order": 0
      }
    ],
    "news": [
      {
        "id": 2,
        "section": "news",
        "url": "https://9to5linux.com/vim-9-2-is-out",
        "title": "Vim 9.2 Is Out with Comprehensive Completion, Wayland Support, and More",
        "author_name": "Marcus Nestor",
        "author_url": "https://9to5linux.com/author/admin",
        "sort_order": 0
      }
    ]
  },
  "config": {
    "show_title": "Cozy News Corner",
    "show_tagline": "Your source for Open Source news",
    "sections": {
      "vulnerability": "Vulnerability",
      "news": "News"
    }
  }
}
```

### Appendix D: Requirements Traceability

| Requirement | Specification Section |
|-------------|----------------------|
| UC-01: Start/Continue Episode | §3.5 (index.php), §4.2 (schema seed), §5.7 (reset_episode) |
| UC-02: Set Episode Metadata | §3.5, §5.1 (update_episode) |
| UC-03: Add a Link | §3.3 (Scraper), §5.2 (scrape_url), §5.3 (add_item), §3.7 (Add Item panel) |
| UC-04: Edit an Existing Item | §5.4 (update_item), §3.7 (inline edit) |
| UC-05: Delete an Item | §5.5 (delete_item), §3.7 (delete confirmation) |
| UC-06: Reorder Items | §5.6 (reorder_items), §3.7 (DnD) |
| UC-07: Generate Markdown | §3.4 (Generator), §5.9 (generate_markdown) |
| UC-08: Copy to Clipboard | §3.7 (copy button) |
| UC-09: Reset / New Episode | §5.7 (reset_episode), §3.7 (New Episode button) |
| F-01: Episode Header | §3.4 (Generator) |
| F-02: YouTube Embed | §3.4 (Generator) |
| F-03: Vulnerability Section | §3.4 (Generator) |
| F-04: News Section | §3.4 (Generator) |
| F-05: URL Metadata Scraping | §3.3 (Scraper), §5.2 |
| F-06: Editable Scraped Fields | §3.7 (inline edit), §5.4 |
| F-07: Drag-and-Drop Reordering | §3.7 (DnD), §5.6 |
| F-08: SQLite Persistence | §3.2 (Database), §4.2 |
| F-09: Configuration File | §3.1 (config.php) |
| F-10: Visual Episode Overview | §3.8 (layout) |
| F-11: Author History Dropdown | §4.1 (AuthorHistory), §5.8, §3.7 |
| NFR: SSRF Protection | §6.1 |
| NFR: SQL Injection | §6.2 |
| NFR: SQLite outside web root | §6.4, §7.2 |
| NFR: Auto-save | §3.7 (state management) |
| NFR: Scrape timeout | §3.3, §3.1 (config) |

---

*End of SPECIFICATION.md*
