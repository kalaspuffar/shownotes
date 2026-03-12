# Project Specification: Cozy News Corner — Input Flow Improvements

**Version:** 1.0
**Date:** 2026-03-12
**Author:** Solution Architect (Claude Code)
**Status:** Draft — Pending Review
**Based on:** REQUIREMENTS-INPUT-FLOW.md v3.0
**Extends:** SPECIFICATION.md v1.0, SPECIFICATION-RECORDING.md v1.0

> **Reading guide:** This document specifies only the input flow redesign (Article Input Modal, layout restructure, card-based list items, textarea notes editor, API extension). It must be read alongside `SPECIFICATION.md` v1.0 and `SPECIFICATION-RECORDING.md` v1.0, which remain authoritative for all existing functionality. Where this document modifies an existing component, the modification is explicitly called out.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Overview](#2-architecture-overview)
3. [System Components](#3-system-components)
4. [Data Architecture](#4-data-architecture)
5. [API Specifications](#5-api-specifications)
6. [Article Input Modal](#6-article-input-modal)
7. [Layout Restructure](#7-layout-restructure)
8. [Card-Based Article Separation](#8-card-based-article-separation)
9. [Textarea Notes Editor](#9-textarea-notes-editor)
10. [Generated Markdown Overlay](#10-generated-markdown-overlay)
11. [Security Architecture](#11-security-architecture)
12. [Testing Strategy](#12-testing-strategy)
13. [Implementation Plan](#13-implementation-plan)
14. [Risks and Mitigations](#14-risks-and-mitigations)
15. [Appendices](#15-appendices)

---

## 1. Executive Summary

### Project Overview

This specification redesigns the article input workflow for the Cozy News Corner Show Notes Generator. The current flow is fragmented: the user interacts with a sidebar form to add a URL, then must scroll the main list to locate the new item, then manually add recording notes one bullet at a time. The page layout scrolls as a whole, burying the header and action bar off-screen as the list grows.

The redesign introduces five coordinated changes:

1. **Article Input Modal** — A focused overlay where the entire add-and-annotate cycle (paste URL → auto-fetch metadata → review fields → write notes → done) happens in one place.
2. **Layout restructure** — The sidebar is removed; the page adopts a fixed header / scrollable content / fixed footer layout. The "Add Article" button moves to the fixed footer alongside existing action buttons.
3. **Card-based article separation** — Each article in the list renders as a visually distinct card with borders, background, and spacing.
4. **Textarea notes editor** — The `<ul><li contenteditable>` talking-points editor is replaced with a standard `<textarea>` in both the modal and the inline list.
5. **`add_item` API extension** — The API accepts optional `talking_points` in a single request, eliminating a second round-trip.

Additionally, all paste events within the application strip formatting and insert plain text only.

### Key Objectives

| Objective | Measure |
|-----------|---------|
| Zero-scroll input workflow | User completes full add-and-annotate cycle without scrolling the main list |
| Single-context input | URL entry, metadata review, field editing, and note-taking all happen in one overlay |
| Persistent controls | Header (show title, episode meta) and footer (Add Article, Generate, New Episode, Start Recording) are always visible |
| Clear article boundaries | A user can instantly distinguish where one article ends and the next begins |
| Natural note-taking | Arrow keys navigate between lines; multi-line paste works; typing feels like a text editor |
| Plain-text pasting | All paste events throughout the application strip HTML/RTF formatting |
| No speed regression | Rapid-fire URL adding is at least as fast as the current sidebar flow |

### Success Criteria

| Criterion | Measure |
|-----------|---------|
| Input workflow | Paste URL → write notes → "Add Article" in under 10 seconds, zero scrolls |
| Rapid-fire input | 5 articles added via "Add & Next" without closing/reopening the modal |
| Layout stability | Header and footer visible at all times with 20+ items in the list |
| Visual separation | 10+ items in the list — any single article's boundaries are instantly identifiable |
| Notes editor | 5-line paste inserts 5 lines; arrow keys navigate naturally; no custom keydown handlers for navigation |
| No regressions | Recording mode, audience view, story groups, drag-and-drop, markdown generation all pass full regression |

---

## 2. Architecture Overview

### Changes to High-Level Architecture

No new files are introduced. No new execution contexts. The changes are confined to the existing single-page application:

```
┌─────────────────────────────────────────────────────────────┐
│                    Browser (Desktop)                          │
│                                                               │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │  www/index.php + app.js                                  │ │
│  │                                                           │ │
│  │  ┌─────────────────────────────────────────────────────┐ │ │
│  │  │ FIXED HEADER                                         │ │ │
│  │  │  Show title  |  Status indicator                     │ │ │
│  │  │  Episode meta (week, year, YouTube)                  │ │ │
│  │  ├─────────────────────────────────────────────────────┤ │ │
│  │  │ SCROLLABLE CONTENT AREA                              │ │ │
│  │  │  ┌───────────────────────────────────────────────┐  │ │ │
│  │  │  │ Vulnerability section (card list)              │  │ │ │
│  │  │  └───────────────────────────────────────────────┘  │ │ │
│  │  │  ┌───────────────────────────────────────────────┐  │ │ │
│  │  │  │ News section (card list)                       │  │ │ │
│  │  │  └───────────────────────────────────────────────┘  │ │ │
│  │  ├─────────────────────────────────────────────────────┤ │ │
│  │  │ FIXED FOOTER                                         │ │ │
│  │  │  Generate | Add Article | New Episode | Recording    │ │ │
│  │  └─────────────────────────────────────────────────────┘ │ │
│  │                                                           │ │
│  │  ┌─────────────────────────────────────────────────────┐ │ │
│  │  │ OVERLAYS (z-index layer, above fixed header/footer)  │ │ │
│  │  │  • Article Input Modal (add/annotate workflow)       │ │ │
│  │  │  • Generated Markdown Overlay (read-only output)     │ │ │
│  │  │  • Confirm Dialog (existing, unchanged)              │ │ │
│  │  └─────────────────────────────────────────────────────┘ │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTP POST (AJAX)
┌──────────────────────────▼──────────────────────────────────┐
│  www/api.php                                                  │
│  └─ add_item action: now accepts optional talking_points      │
│                                                               │
│  include/Database.php                                         │
│  └─ addItem(): now persists talking_points if provided        │
└───────────────────────────────────────────────────────────────┘
```

### Key Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| "Add Article" button placement | Fixed footer (alongside Generate, New Episode, Start Recording) | All action buttons in one bar; header stays compact for episode meta and title |
| Modal vs. sidebar | Modal overlay | Requirements mandate single-context input; modal eliminates scrolling and context switching entirely |
| Output panel | Modal overlay | Consistent with the input modal pattern; avoids layout complexity of embedding in scrollable area |
| Keyboard shortcut | `Ctrl+Shift+A` | Three-key combo avoids all standard browser shortcuts; `Ctrl+N` (new window), `Ctrl+T` (new tab), `Alt+A` (menu accelerator on Windows/Linux) are all avoided |
| Textarea vs. contenteditable | `<textarea>` for both modal and inline editors | Native keyboard navigation (arrow keys, selection, paste); zero custom keydown handlers for basic editing; eliminates P4 and P5 pain points |
| Plain-text paste | Global `paste` event handler strips formatting | Prevents rich-text artifacts from entering any text field; consistent behavior across the entire application |
| Card-based item separation | CSS cards with border, background, shadow, margin | Directly addresses P2; each item is visually self-contained |
| Toast relocation | Inside scrollable content area, above footer | Prevents overlap with fixed footer |

---

## 3. System Components

### 3.1 Modified: Configuration — `etc/config.php`

**Change:** None. No new configuration constants are needed.

---

### 3.2 Modified: Database Layer — `include/Database.php`

**Change:** One modified method signature.

#### Modified Method: `addItem()`

**Current signature:**

```php
public function addItem(
    string $section,
    string $url,
    string $title,
    string $authorName,
    string $authorUrl
): array
```

**New signature:**

```php
public function addItem(
    string $section,
    string $url,
    string $title,
    string $authorName,
    string $authorUrl,
    string $talkingPoints = ''
): array
```

**Change:** A sixth parameter `$talkingPoints` is added with a default of `''` (empty string), making this change backward-compatible — all existing callers continue to work without modification.

**Implementation:**

```php
public function addItem(
    string $section,
    string $url,
    string $title,
    string $authorName,
    string $authorUrl,
    string $talkingPoints = ''
): array {
    $maxStmt = $this->pdo->prepare(
        'SELECT COALESCE(MAX(sort_order) + 1, 0) AS next_order
         FROM items WHERE section = :section'
    );
    $maxStmt->execute([':section' => $section]);
    $nextOrder = (int) $maxStmt->fetchColumn();

    $insertStmt = $this->pdo->prepare(
        'INSERT INTO items (section, url, title, author_name, author_url, sort_order, talking_points)
         VALUES (:section, :url, :title, :author_name, :author_url, :sort_order, :talking_points)'
    );
    $insertStmt->execute([
        ':section'        => $section,
        ':url'            => $url,
        ':title'          => $title,
        ':author_name'    => $authorName,
        ':author_url'     => $authorUrl,
        ':sort_order'     => $nextOrder,
        ':talking_points' => $talkingPoints !== '' ? $talkingPoints : null,
    ]);

    $newId   = (int) $this->pdo->lastInsertId();
    $rowStmt = $this->pdo->prepare('SELECT * FROM items WHERE id = :id');
    $rowStmt->execute([':id' => $newId]);

    return $rowStmt->fetch();
}
```

**Behavior:**
- If `$talkingPoints` is empty (the default), `NULL` is written to the `talking_points` column — identical to current behavior.
- If non-empty, the provided string is written directly. Empty lines are stripped by the caller (API handler) before reaching this method.

**Acceptance Criteria:**
- `addItem('news', 'https://...', 'Title', 'Author', 'https://...', "Point A\nPoint B")` creates an item with `talking_points = "Point A\nPoint B"`.
- `addItem('news', 'https://...', 'Title', 'Author', 'https://...')` creates an item with `talking_points = NULL` (backward-compatible).
- Prepared statements used exclusively.

---

### 3.3 Modified: API Handler — `www/api.php`

**Change:** One modified action handler. No new actions.

#### Modified: `handleAddItem()`

**Current:**

```php
function handleAddItem(array $body, Database $db): array
{
    // ... validation ...
    $item = $db->addItem($section, $url, (string) $title, (string) $authorName, (string) $authorUrl);
    // ... author history ...
    return jsonSuccess(['item' => $item]);
}
```

**New:**

```php
function handleAddItem(array $body, Database $db): array
{
    $allowedSections = ['vulnerability', 'news'];
    $section    = $body['section'] ?? '';
    $url        = $body['url'] ?? '';
    $title      = $body['title'] ?? '';
    $authorName = $body['author_name'] ?? '';
    $authorUrl  = $body['author_url'] ?? '';
    $rawPoints  = $body['talking_points'] ?? '';

    if (!in_array($section, $allowedSections, true)) {
        return jsonError('section must be "vulnerability" or "news"');
    }
    if (!is_string($url) || $url === '') {
        return jsonError('url is required');
    }

    // Strip empty lines and trim whitespace from talking points.
    $talkingPoints = '';
    if (is_string($rawPoints) && $rawPoints !== '') {
        $lines = array_filter(
            array_map('trim', explode("\n", $rawPoints)),
            fn(string $line) => $line !== ''
        );
        $talkingPoints = implode("\n", $lines);
    }

    $item = $db->addItem(
        $section,
        $url,
        (string) $title,
        (string) $authorName,
        (string) $authorUrl,
        $talkingPoints
    );

    if ($authorName !== '') {
        $domain = extractDomain($url);
        if ($domain !== '') {
            $db->upsertAuthorHistory($domain, (string) $authorName, (string) $authorUrl);
        }
    }

    return jsonSuccess(['item' => $item]);
}
```

**Request format:**

```json
{
    "action": "add_item",
    "section": "news",
    "url": "https://example.com/article",
    "title": "Article Title",
    "author_name": "Jane Doe",
    "author_url": "https://example.com/jane",
    "talking_points": "First point\nSecond point\nThird point"
}
```

The `talking_points` field is **optional**. If omitted or empty, behavior is identical to the current API.

**Response format (unchanged):**

```json
{
    "success": true,
    "data": {
        "item": {
            "id": 42,
            "section": "news",
            "url": "https://example.com/article",
            "title": "Article Title",
            "author_name": "Jane Doe",
            "author_url": "https://example.com/jane",
            "sort_order": 5,
            "talking_points": "First point\nSecond point\nThird point",
            "parent_id": null
        }
    }
}
```

**Acceptance Criteria:**
- A single `add_item` call with `talking_points` creates an item with notes populated.
- Empty lines in `talking_points` are stripped before persistence.
- Omitting `talking_points` from the request body works identically to the current behavior.
- The `update_talking_points` endpoint remains unchanged (used by inline editing).

---

### 3.4 Modified: Page Template — `www/index.php`

**Change:** Remove sidebar HTML; restructure layout to fixed header / scrollable content / fixed footer; add "Add Article" button to footer; add modal HTML skeleton.

#### Removed Elements

- The entire `<aside id="add-item-panel">` block (lines 64–103 in current file) is removed.
- The `#workspace` wrapper `<div>` is removed. The `#item-lists` section becomes a direct child of the scrollable content area.

#### Modified: `<header>`

The header retains the show title and status indicator. Episode metadata remains immediately below the header. Both are fixed to the top.

```html
<header id="app-header">
    <h1><?= $pageTitle ?></h1>
    <span id="status-indicator" aria-live="polite" aria-label="Save status"></span>
</header>

<div id="episode-meta-bar">
    <section id="episode-meta" aria-label="Episode metadata">
        <div class="episode-meta-fields">
            <label for="ep-week">Week</label>
            <input type="number" id="ep-week" name="week_number" min="1" max="53"
                   value="<?= $epWeek ?>" aria-label="Week number">
            <label for="ep-year">Year</label>
            <input type="number" id="ep-year" name="year" min="2020"
                   value="<?= $epYear ?>" aria-label="Year">
            <label for="ep-youtube">YouTube URL</label>
            <input type="url" id="ep-youtube" name="youtube_url"
                   value="<?= $epYoutube ?>" placeholder="https://www.youtube.com/watch?v=..."
                   aria-label="YouTube URL">
        </div>
    </section>
</div>
```

#### Modified: Content Area

The content area contains only the item lists. It scrolls independently between the fixed header/meta bar and the fixed footer.

```html
<div id="content-area">
    <section id="vulnerability-list" aria-label="<?= $vulnLabel ?>">
        <h2><?= $vulnLabel ?></h2>
    </section>
    <section id="news-list" aria-label="<?= $newsLabel ?>">
        <h2><?= $newsLabel ?></h2>
    </section>
</div>
```

#### Modified: Footer (Action Bar)

The action bar becomes a fixed footer. The "Add Article" button is added here.

```html
<footer id="app-footer">
    <button type="button" id="btn-generate">Generate Show Notes</button>
    <button type="button" id="btn-add-article" aria-label="Add Article"
            title="Add Article (Ctrl+Shift+A)">+ Add Article</button>
    <div class="action-bar__right">
        <button type="button" id="btn-new-episode">New Episode</button>
        <button type="button" id="btn-start-recording" class="btn-recording" disabled>
            Start Recording
        </button>
    </div>
</footer>
```

#### New: Article Input Modal Skeleton

The modal HTML is generated by JavaScript (not server-rendered) to keep the template clean. See §6 for the full modal specification.

#### New: Generated Markdown Overlay Skeleton

The output panel becomes an overlay, also generated by JavaScript. See §10 for the full overlay specification.

#### Removed: Output Panel Static HTML

The `<section id="output-panel">` block is removed from the template. The overlay is created dynamically by JS.

**Acceptance Criteria:**
- No sidebar is visible on the page.
- Header (title + episode meta) is fixed at the top.
- Footer (action bar with all buttons) is fixed at the bottom.
- Content area scrolls independently between them.
- "Add Article" button is visible in the footer at all times.

---

### 3.5 Modified: Frontend JavaScript — `www/js/app.js`

**Change:** New modal module; modified rendering functions; replaced talking-points editor; global paste handler; keyboard shortcut listener; removed sidebar-related code.

#### Removed Code

- All sidebar event handlers: `#add-url` input listener, `#btn-fetch` click handler, `#btn-add` click handler, `#add-section` change handler.
- The `#scraped-fields` show/hide logic.
- The `talkingPointsModule` contenteditable bullet editor (replaced by textarea editor).

#### New: `articleInputModal` Module

**Responsibilities:**
- Open/close the Article Input Modal overlay.
- Auto-fetch metadata on URL paste.
- Manage form state (URL, section, title, author name, author URL, talking points).
- Submit via "Add Article" or "Add & Next".
- Discard confirmation on close with unsaved content.
- Keyboard shortcut (`Ctrl+Shift+A`) to open.

**Key functions:**

```
openModal()
  → create overlay DOM, append to <body>, trap focus, auto-focus URL input

closeModal(force = false)
  → if force or fields empty: destroy overlay, restore focus to #btn-add-article
  → if fields have content: call showConfirmDialog() for discard confirmation

handleUrlPaste(e)
  → extract plain text from clipboard
  → if matches /^https?:\/\//: trigger autoFetch()

autoFetch()
  → show loading state (spinner on URL field)
  → apiCall('scrape_url', { url }) → populate title, author_name, author_url
  → on error: show inline warning, fields remain editable

handleSubmit(andNext = false)
  → validate URL is non-empty
  → build payload { action: 'add_item', section, url, title, author_name, author_url, talking_points }
  → apiCall('add_item', payload)
  → on success:
      → update state, re-render appropriate section list
      → show toast "Article added to [Section]"
      → if andNext: clear all fields, re-focus URL input
      → if !andNext: closeModal(force = true)

renderModal()
  → returns DOM structure (see §6 for full layout)
```

#### New: `markdownOverlay` Module

**Responsibilities:**
- Open/close the Generated Markdown overlay.
- Display read-only markdown output with copy-to-clipboard.

**Key functions:**

```
openOverlay(markdown)
  → create overlay DOM with read-only textarea and copy button
  → append to <body>, trap focus

closeOverlay()
  → destroy overlay, restore focus to #btn-generate
```

#### Modified: `renderItem()`

- Item rows render as cards (CSS handles visual separation; see §8).
- Talking-points panel uses `<textarea>` instead of `<ul><li contenteditable>` (see §9).

#### Modified: `renderNewsList()` / `renderVulnerabilityList()`

- No structural changes to the rendering logic beyond the card CSS and textarea editor swap.

#### New: Global Paste Handler

A single `paste` event listener on `document` intercepts all paste events and forces plain-text insertion:

```javascript
document.addEventListener('paste', (e) => {
    // Only intercept in editable contexts (input, textarea, contenteditable)
    const target = e.target;
    const isEditable = target.tagName === 'INPUT'
        || target.tagName === 'TEXTAREA'
        || target.isContentEditable;
    if (!isEditable) return;

    const text = e.clipboardData.getData('text/plain');
    e.preventDefault();

    if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') {
        // Insert at cursor position via execCommand or manual splice
        const start = target.selectionStart;
        const end = target.selectionEnd;
        target.value = target.value.slice(0, start) + text + target.value.slice(end);
        target.selectionStart = target.selectionEnd = start + text.length;
        target.dispatchEvent(new Event('input', { bubbles: true }));
    } else if (target.isContentEditable) {
        document.execCommand('insertText', false, text);
    }
});
```

**Note:** This handler affects all paste events in the application — sidebar forms (now removed), inline edits, modal fields, textarea editors. Any remaining `contenteditable` elements (if any survive the refactor) also get plain-text paste.

#### New: Keyboard Shortcut Listener

```javascript
document.addEventListener('keydown', (e) => {
    // Ctrl+Shift+A — open Article Input Modal
    if (e.ctrlKey && e.shiftKey && e.key === 'A') {
        e.preventDefault();
        articleInputModal.openModal();
    }
});
```

The shortcut is suppressed when the modal is already open or when in recording mode.

#### Modified: Toast Container

The toast container is repositioned inside the content area rather than using `position: fixed`. See §7.5 for CSS details.

#### Modified: Generate Show Notes Handler

The `#btn-generate` click handler now calls `markdownOverlay.openOverlay(markdown)` instead of populating the static `#output-panel`.

**Acceptance Criteria:**
- All sidebar-related JS code is removed.
- `articleInputModal.openModal()` creates and shows the modal.
- `Ctrl+Shift+A` opens the modal from anywhere in the app (except during recording).
- Pasting rich text anywhere in the app results in plain text only.
- "Add & Next" clears the modal and re-focuses the URL field.
- "Generate Show Notes" opens the markdown overlay instead of populating a static panel.
- All existing functionality (drag-and-drop, inline editing, story groups, recording mode) continues to work.

---

### 3.6 Modified: Stylesheet — `www/css/style.css`

**Change:** Remove sidebar styles; add fixed header/footer layout; add scrollable content area; add modal styles; add card-based item separation; add textarea notes editor styles; dark mode updates.

See §7 (Layout), §6.7 (Modal CSS), §8 (Cards), §9.4 (Textarea CSS), and §10.2 (Markdown Overlay CSS) for full CSS specifications.

---

## 4. Data Architecture

### No Schema Changes

The database schema is unchanged. The `talking_points` column on the `items` table (added by SPECIFICATION-RECORDING.md) is reused. The `addItem()` method now writes to it directly when provided.

**Data format (unchanged):**
- `talking_points`: `TEXT`, nullable. Newline-separated string. Empty lines stripped on save. `NULL` when no talking points exist.

---

## 5. API Specifications

### Modified: `add_item`

See §3.3 for the full request/response specification.

**Summary of change:** The `add_item` action now accepts an optional `talking_points` string field. All other actions are unchanged.

### Unchanged Actions

All other API actions remain unchanged:

| Action | Status |
|--------|--------|
| `update_episode` | Unchanged |
| `scrape_url` | Unchanged |
| `update_item` | Unchanged |
| `delete_item` | Unchanged |
| `reorder_items` | Unchanged |
| `reset_episode` | Unchanged |
| `get_author_suggestions` | Unchanged |
| `generate_markdown` | Unchanged |
| `update_talking_points` | Unchanged (used by inline textarea editor) |
| `nest_item` | Unchanged |
| `extract_item` | Unchanged |
| `reorder_group` | Unchanged |

---

## 6. Article Input Modal

### 6.1 Opening the Modal

1. Clicking `#btn-add-article` in the fixed footer opens the modal.
2. Pressing `Ctrl+Shift+A` opens the modal from anywhere in the app.
3. The shortcut is ignored when:
   - The modal is already open.
   - The app is in recording mode (`.recording-mode` on `<body>`).
   - Another modal/overlay is open (confirm dialog, markdown overlay).
4. The modal is created by JavaScript and appended to `<body>`. It is not present in the DOM when closed.

### 6.2 Modal DOM Structure

```html
<div class="modal-backdrop article-input-backdrop" role="presentation">
    <div class="modal-dialog article-input-modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="aim-title">

        <!-- Header -->
        <div class="aim-header">
            <h2 id="aim-title">Add Article</h2>
            <button type="button" class="aim-close" aria-label="Close">&times;</button>
        </div>

        <!-- Body -->
        <div class="aim-body">
            <!-- URL + Section row -->
            <div class="aim-url-row">
                <div class="form-group aim-url-group">
                    <label for="aim-url">URL</label>
                    <input type="url" id="aim-url" placeholder="https://example.com/article"
                           autocomplete="off">
                </div>
                <div class="form-group aim-section-group">
                    <label for="aim-section">Section</label>
                    <select id="aim-section">
                        <option value="news" selected>News</option>
                        <option value="vulnerability">Vulnerability</option>
                    </select>
                </div>
            </div>

            <!-- Fetch button -->
            <button type="button" id="aim-fetch" disabled>Fetch Metadata</button>

            <!-- Metadata fields (shown after fetch or manual entry) -->
            <div class="aim-metadata">
                <div class="form-group">
                    <label for="aim-title-field">Title</label>
                    <input type="text" id="aim-title-field" placeholder="Article title">
                </div>
                <div class="form-group aim-author-group">
                    <label for="aim-author-name">Author Name</label>
                    <input type="text" id="aim-author-name" placeholder="Author name"
                           autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="aim-author-url">Author URL</label>
                    <input type="url" id="aim-author-url" placeholder="https://example.com/author">
                </div>
            </div>

            <!-- Talking points (visible only when section = "news") -->
            <div class="aim-notes" id="aim-notes-section">
                <label for="aim-talking-points">
                    Recording Notes
                    <span class="aim-notes-hint">— not included in show notes</span>
                </label>
                <textarea id="aim-talking-points" rows="6"
                          placeholder="One talking point per line..."></textarea>
            </div>
        </div>

        <!-- Footer -->
        <div class="aim-footer">
            <button type="button" id="aim-add" class="aim-btn-primary" disabled>
                Add Article
            </button>
            <button type="button" id="aim-add-next" class="aim-btn-secondary" disabled>
                Add &amp; Next
            </button>
        </div>
    </div>
</div>
```

### 6.3 Focus Management

1. On open: focus is set to `#aim-url`.
2. Focus is trapped within the modal. Tab from the last focusable element wraps to the first; Shift+Tab from the first wraps to the last.
3. Focusable elements in tab order: `#aim-url`, `#aim-section`, `#aim-fetch`, `#aim-title-field`, `#aim-author-name`, `#aim-author-url`, `#aim-talking-points` (if visible), `#aim-add`, `#aim-add-next`, `.aim-close`.
4. On close: focus returns to `#btn-add-article`.

### 6.4 Dismissing the Modal

The modal is dismissible via:

1. **Escape key** — captured by a `keydown` listener on the modal.
2. **Close button (×)** — click on `.aim-close`.
3. **Backdrop click** — click on `.article-input-backdrop` (not the dialog itself).

**Discard confirmation logic:**

```
hasContent = aim-url.value || aim-title-field.value
          || aim-author-name.value || aim-talking-points.value

if (hasContent) {
    showConfirmDialog({
        title: 'Discard unsaved changes?',
        message: 'The article has not been added. Discard all entered data?',
        confirmLabel: 'Discard',
        cancelLabel: 'Keep Editing',
        onConfirm: () => closeModal(force = true)
    });
} else {
    closeModal(force = true);
}
```

The existing `showConfirmDialog()` infrastructure is reused unchanged.

### 6.5 Auto-Fetch on Paste

When the user pastes into `#aim-url`:

1. The global paste handler fires first, inserting plain text.
2. After the `input` event fires, the modal checks if the URL field value matches `^https?://`.
3. If it matches and no fetch is currently in progress:
   - The "Fetch Metadata" button enters loading state.
   - `apiCall('scrape_url', { url })` is called.
   - During the fetch, the rest of the modal remains interactive (user can change section, start typing notes).
4. On success: `#aim-title-field`, `#aim-author-name`, `#aim-author-url` are populated with scraped values (only if still empty — user edits are not overwritten).
5. On failure: An inline warning appears below the URL field (e.g., "Could not fetch metadata — fill fields manually"). The warning is non-blocking.

The "Fetch Metadata" button is enabled whenever the URL field contains a valid-looking URL. Clicking it triggers a manual fetch (same logic as auto-fetch). This covers:
- Re-fetching after editing the URL.
- Retrying after a failed auto-fetch.
- Fetching when the URL was typed rather than pasted.

### 6.6 Section Selector Behavior

- Default: "News" (selected).
- When "News" is selected: the `#aim-notes-section` (talking points textarea) is visible.
- When "Vulnerability" is selected: `#aim-notes-section` is hidden. The textarea value is preserved but not sent in the API call for vulnerability items.

### 6.7 Submit Buttons

**"Add Article" (`#aim-add`):**
1. Builds the `add_item` payload including `talking_points` (only for news section).
2. Calls the API.
3. On success:
   - Updates local state with the new item.
   - Re-renders the appropriate section list.
   - Shows a success toast: "Article added to News" or "Article added to Vulnerability".
   - Closes the modal (`closeModal(force = true)`).

**"Add & Next" (`#aim-add-next`):**
1. Same API call as "Add Article".
2. On success:
   - Updates local state and re-renders.
   - Shows the same success toast.
   - Clears all modal fields (URL, title, author name, author URL, talking points).
   - Resets the "Fetch Metadata" button to disabled.
   - Sets focus to `#aim-url`.
   - The modal remains open. The section selector retains its current value.

**Both buttons are disabled when `#aim-url` is empty.** They are enabled as soon as the URL field has any non-whitespace content.

### 6.8 Author Suggestion Dropdown

The existing author suggestion dropdown (domain-aware, debounced 300ms) is reused in the modal. When `#aim-author-name` receives focus or input:

1. The domain is extracted from the current `#aim-url` value.
2. `apiCall('get_author_suggestions', { domain, query })` is called.
3. The suggestion list is rendered below `#aim-author-name` using the same `.author-suggest-list` markup and styles.
4. Clicking a suggestion fills both `#aim-author-name` and `#aim-author-url`.

The dropdown uses the same DOM structure and CSS as the current inline-edit author suggestions. The positioning logic accounts for the modal's coordinate system (the `.aim-author-group` is `position: relative`).

### 6.9 Modal CSS

**New CSS custom properties:**

```css
:root {
    /* Article Input Modal */
    --aim-width:          min(600px, calc(100vw - 48px));
    --aim-max-height:     calc(100vh - 48px);
}
```

**Modal styles:**

```css
/* Reuse existing .modal-backdrop positioning and z-index */
.article-input-backdrop {
    /* Inherits from .modal-backdrop: position: fixed; inset: 0; z-index: 500; */
}

.article-input-modal {
    width: var(--aim-width);
    max-height: var(--aim-max-height);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.aim-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-md) var(--spacing-lg);
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

.aim-header h2 {
    font-size: 1.1rem;
    margin: 0;
}

.aim-close {
    background: none;
    border: none;
    font-size: 1.4rem;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: 4px 8px;
    border-radius: var(--radius);
    line-height: 1;
}

.aim-close:hover {
    color: var(--color-text);
    background: var(--color-bg);
}

.aim-body {
    padding: var(--spacing-lg);
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
    overflow-y: auto;
    flex: 1;
}

.aim-url-row {
    display: flex;
    gap: var(--spacing-md);
    align-items: flex-end;
}

.aim-url-group {
    flex: 1;
}

.aim-section-group {
    width: 140px;
    flex-shrink: 0;
}

.aim-metadata {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
    border-top: 1px solid var(--color-border);
    padding-top: var(--spacing-md);
}

.aim-notes {
    border-top: 1px solid var(--color-border);
    padding-top: var(--spacing-md);
}

.aim-notes label {
    display: block;
    font-size: 0.85rem;
    color: var(--color-text);
    margin-bottom: 4px;
}

.aim-notes-hint {
    font-style: italic;
    color: var(--color-text-muted);
    font-size: 0.8rem;
}

.aim-footer {
    display: flex;
    gap: var(--spacing-sm);
    padding: var(--spacing-md) var(--spacing-lg);
    border-top: 1px solid var(--color-border);
    flex-shrink: 0;
}

.aim-btn-primary {
    padding: 8px var(--spacing-lg);
    border: none;
    border-radius: var(--radius);
    background-color: var(--color-primary);
    color: #fff;
    font-family: var(--font-sans);
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.15s;
}

.aim-btn-primary:hover:not(:disabled) {
    background-color: var(--color-primary-dark);
}

.aim-btn-primary:disabled,
.aim-btn-secondary:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.aim-btn-secondary {
    padding: 8px var(--spacing-lg);
    border: 1px solid var(--color-primary);
    border-radius: var(--radius);
    background-color: transparent;
    color: var(--color-primary);
    font-family: var(--font-sans);
    font-size: 0.95rem;
    cursor: pointer;
    transition: background-color 0.15s, color 0.15s;
}

.aim-btn-secondary:hover:not(:disabled) {
    background-color: var(--color-primary);
    color: #fff;
}
```

**Dark mode:** All modal styles use CSS custom properties (`--color-surface`, `--color-border`, `--color-text`, etc.), so the dark mode override in the `@media (prefers-color-scheme: dark)` block applies automatically. No additional dark mode rules are needed.

**Acceptance Criteria:**
- Modal is centered, ~600px wide (or viewport minus padding on smaller screens).
- The notes textarea shows ~6 visible lines.
- Modal scrolls internally if content exceeds viewport height.
- All fields use existing CSS variables — dark mode works automatically.
- Author suggestion dropdown renders correctly within the modal.

---

## 7. Layout Restructure

### 7.1 Overall Layout

The page layout changes from a scrolling document to a fixed header / scrollable content / fixed footer arrangement:

```
┌──────────────────────────────────────────────────────┐
│ FIXED HEADER (#app-header)                            │
│   Show Title                        Status Indicator  │
├──────────────────────────────────────────────────────┤
│ FIXED META BAR (#episode-meta-bar)                    │
│   Week [__] Year [____] YouTube URL [___________]     │
├──────────────────────────────────────────────────────┤
│                                                        │
│ SCROLLABLE CONTENT (#content-area)                     │
│ ┌──────────────────────────────────────────────────┐  │
│ │  ┌──────────────────────────────────────────────┐│  │
│ │  │ Vulnerability                                ││  │
│ │  │  ┌─────────────────────────────────────────┐ ││  │
│ │  │  │ Card: Vuln Item 1                       │ ││  │
│ │  │  └─────────────────────────────────────────┘ ││  │
│ │  │  ┌─────────────────────────────────────────┐ ││  │
│ │  │  │ Card: Vuln Item 2                       │ ││  │
│ │  │  └─────────────────────────────────────────┘ ││  │
│ │  └──────────────────────────────────────────────┘│  │
│ │  ┌──────────────────────────────────────────────┐│  │
│ │  │ News                                         ││  │
│ │  │  ┌─────────────────────────────────────────┐ ││  │
│ │  │  │ Card: News Item 1  [textarea]           │ ││  │
│ │  │  └─────────────────────────────────────────┘ ││  │
│ │  │  ┌─────────────────────────────────────────┐ ││  │
│ │  │  │ Card: News Item 2  [textarea]           │ ││  │
│ │  │  └─────────────────────────────────────────┘ ││  │
│ │  └──────────────────────────────────────────────┘│  │
│ └──────────────────────────────────────────────────┘  │
│                                                        │
├──────────────────────────────────────────────────────┤
│ FIXED FOOTER (#app-footer)                             │
│  [Generate]  [+ Add Article]    [New Episode] [Rec]    │
└──────────────────────────────────────────────────────┘
```

### 7.2 Fixed Header CSS

```css
#app-header {
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    background-color: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
    padding: var(--spacing-sm) var(--spacing-md);
}

#episode-meta-bar {
    position: sticky;
    top: 0;  /* adjusted by JS or calc to sit below #app-header */
    z-index: 99;
    background-color: var(--color-surface);
    border-bottom: 1px solid var(--color-border);
    padding: var(--spacing-sm) var(--spacing-md);
}
```

**Implementation note:** Since both the header and the meta bar are `position: sticky` with `top: 0`, the meta bar needs its `top` offset to equal the height of the header. Two approaches:

- **Option A (preferred):** Wrap both in a single sticky container:
  ```css
  #app-top {
      position: sticky;
      top: 0;
      z-index: 100;
  }
  ```
  Where `#app-top` contains both `#app-header` and `#episode-meta-bar`. This avoids measuring header height.

- **Option B:** Use a CSS custom property set by JS on load: `--header-height`. Set `#episode-meta-bar { top: var(--header-height); }`.

**Recommendation:** Option A — wrap both in `#app-top`.

### 7.3 Scrollable Content CSS

```css
#content-area {
    flex: 1;
    overflow-y: auto;
    padding: var(--spacing-lg);
    display: flex;
    flex-direction: column;
    gap: var(--spacing-lg);
}
```

The `<body>` and `<main>` adopt a flex-column layout to enable the content area to fill the remaining vertical space:

```css
body {
    margin: 0;
    font-family: var(--font-sans);
    background-color: var(--color-bg);
    color: var(--color-text);
    line-height: 1.5;
    height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;  /* prevent body scroll; content area scrolls */
}

main {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;     /* allow flex child to shrink below content size */
}

.prep-ui {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}
```

### 7.4 Fixed Footer CSS

```css
#app-footer {
    position: sticky;
    bottom: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-sm) var(--spacing-lg);
    background-color: var(--color-surface);
    border-top: 1px solid var(--color-border);
    flex-shrink: 0;
}
```

**"Add Article" button styling:**

```css
#btn-add-article {
    padding: 8px var(--spacing-lg);
    border: none;
    border-radius: var(--radius);
    background-color: var(--color-primary);
    color: #fff;
    font-family: var(--font-sans);
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.15s;
}

#btn-add-article:hover {
    background-color: var(--color-primary-dark);
}
```

### 7.5 Toast Repositioning

The toast container moves from `position: fixed; bottom: 24px; right: 24px` to being anchored inside the scrollable content area, just above the footer. Since toasts should float above the content but not overlap the footer:

```css
#toast-container {
    position: fixed;
    bottom: calc(48px + var(--spacing-md));  /* footer height + gap */
    right: var(--spacing-lg);
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
    z-index: 200;  /* above content, below modals (500) */
    pointer-events: none;
}
```

This positions toasts above the footer but below any modal overlay.

### 7.6 Removed Styles

The following CSS rules are removed:

```css
/* Remove: #workspace grid layout */
#workspace { ... }

/* Remove: #add-item-panel and all children */
#add-item-panel { ... }
#add-item-panel h2 { ... }
#add-item-panel button { ... }
#add-item-panel button:hover:not(:disabled) { ... }
#add-item-panel button:disabled { ... }
#btn-fetch.loading { ... }
#scraped-fields { ... }

/* Remove: static #output-panel (replaced by overlay) */
#output-panel { ... }
#output-panel h2 { ... }
#output-panel[hidden] { ... }
```

**Acceptance Criteria:**
- With 20+ items, the user can scroll through articles while the "Add Article" button (footer) and show title (header) remain visible.
- No content is hidden behind the fixed header or footer.
- Body does not scroll — only `#content-area` scrolls.
- Layout works at 1024px+ viewport width.
- Dark mode renders correctly for all new layout elements.

---

## 8. Card-Based Article Separation

### 8.1 Design

Each `.item-row` in both the vulnerability and news sections renders as a visually distinct card:

```
┌─────────────────────────────────────────────────────────┐
│ ⠿  Title: Article Title                            [×] │
│     By: Author Name                                     │
│     URL: https://example.com/article                    │
│     ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─                │
│     Recording Notes — not included in show notes        │
│     ┌─────────────────────────────────────────────────┐ │
│     │ Talking point 1                                 │ │
│     │ Talking point 2                                 │ │
│     │ Talking point 3                                 │ │
│     └─────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘
          ↕ 12px gap (margin between cards)
┌─────────────────────────────────────────────────────────┐
│ ⠿  Title: Another Article                          [×] │
│     ...                                                  │
└─────────────────────────────────────────────────────────┘
```

### 8.2 Card CSS

```css
.item-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: var(--spacing-sm);
    padding: var(--spacing-md);
    position: relative;
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
    margin-bottom: var(--spacing-sm);
}

.item-row:last-child {
    margin-bottom: 0;
}
```

**Changes from current `.item-row`:**

| Property | Before | After |
|----------|--------|-------|
| `padding` | `var(--spacing-sm) 0` | `var(--spacing-md)` (all sides) |
| `border-bottom` | `1px solid var(--color-border)` | Removed |
| `border` | None | `1px solid var(--color-border)` (all four sides) |
| `border-radius` | None | `var(--radius)` (6px) |
| `box-shadow` | None | `0 1px 3px rgba(0, 0, 0, 0.06)` |
| `margin-bottom` | None | `var(--spacing-sm)` (8px gap between cards) |
| `background-color` | `var(--color-surface)` | `var(--color-surface)` (unchanged, but now visible against `--color-bg`) |

### 8.3 Dark Mode Card Adjustments

```css
@media (prefers-color-scheme: dark) {
    .item-row {
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }
}
```

The border and background use CSS custom properties, so they adapt automatically. The shadow is made slightly stronger in dark mode for visibility.

### 8.4 Story Group Interaction

Story groups (`.story-group`) already have distinct visual styling (left border, background, padding). The card styling targets standalone `.item-row` elements only. Within a story group, secondary items already have their own card-like border and radius (`.story-group__secondaries .item-row`).

The primary item row inside a story group does **not** get an additional card border — it inherits the group container's visual identity. To achieve this:

```css
/* Standalone and top-level item rows get card styling */
.item-row {
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
    margin-bottom: var(--spacing-sm);
    padding: var(--spacing-md);
}

/* Primary row inside a story group — no double border */
.story-group__primary .item-row {
    border: none;
    box-shadow: none;
    margin-bottom: 0;
    padding: var(--spacing-sm) 0;
}
```

### 8.5 Drag Handle

The drag handle (⠿) remains visually associated with each card. No changes to `.drag-handle` positioning or styling — the increased card padding naturally accommodates it.

**Acceptance Criteria:**
- A user viewing 10+ items can instantly point to the boundaries of any single article.
- Visual separation is clear but not heavy-handed (subtle shadow, 1px border, 8px gap).
- Dark mode styling is equally clear.
- Story group items maintain their existing distinct visual identity.
- Drag handles remain functional and properly positioned within cards.

---

## 9. Textarea Notes Editor

### 9.1 Editor Model

The talking-points editor replaces the current `<ul><li contenteditable>` with a standard `<textarea>`. This applies to:

1. **Article Input Modal** — `#aim-talking-points` (see §6.2).
2. **Inline list editor** — the talking-points panel within each news item card.

Each line of text (separated by `\n`) represents one talking point. The `<textarea>` provides native keyboard navigation:

- Arrow keys (up/down/left/right) navigate naturally.
- Enter creates a new line.
- Multi-line paste inserts all lines.
- Standard selection (Shift+Arrow, Ctrl+A) works natively.
- No custom `keydown` handlers are needed for basic editing.

### 9.2 Inline Editor (List)

The inline talking-points panel in each news item card changes from:

```html
<!-- Before: contenteditable bullets -->
<div class="talking-points-panel">
    <span class="talking-points-panel__label">
        Recording Notes — not included in show notes
    </span>
    <ul>
        <li contenteditable data-placeholder="Add talking point...">Point 1</li>
        <li contenteditable data-placeholder="Add talking point...">Point 2</li>
        <li contenteditable data-placeholder="Add talking point..."></li>
    </ul>
</div>
```

To:

```html
<!-- After: textarea -->
<div class="talking-points-panel">
    <span class="talking-points-panel__label">
        Recording Notes — not included in show notes
    </span>
    <textarea class="talking-points-textarea"
              rows="3"
              placeholder="One talking point per line..."
              data-item-id="42"></textarea>
</div>
```

### 9.3 Display Rendering (Read-Only Context)

When displaying talking points in a **non-editing** context (e.g., if the panel is collapsed or read-only), each line renders as a visually distinct bullet:

```html
<div class="talking-points-display">
    <ul>
        <li>Point 1</li>
        <li>Point 2</li>
        <li>Point 3</li>
    </ul>
</div>
```

Recording mode and audience view continue to display talking points as bullet lists, reading from `item.talking_points` data (not DOM). No changes to recording mode rendering.

### 9.4 Auto-Save Behavior

The inline textarea auto-saves on:

1. **Blur** — when the textarea loses focus.
2. **Debounce** — 800ms after the last keystroke (consistent with other field debounce timers).

On save:
1. The textarea value is split by `\n`.
2. Empty lines are stripped.
3. The cleaned string is sent via `apiCall('update_talking_points', { id, talking_points })`.
4. The existing `update_talking_points` endpoint is reused unchanged.

### 9.5 Textarea CSS

```css
/* Shared textarea styles for both modal and inline */
.talking-points-textarea,
#aim-talking-points {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: var(--spacing-sm);
    font-family: var(--font-sans);
    font-size: 0.85rem;
    color: var(--color-text);
    background-color: var(--color-bg);
    resize: vertical;
    line-height: 1.6;
}

.talking-points-textarea:hover,
#aim-talking-points:hover {
    border-color: var(--color-primary);
}

.talking-points-textarea:focus-visible,
#aim-talking-points:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}

/* Inline textarea in list — compact sizing */
.talking-points-textarea {
    min-height: 2.4em;   /* at least one visible line */
    max-height: 200px;   /* cap expansion in the list */
}

/* Modal textarea — more room */
#aim-talking-points {
    min-height: 8em;     /* ~5-6 visible lines */
}
```

### 9.6 Removed Styles

The following CSS rules are removed:

```css
/* Remove: contenteditable bullet styles */
.talking-points-panel ul { ... }
.talking-points-panel li::marker { ... }
.talking-points-panel li[contenteditable] { ... }
.talking-points-panel li[contenteditable]:focus-visible { ... }
.talking-points-panel li[contenteditable][data-placeholder]:empty::before { ... }
```

### 9.7 Data Format

The persisted data format is unchanged:

- Column: `talking_points TEXT` (nullable).
- Format: newline-separated string.
- Empty lines stripped on save.
- `NULL` when no talking points exist.

The textarea value maps directly to this format — no conversion needed beyond stripping empty lines.

**Acceptance Criteria:**
- User can paste a 5-line block of text and see 5 lines in the editor.
- Arrow keys move through lines naturally with zero custom keydown handling.
- Saved data format is identical to current (newline-separated string, empty lines stripped).
- Recording mode displays talking points correctly from the saved data.
- Auto-save fires on blur and after 800ms debounce.
- Both modal and inline editors use the same `<textarea>` approach.

---

## 10. Generated Markdown Overlay

### 10.1 Overlay Behavior

The "Generate Show Notes" button (`#btn-generate`) now opens a modal overlay instead of revealing a static panel:

1. Click `#btn-generate` → `apiCall('generate_markdown')` → on success: `markdownOverlay.openOverlay(markdown)`.
2. The overlay displays the generated markdown in a read-only textarea.
3. A "Copy to Clipboard" button copies the content and shows a "Copied!" state for 2 seconds.
4. The overlay is dismissible via Escape, close button (×), or backdrop click. No discard confirmation needed (content is read-only).

### 10.2 Overlay DOM Structure

```html
<div class="modal-backdrop markdown-overlay-backdrop" role="presentation">
    <div class="modal-dialog markdown-overlay"
         role="dialog"
         aria-modal="true"
         aria-labelledby="mo-title">

        <div class="mo-header">
            <h2 id="mo-title">Generated Markdown</h2>
            <button type="button" class="mo-close" aria-label="Close">&times;</button>
        </div>

        <div class="mo-body">
            <textarea readonly id="mo-markdown"
                      aria-label="Generated Markdown content"
                      rows="20"></textarea>
        </div>

        <div class="mo-footer">
            <button type="button" id="mo-copy">📋 Copy to Clipboard</button>
        </div>
    </div>
</div>
```

### 10.3 Overlay CSS

```css
.markdown-overlay {
    width: min(800px, calc(100vw - 48px));
    max-height: calc(100vh - 48px);
    display: flex;
    flex-direction: column;
}

.mo-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-md) var(--spacing-lg);
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
}

.mo-header h2 {
    font-size: 1.1rem;
    margin: 0;
}

.mo-close {
    background: none;
    border: none;
    font-size: 1.4rem;
    color: var(--color-text-muted);
    cursor: pointer;
    padding: 4px 8px;
    border-radius: var(--radius);
    line-height: 1;
}

.mo-close:hover {
    color: var(--color-text);
    background: var(--color-bg);
}

.mo-body {
    padding: var(--spacing-lg);
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

#mo-markdown {
    width: 100%;
    flex: 1;
    box-sizing: border-box;
    font-family: var(--font-mono);
    font-size: 0.85rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: var(--spacing-sm);
    resize: none;
    background-color: var(--color-bg);
    color: var(--color-text);
    min-height: 300px;
}

.mo-footer {
    padding: var(--spacing-md) var(--spacing-lg);
    border-top: 1px solid var(--color-border);
    flex-shrink: 0;
}

#mo-copy {
    padding: 7px var(--spacing-md);
    border: none;
    border-radius: var(--radius);
    background-color: var(--color-primary);
    color: #fff;
    font-family: var(--font-sans);
    font-size: 0.9rem;
    cursor: pointer;
    transition: background-color 0.15s;
}

#mo-copy:hover {
    background-color: var(--color-primary-dark);
}

#mo-copy.copied {
    background-color: var(--color-primary-dark);
}
```

**Focus management:** On open, focus moves to `#mo-copy` (the primary action). On close, focus returns to `#btn-generate`.

**Acceptance Criteria:**
- "Generate Show Notes" opens an overlay with the markdown content.
- Copy-to-clipboard works and shows "Copied!" for 2 seconds.
- Escape, close button, or backdrop click dismisses the overlay.
- The overlay uses existing CSS variables; dark mode works automatically.

---

## 11. Security Architecture

### No New Security Concerns

All changes are UI-layer modifications. The security baseline established in SPECIFICATION.md v1.0 applies unchanged:

- **Prepared statements:** The modified `addItem()` method uses prepared statements.
- **Input validation:** The `handleAddItem()` handler validates `talking_points` as a string before use.
- **SSRF protection:** The `scrape_url` action is unchanged.
- **No new endpoints:** No new API actions are introduced.
- **Plain-text paste:** Stripping formatting on paste prevents potential XSS vectors from rich-text clipboard content entering contenteditable elements.

---

## 12. Testing Strategy

### 12.1 Article Input Modal Tests

| # | Test | Expected |
|---|------|----------|
| T1 | Click "Add Article" in footer | Modal opens, URL field focused |
| T2 | Press `Ctrl+Shift+A` | Modal opens |
| T3 | Press `Ctrl+Shift+A` during recording mode | Nothing happens |
| T4 | Press `Ctrl+Shift+A` with modal already open | Nothing happens |
| T5 | Paste valid URL into URL field | Auto-fetch triggers; metadata fields populate |
| T6 | Paste invalid text into URL field | No auto-fetch; no error |
| T7 | Auto-fetch fails (unreachable URL) | Inline warning; fields remain editable |
| T8 | Click "Fetch Metadata" after editing URL | Re-fetches with new URL |
| T9 | Change section to Vulnerability | Notes area hides |
| T10 | Change section back to News | Notes area reappears with previous content |
| T11 | Fill URL + notes → click "Add Article" | Item created with talking points; modal closes; toast shown |
| T12 | Fill URL + notes → click "Add & Next" | Item created; modal stays open; fields cleared; URL focused |
| T13 | Add 5 articles via "Add & Next" | All 5 appear in list; modal stays open throughout |
| T14 | Press Escape with content in fields | Discard confirmation shown |
| T15 | Press Escape with empty fields | Modal closes immediately |
| T16 | Click backdrop with content | Discard confirmation shown |
| T17 | Confirm discard | Modal closes; no item created |
| T18 | Cancel discard | Modal stays open; content preserved |
| T19 | Tab through all fields | Focus cycles through all fields and buttons correctly |
| T20 | Shift+Tab from first field | Focus wraps to close button |
| T21 | Submit with empty URL | Buttons disabled; nothing happens |
| T22 | Author suggestions in modal | Dropdown appears; selecting fills author name + URL |

### 12.2 Layout Tests

| # | Test | Expected |
|---|------|----------|
| T23 | Load page with 20+ items | Header and footer visible; content scrolls between them |
| T24 | Scroll content area | Header/footer remain fixed |
| T25 | No sidebar visible | Single-column layout; lists use full width |
| T26 | Footer buttons | Generate, Add Article, New Episode, Start Recording all present |
| T27 | Resize to 1024px width | Layout does not break |
| T28 | Toast with fixed footer | Toast appears above footer, not overlapping |

### 12.3 Card Separation Tests

| # | Test | Expected |
|---|------|----------|
| T29 | View 10+ items | Each item is a visually distinct card with border and gap |
| T30 | Dark mode | Cards have visible borders and subtle shadow |
| T31 | Story group items | Group maintains its existing visual identity; no double border on primary |
| T32 | Drag-and-drop | Cards drag correctly; drop indicators appear between cards |

### 12.4 Textarea Editor Tests

| # | Test | Expected |
|---|------|----------|
| T33 | Paste 5-line text into modal textarea | 5 lines appear |
| T34 | Arrow keys in textarea | Navigate naturally between lines |
| T35 | Inline textarea: type notes, blur | Auto-saves; data persists on reload |
| T36 | Inline textarea: type notes, wait 800ms | Auto-saves via debounce |
| T37 | Saved data format | Newline-separated; empty lines stripped |
| T38 | Recording mode | Talking points display as bullet list from saved data |

### 12.5 Plain-Text Paste Tests

| # | Test | Expected |
|---|------|----------|
| T39 | Copy rich text (bold, italic) from web page, paste into URL field | Plain text only |
| T40 | Copy rich text, paste into textarea | Plain text only |
| T41 | Copy rich text, paste into inline edit input | Plain text only |
| T42 | Copy plain text, paste anywhere | Works normally |

### 12.6 Generated Markdown Overlay Tests

| # | Test | Expected |
|---|------|----------|
| T43 | Click "Generate Show Notes" | Overlay opens with markdown content |
| T44 | Click "Copy to Clipboard" | Content copied; button shows "Copied!" for 2s |
| T45 | Press Escape | Overlay closes |
| T46 | Click backdrop | Overlay closes |
| T47 | Focus management | Focus moves to copy button on open; returns to Generate on close |

### 12.7 Regression Tests

| # | Test | Expected |
|---|------|----------|
| T48 | Drag-and-drop reorder (both sections) | Works correctly with new card layout |
| T49 | Story group nesting/extraction | Unchanged behavior |
| T50 | Inline field editing (click to edit) | Still works for all fields |
| T51 | Episode metadata auto-save | Still works |
| T52 | Recording mode start/exit | Prep UI hidden/restored correctly |
| T53 | Audience view navigation | Unchanged |
| T54 | WebSocket sync | Unchanged |
| T55 | New Episode reset | Clears all items; episode meta reset |
| T56 | Markdown output format | Byte-identical to pre-change output for equivalent input |
| T57 | Delete item confirmation | Still uses existing confirm dialog |

---

## 13. Implementation Plan

### Phase 1 — API Extension and Database Change

**Files:** `include/Database.php`, `www/api.php`

**Tasks:**
1. Add `$talkingPoints` parameter (with default `''`) to `Database::addItem()`.
2. Update the INSERT statement to include `talking_points`.
3. Modify `handleAddItem()` in `api.php` to read `talking_points` from request body, strip empty lines, pass to `addItem()`.
4. Test: `add_item` with and without `talking_points` via manual API calls.

**Acceptance:** API call `{ action: "add_item", ..., talking_points: "A\nB" }` creates an item with `talking_points = "A\nB"`. Omitting the field creates an item with `talking_points = NULL`.

---

### Phase 2 — Layout Restructure

**Files:** `www/index.php`, `www/css/style.css`, `www/js/app.js`

**Tasks:**
1. Restructure `index.php`: remove sidebar HTML; wrap header + meta bar in `#app-top`; create `#content-area`; convert action bar to `#app-footer`; add `#btn-add-article`.
2. Add CSS: fixed header (`#app-top`), scrollable content (`#content-area`), fixed footer (`#app-footer`), body flex layout.
3. Remove CSS: `#workspace` grid, `#add-item-panel` and children, static `#output-panel`.
4. Update JS: remove sidebar event handlers (`#add-url`, `#btn-fetch`, `#btn-add`, `#add-section`); remove sidebar-related rendering code.
5. Reposition toast container above footer.
6. Verify no content hidden behind fixed header/footer (padding/offset).

**Acceptance:** Page loads with fixed header/footer, full-width content area, no sidebar. All existing features still work (inline editing, DnD, story groups). Toasts appear above footer.

---

### Phase 3 — Card-Based Article Separation

**Files:** `www/css/style.css`

**Tasks:**
1. Update `.item-row` styles: add border (all sides), border-radius, box-shadow, margin-bottom, increased padding.
2. Remove old `border-bottom` separation.
3. Add exception for `.story-group__primary .item-row` (no double border).
4. Add dark mode shadow adjustment.
5. Verify story group and secondary item styling remains correct.

**Acceptance:** Each standalone/secondary item is a visually distinct card. Story groups maintain their existing appearance. Dark mode contrast meets WCAG AA.

---

### Phase 4 — Textarea Notes Editor

**Files:** `www/js/app.js`, `www/css/style.css`

**Tasks:**
1. Replace contenteditable bullet rendering in `renderItem()` with `<textarea>`.
2. Add textarea auto-save: blur handler + 800ms debounce, calling `update_talking_points`.
3. Add textarea CSS (both inline and modal variants).
4. Remove contenteditable bullet CSS.
5. Remove the old `talkingPointsModule` contenteditable handlers (Enter/Backspace/keydown).
6. Add global plain-text paste handler on `document`.
7. Verify recording mode still reads talking points from `item.talking_points` data.

**Acceptance:** Textarea editor works inline. Arrow keys navigate natively. Paste inserts plain text. Auto-save functions correctly. Recording mode talking points display unchanged.

---

### Phase 5 — Article Input Modal

**Files:** `www/js/app.js`, `www/css/style.css`

**Tasks:**
1. Implement `articleInputModal` module: `openModal()`, `closeModal()`, `renderModal()`, `handleUrlPaste()`, `autoFetch()`, `handleSubmit()`.
2. Wire `#btn-add-article` click → `openModal()`.
3. Implement `Ctrl+Shift+A` keyboard shortcut.
4. Implement focus trapping.
5. Implement discard confirmation (reuse `showConfirmDialog()`).
6. Implement auto-fetch on paste.
7. Implement "Add Article" and "Add & Next" submit flows.
8. Implement author suggestion dropdown within modal.
9. Implement section selector show/hide for notes area.
10. Add modal CSS.
11. Add `prefers-reduced-motion` check: disable modal open/close animation.

**Acceptance:** Full add-and-annotate cycle works in the modal. "Add & Next" allows rapid-fire input. Discard confirmation works. Keyboard shortcut opens the modal. Focus is trapped.

---

### Phase 6 — Generated Markdown Overlay

**Files:** `www/js/app.js`, `www/css/style.css`

**Tasks:**
1. Implement `markdownOverlay` module: `openOverlay()`, `closeOverlay()`.
2. Wire `#btn-generate` click → generate API call → `openOverlay(markdown)`.
3. Implement copy-to-clipboard with "Copied!" feedback.
4. Add overlay CSS.
5. Remove static `#output-panel` JS references.

**Acceptance:** "Generate Show Notes" opens overlay. Copy works. Escape/backdrop/close button dismiss it. Focus management correct.

---

### Phase 7 — Integration and Regression Testing

**Tasks:**
1. Run all tests from §12.1–12.7.
2. Verify recording mode end-to-end (prep → record → audience → exit).
3. Verify markdown output is byte-identical to pre-change output.
4. Test dark mode for all new components.
5. Test at 1024px and 1920px viewport widths.
6. Test with `prefers-reduced-motion: reduce`.

---

## 14. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Modal may feel heavy for quick URL-only adds | Medium | Medium | "Add & Next" provides fast path; notes are optional; URL is the only required field |
| Removing sidebar changes layout significantly — may affect story groups, DnD, recording mode | Medium | Medium | Phase 2 is purely layout; tested before adding modal; regression suite in Phase 7 |
| Auto-fetch on paste may trigger unwanted scrapes (partial text, non-URL) | Medium | Low | Only auto-fetch if value matches `^https?://`; manual "Fetch" button as fallback |
| Fixed header/footer may interact poorly with confirm dialog or recording mode z-index | Low | Medium | Modal z-index (500) > fixed bars (100); recording mode (1000) > all; toasts (200) between them |
| `Ctrl+Shift+A` may conflict with browser extensions on some systems | Low | Low | Three-key combo minimizes conflicts; shortcut discoverable via button tooltip |
| Global plain-text paste handler may interfere with unexpected contexts | Low | Low | Handler only intercepts in editable contexts (input, textarea, contenteditable); read-only elements unaffected |
| Textarea auto-resize may cause layout jank with many items | Low | Medium | `max-height: 200px` on inline textarea caps expansion; `resize: vertical` gives user control |

---

## 15. Appendices

### Appendix A — Decisions Log

| # | Decision | Rationale |
|---|---------|-----------|
| D1 | "Add Article" button in fixed footer (not header) | All action buttons in one location; header stays compact for title + meta |
| D2 | Keyboard shortcut: `Ctrl+Shift+A` | Three-key combo avoids all standard browser shortcuts (`Ctrl+N`, `Ctrl+T`, `Alt+A` menu); unlikely to conflict with extensions |
| D3 | Output panel → modal overlay | Consistent with new modal pattern; avoids layout complexity of embedding in scrollable area |
| D4 | Global plain-text paste handler | Prevents rich-text artifacts in all fields; consistent behavior; single handler for entire app |
| D5 | Discard confirmation reuses `showConfirmDialog()` | No new infrastructure; consistent UX with existing delete confirmations |
| D6 | Auto-fetch only on paste (not on every keystroke) | Prevents spurious scrape requests while typing; manual button covers re-fetch |
| D7 | Episode meta bar stays below header (not collapsible) | Current placement works; collapsible adds complexity without clear benefit |
| D8 | Toast z-index 200 (between content 0 and modals 500) | Toasts visible during normal use; modals overlay toasts when open |

### Appendix B — File Modification Summary

| File | Status | Changes |
|------|--------|---------|
| `etc/config.php` | Unchanged | No new constants needed |
| `include/Database.php` | Modified | `addItem()` signature: new `$talkingPoints` parameter with default |
| `include/Scraper.php` | Unchanged | No modification required |
| `include/Generator.php` | Unchanged | No modification required |
| `www/api.php` | Modified | `handleAddItem()` reads + sanitizes `talking_points` from request |
| `www/index.php` | Modified | Remove sidebar; restructure layout (fixed header/footer, scrollable content); add `#btn-add-article` to footer; remove static output panel |
| `www/js/app.js` | Modified | New `articleInputModal` module; new `markdownOverlay` module; textarea editor replacing contenteditable; global paste handler; keyboard shortcut; remove sidebar code; remove static output panel code |
| `www/css/style.css` | Modified | Remove sidebar/workspace/output-panel styles; add fixed layout; add card styles; add modal styles; add overlay styles; add textarea editor styles; reposition toasts |
| `bin/ws-server.php` | Unchanged | No modification required |
| `www/audience.php` | Unchanged | No modification required |

### Appendix C — Z-Index Stack

| Layer | z-index | Elements |
|-------|---------|----------|
| Fixed header/footer | 100 | `#app-top`, `#app-footer` |
| Episode meta bar | 99 | `#episode-meta-bar` (within `#app-top`, no separate z-index needed) |
| Author suggestion dropdown | 100 | `.author-suggest-list` (unchanged) |
| Toast container | 200 | `#toast-container` |
| Modal backdrop | 500 | `.modal-backdrop` (confirm dialog, article input modal, markdown overlay) |
| Modal dialog | 501 | `.modal-dialog` (inherited from existing) |
| Recording mode host view | 1000 | `#host-view` (unchanged) |

### Appendix D — User Workflow — After

```
1. Click "+ Add Article" (footer) or press Ctrl+Shift+A
2. Paste URL                    ← modal (auto-focused, auto-fetches metadata)
3. Review/edit fields           ← modal
4. Write notes (free-form text) ← modal (textarea, natural editing)
5. Click "Add & Next"           ← modal (stays open, fields clear, URL re-focused)
6. Paste next URL               ← modal (immediate, no scrolling)
   ...repeat 2–6...
7. Click "Add Article" (last)   ← modal closes
   List shows all items as clearly separated cards.
   Header and footer remain visible throughout.
```

**Steps reduced from 11 (with 4 pain points) to 7 (no pain points).**

### Appendix E — Glossary (Additions)

| Term | Definition |
|------|-----------|
| **Article Input Modal** | The overlay dialog used to add new articles. Contains URL, section selector, metadata fields, and a textarea for talking points. Opened via footer button or `Ctrl+Shift+A`. |
| **Card** | A visually distinct container for each item row in the list — border on all sides, border-radius, subtle shadow, and margin between cards. |
| **Fixed Header** | The sticky top bar containing the show title, status indicator, and episode metadata fields. Always visible regardless of scroll position. |
| **Fixed Footer** | The sticky bottom bar containing Generate, Add Article, New Episode, and Start Recording buttons. Always visible regardless of scroll position. |
| **Plain-Text Paste** | A global event handler that strips all formatting from clipboard content, ensuring only plain text is inserted into any editable field in the application. |
| **Markdown Overlay** | The modal overlay that displays generated show notes markdown with a copy-to-clipboard button. Replaces the former static output panel. |
