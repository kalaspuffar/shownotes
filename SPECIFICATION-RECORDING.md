# Project Specification: Cozy News Corner — Live Recording & Production Features

**Version:** 1.0
**Date:** 2026-03-01
**Author:** Solution Architect (Claude Code)
**Status:** Final
**Based on:** REQUIREMENTS-RECORDING.md v1.2
**Extends:** SPECIFICATION.md v1.0

> **Reading guide:** This document specifies only the new feature set (Story Groups, Talking Points, Recording Mode, Audience View, WebSocket Server). It must be read alongside `SPECIFICATION.md` v1.0, which remains authoritative for all existing functionality. Where this document modifies an existing component, the modification is explicitly called out.

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Overview](#2-architecture-overview)
3. [System Components](#3-system-components)
4. [Data Architecture](#4-data-architecture)
5. [API Specifications](#5-api-specifications)
6. [WebSocket Protocol](#6-websocket-protocol)
7. [Recording Mode — Host View](#7-recording-mode--host-view)
8. [Audience View](#8-audience-view)
9. [Story Groups — Prep UI](#9-story-groups--prep-ui)
10. [Talking Points — Prep UI](#10-talking-points--prep-ui)
11. [Security Architecture](#11-security-architecture)
12. [Testing Strategy](#12-testing-strategy)
13. [Implementation Plan](#13-implementation-plan)
14. [Risks and Mitigations](#14-risks-and-mitigations)
15. [Appendices](#15-appendices)

---

## 1. Executive Summary

### Project Overview

This specification extends the Cozy News Corner Show Notes Generator into a full end-to-end podcast production tool. Three tightly integrated subsystems are added:

1. **Story Groups with Talking Points** — News items in the prep view can be grouped under a single story container. The group's primary link drives the audience display during recording; secondary links appear in show notes only. Private per-item talking-point bullet notes attach to primary links.

2. **Recording Mode (Host View)** — A full-screen in-page mode for the host during live recording. Displays talking points for the current item and enables hands-free keyboard navigation through the run order. All fields are read-only.

3. **Audience Display Window (Audience View)** — A second browser window (`www/audience.php`), shown on the second monitor, that embeds the current article in a full-viewport iframe and stays synchronised with the Host View via a lightweight PHP WebSocket server (`bin/ws-server.php`).

### Key Objectives

- Allow the host to navigate an entire episode with a single key press, with the audience display updating within 100 ms.
- Introduce no external PHP or JavaScript dependencies.
- Keep the show notes Markdown output completely unaffected by grouping or talking-points data.
- Handle iframe-blocked sites gracefully via a tab-opening fallback.

### Success Criteria

| Criterion | Measure |
|-----------|---------|
| WebSocket round-trip latency | ≤ 100 ms localhost |
| Host View entry | ≤ 1 s from button click |
| Talking points auto-save | ≤ 500 ms API round-trip after debounce fires |
| Iframe fallback trigger | ≤ 3 s after blocked load detected |
| No regressions | All existing features pass full regression suite |

---

## 2. Architecture Overview

### Extended High-Level Architecture

The recording feature introduces two new execution contexts alongside the existing single-page application:

```
┌──────────────────────────────────────────────────────────────────┐
│                        Browser (Desktop)                          │
│                                                                    │
│  ┌──────────────────────────────────────────────────────────────┐ │
│  │  Window 1 — HOST VIEW (www/index.php)         Monitor 1      │ │
│  │  Prep mode:  existing app UI                                  │ │
│  │  Recording:  full-screen host view (CSS class toggle, no      │ │
│  │              page reload)                                     │ │
│  │  ┌────────────────────────────────────────────────────────┐  │ │
│  │  │  www/js/app.js                                         │  │ │
│  │  │  ├── storyGroupModule    — group rendering + DnD       │  │ │
│  │  │  ├── talkingPointsModule — bullet editor + auto-save   │  │ │
│  │  │  ├── recordingModule     — host view, keyboard nav     │  │ │
│  │  │  └── wsClientModule      — WebSocket send/receive      │  │ │
│  │  └────────────────────────────────────────────────────────┘  │ │
│  └──────────────────────────────────────────────────────────────┘ │
│                │  WebSocket (ws://localhost:9001)                  │
│  ┌─────────────▼────────────────────────────────────────────────┐ │
│  │  Window 2 — AUDIENCE VIEW (www/audience.php)   Monitor 2     │ │
│  │  Full-viewport <iframe> of current article                    │ │
│  │  Inline <script>: WebSocket listener, fallback logic         │ │
│  └──────────────────────────────────────────────────────────────┘ │
└────────────────────────┬─────────────────────────────────────────┘
                         │ HTTP (local network)
┌────────────────────────▼─────────────────────────────────────────┐
│              Apache (mod_php / PHP-FPM)                           │
│                                                                    │
│  www/index.php   — existing (minor additions)                     │
│  www/api.php     — existing + new actions                         │
│  www/audience.php — NEW dedicated audience view page              │
│                                                                    │
│  include/Database.php — new columns + new methods                 │
│  include/Generator.php — unchanged (generator is isolation-safe)  │
│  include/Scraper.php   — unchanged                                │
│  etc/config.php        — two new WebSocket constants added        │
│                                                   │               │
│                        PDO SQLite                 │               │
│  var/shownotes.sqlite — two new columns in items                  │
└────────────────────────────────────────────────────┬──────────────┘
                                                     │ PHP CLI
┌────────────────────────────────────────────────────▼──────────────┐
│  bin/ws-server.php — NEW PHP WebSocket server (raw sockets)       │
│  Listens on 127.0.0.1:9001                                        │
│  Relays 'navigate' messages from Host to Audience                 │
└───────────────────────────────────────────────────────────────────┘
```

### Key Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Recording mode transition | In-page CSS class toggle (`.recording-mode` on `<body>`), no page load | Meets the "instantaneous" requirement (NFR 8.4) |
| Audience sync mechanism | PHP WebSocket server (RFC 6455, raw sockets) | Zero external dependencies; localhost-only for security |
| Audience view | Dedicated `audience.php` file | Cleanest separation; no app chrome; URL is bookmarkable for debugging |
| Story group data model | `parent_id` self-referential FK on `items` table | Minimal schema change; backward compatible (NULL = standalone/primary) |
| Talking points storage | New `talking_points TEXT` column on `items` | Co-located with item data; cleared by existing `reset_episode` logic |
| DnD nesting vs. reorder indicator | Distinct visual indicator (drop-on = highlight ring; reorder = horizontal bar) | Prevents accidental group creation during reorder |
| Generator isolation | `getItemsFlat()` method on Database returns ordered flat list; Generator.php unchanged | Show notes output unaffected (FR-G05, FR-T03) |
| WebSocket client in audience | Inline `<script>` in `audience.php` (no external JS file) | audience.php is self-contained; no dependency on app.js |

---

## 3. System Components

### 3.1 Modified: Configuration — `etc/config.php`

**Change:** Add two constants for the WebSocket server.

```php
// Existing keys unchanged ...

// NEW: WebSocket server
'ws_host' => '127.0.0.1',
'ws_port' => 9001,
```

**Acceptance Criteria:**
- Changing `ws_port` in this file changes the port used by both `bin/ws-server.php` and the `wsClientModule` in `app.js` (the JS receives the port via the `INITIAL_STATE` injection from `index.php`).
- `ws_host` defaults to `127.0.0.1` (localhost only; not `0.0.0.0`).

---

### 3.2 Modified: Database Layer — `include/Database.php`

**Changes:** Two new columns on the `items` table; six new public methods; one modified method.

#### Modified Method: `getItems()`

**Change:** The returned item arrays now include two additional fields:

```
talking_points: string   — plain text, may be empty string or null (treat as '')
parent_id:      int|null — null = standalone or primary; int = secondary (FK to items.id)
```

The query for news items must join or include these fields. The sort order for news items: primary/standalone items (parent_id IS NULL) are returned first ordered by sort_order, then each primary's secondaries (parent_id = primary.id) ordered by their own sort_order. The flat array returned from `getItems()['news']` thus interleaves: `[primary1, secondary1a, secondary1b, primary2, secondary2a, ...]` in display order. (The JS groups them by parent_id for rendering; the Generator uses this order directly for Markdown output.)

**Implementation:** Use a SQL query that orders by `COALESCE(parent_id, id)` then `parent_id IS NOT NULL` then `sort_order`:

```sql
SELECT * FROM items
WHERE section = 'news'
ORDER BY
    COALESCE(parent_id, id) ASC,  -- group items by their primary's id
    parent_id IS NOT NULL ASC,    -- primary (null) before secondaries
    sort_order ASC
```

This returns rows in groups: each primary followed by its secondaries, groups themselves ordered by the primary's id (which correlates with insertion order / original sort_order). For top-level ordering between groups, sort by the primary item's `sort_order` instead:

```sql
-- Correct query using a CTE to get primary sort_order
WITH primary_order AS (
    SELECT id, sort_order AS primary_sort
    FROM items
    WHERE section = 'news' AND parent_id IS NULL
)
SELECT i.* FROM items i
LEFT JOIN primary_order po ON COALESCE(i.parent_id, i.id) = po.id
WHERE i.section = 'news'
ORDER BY
    po.primary_sort ASC,          -- group order (by primary's sort_order)
    i.parent_id IS NOT NULL ASC,  -- primary before secondaries
    i.sort_order ASC              -- within group, secondary sort_order
```

#### Modified Method: `deleteItem(int $id): bool`

**Change:** Before deleting, check if the item is a primary (has children, i.e., items where parent_id = $id).

If it is a primary with secondaries:
1. Retrieve the first secondary (lowest `sort_order` among items where `parent_id = $id`).
2. Transfer `talking_points` from the primary to that first secondary (per FR-G06 final clause: "if the deleted primary had talking points, they are transferred to the promoted secondary without a dialog since deletion is already a confirmed action").
3. Set the first secondary's `parent_id = NULL` and `sort_order = <primary's sort_order>`.
4. Update all remaining secondaries' `parent_id` to the newly promoted primary's `id`.
5. Delete the original primary.
6. Resequence sort_order within section.

All steps are wrapped in a single transaction.

If deleting a secondary (parent_id IS NOT NULL):
1. Delete the secondary.
2. Check: if the former primary now has zero secondaries, it is now standalone — no additional change needed (it remains with parent_id = NULL; it was always the primary).
3. Resequence sort_order of remaining secondaries within the group.

#### Modified Method: `reorderItems(string $section, array $orderedIds): bool`

**Change (news section only):** The `$orderedIds` array for the news section contains only top-level item IDs (items where `parent_id IS NULL`). The method must:
1. Validate that all provided IDs have `parent_id IS NULL` and belong to the news section.
2. Set `sort_order` for each ID based on its array index (0, 1, 2, …).
3. Secondary items (children) are unaffected by this call.

For the vulnerability section, behavior is unchanged.

#### New Method: `updateTalkingPoints(int $id, string $talkingPoints): array`

Updates the `talking_points` column for a given item. Returns the updated item row.

**Validation (application-level):**
- The item must have `parent_id IS NULL` (only primary/standalone items have talking points).
- If called with a secondary item's ID, throw an exception.

#### New Method: `nestItem(int $itemId, int $targetId): array`

Makes `$itemId` a secondary link under `$targetId`. Returns the updated state: `['primary' => [...], 'secondaries' => [...]]`.

**Logic:**
1. Validate `$targetId` is a news item with `parent_id IS NULL` (not itself a secondary).
2. Validate `$itemId` is a news item.
3. If `$itemId` has `parent_id IS NULL` (standalone or primary):
   - If `$itemId` has children (is a primary): update all those children's `parent_id` to `$targetId`.
4. Set `$itemId.parent_id = $targetId`.
5. Set `$itemId.sort_order = MAX(sort_order) + 1` among items where `parent_id = $targetId`.
6. Resequence sort_order of old group if applicable.

**Note:** Talking-point transfer is handled at the API layer (not here), since the API must first check for a confirmation requirement.

#### New Method: `extractItem(int $itemId, array $newTopLevelOrder): bool`

Extracts a secondary item from its group and makes it standalone.

**Logic:**
1. Validate `$itemId` has `parent_id IS NOT NULL`.
2. Record `$parentId = item.parent_id`.
3. Set `$itemId.parent_id = NULL`.
4. Call `reorderItems('news', $newTopLevelOrder)` — `$newTopLevelOrder` includes `$itemId` at its new position.
5. Resequence sort_order of remaining secondaries within `$parentId`'s group.
6. If `$parentId` now has zero secondaries, it is standalone — no further action needed.
7. All steps in one transaction.

#### New Method: `reorderGroupItems(int $primaryId, array $orderedSecondaryIds): bool`

Reorders the secondary links within a story group.

**Logic:**
1. Validate all IDs in `$orderedSecondaryIds` have `parent_id = $primaryId`.
2. Set `sort_order` to array index for each secondary.

#### New Method: `getItemsFlat(): array`

Returns all items in flat form for use by the Markdown generator. This is identical to the ordered result of the modified `getItems()['news']` query (primary then secondaries in group order), combined with vulnerability items.

```php
return [
    'vulnerability' => $this->getVulnerabilityItems(),
    'news'          => $this->getNewsItemsOrdered(), // flat, primary-then-secondaries
];
```

The Generator is called with this flat array. No structural change to `Generator.php` is required.

**Acceptance Criteria:**
- All new/modified methods use prepared statements exclusively.
- `deleteItem` correctly promotes the first secondary when deleting a primary.
- `getItems()` returns `talking_points` (as empty string if NULL) and `parent_id` (as null) for all items.
- Deleting the SQLite file and restarting still auto-migrates the schema correctly.

---

### 3.3 Modified: API Handler — `www/api.php`

**Change:** Six new action handlers; two modified handlers. The `match` dispatch table is extended.

```php
match($action) {
    // Existing (unchanged):
    'update_episode'         => handleUpdateEpisode(…),
    'scrape_url'             => handleScrapeUrl(…),
    'add_item'               => handleAddItem(…),
    'update_item'            => handleUpdateItem(…),
    'delete_item'            => handleDeleteItem(…),    // MODIFIED
    'reorder_items'          => handleReorderItems(…),  // MODIFIED
    'reset_episode'          => handleResetEpisode(…),
    'get_author_suggestions' => handleGetAuthorSuggestions(…),
    'generate_markdown'      => handleGenerateMarkdown(…), // MODIFIED (uses getItemsFlat)

    // NEW:
    'update_talking_points'  => handleUpdateTalkingPoints(…),
    'nest_item'              => handleNestItem(…),
    'extract_item'           => handleExtractItem(…),
    'reorder_group'          => handleReorderGroup(…),
    default                  => jsonError('Unknown action', 400),
};
```

**Modified: `generate_markdown`** — calls `$db->getItemsFlat()` instead of `$db->getItems()` to retrieve items for the generator, ensuring secondary items appear in the output in correct order.

See §5 for full API request/response specifications for each new and modified action.

---

### 3.4 Modified: Frontend JavaScript — `www/js/app.js`

**Change:** Four new functional modules are added to `app.js`. Existing modules are minimally modified (described below). No existing module is removed or restructured.

#### New Module: `storyGroupModule`

**Responsibilities:**
- Render story group containers in the news section (group container with primary + indented secondaries).
- Implement drop-on-item nesting DnD behavior (distinct from drop-between reorder).
- Render secondary items with all standard metadata fields and a drag handle.
- Trigger the primary-demotion confirmation dialog (FR-G06) before calling `nest_item`.
- Detect group dissolution (primary left alone) and update rendering.

**Key functions:**

```
renderNewsSection(items)
  → groups items by parent_id, renders group containers and standalone items

renderGroupContainer(primary, secondaries)
  → group div with drag handle, primary row (with "PRIMARY" badge), secondary rows

renderSecondaryItem(item)
  → item row with "SECONDARY" badge, full metadata fields, drag handle

handleDropOnItem(draggedId, targetId)
  → check if dragged item is a primary with talking points
  → if yes and talking_points non-empty: show confirmDialog() before proceeding
  → call apiCall('nest_item', { itemId, targetId, transferTalkingPoints })

handleGroupExtract(itemId, newTopLevelOrder)
  → call apiCall('extract_item', { itemId, newTopLevelOrder })
```

**DnD modification to existing item lists:**

The existing `dragover` handler must distinguish between two targets:
- **Drop between** (standard reorder): cursor is closer to the gap between items → show horizontal bar indicator
- **Drop on** (nesting): cursor is over the center 50% of an item's height → show highlight ring on target item

A CSS class `.drop-target-nest` is applied to the target item during hover and removed on `dragleave` or `drop`.

Group containers have their own drag handle at the container level for reordering the group as a whole within the news section. The group container is `draggable="true"` with its primary item's id stored in `data-primary-id`.

#### New Module: `talkingPointsModule`

**Responsibilities:**
- Render the talking-points panel beneath each primary/standalone news item in prep mode.
- Implement a dynamic bullet-list editor (Enter = new bullet; Backspace on empty bullet = remove bullet).
- Auto-save with 800 ms debounce after the last keystroke.
- Label the panel clearly as private/recording-only.
- Suppress the panel for secondary items (they have no talking points field).

**Key functions:**

```
renderTalkingPointsPanel(item)
  → returns DOM node for the panel, pre-populated from item.talking_points
  → panel is a <ul> of <li contenteditable="true"> bullets

parseBullets(panelElement)
  → returns string of newline-joined bullet text content

handleBulletKeydown(e)
  → Enter: insert new <li> after current, focus it
  → Backspace on empty li: delete li, focus previous

saveTalkingPoints(itemId, panelElement)
  → debounced: apiCall('update_talking_points', { itemId, talkingPoints })
```

**Storage format:** Talking points are stored as a single newline-delimited string (one line per bullet). The UI splits on `\n` to render bullets and joins on `\n` to save. Leading/trailing whitespace per line is trimmed.

#### New Module: `recordingModule`

**Responsibilities:**
- Manage the transition into and out of recording mode (Host View).
- Compute and maintain the run order array.
- Render the Host View layout (current item header, talking points panel, navigation controls, status indicators).
- Handle keyboard navigation (Right/Space = next, Left = prev, Home = first, End = last).
- Maintain current position in the run order.
- Insert the "NEWS" segment break into the run order between the last vulnerability item and the first news item.
- Send `navigate` WebSocket events on each navigation step.
- Open and close the Audience Window.
- Show the exit-recording control.

**Run order computation:**

```javascript
function buildRunOrder(state) {
    const order = [];

    // 1. Vulnerability items
    const vulns = state.items.vulnerability
        .slice()
        .sort((a, b) => a.sort_order - b.sort_order);
    for (const item of vulns) {
        order.push({ type: 'item', item, segment: 'vulnerability' });
    }

    // 2. Segment break (only if both sections have items)
    const newsStops = state.items.news
        .filter(item => item.parent_id === null)
        .sort((a, b) => a.sort_order - b.sort_order);

    if (vulns.length > 0 && newsStops.length > 0) {
        order.push({ type: 'segment_break', segment: 'news' });
    }

    // 3. News stops (primaries + standalones only)
    for (const item of newsStops) {
        order.push({ type: 'item', item, segment: 'news' });
    }

    return order;
}
```

**Navigation rules:**
- Advancing past the last item: no-op (no cycling).
- Retreating before the first item: no-op.
- Crossing a segment break: display the segment break marker until the next navigation input.
- On each navigation that lands on a `type: 'item'` entry: call `wsClient.sendNavigate(item)`.
- On entry to recording mode: navigate to index 0 immediately (emit the first navigate event).

**Host View rendering:**

The Host View is a `<div id="host-view">` rendered inside `www/index.php` (hidden in prep mode, shown in recording mode via CSS). It is populated by `recordingModule.render(runOrderEntry)` on each navigation step.

```
┌──────────────────────────────────────────────────────────────────┐
│  [● WS: Connected]         [Exit Recording]          [3 of 17]  │
│  ─────────────────────────────────────────────────────────────── │
│                                                                    │
│  VULNERABILITY  (or NEWS)                  ← segment label       │
│  Article Title Here                        ← item.title (≥24px) │
│  https://example.com/article               ← item.url (muted)   │
│                                                                    │
│  ● Talking point one                                              │
│  ● Talking point two                       ← bullets (≥20px)    │
│  ● Talking point three                                            │
│                                                                    │
│  ─────────────────────────────────────────────────────────────── │
│  [◀ Prev]                                         [Next ▶]       │
└──────────────────────────────────────────────────────────────────┘

Segment break state:
┌──────────────────────────────────────────────────────────────────┐
│                                                                    │
│                          N E W S                                  │
│                   ← press Next to continue →                      │
│                                                                    │
└──────────────────────────────────────────────────────────────────┘
```

**Audience Window management:**

```javascript
let audienceWindow = null;

function openAudienceWindow() {
    const url = '/audience.php';
    audienceWindow = window.open(
        url,
        'cncAudienceWindow',
        `width=${screen.availWidth},height=${screen.availHeight},left=0,top=0`
    );
    if (!audienceWindow) {
        showPopupBlockedWarning(); // display inline instruction
    }
}

function closeAudienceWindow() {
    if (audienceWindow && !audienceWindow.closed) {
        audienceWindow.close();
    }
    audienceWindow = null;
}
```

**Start Recording flow (triggered from action bar button):**

1. Check if episode has at least one item (button is disabled otherwise — no check needed here).
2. Attempt WebSocket connection (via `wsClientModule.connect()`).
3. After 1 s connection attempt:
   - If connected: proceed.
   - If not connected: show non-blocking inline warning with CLI command (`php bin/ws-server.php`) and "Continue without sync" option.
4. Open Audience Window via `openAudienceWindow()`.
5. If popup blocked: show inline instruction "Allow popups for localhost in your browser, then click Start Recording again." Abort entry.
6. Build run order from current state.
7. Apply `.recording-mode` class to `<body>`.
8. Set `currentIndex = 0`, render Host View for `runOrder[0]`.
9. If `runOrder[0].type === 'item'`: send `navigate` event for that item.
10. Bind global keyboard listener.

**Exit Recording flow:**

1. Remove `.recording-mode` class from `<body>`.
2. Unbind global keyboard listener.
3. Call `closeAudienceWindow()`.
4. Call `wsClientModule.disconnect()`.

#### New Module: `wsClientModule`

**Responsibilities:**
- Manage the WebSocket connection from the Host View to `ws://localhost:{ws_port}`.
- Send `hello` (role: host) on connection open.
- Send `navigate` messages on navigation events.
- Provide connection status (connected/disconnected) observable by `recordingModule`.
- Attempt reconnection every 3 s on unexpected close.
- Wrap all operations in try/catch; no uncaught exceptions.

```javascript
const wsClientModule = (() => {
    let ws = null;
    let statusCallback = null;
    let reconnectTimer = null;
    const WS_URL = `ws://127.0.0.1:${INITIAL_STATE.config.ws_port}`;

    function connect() { … }
    function disconnect() { clearTimeout(reconnectTimer); ws?.close(); }
    function sendNavigate(item) { … }
    function onStatusChange(cb) { statusCallback = cb; }

    return { connect, disconnect, sendNavigate, onStatusChange };
})();
```

The WS port is injected into `INITIAL_STATE.config` by `index.php` (see §3.7).

#### Modified: Existing `renderNewsList()` function

**Change:** Now delegates to `storyGroupModule.renderNewsSection(items.news)` for rendering. The function signature is unchanged; the implementation is replaced.

#### Modified: Existing `handleDeleteItem()` function

**Change:** After successful `delete_item` API call, re-render the full news list (since a group may have dissolved or a primary may have been promoted). No UI change to the confirmation dialog.

#### Modified: Existing action bar rendering

**Change:** Add the "Start Recording" button to the action bar:

```html
<button id="btn-start-recording" class="btn-recording" disabled>
    Start Recording
</button>
```

The button is enabled/disabled based on episode item count (same logic as the Generate button's enabling condition, but triggers on item count > 0, not on YouTube URL presence).

---

### 3.5 Modified: Stylesheet — `www/css/style.css`

**New CSS custom properties (added to `:root`):**

```css
/* ─── Recording mode — surfaces and text ─── */
--color-recording-bg:           #1a1a2e;   /* Host view dark background */
--color-recording-surface:      #16213e;
--color-recording-accent:       #e94560;   /* Segment break, bullet dots */
--color-recording-text:         #eaeaea;
--color-recording-muted:        #8892a4;

/* ─── WebSocket status indicators ─── */
--color-ws-connected:           #27ae60;
--color-ws-disconnected:        #e74c3c;

/* ─── Story group containers ─── */
--color-group-border:           #c5d8c0;   /* Story group container border */
--color-group-bg:               #f0f5f0;   /* Story group container background */
--color-group-border-width:     3px;       /* Left accent border thickness */

/* ─── Story group badges ─── */
--color-primary-badge:          #4a7c59;
--color-secondary-badge:        #7fa99f;
--badge-font-size:              10px;
--badge-font-weight:            700;
--badge-padding:                2px 8px;
--badge-border-radius:          100px;     /* Fully rounded pill */
--badge-letter-spacing:         0.06em;

/* ─── Start Recording button (prep view action bar) ─── */
--color-btn-recording:          #c0392b;
--color-btn-recording-hover:    #a93226;

/* ─── Host View — navigation buttons ─── */
--color-hv-nav-btn:             #2a2a4a;
--color-hv-nav-btn-hover:       #3d3d6b;
--color-hv-nav-btn-text:        #eaeaea;
--color-hv-nav-btn-disabled-bg: rgba(255, 255, 255, 0.06);
--color-hv-nav-btn-disabled-fg: #4a5468;

/* ─── Host View — Exit Recording button ─── */
--color-hv-exit-btn:            rgba(255, 255, 255, 0.10);
--color-hv-exit-btn-hover:      rgba(255, 255, 255, 0.20);
--color-hv-exit-btn-border:     rgba(255, 255, 255, 0.25);

/* ─── Audience View (mirrored in audience.php inline styles) ─── */
--color-audience-loading-bar:   #e94560;   /* Same as --color-recording-accent */
--audience-loading-bar-h:       4px;
--audience-disconnect-offset:   12px;      /* Bottom/left offset for disconnect dot */

/* ─── Talking points panel ─── */
--tp-label-font-size:           12px;
--color-tp-separator:           #d0cec8;   /* Same as base --color-border */
```

**New CSS sections:**

#### Story Group Styles

```
.story-group
  Container div wrapping primary + secondaries.
  border-left: var(--color-group-border-width, 3px) solid var(--color-group-border);
  background: var(--color-group-bg);
  border-radius: var(--radius);
  margin-bottom: var(--spacing-sm);
  padding: var(--spacing-sm) 0 var(--spacing-sm) 0;
  position: relative;

.story-group__drag-handle  (group-level handle, left-aligned on the container)
  Identical ⠿ glyph to per-item drag handles. position: absolute; left: -20px; top: 50%;
  transform: translateY(-50%); width: 20px; text-align: center;
  color: var(--color-drag-handle); cursor: grab; font-size: 16px; line-height: 1;
  (Alternatively: rendered as the first flex child of .story-group with align-self: center;
   and flex-shrink: 0; if the container uses display: flex.)

.story-group__primary
  The primary item row. Displayed at full width inside the container.
  padding: var(--spacing-sm) var(--spacing-md);

.story-group__primary-badge, .story-group__secondary-badge
  Shared pill label styles:
    display: inline-block;
    font-size: var(--badge-font-size, 10px);
    font-weight: var(--badge-font-weight, 700);
    padding: var(--badge-padding, 2px 8px);
    border-radius: var(--badge-border-radius, 100px);
    text-transform: uppercase;
    letter-spacing: var(--badge-letter-spacing, 0.06em);
    color: #ffffff;
    vertical-align: middle;
    margin-left: var(--spacing-sm);

  .story-group__primary-badge   background: var(--color-primary-badge);
  .story-group__secondary-badge background: var(--color-secondary-badge);

.story-group__secondaries
  Indented container for secondary item rows.
  padding-left: 24px;
  border-left: 2px solid var(--color-group-border);
  margin-left: var(--spacing-md);

  (The border-left here provides the visual tree connector from primary to secondaries,
   distinct from the outer .story-group's left accent border.)

.story-group__secondaries .item-row
  background: var(--color-surface);  /* Same as standalone items — no extra tinting */
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  margin-bottom: 4px;

.drop-target-nest
  Applied to an item that is the current DnD nesting target.
  box-shadow: inset 0 0 0 2px var(--color-primary);
  border-radius: var(--radius);
  background: rgba(74, 124, 89, 0.08);   /* --color-primary at 8% opacity */
  transition: box-shadow 0.1s ease, background 0.1s ease;
  Distinct from the existing .drag-over (horizontal bar indicator).

.talking-points-panel
  Panel below the item's main fields, separated by a horizontal rule.
  padding-top: var(--spacing-sm);
  margin-top: var(--spacing-sm);
  border-top: 1px solid var(--color-tp-separator, #d0cec8);

.talking-points-panel__label
  "Recording Notes — not included in show notes"
  font-size: var(--tp-label-font-size, 12px);
  color: var(--color-text-muted);
  font-style: italic;
  margin-bottom: 4px;
  display: block;

.talking-points-panel ul
  list-style-type: disc; list-style-position: inside;
  padding: 0; margin: 0;

.talking-points-panel li[contenteditable]
  min-height: 1.4em;
  padding: 2px 0;
  outline: none;                        /* Remove default browser outline */
  border-radius: 2px;
  &:focus { outline: 2px solid var(--color-primary); outline-offset: 1px; }
  &:empty::before { content: attr(data-placeholder); color: var(--color-text-muted); }
```

#### Recording Mode Styles

When `<body class="recording-mode">` is active:

```
body.recording-mode .prep-ui
  display: none !important — hides the entire prep/edit UI

body.recording-mode #host-view
  display: flex; flex-direction: column; height: 100vh; width: 100vw;
  background: var(--color-recording-bg); color: var(--color-recording-text);
  position: fixed; top: 0; left: 0; z-index: 1000;
  font-family: var(--font-sans);

/* ── Top bar ── */
#host-view .hv-topbar
  display: flex; justify-content: space-between; align-items: center;
  padding: var(--spacing-md) var(--spacing-lg);
  border-bottom: 1px solid var(--color-recording-surface);
  gap: var(--spacing-md);
  flex-shrink: 0;     /* Must not compress under flex column layout */

  Three children, left-to-right:
    .hv-topbar-left   — WS status indicator + text  (flex, align-items: center, gap: 8px)
    .hv-topbar-center — "Exit Recording" button      (flex, justify-content: center)
    .hv-topbar-right  — Item counter                 (flex, align-items: center)

#host-view .hv-ws-indicator
  width: 10px; height: 10px; border-radius: 50%; display: inline-block;
  flex-shrink: 0;
  background: var(--color-ws-disconnected);
  transition: background 0.3s ease;
  &.connected { background: var(--color-ws-connected); }

#host-view .hv-ws-text
  font-size: 13px; color: var(--color-recording-muted);
  white-space: nowrap;
  /* Text content: "WS: Connected" or "WS: Disconnected" — updated by wsClientModule */

#host-view .hv-exit-btn
  background: var(--color-hv-exit-btn);
  color: var(--color-recording-text);
  border: 1px solid var(--color-hv-exit-btn-border);
  border-radius: var(--radius);
  font-size: 13px; font-weight: 500;
  padding: 6px 16px;
  cursor: pointer;
  transition: background 0.15s ease;
  white-space: nowrap;
  &:hover { background: var(--color-hv-exit-btn-hover); }

#host-view .hv-nav-counter
  font-size: 14px; color: var(--color-recording-muted);
  white-space: nowrap;   /* "3 of 17" or "Segment break" */

/* ── Content area ── */
#host-view .hv-content
  flex: 1; overflow: hidden;
  display: flex; flex-direction: column;
  padding: var(--spacing-lg);
  max-width: 1200px;  /* Prevents excessively wide text on large monitors */
  width: 100%;
  align-self: center;

#host-view .hv-segment-label
  font-size: 14px; text-transform: uppercase; letter-spacing: 2px;
  color: var(--color-recording-muted); margin-bottom: var(--spacing-sm);

#host-view .hv-title
  font-size: 28px; font-weight: 700; color: var(--color-recording-text);
  line-height: 1.3; margin-bottom: var(--spacing-sm);

#host-view .hv-url
  font-size: 14px; color: var(--color-recording-muted); word-break: break-all;
  margin-bottom: var(--spacing-md);

#host-view .hv-talking-points
  flex: 1; overflow-y: auto; padding-top: var(--spacing-md);

#host-view .hv-talking-points ul
  list-style-type: none; padding: 0; margin: 0;

#host-view .hv-talking-points ul li
  font-size: 22px; line-height: 1.6; padding: 6px 0;
  display: flex; align-items: flex-start; gap: 12px;
  &::before { content: "●"; color: var(--color-recording-accent); flex-shrink: 0; }

#host-view .hv-talking-points--empty
  /* Shown when there are no talking points for the current item */
  display: none;

/* ── Nav bar ── */
#host-view .hv-nav-bar
  display: flex; justify-content: space-between; align-items: center;
  padding: var(--spacing-md) var(--spacing-lg);
  border-top: 1px solid var(--color-recording-surface);
  flex-shrink: 0;

#host-view .hv-nav-btn
  background: var(--color-hv-nav-btn);
  color: var(--color-hv-nav-btn-text);
  border: none; border-radius: var(--radius);
  font-size: 18px; font-weight: 600;
  padding: 12px 36px;
  min-width: 140px;
  cursor: pointer;
  transition: background 0.15s ease;
  &:hover:not(:disabled) { background: var(--color-hv-nav-btn-hover); }
  &:disabled {
    background: var(--color-hv-nav-btn-disabled-bg);
    color: var(--color-hv-nav-btn-disabled-fg);
    cursor: not-allowed;
  }

  "◀ Prev" — disabled when currentIndex === 0
  "Next ▶" — disabled when currentIndex === runOrder.length - 1

/* ── Segment break screen ── */
#host-view .hv-segment-break
  flex: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: var(--spacing-md);

#host-view .hv-segment-break__word
  font-size: 96px; letter-spacing: 24px;
  color: var(--color-recording-accent); font-weight: 700;
  text-transform: uppercase;

#host-view .hv-segment-break__hint
  font-size: 16px; color: var(--color-recording-muted);
  letter-spacing: 0;
  /* Content: "← press Next to continue →" */

/* ── "Start Recording" button (in prep view action bar) ── */
.btn-recording
  background: var(--color-btn-recording);
  color: #ffffff;
  border: none; border-radius: var(--radius);
  font-size: 14px; font-weight: 600;
  padding: 10px 20px;
  cursor: pointer;
  transition: background 0.15s ease;
  &:hover:not(:disabled) { background: var(--color-btn-recording-hover); }
  &:disabled { opacity: 0.45; cursor: not-allowed; }
  /* Visually distinct from .btn-primary (green) — this button is red */

/* ── Action bar layout (updated with Start Recording) ── */
.action-bar
  display: flex; align-items: center; gap: var(--spacing-sm);

  Visual order (left → right):
    [Generate Show Notes]   ← left-aligned, .btn-primary (green)
    (flex spacer: flex: 1)
    [Start Recording]       ← right cluster, .btn-recording (red)
    [New Episode]           ← right cluster, .btn-danger or secondary (existing style)

  The [Start Recording] and [New Episode] buttons share a right-side group:
    .action-bar__right { display: flex; gap: var(--spacing-sm); align-items: center; }
```

#### WS Warning Panel (`#ws-warning`)

Shown when the WebSocket server is unreachable after the 1-second connection attempt:

```
#ws-warning
  Display: block; position: static (inline, below the Start Recording button area).
  background: #fffbeb;          /* Warm amber tint — warning, not error */
  border: 1px solid #f59e0b;
  border-radius: var(--radius);
  padding: var(--spacing-md);
  margin-top: var(--spacing-sm);
  font-size: 13px; color: #92400e;
  max-width: 480px;

#ws-warning code
  font-family: var(--font-mono);
  background: rgba(0,0,0,0.08);
  padding: 2px 6px; border-radius: 3px;

#ws-warning .ws-warning__actions
  display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-sm);
  /* Contains: [Continue without sync] (secondary) + [Cancel] (text link) */
```

#### Audience View Styles (inline in `audience.php`)

Styles for the audience view are inlined in `audience.php` (not in `style.css`) because audience.php is a standalone page with no dependency on the app stylesheet.

```css
/* Reset + base */
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body {
  width: 100%; height: 100%; overflow: hidden;
  background: #000000; font-family: system-ui, -apple-system, sans-serif;
}

/* Full-viewport article iframe */
#audience-iframe {
  width: 100%; height: 100%; border: none;
  display: none;   /* Hidden until first load event fires */
}

/* ── Waiting for broadcast (initial state) ── */
#waiting-msg {
  position: fixed; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  font-size: 20px; color: rgba(255, 255, 255, 0.75);
  text-align: center; line-height: 1.6;
  white-space: pre-line;
  pointer-events: none;
}

/* ── Indeterminate loading bar (top of viewport) ── */
#loading-bar {
  position: fixed; top: 0; left: 0;
  height: 4px; width: 40%;
  background: #e94560;   /* --color-recording-accent equivalent */
  border-radius: 0 2px 2px 0;
  display: none;          /* Shown via JS when iframe src is set */
  animation: audienceLoading 1.4s ease-in-out infinite;
  z-index: 200;
}

@keyframes audienceLoading {
  0%   { left: -40%; }
  60%  { left: 100%; }
  100% { left: 100%; }
}

/* ── Fallback message (iframe-blocked notification) ── */
#fallback-msg {
  position: fixed; bottom: 32px; left: 50%;
  transform: translateX(-50%);
  background: rgba(0, 0, 0, 0.82);
  color: rgba(255, 255, 255, 0.90);
  font-size: 14px; line-height: 1.5;
  padding: 10px 20px; border-radius: 6px;
  max-width: 420px; text-align: center;
  opacity: 0; pointer-events: none;
  transition: opacity 0.25s ease;
  z-index: 300;
}
#fallback-msg.visible { opacity: 1; }
/* Shown for 5 s then faded out by removing .visible class */

/* ── WebSocket disconnect dot (bottom-left corner) ── */
#disconnect-dot {
  position: fixed;
  bottom: 12px;  /* --audience-disconnect-offset */
  left: 12px;    /* --audience-disconnect-offset */
  width: 12px; height: 12px;
  border-radius: 50%;
  background: #e74c3c;   /* Disconnected — red */
  opacity: 0.6;
  display: none;         /* Hidden when connected; shown on disconnect */
}
#disconnect-dot.disconnected {
  display: block;
  animation: audiencePulse 2s ease-in-out infinite;
}

@keyframes audiencePulse {
  0%, 100% { opacity: 0.6; }
  50%       { opacity: 0.2; }
}
/* Note: The dot is not shown in a "connected" green state — it is simply
   hidden (display: none) when connected. It only appears when the
   WebSocket is disconnected, acting as a subtle alert for the host only. */
```

---

### 3.6 Modified: Main Application Page — `www/index.php`

**Changes:**

1. **`INITIAL_STATE` shape extension** — Add `ws_port` to the config section and include `talking_points` + `parent_id` in item shapes (these come from the modified `getItems()` automatically).

   ```json
   {
     "episode": { … },
     "items": {
       "vulnerability": [
         { "id": 1, "section": "vulnerability", "url": "…", "title": "…",
           "author_name": "", "author_url": "", "sort_order": 0,
           "talking_points": null, "parent_id": null }
       ],
       "news": [
         { "id": 2, "section": "news", "url": "…", "title": "…",
           "author_name": "…", "author_url": "…", "sort_order": 0,
           "talking_points": "First point\nSecond point", "parent_id": null },
         { "id": 3, "section": "news", "url": "…", "title": "…",
           "author_name": "…", "author_url": "…", "sort_order": 0,
           "talking_points": null, "parent_id": 2 }
       ]
     },
     "config": {
       "show_title": "…", "show_tagline": "…", "sections": { … },
       "ws_port": 9001
     }
   }
   ```

2. **Host View HTML** — Add `<div id="host-view" hidden>` to the page body. This element is populated dynamically by `recordingModule`. It is outside the `.prep-ui` wrapper.

3. **Wrapping prep UI** — The existing main UI content (episode meta, workspace, action bar, output panel) must be wrapped in `<div class="prep-ui">` to allow the CSS `.recording-mode .prep-ui { display: none }` rule to work.

---

### 3.7 New: WebSocket Server — `bin/ws-server.php`

**Purpose:** A standalone PHP CLI script implementing the RFC 6455 WebSocket protocol using raw PHP socket functions. No external libraries. Relays `navigate` messages from the Host View to the Audience View.

**Class/structure:** A single self-contained script (`require_once __DIR__ . '/../etc/config.php'` for port configuration).

**Implementation requirements:**

- Uses `socket_create()`, `socket_bind()`, `socket_listen()`, `socket_select()`, `socket_accept()`, `socket_read()`, `socket_write()`, `socket_close()` from PHP's `ext-sockets`.
- Binds to `127.0.0.1:{ws_port}` — localhost only.
- Accepts multiple concurrent connections (maintains array of connected sockets).
- Implements RFC 6455 handshake:
  1. Read HTTP upgrade request.
  2. Extract `Sec-WebSocket-Key` header.
  3. Compute accept key: `base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))`.
  4. Send 101 Switching Protocols response.
- Implements WebSocket frame parsing (decode incoming frames):
  - Support text frames (opcode 0x1) and close frames (opcode 0x8) and ping (0x9)/pong (0xA).
  - Handle masking (clients always mask; server must unmask).
  - Handle multi-byte payload length (7-bit, 16-bit, 64-bit).
- Implements WebSocket frame encoding (encode outgoing frames):
  - Server sends unmasked frames.
  - Text frames (opcode 0x1), FIN bit set.
- On receiving a text frame: decode JSON, check `action` field.
  - `navigate`: broadcast the raw JSON to all OTHER connected clients.
  - `hello`: log the role; optionally send `ack` with client count.
  - Unrecognised: log and ignore.
- Logging: `echo` to stdout with timestamp and client address for: connections, disconnections, messages received, errors.
- Signal handling: catch SIGINT for clean shutdown (close all sockets).
- Non-blocking `socket_select()` loop with 0.1 s timeout (allows SIGINT to be caught on PHP 8.4).

**Detailed frame parsing pseudocode:**

```
function parseFrame(socket):
    data = socket_read(socket, 4096)
    if !data: return null (disconnect)

    byte1 = ord(data[0])
    byte2 = ord(data[1])
    fin    = (byte1 >> 7) & 1
    opcode = byte1 & 0x0F
    masked = (byte2 >> 7) & 1
    paylen = byte2 & 0x7F

    offset = 2
    if paylen == 126:
        paylen = unpack('n', substr(data, offset, 2))[1]; offset += 2
    elif paylen == 127:
        paylen = unpack('J', substr(data, offset, 8))[1]; offset += 8

    mask = ''
    if masked:
        mask = substr(data, offset, 4); offset += 4

    payload = substr(data, offset, paylen)
    if masked:
        for i in range(paylen):
            payload[i] ^= mask[i % 4]

    return { opcode, payload }

function encodeFrame(payload):
    len = strlen(payload)
    if len <= 125:
        return chr(0x81) . chr(len) . payload
    elif len <= 65535:
        return chr(0x81) . chr(126) . pack('n', len) . payload
    else:
        return chr(0x81) . chr(127) . pack('J', len) . payload
```

**Startup output:**

```
[ws-server] Cozy News Corner WebSocket Server
[ws-server] Listening on ws://127.0.0.1:9001
[ws-server] Press Ctrl+C to stop.
```

**Acceptance Criteria:**
- Server starts and listens on 127.0.0.1:9001 within 1 s.
- Two simultaneous connections (host + audience) are maintained.
- A `navigate` message sent by the host is received by the audience within 100 ms on localhost.
- Server exits cleanly on Ctrl+C without zombie processes.
- If PHP socket functions are unavailable, the script exits with a clear error message.

---

### 3.8 New: Audience View — `www/audience.php`

**Purpose:** A dedicated, minimal PHP page that opens in the second browser window. Displays the current article in a full-viewport iframe. Synchronises with the Host View via WebSocket.

**PHP responsibilities:**
- Output `<!DOCTYPE html>` with minimal HTML.
- Inject the WebSocket port from `etc/config.php` into a `<script>` block.
- No dependency on `Database.php`, `app.js`, or `style.css`.

**HTML structure:**

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cozy News Corner — Audience View</title>
    <style>
        /* All audience styles inline — see §3.5 Audience View Styles */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; height: 100%; overflow: hidden; background: #000; }
        #audience-iframe { width: 100%; height: 100%; border: none; display: none; }
        #waiting-msg { … }
        #loading-bar { … }
        #fallback-msg { … }
        #disconnect-dot { … }  /* subtle corner indicator */
    </style>
</head>
<body>
    <div id="waiting-msg">Waiting for broadcast…</div>
    <div id="loading-bar"></div>
    <iframe id="audience-iframe" sandbox="allow-scripts allow-same-origin allow-forms
        allow-popups allow-presentation"></iframe>
    <div id="fallback-msg" hidden></div>
    <div id="disconnect-dot" hidden></div>

    <script>
    const WS_PORT = <?= (int)$config['ws_port'] ?>;
    // Inline WebSocket client + iframe/fallback logic — see §8
    </script>
</body>
</html>
```

**Iframe `sandbox` attribute:** Uses a permissive but scoped sandbox:
- `allow-scripts` — articles typically require JS
- `allow-same-origin` — required for some sites to render correctly
- `allow-forms` — allow form interactions if present
- `allow-popups` — some articles link out
- `allow-presentation` — full-screen video embeds

---

## 4. Data Architecture

### 4.1 Schema Migration

The existing `items` table gains two new columns via `ALTER TABLE` statements. These statements run inside `Database::init()` (which runs on every connection), guarded by a try/catch so they silently succeed if the columns already exist (SQLite does not support `ADD COLUMN IF NOT EXISTS` prior to SQLite 3.37; use a try/catch around each `ALTER TABLE`).

```sql
ALTER TABLE items ADD COLUMN talking_points TEXT;
ALTER TABLE items ADD COLUMN parent_id INTEGER REFERENCES items(id) ON DELETE SET NULL;
```

**Migration strategy:** On connection, `Database.php` attempts both `ALTER TABLE` statements inside a try/catch block. If they fail (columns already exist), the exception is swallowed silently. This is the same pattern used for schema initialisation in the existing codebase.

**Index addition:**

```sql
CREATE INDEX IF NOT EXISTS idx_items_parent
    ON items (parent_id, sort_order);
```

### 4.2 Modified Data Model: `items` Table

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT | Unchanged |
| `section` | TEXT | NOT NULL, CHECK IN ('vulnerability','news') | Unchanged |
| `url` | TEXT | NOT NULL DEFAULT '' | Unchanged |
| `title` | TEXT | NOT NULL DEFAULT '' | Unchanged |
| `author_name` | TEXT | NOT NULL DEFAULT '' | Unchanged |
| `author_url` | TEXT | NOT NULL DEFAULT '' | Unchanged |
| `sort_order` | INTEGER | NOT NULL DEFAULT 0 | **Semantic change for news:** now represents position among sibling items at the same level (top-level items among top-level items; secondaries within their group) |
| `talking_points` | TEXT | nullable | NEW. Newline-delimited bullet text. NULL/'' = no talking points. Only set on items where `parent_id IS NULL` and `section = 'news'`. |
| `parent_id` | INTEGER | nullable, FK → `items.id` ON DELETE SET NULL | NEW. NULL = standalone or primary link. Non-null = secondary link, points to primary's `id`. |

### 4.3 Data Invariants (Application-Enforced)

These invariants are enforced by the API layer and not by database constraints (SQLite has limited support for complex check constraints):

| Invariant | Rule |
|-----------|------|
| No secondary vulnerabilities | `parent_id IS NOT NULL` items must have `section = 'news'` |
| No multi-level nesting | `parent_id` must reference an item where `parent_id IS NULL` |
| No talking points on secondaries | `talking_points` must be NULL/empty when `parent_id IS NOT NULL` |
| Primary always in news | Items with children (items referencing their `id` via `parent_id`) must have `section = 'news'` |
| Generator isolation | `Generator.php` receives items via `getItemsFlat()` which includes all items; generator does not read `talking_points` or `parent_id` |

---

## 5. API Specifications

All existing API actions from `SPECIFICATION.md v1.0` are unchanged unless noted. New/modified actions are fully specified below.

All requests: `POST /api.php`, `Content-Type: application/json`.
All responses: `Content-Type: application/json`.
Status codes: 200 (success), 400 (bad input), 409 (confirmation required), 500 (server error).

---

### 5.1 Action: `update_talking_points` *(NEW)*

**Description:** Updates the talking-points text for a primary or standalone news item. Called by the auto-save debounce in the prep view.

**Request:**
```json
{
  "action": "update_talking_points",
  "itemId": 42,
  "talkingPoints": "First talking point\nSecond talking point\nThird talking point"
}
```

**Validation:**
- `itemId`: integer, required.
- `talkingPoints`: string, required (empty string is valid — clears talking points).
- The item must exist and have `parent_id IS NULL`; if `parent_id IS NOT NULL`, return 400.

**Response (success):**
```json
{
  "success": true,
  "data": {
    "item": {
      "id": 42,
      "talking_points": "First talking point\nSecond talking point\nThird talking point"
    }
  }
}
```

**Response (secondary item):**
```json
{
  "success": false,
  "error": "Talking points can only be set on primary or standalone items."
}
```

---

### 5.2 Action: `nest_item` *(NEW)*

**Description:** Makes one item a secondary link under another (the target becomes/remains the primary). Handles the primary-demotion warning flow.

**Request:**
```json
{
  "action": "nest_item",
  "itemId": 5,
  "targetId": 3,
  "transferTalkingPoints": false
}
```

**Field descriptions:**
- `itemId`: ID of the item being nested (to become secondary).
- `targetId`: ID of the item becoming (or remaining) the primary.
- `transferTalkingPoints`: boolean. Must be explicitly `true` to proceed when `itemId` is a primary with non-empty talking points.

**Validation:**
- Both IDs must exist in the `items` table.
- Both items must have `section = 'news'`.
- `targetId` must have `parent_id IS NULL` (cannot nest under a secondary).
- `itemId` must not equal `targetId`.
- `targetId`'s `parent_id` must not equal `itemId` (no circular reference).

**Confirmation gate (409 response):**

If `itemId` has `parent_id IS NULL` AND `talking_points` is non-empty AND `transferTalkingPoints !== true`:

```json
{
  "success": false,
  "requiresConfirmation": true,
  "warning": "This item has recording notes. If you continue, those notes will be transferred to the new primary link. The item will lose its notes.",
  "fromItemId": 5,
  "toItemId": 3
}
```

The frontend displays the confirmation dialog. On "Transfer & Continue", re-sends with `"transferTalkingPoints": true`. On "Cancel", aborts.

**Response (success):**
```json
{
  "success": true,
  "data": {
    "items": {
      "news": [ /* full updated flat item list with parent_id and talking_points */ ]
    }
  }
}
```

The response returns the full updated news item list so the frontend can re-render the entire news section.

**Side effects:**
- If `itemId` was a primary with children: those children's `parent_id` are updated to `targetId`.
- If `transferTalkingPoints === true`: `targetId.talking_points` is set to `itemId.talking_points`; `itemId.talking_points` is cleared.
- Sort_order of `itemId` (as secondary) is set to `MAX(sort_order)+1` among `targetId`'s existing secondaries.

---

### 5.3 Action: `extract_item` *(NEW)*

**Description:** Extracts a secondary item from its group, making it a standalone item at a specified position in the top-level news list.

**Request:**
```json
{
  "action": "extract_item",
  "itemId": 7,
  "newTopLevelOrder": [3, 7, 12, 15]
}
```

**Field descriptions:**
- `itemId`: ID of the secondary item to extract.
- `newTopLevelOrder`: Ordered array of all top-level news item IDs (parent_id IS NULL) after the extraction, including `itemId` at its new position.

**Validation:**
- `itemId` must have `parent_id IS NOT NULL`.
- `newTopLevelOrder` must contain `itemId` and all existing top-level news item IDs (no additions, no omissions except that `itemId` is being promoted).
- No duplicate IDs.

**Response (success):**
```json
{
  "success": true,
  "data": {
    "items": {
      "news": [ /* full updated flat item list */ ]
    }
  }
}
```

**Side effects:**
- `itemId.parent_id` is set to NULL.
- Sort_order for all top-level news items is resequenced per `newTopLevelOrder`.
- Remaining secondaries in the original group have their sort_order resequenced.

---

### 5.4 Action: `reorder_group` *(NEW)*

**Description:** Reorders the secondary items within a story group.

**Request:**
```json
{
  "action": "reorder_group",
  "primaryId": 3,
  "newSecondaryOrder": [8, 5, 11]
}
```

**Validation:**
- `primaryId` must have `parent_id IS NULL`.
- All IDs in `newSecondaryOrder` must have `parent_id = primaryId`.
- No missing or extra IDs.

**Response (success):**
```json
{
  "success": true,
  "data": {
    "items": {
      "news": [ /* full updated flat item list */ ]
    }
  }
}
```

---

### 5.5 Modified Action: `delete_item`

**Change:** The handler must now check if the item being deleted is a primary with children. If so, `Database::deleteItem()` handles the promotion and talking-points transfer logic automatically (see §3.2). No change to the API request or response format.

**Response (success):** Unchanged. The frontend should re-render the full news section after any delete.

---

### 5.6 Modified Action: `reorder_items` (news section)

**Change:** For `section = 'news'`, the `order` array must contain only top-level item IDs (items where `parent_id IS NULL`). The server validates this and returns 400 if any supplied ID has `parent_id IS NOT NULL`.

**Request (news — unchanged format, new validation):**
```json
{
  "action": "reorder_items",
  "section": "news",
  "order": [3, 12, 7]
}
```

Note: `order` does NOT include secondary item IDs. Secondaries follow their primaries implicitly.

---

### 5.7 Modified Action: `generate_markdown`

**Change:** The handler now calls `$db->getItemsFlat()` instead of `$db->getItems()` to retrieve items for the Generator. The `getItemsFlat()` method returns all items (including secondaries) ordered correctly for the Markdown output (primary first, then its secondaries, per FR-G05). No other change to request or response format.

---

## 6. WebSocket Protocol

### 6.1 Connection Lifecycle

```
Host Window                ws-server.php              Audience Window
    │                           │                           │
    │──── TCP connect ──────────►│                           │
    │──── HTTP Upgrade ─────────►│                           │
    │◄─── 101 Switching Protocols│                           │
    │──── hello {role:host} ────►│                           │
    │◄─── ack {clients:1} ───────│                           │
    │                           │◄─── TCP connect ──────────│
    │                           │◄─── HTTP Upgrade ─────────│
    │                           │──── 101 Switching Protocols►│
    │                           │◄─── hello {role:audience} ─│
    │                           │──── ack {clients:2} ───────►│
    │                           │                           │
    │──── navigate {…} ─────────►│                           │
    │                           │──── navigate {…} ─────────►│
    │                           │                           │
    │──── navigate {…} ─────────►│                           │
    │                           │──── navigate {…} ─────────►│
    │                           │                           │
    │──── close ────────────────►│                           │
    │                           │──── close ────────────────►│
```

### 6.2 Message Protocol

All messages are JSON-encoded text frames (UTF-8).

#### `hello` — Client → Server (on connection)

```json
{ "action": "hello", "role": "host" }
{ "action": "hello", "role": "audience" }
```

#### `ack` — Server → Client (optional, on each connection)

```json
{ "action": "ack", "clients": 2 }
```

#### `navigate` — Host → Server → Audience

```json
{
  "action": "navigate",
  "itemId": 42,
  "url": "https://example.com/article",
  "title": "Article Title Here",
  "section": "news"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `itemId` | integer | DB id of the current item |
| `url` | string | URL to load in audience iframe |
| `title` | string | Display title of the current item |
| `section` | `"vulnerability"` \| `"news"` | Segment identifier |

The server broadcasts the `navigate` message verbatim to all connected clients that did not send it (i.e., the audience).

#### Reconnect behaviour

The audience window's WebSocket client reconnects every 3 seconds on unexpected close. The host window's client reconnects every 3 seconds as well, but the recording module shows the "disconnected" indicator and the host is not blocked from navigating.

---

## 7. Recording Mode — Host View

### 7.1 Entry and Exit

**Entry (from "Start Recording" button click):**

1. Disable the "Start Recording" button (prevents double-click).
2. Attempt `wsClientModule.connect()`.
3. Wait up to 1 s for the connection result:
   - Connected → proceed.
   - Timed out → show `#ws-warning` panel:
     ```
     WebSocket server not found. Start it with:
       php bin/ws-server.php
     [Continue without sync]  [Cancel]
     ```
   - "Continue without sync": proceed (audience window opens, shows static message).
   - "Cancel": re-enable button, abort.
4. Call `openAudienceWindow()`.
5. If `window.open()` returns null: show inline instruction, abort.
6. Apply `.recording-mode` to `<body>`.
7. Build run order; navigate to index 0; send first navigate event (if item).
8. Bind `document.addEventListener('keydown', handleRecordingKey)`.

**Exit (from "Exit Recording" button or Escape key):**

1. Remove `.recording-mode` from `<body>`.
2. `document.removeEventListener('keydown', handleRecordingKey)`.
3. `wsClientModule.disconnect()`.
4. `closeAudienceWindow()`.
5. Re-enable "Start Recording" button.

### 7.2 Keyboard Navigation

The global keydown handler is active only during recording mode. It intercepts events regardless of focused element.

| Key | Action |
|-----|--------|
| `ArrowRight` or `Space` | Advance to next run-order entry |
| `ArrowLeft` | Go back to previous run-order entry |
| `Home` | Jump to index 0 |
| `End` | Jump to last index |
| `Escape` | Exit recording mode |

Boundaries:
- Advancing past the last index: no-op, no wrap.
- Retreating before index 0: no-op, no wrap.
- `Space` key: `e.preventDefault()` to avoid page scroll.

On every navigation step:
1. Update `currentIndex`.
2. Call `renderHostView(runOrder[currentIndex])`.
3. If `runOrder[currentIndex].type === 'item'`: call `wsClientModule.sendNavigate(item)`.
4. If `runOrder[currentIndex].type === 'segment_break'`: do not send navigate event (audience stays on last item).

### 7.3 Segment Break Marker

When `runOrder[currentIndex].type === 'segment_break'`:
- `renderHostView` shows the segment break layout (`.hv-segment-break` element) with large "NEWS" text.
- Talking-points panel is hidden.
- Navigation counter reads "Segment break" (not a numbered stop).
- No navigate WebSocket event is sent.
- Pressing Next advances to the first news item and sends its navigate event.

### 7.4 Navigation Counter

Displayed as "Item N of M" where:
- M = total number of `type: 'item'` entries in the run order (segment breaks are not counted).
- N = count of `type: 'item'` entries navigated to so far (1-indexed), or "Break" for segment breaks.

### 7.5 Read-Only Enforcement

When `.recording-mode` is active on `<body>`, all prep-UI contenteditable elements and form inputs are in the hidden `.prep-ui` div and thus inaccessible. The host view itself renders only static text (no contenteditable, no inputs). No additional read-only enforcement is required.

### 7.6 Host View — Visual Layout Reference

```
┌─────────────────────────────────────────────────────────────────────┐
│  ● WS: Connected   │   [Exit Recording]   │          3 of 17        │
│  (13px muted)      │   (glass button)     │         (14px muted)    │
│─────────────────────────────────────────────────────────────────────│
│                                                                      │
│  VULNERABILITY                           ← .hv-segment-label        │
│  14px uppercase, letter-spacing 2px                                  │
│                                                                      │
│  Article Title Goes Here                 ← .hv-title                │
│  28px bold, line-height 1.3                                          │
│                                                                      │
│  https://example.com/the-article/path    ← .hv-url                  │
│  14px muted, word-break break-all                                    │
│                                                                      │
│  ● First talking point bullet            ← .hv-talking-points ul li  │
│  ● Second talking point bullet           22px, ● in accent color     │
│  ● Third talking point bullet                                        │
│                                                                      │
│─────────────────────────────────────────────────────────────────────│
│  [◀ Prev]                    Segment break            [Next ▶]       │
│  18px, 12px 36px pad                                  disabled → 45% │
└─────────────────────────────────────────────────────────────────────┘

Segment break screen (replaces content area only — topbar and navbar unchanged):
┌─────────────────────────────────────────────────────────────────────┐
│  ● WS: Connected   │   [Exit Recording]   │       Segment break      │
│─────────────────────────────────────────────────────────────────────│
│                                                                      │
│                         N E W S                                      │
│               96px, letter-spacing 24px, accent #e94560             │
│                                                                      │
│                 ← press Next to continue →                           │
│                 16px muted text, no letter-spacing                   │
│                                                                      │
│─────────────────────────────────────────────────────────────────────│
│  [◀ Prev]                                             [Next ▶]       │
└─────────────────────────────────────────────────────────────────────┘
```

**Navigation button states:**

| State | Background | Text color | Notes |
|-------|-----------|------------|-------|
| Default | `#2a2a4a` | `#eaeaea` | Resting state |
| Hover | `#3d3d6b` | `#eaeaea` | Transition: 0.15s ease |
| Disabled | `rgba(255,255,255,0.06)` | `#4a5468` | Prev at index 0; Next at last index |

**Exit Recording button:** Glass effect — `rgba(255,255,255,0.10)` background, `1px solid rgba(255,255,255,0.25)` border. On hover: `rgba(255,255,255,0.20)`. Padding: `6px 16px`. This deliberately contrasts with the nav buttons so it is not accidentally pressed during navigation.

---

## 8. Audience View

### 8.1 Initial State

When `audience.php` loads before any navigate event is received, it shows:
- `#waiting-msg`: "Waiting for broadcast…" centered in the viewport, white text on black background.
- `#loading-bar`: hidden.
- `#audience-iframe`: `display: none`.

### 8.2 Iframe Loading and Fallback

On receiving a `navigate` message:

1. **Validate URL:** Check that the URL scheme is `http://` or `https://`. Check that the host is not a private IP range (127.x, 10.x, 172.16-31.x, 192.168.x, ::1, etc.). If validation fails: silently ignore (do not load). Log to console.

2. **If valid URL:**
   - Hide `#waiting-msg`.
   - Show `#loading-bar` (animated progress bar, CSS animation, not JS-controlled).
   - Set `#audience-iframe.src = url`.
   - Set a fallback timer for 3 seconds.

3. **On iframe `load` event:**
   - Clear the fallback timer.
   - Hide `#loading-bar`.
   - Show `#audience-iframe`.

4. **On iframe `error` event OR fallback timer expires:**
   - Clear the fallback timer.
   - Hide `#loading-bar`.
   - Remove iframe from DOM (or set `src=""` and hide it).
   - Call `window.open(url, '_blank')`.
   - Show `#fallback-msg`: `"Opening [title] in a new tab…"` for 5 seconds, then hide.

**Iframe same-origin detection limitation:** Browsers do not expose X-Frame-Options/CSP errors to the parent frame's JS. The timeout heuristic is the primary fallback mechanism, as specified in REQUIREMENTS-RECORDING.md §12 Assumption 7.

**Iframe sandbox attribute:**

```html
<iframe sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-presentation">
```

Note: `allow-same-origin` is required for the load event to fire correctly for same-origin content; for cross-origin content, the `load` event still fires even for framing-blocked pages (the blocked page shows an error page). The 3-second timeout is therefore the reliable trigger.

### 8.3 WebSocket Client (Audience)

Inline `<script>` in `audience.php`:

```javascript
(function() {
    const WS_URL = `ws://127.0.0.1:${WS_PORT}`;
    let ws = null;
    let reconnectTimer = null;
    let lastUrl = null;
    let lastTitle = '';

    function connect() {
        try {
            ws = new WebSocket(WS_URL);
            ws.onopen = () => {
                hideDisconnectDot();
                ws.send(JSON.stringify({ action: 'hello', role: 'audience' }));
            };
            ws.onmessage = (e) => {
                try {
                    const msg = JSON.parse(e.data);
                    if (msg.action === 'navigate') handleNavigate(msg);
                } catch (_) {}
            };
            ws.onclose = () => {
                showDisconnectDot();
                reconnectTimer = setTimeout(connect, 3000);
            };
            ws.onerror = () => { /* onclose will follow */ };
        } catch (err) {
            showDisconnectDot();
            reconnectTimer = setTimeout(connect, 3000);
        }
    }

    function handleNavigate(msg) {
        if (!msg.url || msg.url === lastUrl) return;
        lastUrl = msg.url;
        lastTitle = msg.title || '';
        loadUrl(msg.url);
    }

    connect();
})();
```

### 8.4 No-Sync Mode

If the WebSocket server is unavailable, `audience.php` shows:
- `#waiting-msg`: "Live sync unavailable. Start the server with: php bin/ws-server.php"
- The `#disconnect-dot` is shown in its disconnected state.
- The reconnect loop continues every 3 s silently.
- No error is shown to the audience (the message is styled to be minimal and only relevant to the host).

### 8.5 Disconnection Indicator

`#disconnect-dot`: a 12 px circle, `position: fixed; bottom: 12px; left: 12px`. Only shown (`display: block`) when disconnected; hidden (`display: none`) when connected. Color when disconnected: `#e74c3c`. Animation: `@keyframes audiencePulse` — `opacity` oscillates between 0.6 (rest) and 0.2 (mid-pulse), 2 s cycle with `ease-in-out`. The dot is intentionally not shown green when connected: its absence is the "all good" signal.

### 8.6 Audience View — Visual State Reference

```
Initial state (no navigate event received yet):
┌─────────────────────────────────────────────────────────────────────┐
│                                                                      │
│              Waiting for broadcast…                                  │
│              (20px, rgba(255,255,255,0.75), centered)               │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘

Loading state (iframe src just set, waiting for load event):
┌─────────────────────────────────────────────────────────────────────┐
│▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓── 4px animated bar slides L→R in #e94560 ────────│
│                                                                      │
│   (iframe hidden, #waiting-msg also hidden, loading bar on top)     │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘

Loaded state (iframe load event fired):
┌─────────────────────────────────────────────────────────────────────┐
│  [full-viewport iframe: article content fills the window]            │
│                                                                      │
│                                                                      │
│                                                                      │
│  ●  ← bottom-left dot, hidden (connected) or red pulsing (dropped) │
└─────────────────────────────────────────────────────────────────────┘

Fallback state (iframe blocked, 3s timeout triggered):
┌─────────────────────────────────────────────────────────────────────┐
│  [iframe removed or hidden]                                          │
│                                                                      │
│                                                                      │
│       ╔══════════════════════════════════════════╗                   │
│       ║  Opening Article Title in a new tab…    ║  ← fallback msg  │
│       ╚══════════════════════════════════════════╝                   │
│         dark glass panel, 14px, fade out after 5s                   │
└─────────────────────────────────────────────────────────────────────┘

No-sync state (WebSocket server unavailable):
┌─────────────────────────────────────────────────────────────────────┐
│                                                                      │
│   Live sync unavailable.                                             │
│   Start the server with: php bin/ws-server.php                      │
│   (20px, white/75%, centered; text wraps naturally)                 │
│                                                                      │
│  ●  ← bottom-left dot visible (red, pulsing)                        │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 9. Story Groups — Prep UI

### 9.1 Group Rendering

The news section list is re-rendered by `storyGroupModule.renderNewsSection(newsItems)`, which receives the flat news item array (with `parent_id` and `talking_points`) from state.

**Grouping algorithm:**

```javascript
function groupNewsItems(items) {
    const primaries = new Map();   // id → { primary, secondaries[] }
    const order = [];              // top-level order (primaries + standalones by sort_order)

    for (const item of items) {
        if (item.parent_id === null) {
            primaries.set(item.id, { primary: item, secondaries: [] });
            order.push(item.id);
        }
    }
    for (const item of items) {
        if (item.parent_id !== null) {
            primaries.get(item.parent_id)?.secondaries.push(item);
        }
    }
    return order.map(id => primaries.get(id));
}
```

Items with `parent_id === null` that have no children render as standalone items (identical to existing behavior, but with a talking-points panel added).

Items with at least one child render inside a `.story-group` container.

### 9.2 Drag-and-Drop Nesting

The DnD event model extends the existing reorder DnD with nesting detection.

**On `dragover` over an item:**

```javascript
function getDragIntent(e, targetElement) {
    const rect = targetElement.getBoundingClientRect();
    const relY = e.clientY - rect.top;
    const pct = relY / rect.height;

    if (pct > 0.25 && pct < 0.75) {
        return 'nest';   // center 50% of the item → nesting drop target
    }
    return 'reorder';    // top 25% or bottom 25% → between-items reorder
}
```

Visual feedback:
- `intent === 'nest'`: Add `.drop-target-nest` to target item; remove drop-indicator bar.
- `intent === 'reorder'`: Remove `.drop-target-nest` from any item; show drop-indicator bar between items.

**On `drop` with `intent === 'nest'`:**

1. Get `draggedId` from `dataTransfer`.
2. Get `targetId` from the target item's `data-item-id`.
3. If `targetId === draggedId`: abort.
4. If target item has `parent_id !== null` (is secondary): show toast "Cannot nest under a secondary item." Abort.
5. If dragged item has `parent_id !== null` (is secondary being moved to a new group):
   - Skip the confirmation gate (secondaries have no talking points).
   - Call `apiCall('nest_item', { itemId: draggedId, targetId, transferTalkingPoints: false })`.
6. If dragged item has `parent_id === null`:
   - Call `apiCall('nest_item', { itemId: draggedId, targetId, transferTalkingPoints: false })`.
   - If response is 409 (`requiresConfirmation: true`): show confirmation dialog (see §9.5).
7. On success: re-render news section with updated state.

**On `drop` with `intent === 'reorder'` in news section:**

- Compute new order of top-level item IDs.
- Call `apiCall('reorder_items', { section: 'news', order: [topLevelIds...] })`.
- Top-level IDs are items where `parent_id === null`.
- Group containers (primaries) participate in reordering; their secondaries follow implicitly.

**Group container drag handle:**

The `.story-group` container has a `draggable="true"` attribute and a drag handle element. When dragged, `dataTransfer` carries the primary item's ID. On drop into the top-level reorder zone, the group (primary + all its secondaries) moves as a unit.

**Within-group secondary reordering:**

Secondary items have drag handles within their `.story-group__secondaries` container. DnD within this container calls `apiCall('reorder_group', { primaryId, newSecondaryOrder: [...] })`.

### 9.3 Extraction from Group

When a secondary item is dragged outside the `.story-group__secondaries` container and dropped into the top-level news list:

1. Compute `newTopLevelOrder`: the current top-level IDs with the extracted item inserted at the drop position.
2. Call `apiCall('extract_item', { itemId, newTopLevelOrder })`.
3. On success: re-render news section.

Dissolution check: if the group now has only the primary remaining (zero secondaries), the primary is rendered as a standalone item. This is handled automatically by the grouping algorithm on re-render.

### 9.4 Primary Link Promotion on Delete

The primary-promotion logic lives entirely in `Database::deleteItem()` (see §3.2). The API returns the updated news item list. The frontend re-renders the news section from the response.

No UI-level intervention required. The user sees the confirmation dialog for item deletion (existing behavior), after which the backend handles the promotion automatically.

### 9.5 Primary Demotion Confirmation Dialog

When `apiCall('nest_item', …)` returns a 409 response with `requiresConfirmation: true`:

1. The DnD operation is visually cancelled (dragged item returns to original position).
2. A modal dialog is shown:

   ```
   ┌─────────────────────────────────────────────────────┐
   │  Transfer Recording Notes?                           │
   │                                                      │
   │  "[Item Title]" has recording notes. If you         │
   │  continue, those notes will be moved to the new     │
   │  primary link.                                       │
   │                                                      │
   │  [Cancel]              [Transfer & Continue]         │
   └─────────────────────────────────────────────────────┘
   ```

3. **"Cancel"**: close dialog; no API call; items remain in original positions.
4. **"Transfer & Continue"**: re-send `apiCall('nest_item', { …, transferTalkingPoints: true })`; on success, re-render news section.

### 9.6 Story Groups — Visual Layout Reference

**Story group container anatomy:**

```
  ⠿ ┌────────────────────────────────────────────────────────────┐
    │  ⠿  [PRIMARY]  Article Title / Primary URL                   │  ← .story-group__primary
    │     URL: https://primary-link.example.com                    │
    │     Author: Jane Doe | Author URL: …                        │
    │     ──────────────────────────────────────────────────────── │
    │     Recording Notes — not included in show notes             │
    │     • First talking point                                    │
    │  ├──────────────────────────────────────────────────────────┤
    │  │  ⠿  [SECONDARY]  Another Article                        │  ← indented 24px
    │  │     URL: https://secondary.example.com                   │     with left border
    │  ├──────────────────────────────────────────────────────────┤     connector line
    │  │  ⠿  [SECONDARY]  Third Article                         │
    │  │     URL: https://third.example.com                       │
    └──┴──────────────────────────────────────────────────────────┘
  ↑
  Group-level drag handle (⠿, same glyph as item handles)
  Positioned left of the .story-group container border
```

**Badge visual specification:**

| Property | Value |
|----------|-------|
| Font size | 10px |
| Font weight | 700 (bold) |
| Padding | 2px 8px |
| Border radius | 100px (fully rounded pill) |
| Text transform | UPPERCASE |
| Letter spacing | 0.06em |
| Color | #ffffff on colored background |
| PRIMARY background | `#4a7c59` (`--color-primary-badge`) |
| SECONDARY background | `#7fa99f` (`--color-secondary-badge`) |

**Modal dialog visual specification (for confirmation dialogs):**

```css
/* Backdrop */
.modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.50);
  z-index: 500;
  display: flex; align-items: center; justify-content: center;
}

/* Dialog panel */
.modal-dialog {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
  padding: var(--spacing-lg);
  max-width: 440px; width: calc(100% - 2 * var(--spacing-lg));
  z-index: 501;
}

.modal-dialog__title  { font-size: 16px; font-weight: 700; margin-bottom: var(--spacing-sm); }
.modal-dialog__body   { font-size: 14px; color: var(--color-text); line-height: 1.6;
                         margin-bottom: var(--spacing-lg); }
.modal-dialog__actions {
  display: flex; justify-content: flex-end; gap: var(--spacing-sm);
}
/* [Cancel] uses secondary/ghost button style */
/* [Transfer & Continue] uses .btn-primary (green) */
/* [Delete] in item-delete dialog uses .btn-danger (red) */
```

---

## 10. Talking Points — Prep UI

### 10.1 Panel Display

The talking-points panel is rendered directly below the item's existing metadata fields (URL, title, author name, author URL) within the item card. It is displayed as:

```
┌──────────────────────────────────────────────────────┐
│  [drag handle] [PRIMARY]  Article Title               │
│  URL: https://example.com/…                          │
│  Author: Jane Doe  |  Author URL: …                  │
│  ─────────────────────────────────────────────────── │
│  Recording Notes — not included in show notes        │
│  • First talking point                               │
│  • Second talking point                              │
│  • ▌  (cursor on empty new bullet)                   │
└──────────────────────────────────────────────────────┘
```

The panel is always visible in prep mode (not collapsed) for primary/standalone items.
Secondary items within a group: no talking-points panel.
Vulnerability items: no talking-points panel (the field exists in the DB but is never shown or edited).

### 10.2 Bullet List Editor

Each bullet is a `<li contenteditable="true" spellcheck="true">`.

**Key bindings within a bullet:**

| Key | Behaviour |
|-----|-----------|
| `Enter` | Insert new empty `<li>` after the current one, move cursor to it |
| `Backspace` (on empty bullet) | Delete the `<li>`; move cursor to the end of the previous `<li>` |
| `Tab` | (no action; tabs are not supported in the bullet structure) |

**Auto-save:**

An 800 ms debounce timer starts on each `input` event within any bullet in the panel. On fire:
1. Collect text content of all `<li>` elements (trim each).
2. Join with `\n`.
3. Call `apiCall('update_talking_points', { itemId, talkingPoints })`.
4. On success: update `state.items.news[itemIndex].talking_points`.

### 10.3 Rendering from State

When the news section is rendered from `INITIAL_STATE` or after an API response:
- Parse `item.talking_points` by splitting on `\n`.
- Filter out empty strings (ignore blank lines from old data).
- Render each non-empty line as a `<li contenteditable="true">` with `textContent` = the line.
- If `talking_points` is null/empty: render one empty `<li>` (placeholder for entry).

### 10.4 Host View Display

In the Host View, talking points are rendered as a read-only `<ul>` from the current run-order item's `talking_points` field:
- Split on `\n`, filter empty lines.
- Each line → `<li>` with `textContent` (not `innerHTML`).
- If no talking points (or vulnerability item): render an empty `<ul>` with no bullets (no visible panel).

### 10.5 Talking Points Panel — Visual Layout Reference

**Prep mode panel (within a news item card):**

```
┌──────────────────────────────────────────────────────────────────┐
│  ⠿  [PRIMARY]  Vim 9.2 Is Out with Comprehensive Completion     │
│     URL: https://9to5linux.com/vim-9-2-is-out-…                 │
│     Author: Marcus Nestor  |  Author URL: https://9to5linux.com/…│
│  ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─  │
│  Recording Notes — not included in show notes              (italic)│
│  • Mention the Wayland improvement                         12px label│
│  • Compare to 9.1: no breaking changes                           │
│  • ▌                                                             │
└──────────────────────────────────────────────────────────────────┘
```

**Separator line:** `border-top: 1px solid #d0cec8` with `margin-top: 8px; padding-top: 8px` — uses the same value as the base `--color-border` to feel continuous with the card structure.

**Label text:** "Recording Notes — not included in show notes" — 12px, italic, `var(--color-text-muted)`, displayed on a single line above the bullet list.

**Empty-bullet placeholder:** When a `<li>` is empty, display a `data-placeholder` attribute value of "Add a talking point…" in muted color via CSS `::before` pseudo-element. This placeholder disappears on focus (use `:focus::before { display: none }`).

**Auto-save feedback:** No explicit "Saved" indicator for talking points — the 800 ms auto-save is silent to avoid disrupting the host during prep. Any API error is surfaced as an existing toast notification.

---

## 11. Security Architecture

### 11.1 WebSocket Server Binding

The server binds to `127.0.0.1:9001` only, not `0.0.0.0`. This prevents any external network access to the WebSocket server, even on a networked machine.

### 11.2 URL Validation in Audience View

Before loading any URL into the iframe (or opening in a new tab), the audience view's inline JS validates:

```javascript
function isAllowedUrl(url) {
    try {
        const parsed = new URL(url);
        if (!['http:', 'https:'].includes(parsed.protocol)) return false;
        const hostname = parsed.hostname;
        // Block private IP ranges
        const privateRanges = [
            /^127\./, /^10\./, /^172\.(1[6-9]|2\d|3[01])\./,
            /^192\.168\./, /^::1$/, /^localhost$/i, /^0\.0\.0\.0$/
        ];
        if (privateRanges.some(re => re.test(hostname))) return false;
        return true;
    } catch (_) {
        return false;
    }
}
```

This prevents SSRF-style attacks if the WebSocket message content is tampered with (though both clients are localhost, defence-in-depth applies).

### 11.3 Talking Points as Plain Text

Talking points are rendered using `textContent` (never `innerHTML`) in both the prep view and host view. This prevents any XSS from user-entered talking points.

### 11.4 WebSocket Message Validation

The server forwards messages without modification. The audience window validates the message structure before acting on it:

```javascript
if (msg.action !== 'navigate') return;
if (typeof msg.url !== 'string') return;
if (!isAllowedUrl(msg.url)) return;
```

### 11.5 No New SQL Injection Vectors

All new database operations (`updateTalkingPoints`, `nestItem`, `extractItem`, `reorderGroupItems`) use PDO prepared statements with bound parameters. No string interpolation of user input into SQL.

### 11.6 Error Wrapping

All WebSocket operations in `wsClientModule` and the audience inline script are wrapped in `try/catch`. No unhandled promise rejections or WebSocket errors may propagate to the console as uncaught exceptions.

---

## 12. Testing Strategy

### 12.1 Regression Tests (Existing Functionality)

Before merging this feature, run a full regression pass:

- [ ] URL scraping (Fetch Metadata) returns correct fields
- [ ] Add item to vulnerability section
- [ ] Add item to news section
- [ ] Inline edit of all fields (URL, title, author name, author URL)
- [ ] Delete item from both sections
- [ ] Drag-and-drop reorder within vulnerability section
- [ ] Drag-and-drop reorder within news section (standalone items only)
- [ ] Generate show notes — verify Markdown output is unchanged
- [ ] Copy to clipboard
- [ ] Author history dropdown (domain-aware suggestions)
- [ ] Dark mode (if applicable)
- [ ] New Episode / reset

### 12.2 Story Groups

- [ ] Drag item B onto item A → group forms with A as primary, B as secondary
- [ ] Group container shows PRIMARY / SECONDARY badges
- [ ] Drop-on indicator (ring) is visually distinct from drop-between indicator (bar)
- [ ] Drag secondary out of group → item becomes standalone
- [ ] Drag last secondary out → group dissolves; former primary renders as standalone
- [ ] Reorder secondary items within a group
- [ ] Reorder group containers (primary drag) within news list
- [ ] Drag primary (with talking points) onto another item → 409 → confirmation dialog → Transfer & Continue → talking points transferred to new primary
- [ ] Cancel the confirmation dialog → items unchanged
- [ ] Delete primary with secondaries → first secondary promoted to primary → talking points transferred
- [ ] Delete standalone item → no group effects
- [ ] Delete secondary → group intact

### 12.3 Talking Points

- [ ] Enter talking points → saved after 800 ms debounce → page refresh → talking points persist
- [ ] Enter key on a bullet → new bullet inserted, cursor moves to it
- [ ] Backspace on empty bullet → bullet removed, cursor moves to previous
- [ ] Generate show notes with items that have talking points → verify talking points NOT in output
- [ ] Secondary items: no talking-points panel visible
- [ ] Vulnerability items: no talking-points panel visible
- [ ] Talking points cleared when "New Episode" is used

### 12.4 Recording Mode

- [ ] "Start Recording" button disabled when episode has no items; enabled when ≥ 1 item exists
- [ ] Click "Start Recording" with WebSocket server running → connects, opens audience window, transitions to host view
- [ ] Click "Start Recording" without WebSocket server → warning shown with CLI command; "Continue without sync" proceeds
- [ ] Host view shows correct item title, URL, segment label, talking points for first item
- [ ] Right Arrow → advances to next item; talking points update; counter increments
- [ ] Left Arrow → retreats to previous item
- [ ] Home → jumps to first item
- [ ] End → jumps to last item
- [ ] Advancing past last item → no-op
- [ ] Retreating before first item → no-op
- [ ] Secondary links are NOT navigation stops (skipped in run order)
- [ ] Vulnerability → News boundary → segment break marker shown → press Next → first news item shown
- [ ] "Exit Recording" button → returns to prep view; audience window closes; WS disconnects
- [ ] Escape key → same as Exit Recording

### 12.5 Audience View

- [ ] `audience.php` loads showing "Waiting for broadcast…"
- [ ] Navigate message received → iframe loads article URL
- [ ] Known iframe-blocking URL → timeout fires within 3 s → new tab opens → fallback message shown briefly
- [ ] WebSocket disconnects mid-session → disconnect dot appears; reconnects after 3 s → dot disappears
- [ ] No app chrome visible in audience window (no header, nav, or app UI)
- [ ] Audience window URL validates: `javascript:` scheme → ignored; private IP → ignored

### 12.6 WebSocket Server

- [ ] `php bin/ws-server.php` starts and prints startup message
- [ ] Host and audience windows connect; server logs connections
- [ ] Navigate event sent by host → audience receives it within 100 ms (measure with browser DevTools timestamp)
- [ ] Ctrl+C exits server cleanly
- [ ] Restart server mid-session → both windows reconnect within 6 s; navigation resumes
- [ ] PHP socket functions unavailable → script exits with clear error message (simulate by running with a SAPI that lacks sockets)

---

## 13. Implementation Plan

### Phase 1 — Data Layer (prerequisite for all other phases)

**Files modified:** `include/Database.php`, `etc/config.php`

**Tasks:**
1. Add `ws_host` and `ws_port` constants to `etc/config.php`.
2. Implement schema migration in `Database::init()`: `ALTER TABLE items ADD COLUMN talking_points TEXT` and `ALTER TABLE items ADD COLUMN parent_id INTEGER REFERENCES items(id) ON DELETE SET NULL` with try/catch guards.
3. Add index: `idx_items_parent`.
4. Modify `getItems()` to include `talking_points` and `parent_id` in all returned rows.
5. Modify `reorderItems()` to validate news-section IDs are top-level only.
6. Modify `deleteItem()` to handle primary deletion with secondary promotion and talking-points transfer.
7. Implement `updateTalkingPoints()`.
8. Implement `nestItem()`.
9. Implement `extractItem()`.
10. Implement `reorderGroupItems()`.
11. Implement `getItemsFlat()`.

**Acceptance:** Database methods pass unit tests. Schema auto-migrates from an existing populated database with no data loss.

---

### Phase 2 — API Layer

**Files modified:** `www/api.php`

**Tasks:**
1. Add `update_talking_points` handler.
2. Add `nest_item` handler (including 409 confirmation gate).
3. Add `extract_item` handler.
4. Add `reorder_group` handler.
5. Modify `generate_markdown` handler to use `getItemsFlat()`.
6. Modify `delete_item` handler (no request/response change; updated DB call handles promotion).
7. Verify all new handlers validate inputs and return correct HTTP status codes.

**Acceptance:** All API actions return valid JSON; invalid inputs return 400; server errors return 500; confirmation gate returns 409.

---

### Phase 3 — Story Groups and Talking Points (Prep UI)

**Files modified:** `www/js/app.js`, `www/css/style.css`, `www/index.php`

**Tasks:**
1. Wrap existing prep UI in `<div class="prep-ui">` in `index.php`.
2. Add `<div id="host-view" hidden>` to `index.php`.
3. Extend `INITIAL_STATE` shape to include `ws_port` in config.
4. Implement `storyGroupModule`: group rendering, PRIMARY/SECONDARY badges, group container with drag handle.
5. Extend DnD logic: drop-on-item detection (center 50% heuristic), `.drop-target-nest` class, group container dragging.
6. Implement confirmation dialog for primary demotion (409 handler).
7. Implement `talkingPointsModule`: bullet-list editor, Enter/Backspace key handling, auto-save debounce.
8. Add all new CSS: group containers, badges, talking-points panel, drop-target-nest indicator, "Start Recording" button styles.
9. Implement "Start Recording" button enable/disable logic (item count observer).

**Acceptance:** Story groups render correctly; DnD creates/dissolves groups; talking points save and persist; show notes output unchanged.

---

### Phase 4 — WebSocket Server

**Files created:** `bin/ws-server.php`

**Tasks:**
1. Implement RFC 6455 handshake (HTTP upgrade, Sec-WebSocket-Key computation).
2. Implement `socket_select()` event loop with non-blocking reads.
3. Implement frame parsing (text frames, masking, variable-length payloads, ping/pong/close opcodes).
4. Implement frame encoding (unmasked text frames).
5. Implement `navigate` broadcast logic.
6. Implement `hello`/`ack` messages.
7. Implement SIGINT handler for clean shutdown.
8. Implement startup check for `ext-sockets` availability.

**Acceptance:** Two simultaneous connections maintained; navigate messages round-trip in ≤ 100 ms on localhost; clean Ctrl+C exit.

---

### Phase 5 — Recording Mode and Audience View

**Files created:** `www/audience.php`
**Files modified:** `www/js/app.js`, `www/css/style.css`

**Tasks:**
1. Implement `wsClientModule` in `app.js` (connection management, `hello`, `navigate` send, reconnect, status callback).
2. Implement `recordingModule` in `app.js`:
   - `buildRunOrder()`.
   - Entry/exit flow (WS check, popup, body class).
   - `renderHostView()` for item and segment-break states.
   - Keyboard listener.
   - Navigation counter.
3. Add Host View HTML to `index.php` (`<div id="host-view">`).
4. Add Host View CSS (dark theme, large fonts, segment break marker, nav bar).
5. Create `www/audience.php`: minimal HTML, full-viewport iframe, inline WebSocket client, fallback logic, disconnect dot.
6. Test popup blocker detection and instruction flow.

**Acceptance:** Full recording session testable end-to-end: prep → start recording → host navigates → audience iframe updates → exit recording → prep restored.

---

### Phase 6 — Integration and Regression Testing

**Tasks:**
1. Run full regression suite (§12.1).
2. Run all story group tests (§12.2).
3. Run all talking-points tests (§12.3).
4. Run all recording mode tests (§12.4).
5. Run all audience view tests (§12.5).
6. Run all WebSocket server tests (§12.6).
7. Verify show notes Markdown output is byte-identical to pre-feature output for equivalent input.

---

## 14. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Most article sites block iframe embedding | High | Medium | 3-second timeout fallback to `window.open()` (FR-A01); always-visible fallback message |
| `window.open()` blocked by popup blocker | Medium | High | Detect null return value; show inline instruction with browser-specific guidance for localhost |
| PHP `ext-sockets` disabled in CLI | Low | High | Script checks `function_exists('socket_create')` at startup; exits with clear message if unavailable |
| WebSocket server crashes mid-recording | Low | High | Auto-reconnect every 3 s in both windows; host view remains fully navigable without WS (FR-W04) |
| DnD drop-on vs. drop-between confusion | Medium | Medium | Distinct visual indicators; center 50% zone for nesting is large enough to be intentional |
| Drag-and-drop nesting on a group primary accidentally nests it under another group | Medium | Medium | 409 confirmation gate; cancellable dialog; atomic server-side transaction |
| Sort_order corruption during complex group operations | Low | High | All sort_order updates run inside SQLite transactions; `reorderItems` validates IDs |
| Keyboard shortcuts intercepted by browser/OS | Low | Medium | `e.preventDefault()` for Space; Prev/Next buttons as visible backup (FR-H02) |
| Talking points accidentally in show notes | Very Low | Low | `Generator.php` is unmodified; `getItemsFlat()` does not include talking_points in what it returns; generator ignores extra fields |
| Running multiple episodes simultaneously (two browser sessions) | Very Low | Low | Not supported by design; WebSocket server handles one session; no concurrency issues in SQLite WAL mode |

---

## 15. Appendices

### Appendix A — Modified `INITIAL_STATE` Shape

```json
{
  "episode": {
    "id": 1,
    "week_number": 9,
    "year": 2026,
    "youtube_url": ""
  },
  "items": {
    "vulnerability": [
      {
        "id": 1, "section": "vulnerability", "url": "https://example.com/cve",
        "title": "CVE-2026-1234", "author_name": "", "author_url": "",
        "sort_order": 0, "talking_points": null, "parent_id": null
      }
    ],
    "news": [
      {
        "id": 2, "section": "news", "url": "https://example.com/article",
        "title": "Primary Article", "author_name": "Jane Doe",
        "author_url": "https://example.com/author/jane", "sort_order": 0,
        "talking_points": "First point\nSecond point\nThird point",
        "parent_id": null
      },
      {
        "id": 3, "section": "news", "url": "https://example.com/related",
        "title": "Related Article", "author_name": "Bob Smith",
        "author_url": "https://example.com/author/bob", "sort_order": 0,
        "talking_points": null, "parent_id": 2
      },
      {
        "id": 4, "section": "news", "url": "https://example.com/standalone",
        "title": "Standalone Article", "author_name": "Alice Green",
        "author_url": "https://example.com/author/alice", "sort_order": 1,
        "talking_points": "One note", "parent_id": null
      }
    ]
  },
  "config": {
    "show_title": "Cozy News Corner",
    "show_tagline": "Your source for Open Source news",
    "sections": { "vulnerability": "Vulnerability", "news": "News" },
    "ws_port": 9001
  }
}
```

In this example:
- Item 2 is a primary with one secondary (item 3).
- Item 4 is a standalone item.
- Item 3 has `parent_id: 2` and no talking points.

### Appendix B — File Modification Summary

| File | Status | Changes |
|------|--------|---------|
| `etc/config.php` | Modified | Add `ws_host`, `ws_port` |
| `include/Database.php` | Modified | Schema migration; 4 new methods; 2 modified methods |
| `www/api.php` | Modified | 4 new actions; 3 modified actions |
| `www/index.php` | Modified | INITIAL_STATE shape; `.prep-ui` wrapper; `#host-view` div |
| `www/js/app.js` | Modified | 4 new modules; 3 modified functions |
| `www/css/style.css` | Modified | New custom properties; 4 new component sections |
| `bin/ws-server.php` | **New** | RFC 6455 WebSocket server (standalone PHP CLI) |
| `www/audience.php` | **New** | Audience view (minimal HTML + inline JS + inline CSS) |
| `include/Generator.php` | Unchanged | No modification required |
| `include/Scraper.php` | Unchanged | No modification required |
| `var/shownotes.sqlite` | Auto-migrated | Two new columns added on next request |

### Appendix C — WebSocket Server Startup and Usage

**Prerequisites:**
- PHP 8.4 CLI installed.
- PHP `ext-sockets` enabled in CLI SAPI (`php -m | grep sockets`).
- Port 9001 available (or change `ws_port` in `etc/config.php`).

**Start the server:**

```bash
php bin/ws-server.php
```

**Expected output:**

```
[ws-server] Cozy News Corner WebSocket Server
[ws-server] Listening on ws://127.0.0.1:9001
[ws-server] Press Ctrl+C to stop.
[ws-server] [2026-03-01 19:00:01] Client connected: 127.0.0.1:54321 (total: 1)
[ws-server] [2026-03-01 19:00:02] Client connected: 127.0.0.1:54322 (total: 2)
[ws-server] [2026-03-01 19:00:10] navigate → item 2 (news)
```

**Stop the server:**
Press `Ctrl+C`.

### Appendix D — Run Order Example

For an episode with 2 vulnerability items and a news section containing one story group (primary + 2 secondaries) and one standalone item:

```
Run Order Array:
─────────────────────────────────────────────────────────
Index 0  │ type: 'item'          │ Vuln Item A     (stop 1/3)
Index 1  │ type: 'item'          │ Vuln Item B     (stop 2/3)
Index 2  │ type: 'segment_break' │ NEWS            (not counted)
Index 3  │ type: 'item'          │ News Primary    (stop 3/3)  ← talking points shown
         │                       │   [Secondary 1 — skipped]
         │                       │   [Secondary 2 — skipped]
Index 4  │ type: 'item'          │ News Standalone (stop 3/3 is wrong — let me re-count)

Corrected:
Index 0  │ stop 1 of 3  │ Vuln Item A
Index 1  │ stop 2 of 3  │ Vuln Item B
Index 2  │ break        │ NEWS segment break
Index 3  │ stop 3 of 3  │ News Group Primary
         │              │   ↳ Secondary 1, Secondary 2 (not navigation stops)
Index 4  │ stop 4 of 4  │ News Standalone

Wait — total items (navigation stops) = 2 vulns + 2 news = 4:
Index 0  │ stop 1 of 4  │ Vuln Item A
Index 1  │ stop 2 of 4  │ Vuln Item B
Index 2  │ break        │ NEWS (not a stop)
Index 3  │ stop 3 of 4  │ News Group Primary
Index 4  │ stop 4 of 4  │ News Standalone
─────────────────────────────────────────────────────────
```

**Navigation counter display:** "Item 3 of 4" when at Index 3 (segment break not counted in M or N).

### Appendix E — Glossary (Additions to SPECIFICATION.md Glossary)

| Term | Definition |
|------|-----------|
| **Story Group** | A container in the prep UI holding one primary link and one or more secondary links covering the same news story. |
| **Primary Link** | The first (top) item in a Story Group. Its URL is shown to the audience. Its talking points are displayed to the host. Identified by `parent_id IS NULL` and having at least one item with `parent_id = its id`. |
| **Secondary Link** | Any non-primary item in a Story Group (`parent_id IS NOT NULL`). Included in show notes; not a navigation stop in recording. |
| **Talking Points** | Private bullet notes on a primary/standalone news item. Shown only in the Host View during recording. Stored in the `talking_points` column. Never in show notes. |
| **Host View** | The full-screen recording UI shown on the host's primary monitor when `.recording-mode` is applied to `<body>`. |
| **Audience View** | `www/audience.php`, opened as a second browser window. Shows the current article in a full-viewport iframe. |
| **WebSocket Sync Server** | `bin/ws-server.php`. A PHP CLI process that relays `navigate` events from the Host View to the Audience View. |
| **Run Order** | The ordered array of navigation stops computed at recording-start: all vulnerability items, then the NEWS segment break, then all news primary/standalone items. |
| **Segment Break** | The `{ type: 'segment_break', segment: 'news' }` entry in the run order. Displays the "NEWS" full-screen marker. Requires a keypress to advance past. |
| **No-Sync Mode** | Recording mode entered when the WebSocket server is unavailable. Host View functions normally; Audience Window shows a static message. |
| **Drop-On Intent** | A DnD drop where the cursor is within the center 50% of an item's height, indicating the user wants to nest the dragged item under the target. |
| **Drop-Between Intent** | A DnD drop where the cursor is in the top or bottom 25% of an item, indicating the user wants to reorder (insert between items). |
