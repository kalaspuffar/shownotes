# Requirements Document: Cozy News Corner — Live Recording & Production Features

**Version:** 1.2
**Date:** 2026-03-01
**Author:** Requirements Analyst (Claude Code)
**Status:** **Final** — All questions resolved (2026-03-01)
**Supersedes:** N/A (addendum to REQUIREMENTS.md v1.1)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Business Context](#2-business-context)
3. [Goals and Objectives](#3-goals-and-objectives)
4. [Scope](#4-scope)
5. [Stakeholders](#5-stakeholders)
6. [User Personas / Actors](#6-user-personas--actors)
7. [Functional Requirements](#7-functional-requirements)
8. [Non-Functional Requirements](#8-non-functional-requirements)
9. [Data Requirements](#9-data-requirements)
10. [Integration Requirements](#10-integration-requirements)
11. [Constraints](#11-constraints)
12. [Assumptions](#12-assumptions)
13. [Dependencies](#13-dependencies)
14. [Risks](#14-risks)
15. [Success Criteria](#15-success-criteria)
16. [Open Questions](#16-open-questions)
17. [Appendices](#17-appendices)

---

## 1. Executive Summary

### Project Overview
This document defines requirements for a second major feature set for **Cozy News Corner**, expanding it from a show-notes generator into a full end-to-end podcast production tool covering the entire weekly workflow: link gathering, fact prep (talking points), live recording support, and audience display.

### Business Problem Being Solved
The host currently uses multiple disconnected tools during the weekly production cycle — a browser for research, separate notes, and the existing app for show notes. During the live recording session, the host must manually manage article tabs for the audience to see, while simultaneously tracking their own talking points. This is cognitively demanding and error-prone under time pressure.

### High-Level Solution Approach
Three tightly integrated additions to the existing application:

1. **Story Groups with Talking Points** — News items in the prep view can be grouped under a single "story" container. The primary link in the group drives audience display; secondary links appear in show notes but not on screen. Talking points (private bullet notes) are attached to the primary link.

2. **Recording Mode (Host View)** — A dedicated full-screen view for the host during recording. Displays the current item's talking points and allows keyboard navigation through the run order. Read-only to prevent accidental edits while live.

3. **Audience Display Window (Audience View)** — A second browser window, opened on a second monitor, that embeds the current article via iframe and stays in sync with the host view via a lightweight PHP WebSocket server (`bin/ws-server.php`).

### Key Success Criteria
- Host can navigate through all items with a single key press, with the audience display updating within ~100 ms.
- Zero additional external dependencies introduced (pure PHP, vanilla JS).
- The show notes Markdown output is completely unaffected by the new grouping and talking-points data.
- Iframe fallback (tab opening) works reliably for sites that block embedding.

---

## 2. Business Context

### Background
Cozy News Corner is a weekly open-source news podcast with a rapid turnaround — episodes are gathered, recorded, and published every week. The existing app handles one step (show notes generation) efficiently. The remaining steps — reading up on topics, drafting talking points, and running the live recording session — still happen outside the app.

### Strategic Alignment
The vision is a single integrated tool for the entire weekly production lifecycle:
- **Prep phase**: gather links, add talking points, group related stories
- **Recording phase**: teleprompter-style host view + synced audience display
- **Post-production phase**: generate show notes (already implemented)

### Current State vs. Desired State

| Dimension | Current State | Desired State |
|-----------|--------------|---------------|
| Link prep | URLs added one at a time, flat list | URLs grouped into stories with primary/secondary designation |
| Talking points | Not supported | Per-item bullet notes on the primary link of each story |
| Recording session | Host manages separate notes + browser tabs manually | Integrated host view with keyboard nav + synced audience iframe |
| Audience display | Host switches browser tabs manually | Second browser window shows the current article automatically |
| Show notes | Generated from flat item list | Generated from same data (unaffected by grouping) |

---

## 3. Goals and Objectives

### Business Goals
- Reduce weekly production time by eliminating manual context switching during recording.
- Consolidate all production steps into one tool.

### User Goals
- **During prep**: Capture talking points while researching, organised per story, without leaving the app.
- **During recording**: See talking points clearly on one screen while the audience sees the article on another, navigating hands-free with keyboard.
- **During post-production**: Generate show notes as before, with no extra effort.

### Measurable Success Criteria
- Audience display updates within 100 ms of host advancing to the next item.
- Host can advance through a full episode (≈ 15–20 items) without touching the mouse during recording.
- No regressions in existing show notes Markdown output.
- Iframe fallback activates within 3 seconds of detecting a blocked embed.

---

## 4. Scope

### In Scope
- **Story Groups** (News section only): implicit group creation by drag-nesting; primary link designation; secondary links for show notes.
- **Talking Points** (News section, primary link only): per-item bullet list; prep-phase editing; read-only during recording; private (never in show notes).
- **Recording Mode — Host View**: full-screen mode entered from the main screen; keyboard navigation (arrow keys / spacebar); shows current item's talking points; opens the Audience Window.
- **Recording Mode — Audience View**: second browser window opened via `window.open()`; full-screen iframe of the current article; no app chrome; fallback to opening a new browser tab if iframe is blocked.
- **WebSocket Sync Server**: pure PHP CLI server at `bin/ws-server.php`; syncs navigation events between Host View and Audience View; started manually via terminal.
- **Vulnerability segment in recording**: same iframe-then-fallback approach as news; no talking points.

### Out of Scope
- Changes to the Markdown show notes format or generation logic.
- Any grouping or structural changes to the Vulnerability section.
- Automation of WebSocket server startup from the UI (manual CLI only).
- Recording/audio capture within the app.
- Video or screen capture integration.
- Multi-user or remote collaboration.
- Mobile or tablet form factor support.
- Any external service dependencies (APIs, CDNs, etc.).

### Future Considerations
- Automated WebSocket server launch from a "Start Recording" button using `exec()` (if server environment allows it).
- A run-sheet or episode outline view showing the full segment order.
- Import of talking points from external notes (Markdown, plain text).
- Timer / stopwatch overlay in the host view to track segment duration.

---

## 5. Stakeholders

| Stakeholder | Role | Concerns |
|-------------|------|----------|
| Podcast Host | Primary user, decision-maker | Ease of use during live recording; no accidental edits while live; reliable audience display |
| Audience | Passive viewers of Audience Window | Article displays correctly and without app chrome |

---

## 6. User Personas / Actors

### Persona: The Podcast Host (Solo Producer)
- **Technical proficiency**: High — comfortable with CLI (`php bin/ws-server.php`), browser developer tools, and server administration.
- **Device setup**: Desktop or laptop with **two monitors**; single machine; both windows running in the same browser.
- **Usage pattern**: Weekly production cycle. Prep phase (adding links, writing talking points) happens days before recording. Recording phase happens once per episode, lasting 30–60 minutes.
- **Key needs during prep**: Quick talking point entry without losing focus; easy reordering; clear distinction between primary and secondary links.
- **Key needs during recording**: Completely hands-free navigation; large, readable talking points; confidence that the audience is seeing the right article.
- **Tolerance for failure**: Low — a broken audience display or navigation failure during a live recording is a significant problem.

---

## 7. Functional Requirements

### 7.1 Story Groups (News Section — Prep UI)

#### FR-G01 — Implicit Group Creation by Drag-Nesting
**Priority:** Must-have

When the user drags a News item and drops it **on top of** (not between) an existing standalone News item, those two items form a Story Group. The item that was already in the list becomes the **primary link** (displayed to the audience). The dragged item becomes a **secondary link** (included in show notes, not displayed).

- The drop target visual indicator for nesting must be distinct from the existing between-item reorder indicator.
- A standalone item (not in any group) displays and behaves identically to how all items do today.
- Groups can hold any number of secondary links (no hard maximum).
- All items — primary and secondary alike — carry the full set of metadata fields: URL, title, author name, and author URL. Secondary links support URL scraping (Fetch Metadata) and inline editing of all fields, identical to how standalone items work today. This ensures all links in the show notes are fully attributed.

#### FR-G02 — Group Visual Representation
**Priority:** Must-have

Story Groups must be visually distinguishable from standalone items in the prep list:
- The group is rendered as a container.
- The primary link is displayed at the top, within the container, with a clear "primary" marker (e.g., a label or icon).
- Secondary links are displayed beneath the primary link, visually indented or nested, with a "secondary" label.
- The group container has a drag handle, allowing the entire group to be reordered within the News section.
- Individual secondary links have drag handles to allow reordering within the group or extraction from the group.

#### FR-G03 — Extracting a Link from a Group
**Priority:** Must-have

The user must be able to drag a secondary link **out** of a group, dropping it between items in the News list to make it a standalone item again.

If the last secondary link is removed from a group (leaving only the primary), the group container dissolves and the remaining item reverts to a standalone item.

#### FR-G04 — Primary Link Promotion
**Priority:** Should-have

If the primary link of a group is deleted, the next secondary link (first in order) is automatically promoted to primary, inheriting any talking points. If no secondary links remain, the group dissolves.

#### FR-G05 — Show Notes Unaffected
**Priority:** Must-have

Story Groups must be invisible to the Markdown generator. All links (primary and secondary) within a group must appear as flat, independent items in the generated show notes, in their current format, in the order they appear (primary first, then secondaries). No structural headers or nesting must be introduced into the Markdown output.

#### FR-G06 — Talking Points Transfer Warning on Primary Demotion
**Priority:** Must-have

If the user drags the **primary link** of a group and drops it so that it becomes a secondary link (beneath another item), and that primary link has non-empty talking points, the application must:

1. **Pause the operation** and display a confirmation dialog before completing the drag.
2. The dialog must clearly state that the item being demoted has talking points and that those notes will be **transferred to the new primary link** if the user proceeds.
3. Provide two options: **"Transfer & Continue"** (proceeds with the drag, moves talking points to the newly promoted primary) and **"Cancel"** (aborts the drag, leaving all items in their original positions).
4. If the user confirms: complete the reorder, promote the correct item to primary, and assign the demoted item's talking points to the new primary. Clear the talking points from the demoted item.
5. If the dragged primary has **no talking points** (empty or null), no warning is shown — the demotion proceeds silently.

This requirement also applies when a primary link is **deleted** and a secondary is auto-promoted (FR-G04): if the deleted primary had talking points, they are transferred to the promoted secondary without a dialog (since deletion is already a confirmed action).

---

### 7.2 Talking Points (News Section — Prep Phase)

#### FR-T01 — Talking Points Field on Primary Links
**Priority:** Must-have

Every News item (whether standalone or the primary link of a group) has a **talking points** field: an ordered, free-form bullet list. Each bullet is a single line of plain text.

Standalone items and primary links have this field. Secondary links within a group do **not** have talking points.

#### FR-T02 — Talking Points Entry UI
**Priority:** Must-have

In the prep view, the talking points field must be:
- Displayed below the item's other fields (title, URL, author) in a collapsible or always-visible panel.
- A multi-line text area or a dynamic bullet list where pressing Enter adds a new bullet and Backspace on an empty bullet removes it.
- Auto-saved with the same debounce pattern used for other fields (800 ms after last keystroke).
- Clearly labelled as private/recording-only (e.g., "Recording Notes — not included in show notes").

#### FR-T03 — Talking Points Excluded from Show Notes
**Priority:** Must-have

Talking points must never appear in the generated Markdown, regardless of their content. The Markdown generator must not read or process the talking points field.

#### FR-T04 — Talking Points Persistence
**Priority:** Must-have

Talking points are stored in the database alongside the item. They persist between sessions and survive page refreshes. They are cleared when the user resets the episode ("New Episode" action).

---

### 7.3 Recording Mode — Entry and Exit

#### FR-R01 — Start Recording Button
**Priority:** Must-have

A clearly labelled **"Start Recording"** button must appear on the main prep screen (in the action bar, alongside "Generate Show Notes"). The button must be **disabled** (visually greyed out, not clickable) when the episode has no items in either section. It becomes enabled as soon as at least one item exists.

When clicked (enabled state):

1. Checks if the WebSocket server is reachable (attempt connection to `ws://localhost:9001`).
2. If unreachable: displays a clear, non-blocking warning with the CLI command to start the server (`php bin/ws-server.php`) and an option to continue without sync (audience window will not update automatically).
3. Opens the **Audience Window** via `window.open()`, targeting `/audience.php` (a dedicated PHP file), sized to fill the second monitor if possible.
4. Transitions the current window into the **Host (Recording) View**.

#### FR-R02 — Exit Recording
**Priority:** Must-have

A clearly labelled **"Exit Recording"** button (or keyboard shortcut, e.g., Escape) in the Host View:
1. Closes the Audience Window (via `window.close()` on the reference obtained in FR-R01).
2. Disconnects from the WebSocket server.
3. Returns the host window to the normal prep/editing view, restoring full editing capability.

---

### 7.4 Recording Mode — Host View

#### FR-H01 — Host View Layout
**Priority:** Must-have

The Host View is a full-screen presentation optimised for readability at a glance:
- **Current item header**: Shows the section name ("Vulnerability" or "News"), the story/item title, and the item's URL.
- **Talking points panel**: Displays the current item's talking points as a clean, large-font bullet list. Empty if the item has no talking points (e.g., vulnerability items).
- **Navigation indicator**: Shows current position in the run order (e.g., "Item 3 of 17") and the section currently being presented.
- **Navigation controls**: Visible Prev / Next buttons as a fallback to keyboard nav.
- **"Exit Recording" button**: Always accessible.
- **WebSocket status indicator**: Small, unobtrusive indicator showing connected / disconnected state.

#### FR-H02 — Keyboard Navigation
**Priority:** Must-have

While the Host View is focused, the following keyboard shortcuts must be active:
- **Right Arrow** or **Spacebar**: Advance to the next item.
- **Left Arrow**: Go back to the previous item.
- **Home**: Jump to the first item in the run order.
- **End**: Jump to the last item in the run order.

Navigation wraps at the episode boundaries (no cycling — advancing past the last item does nothing).

The run order is: all Vulnerability items (in their current sort order), followed by all News items (stories in their sort order; within a story, only the primary link is a navigation stop — secondary links do not receive focus during recording).

#### FR-H03 — Read-Only During Recording
**Priority:** Must-have

All data fields (title, URL, author, talking points) must be **non-editable** while in the Host View. There must be no accidental edit capability — clicking on text must not open inline edit mode.

#### FR-H04 — Segment Break Marker
**Priority:** Must-have

When the host advances from the last Vulnerability item into the first News item (i.e., crosses the segment boundary), the Host View must display a full-screen interstitial **"NEWS"** segment break marker before showing the first News item's content.

- The marker is a momentary visual cue (not a timed auto-advance) — it persists until the host presses the next navigation key or clicks Next.
- The marker displays the word **"NEWS"** prominently, styled to match the Host View's typographic scale.
- No audience-facing change occurs at this moment (the Audience Window does not change until the host advances to the first News item).
- There is no equivalent marker at the start of the Vulnerability segment (recording always begins there).

---

### 7.5 Recording Mode — Audience View

#### FR-A01 — Audience View Layout
**Priority:** Must-have

The Audience View is a minimal, clean window intended to be shown to viewers:
- **No application chrome** — no header, no navigation, no branding, no visible UI controls.
- **Full-viewport iframe**: The current item's URL is loaded in an `<iframe>` that fills 100% of the window width and height.
- **Fallback state**: If the iframe fails to load (blocked by X-Frame-Options or CSP headers, detected within 3 seconds), the window:
  1. Removes the iframe.
  2. Attempts `window.open(url, '_blank')` to open the article in a new browser tab.
  3. Displays a minimal fallback message in the window (e.g., "Opening [title] in a new tab...") that disappears after 5 seconds.
- **Loading state**: Brief loading indicator while the iframe is loading (spinner or animated bar), hidden once the page loads.

#### FR-A02 — Passive Audience Window
**Priority:** Must-have

The Audience Window must have no interactive controls visible to the audience. It must not display navigation arrows, item lists, talking points, or any host-facing information.

#### FR-A03 — WebSocket Connection
**Priority:** Must-have

The Audience Window connects to the WebSocket server (`ws://localhost:9001`) on load. It listens for `navigate` events from the Host View and updates its iframe src accordingly. If the WebSocket connection is lost, the Audience Window:
- Displays a subtle disconnection indicator (e.g., a pulsing dot in a corner) that is visible to the host but unobtrusive.
- Does not show any error to the audience.
- Automatically attempts to reconnect every 3 seconds.

---

### 7.6 WebSocket Synchronisation

#### FR-W01 — PHP WebSocket Server (`bin/ws-server.php`)
**Priority:** Must-have

A standalone PHP CLI script at `bin/ws-server.php` that:
- Implements the WebSocket handshake (RFC 6455) using raw PHP socket functions (no external libraries).
- Listens on `localhost:9001` (port configurable in `etc/config.php`).
- Accepts connections from any local client (both windows connect to it).
- Maintains a list of connected clients.
- On receiving a `navigate` message from the Host Window (JSON: `{ "action": "navigate", "itemId": <id>, "url": "<url>", "title": "<title>" }`), broadcasts it to all other connected clients (i.e., the Audience Window).
- Logs connections, disconnections, and errors to stdout.
- Exits cleanly on SIGINT (Ctrl+C).

#### FR-W02 — Host Sends Navigation Events
**Priority:** Must-have

Each time the host advances/retreats to an item, the Host View JavaScript sends a `navigate` message over the WebSocket containing:
- `itemId`: the database ID of the current item.
- `url`: the URL to load in the audience iframe.
- `title`: the display title of the current item.
- `section`: `"vulnerability"` or `"news"`.

#### FR-W03 — Audience Receives and Acts on Navigation Events
**Priority:** Must-have

When the Audience View receives a `navigate` message:
- If the new URL is different from the current iframe src, update the iframe src.
- If the new URL is the same (e.g., navigating among secondary links of the same story — though secondaries are not navigation stops), do nothing.

#### FR-W04 — Graceful Degradation (No WebSocket)
**Priority:** Must-have

If the host proceeds to recording without a WebSocket connection (chose to continue despite the warning in FR-R01):
- The Host View operates normally (keyboard nav, talking points display).
- The Audience Window is still opened but shows a static message indicating it is not synced.
- No JavaScript errors must occur; all WebSocket code must be wrapped in try/catch.

---

### 7.7 Run Order and Segment Handling

#### FR-O01 — Recording Run Order
**Priority:** Must-have

The canonical run order during recording is:
1. All Vulnerability items, in their current `sort_order`, one at a time.
2. All News items (standalone items and story group primaries only, in their current `sort_order`).

Secondary links within a News story group are **not** navigation stops. They are included in show notes but skipped entirely in recording navigation.

#### FR-O02 — Vulnerability Segment in Recording
**Priority:** Must-have

Vulnerability items in the Host View show:
- The item title and URL.
- No talking points panel (or an empty, visually suppressed panel).
- The same navigation controls as News items.

Vulnerability items in the Audience View:
- Follow the same iframe-then-fallback approach as News items.
- If the iframe loads, the vulnerability article is shown full-screen.
- If blocked, the same fallback (open in new tab, show brief message) applies.

---

## 8. Non-Functional Requirements

### 8.1 Performance

| Requirement | Target |
|-------------|--------|
| WebSocket navigation event round-trip (host sends → audience receives → iframe begins loading) | ≤ 100 ms on localhost |
| Host View initial load (recording mode entered) | ≤ 1 second |
| Talking points auto-save (debounced API call completes) | ≤ 500 ms after debounce fires |
| Iframe load detection for fallback trigger | ≤ 3 seconds |

### 8.2 Security

- The WebSocket server binds to **localhost only** (`127.0.0.1:9001`) — not accessible from outside the machine.
- No authentication is required between the two windows (same machine, single-user assumption).
- Navigation URLs passed through the WebSocket must be validated on the Audience Window side before loading in the iframe (same SSRF checks as the existing Scraper: no private IP ranges, http/https only).
- Talking points are plain text only — no HTML rendering (use `textContent`, not `innerHTML`).
- No new SQL injection vectors introduced; all new DB operations must use prepared statements.

### 8.3 Availability & Reliability

- If the WebSocket server crashes during recording, the Audience Window must attempt to reconnect automatically (every 3 seconds) and display a reconnection indicator.
- The Host View must remain fully functional (navigation, talking points display) even without a WebSocket connection.
- No data loss: any talking points typed during prep must be saved before recording starts.

### 8.4 Usability

- Keyboard shortcuts in the Host View must work regardless of which DOM element has focus (global key listener, not dependent on a specific focused element).
- Font size in the Host View should be substantially larger than the prep UI (talking points at ≥ 20px, titles at ≥ 24px) to be readable at a distance.
- The "Start Recording" button must be visually distinct from "Generate Show Notes" (different color/style).
- The transition from prep view to Host View must be instantaneous (no page load).

### 8.5 Maintainability

- The WebSocket server must be a single, self-contained PHP file with no includes beyond `etc/config.php`.
- All new JavaScript for recording mode must follow the existing vanilla JS patterns (no frameworks, no build tools).
- New database columns must be added via a `ALTER TABLE` migration that runs automatically on first use (same pattern as existing schema initialization).

### 8.6 Browser Compatibility

- Both Host View and Audience View must work in the latest stable versions of Chrome and Firefox.
- `window.open()` may be blocked by popup blockers — the UI must detect this and guide the host to allow popups for `localhost`.

---

## 9. Data Requirements

### 9.1 New / Modified Data Entities

#### Modified: `items` Table
The existing `items` table requires the following additions:

| New Column | Type | Description |
|------------|------|-------------|
| `talking_points` | TEXT | Plain-text bullet notes for the primary link. NULL or empty string for items with no talking points (vulnerability items, secondary links). |
| `parent_id` | INTEGER (nullable FK → `items.id`) | If non-null, this item is a secondary link nested under the primary item with `id = parent_id`. NULL means the item is standalone or a primary link. |

Invariants enforced by the application (not necessarily by DB constraints):
- A secondary item (`parent_id IS NOT NULL`) must always belong to the `news` section.
- A secondary item must never have `talking_points` set.
- `parent_id` must never reference another secondary item (no multi-level nesting).

### 9.2 Data Volume Impact
Talking points are small (< 1 KB per item, typically a few hundred bytes). No significant storage impact.

### 9.3 Data Retention
Talking points and grouping relationships are cleared when the user performs the "New Episode" action (consistent with existing behavior for all item data).

### 9.4 Backward Compatibility
Existing `items` rows have `talking_points = NULL` and `parent_id = NULL`, which correctly represents standalone items with no talking points. No data migration needed.

---

## 10. Integration Requirements

### 10.1 WebSocket Server (Internal)
- **Protocol**: RFC 6455 WebSocket, plain (non-TLS) on `ws://localhost:9001`.
- **Message format**: JSON objects.
- **Messages (Host → Audience)**:
  ```json
  { "action": "navigate", "itemId": 42, "url": "https://example.com/article", "title": "Article Title", "section": "news" }
  ```
- **Messages (Audience → Host)**: None required in initial implementation.
- **Port**: Default `9001`; configurable via `etc/config.php`.

### 10.2 Browser Window Communication
- Host View opens Audience View with `window.open(audienceUrl, 'cncAudienceWindow', 'width=...,height=...')` and holds a reference for `window.close()` on exit.
- Popup blocker detection: if `window.open()` returns `null`, display a clear instruction to allow popups for `localhost`.
- Both windows independently connect to the WebSocket server (not via `postMessage` between windows, to avoid same-origin assumptions about window reference persistence).

---

## 11. Constraints

### 11.1 Technical Constraints
- **No external PHP dependencies** (no Composer packages). The WebSocket server must use only PHP built-in functions (`socket_*` or `stream_*` API).
- **No npm or build tools**. All JavaScript is vanilla, loaded directly as `<script>` tags.
- **SQLite database** — no schema migrations tool; migrations are run inline in `Database.php` on connection.
- **PHP 8.4** — modern syntax permitted.
- **Apache web server** — the audience URL must be routable via `audience.php` (a new dedicated file in `www/`).

### 11.2 Business Constraints
- The change must not break or alter any existing functionality (show notes generation, URL scraping, author suggestions, drag-and-drop reordering, dark mode, etc.).
- The weekly production timeline does not change — the tool must be ready for use, not a prototype.

### 11.3 Physical Constraints
- Two monitors on one machine. Both windows are in the same browser on the same OS session. The host controls both windows from the primary monitor.

---

## 12. Assumptions

1. **Same-origin**: Both the host window and audience window are served from the same `localhost` origin. No CORS issues.
2. **PHP CLI available**: The host can run `php bin/ws-server.php` from a terminal on the same machine. PHP's socket functions (`socket_create`, `socket_bind`, etc.) are enabled in the PHP CLI SAPI.
3. **Port 9001 is available**: Nothing else on the machine uses port 9001 (or the host knows how to change the configured port).
4. **Popup blocker allows localhost**: The browser allows `window.open()` calls from a user gesture on localhost, or the host has configured it to do so.
5. **One episode at a time**: The WebSocket server handles one recording session at a time. No scenario where multiple episodes are being recorded simultaneously.
6. **Stable story order before recording**: Story groups and item order are finalized before entering recording mode. Reordering mid-recording is not supported.
7. **X-Frame-Options detection**: Iframe blocking detection is done via a `load`/`error` event or a timeout heuristic. True detection of `X-Frame-Options` without a server-side proxy is not reliable — the timeout fallback is the primary mechanism.

---

## 13. Dependencies

| Dependency | Type | Notes |
|------------|------|-------|
| PHP `socket_*` functions | Runtime | Must be enabled in PHP CLI SAPI. Enabled by default in most PHP installations. |
| PHP 8.4 CLI | Runtime | Same PHP version as the web server (assumed already installed). |
| Apache (web server) | Runtime | Required to serve `audience.php` or the audience view URL. Already in use. |
| Existing `items` schema | Data | New columns added to existing table via `ALTER TABLE`. |
| `etc/config.php` | Config | WebSocket port and host configurable here. |

---

## 14. Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Most article sites block iframe embedding (X-Frame-Options) | High | Medium | Fallback to opening a new tab is defined and required (FR-A01). Host is warned during prep if a URL is known to block embedding. |
| `window.open()` blocked by popup blocker | Medium | High | Detect null return, show clear instruction to allow popups for localhost. |
| PHP socket functions disabled in CLI | Low | High | Check during setup / document as a prerequisite. Fail clearly with a message if sockets are unavailable. |
| WebSocket server crashes mid-recording | Low | High | Auto-reconnect logic in both windows. Host View remains functional without WS (FR-W04). |
| Keyboard shortcuts intercepted by browser/OS | Low | Medium | Test with common browser shortcuts; document any conflicts; provide visible Prev/Next buttons as a backup. |
| Drag-nesting UX is confusing (drop-on vs drop-between) | Medium | Medium | Distinct visual indicator for nesting target vs. reorder target. User testing before ship. |
| Talking points accidentally included in show notes | Low | Low | Generator explicitly ignores the field; covered by automated tests. |

---

## 15. Success Criteria

| Criterion | How Verified |
|-----------|-------------|
| Story group created by drag-nesting | Manual test: drag item B onto item A; verify group container appears with A as primary, B as secondary. |
| Extraction from group | Manual test: drag secondary back to list; verify group dissolves if only primary remains. |
| Talking points saved and persisted | Manual test: enter bullets, refresh page; verify bullets reappear. |
| Talking points absent from show notes | Automated + manual test: add talking points to an item; generate show notes; verify they are not in the output. |
| Recording mode entered cleanly | Manual test: click "Start Recording"; verify Host View appears and Audience Window opens. |
| Keyboard navigation advances both windows | Manual test: press Right Arrow; verify host shows next item's talking points; verify audience iframe changes URL. |
| Audience window is clean (no chrome) | Visual inspection: audience window shows only the iframe, no app UI. |
| Iframe fallback opens new tab | Manual test: use a known iframe-blocking URL; verify new tab opens within 3 seconds. |
| WebSocket reconnect after server restart | Manual test: stop and restart `ws-server.php` mid-session; verify audience reconnects and navigation resumes. |
| No regressions in existing features | Full regression test of: URL scraping, author suggestions, DnD reordering, show notes generation, dark mode, "New Episode". |

---

## 16. Open Questions

### Resolved

| # | Question | Resolution |
|---|----------|------------|
| OQ-1 | Audience Window URL: separate file or query parameter? | **Resolved:** `audience.php` — a dedicated PHP file. See FR-R01, Appendix B. |
| OQ-3 | Visual treatment for the Vulnerability → News segment transition in the Host View? | **Resolved:** Full-screen **"NEWS"** segment break marker, displayed until the host advances. No audience-facing change at that moment. See FR-H04. |
| OQ-4 | Should secondary links carry full metadata (author, URL scraping) in the prep view? | **Resolved:** Yes — all links (primary and secondary) have the full set of metadata fields and support Fetch Metadata, identical to standalone items. See FR-G01. |
| OQ-5 | If a primary link with talking points is dragged to become a secondary, what happens to the talking points? | **Resolved:** The user is warned with a confirmation dialog; if they accept, talking points transfer to the newly promoted primary. If they cancel, the drag is aborted. See FR-G06. |
| OQ-7 | Is 3 seconds the right iframe timeout before triggering the fallback? | **Resolved:** Yes — 3 seconds confirmed. See FR-A01 and §8.1 Performance. |

| OQ-2 | WebSocket port: config-file only or also a UI setting? | **Resolved:** Config-file only (`etc/config.php`). No in-app UI for port configuration. |
| OQ-6 | Should "Start Recording" be disabled when no items exist? | **Resolved:** Yes — button is disabled until at least one item exists in the episode. See FR-R01. |

### Still Open

*None — all questions resolved. Document is final.*

---

## 17. Appendices

### Appendix A — Glossary

| Term | Definition |
|------|-----------|
| **Story Group** | A container holding two or more News items that cover the same news story. One item is the primary link; others are secondary. |
| **Primary Link** | The first (top) item in a Story Group. Its URL is shown to the audience during recording. Its talking points are used by the host. |
| **Secondary Link** | Any non-primary item in a Story Group. Included in show notes but not displayed to the audience during recording and not shown as a navigation stop. |
| **Talking Points** | A private, per-item ordered bullet list attached to the primary link of a News item. Displayed to the host during recording. Never included in show notes. |
| **Host View** | The full-screen recording mode shown on the host's monitor. Displays talking points and enables keyboard navigation. |
| **Audience View** | The second browser window shown on the second monitor (visible to the recording's audience). Displays the current article in an iframe. |
| **WebSocket Sync Server** | The PHP CLI process (`bin/ws-server.php`) that relays navigation events from the Host View to the Audience View. |
| **Run Order** | The ordered sequence of navigation stops during recording: all Vulnerability items, then all News primary links (standalone items and group primaries), in their prep sort order. |
| **Prep Phase** | The period before recording when the host adds links, organises story groups, and writes talking points. |
| **Recording Phase** | The live session when the host records the podcast. Host View and Audience View are active. Data is read-only. |

### Appendix B — Existing Architecture Summary

For context, the existing system consists of:
- `www/index.php` — server-rendered HTML, injects `INITIAL_STATE` as JSON
- `www/api.php` — JSON API endpoint, single-action dispatch
- `www/js/app.js` — 971-line vanilla JS module (all UI logic)
- `www/css/style.css` — 668-line CSS with custom properties + dark mode
- `include/Database.php` — PDO SQLite wrapper, all SQL
- `include/Scraper.php` — URL validation, fetch, metadata extraction
- `include/Generator.php` — Markdown generation (pure function)
- `etc/config.php` — static configuration
- `var/shownotes.sqlite` — SQLite database

New files to be created:
- `bin/ws-server.php` — PHP WebSocket server (CLI)
- `www/audience.php` — Audience View HTML page (dedicated file)

Modified files:
- `include/Database.php` — new columns, new queries
- `www/api.php` — new actions for talking points, story groups
- `www/js/app.js` — new modules: story groups, talking points UI, recording mode, WebSocket client
- `www/css/style.css` — new styles: group containers, host view, audience view
- `etc/config.php` — WebSocket port constant

### Appendix C — WebSocket Message Protocol

```
Direction: Host → Server → Audience

Message: navigate
{
  "action": "navigate",
  "itemId": <integer>,        // DB id of the current item
  "url": "<string>",          // URL to load in audience iframe
  "title": "<string>",        // Display title of the current item
  "section": "vulnerability" | "news"
}

Direction: Client → Server (on connection)
Message: hello
{
  "action": "hello",
  "role": "host" | "audience"
}

Direction: Server → Client (optional acknowledgement)
Message: ack
{
  "action": "ack",
  "clients": <integer>        // Number of currently connected clients
}
```

### Appendix D — Run Order Diagram

```
Episode Recording Run Order
────────────────────────────────────────────
[SEGMENT: Vulnerability]
  Stop 1: Vuln Item A   (iframe or fallback, no talking points)
  Stop 2: Vuln Item B   (iframe or fallback, no talking points)
  Stop 3: Vuln Item C   (iframe or fallback, no talking points)
  ...

  ┌─────────────────────────────────────┐
  │            N E W S                  │  ← Segment break marker (FR-H04)
  │   (host presses Next to continue)   │  ← Audience window unchanged
  └─────────────────────────────────────┘

[SEGMENT: News]
  Stop N:   News Story Group 1
              └─ Primary Link ←── iframe shown, talking points shown to host
              └─ Secondary Link B  (show notes only, skipped in nav)
              └─ Secondary Link C  (show notes only, skipped in nav)
  Stop N+1: News Standalone Item
              └─ (acts as its own primary; iframe shown, talking points shown)
  Stop N+2: News Story Group 2
              └─ Primary Link ←── iframe shown, talking points shown to host
              └─ Secondary Link D  (show notes only, skipped in nav)
  ...
────────────────────────────────────────────
```
