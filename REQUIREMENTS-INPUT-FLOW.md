# Requirements Document: Input Flow Improvements

**Version:** 3.0
**Date:** 2026-03-12
**Author:** Requirements Analyst
**Status:** Draft

---

## 1. Executive Summary

The current input workflow for adding articles to Cozy News Corner is fragmented: the user pastes a URL in the sidebar, fetches metadata, clicks "Add Item," then must scroll through a growing list to find the new entry, visually distinguish it among similar-looking rows, and finally add recording notes one bullet at a time. Each step involves a context switch.

This document specifies a redesigned input flow centered on three changes:

1. **Article Input Modal** — a focused overlay where the entire add-and-annotate cycle (paste URL → auto-fetch metadata → review fields → write notes → done) happens in one place. The item only joins the main list when the user is finished with it.
2. **Layout overhaul** — the sidebar is removed entirely; the "Add Article" button moves to a fixed header; the action bar becomes a fixed footer. The content area scrolls independently between the two fixed bars, minimizing unnecessary scrolling.
3. **Better list readability** — card-based article separation and a textarea-based notes editor replace the current thin borders and contenteditable bullet list.

---

## 2. Problem Statement

### Current Pain Points

| # | Pain Point | Impact |
|---|-----------|--------|
| P1 | After adding a link via the sidebar, the item appears at the bottom of the list — user must scroll down to find it | Breaks flow; forces manual hunting |
| P2 | Article boundaries are hard to distinguish — thin `1px solid` bottom borders between `.item-row` elements blend together, especially in long lists | Cognitive load; user can't quickly locate items |
| P3 | Adding notes requires first locating the item (see P1), then typing bullets one by one in a contenteditable `<li>` | Slow, disjointed workflow |
| P4 | Arrow keys do not move the cursor between talking-point bullets — only Enter (new bullet) and Backspace (delete empty) are handled | Unnatural editing; user must click to reposition |
| P5 | The `<ul><li contenteditable>` bullet model makes pasting multi-line text or typing freely awkward | Friction when jotting notes quickly |
| P6 | The header, episode meta, sidebar, and action bar all scroll with the content — key controls disappear off-screen as the list grows | Loss of context; user must scroll back up to reach controls |

### Root Cause

The fundamental problem is that **input and list management are interleaved**. The user adds an item to the list, then has to navigate the list to finish working on that item. A secondary problem is that **the page layout scrolls as a whole**, burying fixed controls (header, action bar) as the list grows.

### Desired Outcome

The user never scrolls during the input workflow. They paste a URL, see everything about that article in one focused view, write their notes, and dismiss. The article appears in the list fully formed. The header and footer are always visible. The list itself has clear visual boundaries between articles.

---

## 3. Goals and Objectives

| ID | Goal | Measurable Criteria |
|----|------|-------------------|
| G1 | Zero-scroll input workflow | User completes the full add-and-annotate cycle without scrolling the main list |
| G2 | Single-context input | URL entry, metadata review, field editing, and note-taking all happen in one UI surface |
| G3 | Persistent controls | Header ("Add Article", episode meta) and footer (Generate, New Episode, Start Recording) are always visible regardless of scroll position |
| G4 | Improve article visual separation | A user can instantly distinguish where one article ends and the next begins in the list |
| G5 | Natural note-taking | Arrow keys navigate between lines; multi-line paste works; typing feels like a text editor |
| G6 | No speed regression | Rapid-fire URL adding (paste, add, paste, add) is at least as fast as the current sidebar flow |

---

## 4. Scope

### In Scope

- New Article Input Modal for the add-and-annotate workflow
- Removal of the sidebar; "Add Article" button moved to the fixed header
- Fixed header and fixed footer layout
- Visual redesign of article row separation in the main list (cards)
- Revised talking-points editor (textarea-based, natural keyboard navigation)
- Extend `add_item` API to accept `talking_points` in the same request
- Changes to `app.js`, `style.css`, `index.php`, and `api.php`

### Out of Scope

- Recording mode UI changes
- Audience view changes
- Drag-and-drop reordering behavior
- Markdown generation format changes
- Database schema changes
- Story grouping workflow (nesting/extracting items)
- Re-opening the modal to edit existing items (future enhancement)

### Future Considerations

- Edit-via-modal: re-open the modal pre-populated with an existing item's data for editing
- Keyboard shortcut to open the modal with clipboard URL pre-filled (paste-and-go)
- Batch import mode (paste multiple URLs, process sequentially)

---

## 5. Functional Requirements

### FR-1: Article Input Modal

**Priority:** Must-have

A modal dialog SHALL be the primary input surface for adding new articles, replacing the current sidebar form.

#### 5.1.1 Opening the Modal

1. An "Add Article" button in the fixed header (see FR-4) SHALL open the modal.
2. A **keyboard shortcut** SHALL also open the modal. The shortcut MUST NOT conflict with standard browser shortcuts (e.g., avoid Ctrl+N, Ctrl+T, Ctrl+W). Suggested candidates: `Ctrl+Shift+A` or `Alt+A` — developer to verify no browser conflicts.
3. Clicking the button or pressing the shortcut opens a centered modal overlay with a semi-transparent backdrop.
4. The modal SHALL trap focus (standard modal accessibility pattern).

#### 5.1.2 Dismissing the Modal

1. The modal SHALL be dismissible via:
   - **Escape key**
   - **Close button (×)** in the modal header
   - **Clicking the backdrop**
2. If any field in the modal contains user-entered content (URL, title, author, or notes), dismissing SHALL show a **"Discard unsaved changes?"** confirmation dialog before closing. If all fields are empty, the modal closes immediately without confirmation.

#### 5.1.3 Modal Content — Top Section: URL & Metadata

1. The modal SHALL contain a URL input field, **auto-focused** on open.
2. A section selector (Vulnerability / News) SHALL be present, defaulting to News.
3. **Auto-fetch on paste:** When a valid URL (`http://` or `https://` prefix) is pasted into the URL field, metadata fetch SHALL trigger automatically. A loading indicator (spinner or field skeletons) SHALL appear during the fetch.
4. A "Fetch Metadata" button SHALL remain available for **manual re-fetch** (e.g., if the user edits the URL after the initial paste, or if the first fetch failed).
5. After fetching, the modal SHALL display editable fields:
   - **Title** (pre-filled from scrape)
   - **Author Name** (pre-filled from scrape, with author suggestion dropdown — same domain-aware behavior as current)
   - **Author URL** (pre-filled from scrape)
6. If metadata cannot be scraped, a warning SHALL appear inline (not a blocking error) and the user can fill fields manually.

#### 5.1.4 Modal Content — Bottom Section: Recording Notes (News only)

1. When section is "News," a recording notes area SHALL appear below the metadata fields.
2. The notes area SHALL use the improved textarea editor defined in FR-3.
3. A label SHALL indicate: "Recording Notes — not included in show notes" (matching current convention).
4. When section is "Vulnerability," the notes area SHALL be hidden (vulnerabilities have no talking points per current spec).

#### 5.1.5 Modal Actions

1. **"Add Article" button** — saves the item (including talking points) via a single `add_item` API call (see FR-6). Closes the modal. The item appears in the appropriate section list.
2. **"Add & Next" button** — saves the item, clears all modal fields, and keeps the modal open with the URL field focused. This enables rapid-fire input without closing/reopening the modal.
3. Both buttons SHALL be disabled until at least a URL is provided.
4. On successful add, a brief toast confirmation SHALL appear (e.g., "Article added to News").

#### 5.1.6 Modal Sizing and Layout

1. The modal SHOULD be wide enough to comfortably display all fields without horizontal scrolling (~600px or 50% viewport width, whichever is larger, capped at a reasonable maximum).
2. The notes textarea SHOULD show ~5–8 lines of visible text without internal scrolling.
3. The modal SHALL be vertically centered, scrollable internally if content exceeds viewport height.

**Acceptance Criteria:**
- User can open the modal, paste a URL (auto-fetches metadata), write 3 lines of notes, and click "Add Article" — all without the main list scrolling or the user leaving the modal.
- "Add & Next" allows adding 5 articles in sequence without closing and reopening the modal.
- Pressing Escape with content in the fields shows a discard confirmation; pressing Escape with empty fields closes immediately.
- Keyboard shortcut opens the modal from anywhere in the app.

---

### FR-2: Improved Article Visual Separation

**Priority:** Must-have

Article rows in the main list SHALL have stronger visual boundaries to make each item immediately distinguishable:

1. Replace the current thin `1px solid border-bottom` between `.item-row` elements with a **card-based layout**. Each item SHALL be rendered as a visually distinct card with:
   - Its own border (all four sides)
   - Subtle background differentiation or shadow
   - Margin/gap between cards (not just internal padding)

2. Requirements for the card styling:
   - Each article MUST be visually self-contained — the user should never question where one article ends and the next begins.
   - The separation MUST work in both light mode and dark mode with WCAG AA contrast.
   - Story groups (`.story-group`) already have distinct styling — this requirement targets **standalone news items** and **vulnerability items**.
   - Cards SHOULD feel consistent with the existing earthy/warm design language (sage greens, soft beiges).

3. The drag handle (⠿) SHALL remain functional and visually associated with each card.

**Acceptance Criteria:**
- A user viewing a list of 10+ items can instantly point to the boundaries of any single article.
- Visual separation is clear without being heavy-handed.
- Dark mode styling is equally clear.

---

### FR-3: Improved Talking Points Editor

**Priority:** Must-have

The talking-points input SHALL be redesigned for natural text editing, used in both the Article Input Modal (FR-1) and inline in the list.

#### 5.3.1 Editor Model

1. The editor SHALL use a **`<textarea>`** instead of the current `<ul><li contenteditable>` structure.
2. Each line of text (separated by newline) represents one talking point.
3. The editor SHALL behave like a standard text editor:
   - Arrow keys (up/down/left/right) navigate naturally through the text.
   - Enter creates a new line.
   - Pasting multi-line text inserts all lines.
   - Standard text selection (Shift+Arrow, Ctrl+A, etc.) works as expected.
4. No custom `keydown` handlers SHALL be needed for basic navigation — the `<textarea>` provides this natively.

#### 5.3.2 Display Rendering

1. When displaying talking points in the **list** (non-editing context), each line SHALL render as a visually distinct bullet (disc marker or equivalent, one per line) for readability.
2. Recording mode and audience view SHALL continue to display talking points as bullet lists (reading from `item.talking_points` data, not DOM).

#### 5.3.3 Data Format

1. The data format persisted to the database (`talking_points` column, newline-separated string) SHALL remain unchanged.
2. Empty lines SHALL be stripped on save (current behavior preserved).
3. Auto-save behavior for inline editors SHALL be preserved: save on blur or after a debounce period.

**Acceptance Criteria:**
- User can paste a 5-line block of text and see 5 lines in the editor.
- Arrow keys move through lines naturally with zero custom keydown handling.
- Saved data format is identical to current (newline-separated string, empty lines stripped).
- Recording mode displays talking points correctly from the saved data.

---

### FR-4: Fixed Header and Footer Layout

**Priority:** Must-have

The page layout SHALL be restructured so the header and footer are fixed (sticky) and the content area scrolls independently between them.

#### 5.4.1 Fixed Header

1. The header bar SHALL be **fixed to the top** of the viewport (`position: sticky` or `position: fixed`).
2. The header SHALL contain:
   - Show title (left side, as currently)
   - **"Add Article" button** (prominent, positioned for easy access — e.g., right side or center)
   - Status indicator (right side, as currently)
3. Episode metadata fields (week number, year, YouTube URL) SHALL remain accessible. They MAY stay in the header if space permits, or move to a collapsible section immediately below the header. They should not consume excessive header height.

#### 5.4.2 Fixed Footer (Action Bar)

1. The action bar SHALL be **fixed to the bottom** of the viewport.
2. The footer SHALL contain the same actions as the current action bar:
   - "Generate Show Notes" button (left)
   - "New Episode" button (right)
   - "Start Recording" button (right, visible when items exist)

#### 5.4.3 Content Area

1. The content area (Vulnerability list + News list) SHALL occupy the space between the fixed header and fixed footer.
2. The content area SHALL scroll independently (`overflow-y: auto`) — the header and footer remain visible at all times.
3. The current sidebar layout SHALL be **removed entirely**. The content area SHALL use the full page width (single-column layout).

**Acceptance Criteria:**
- With 20+ items in the list, the user can scroll through articles while the "Add Article" button (header) and "Generate Show Notes" button (footer) remain visible at all times.
- No content is hidden behind the fixed header or footer (appropriate padding/offset applied).
- The layout does not break at reasonable desktop viewport sizes (1024px+ width).

---

### FR-5: Sidebar Removal

**Priority:** Must-have

The sidebar ("Add Item" panel) SHALL be removed from the layout entirely:

1. The two-column layout (300px sidebar + flex-grow content) SHALL be replaced with a **single-column, full-width layout**.
2. All sidebar form fields (URL, section, title, author name, author URL, "Fetch Metadata" button, "Add Item" button) SHALL be removed from the page — they now live exclusively in the Article Input Modal (FR-1).
3. The "Add Article" button that opens the modal SHALL be placed in the fixed header (FR-4).
4. The Vulnerability and News lists SHALL expand to use the full available width.

**Acceptance Criteria:**
- No sidebar is visible on the page.
- The article lists use the full content width.
- The only way to add a new article is via the modal (opened from the header button or keyboard shortcut).

---

### FR-6: Extend `add_item` API to Accept Talking Points

**Priority:** Must-have

The `add_item` API action SHALL be extended to optionally accept `talking_points` in the request payload:

1. The `add_item` action in `api.php` SHALL accept an optional `talking_points` field (string, newline-separated).
2. If provided and non-empty, the talking points SHALL be saved to the item in the same database transaction as the item creation.
3. If not provided or empty, behavior SHALL be identical to current (no talking points saved).
4. This eliminates the need for a separate `update_talking_points` call after creating an item from the modal.
5. The existing `update_talking_points` endpoint SHALL remain unchanged (used for inline editing in the list).

**Acceptance Criteria:**
- A single API call from the modal creates an item with talking points populated.
- Existing callers that don't send `talking_points` continue to work unchanged.

---

## 6. Non-Functional Requirements

### NFR-1: Performance

- Modal open/close SHALL feel instant (< 50ms to render).
- Auto-fetch on paste SHALL show a loading indicator during the API call. The modal SHALL remain interactive (user can edit the section selector or start typing notes while metadata loads).
- List re-render after adding an item from the modal SHALL complete in < 100ms for up to 100 items.
- Fixed header/footer SHALL not cause scroll jank or repaint issues in the content area.

### NFR-2: Accessibility

- Modal SHALL implement proper ARIA: `role="dialog"`, `aria-modal="true"`, `aria-labelledby` pointing to the modal title.
- Focus SHALL be trapped within the modal while open and restored to the trigger button on close.
- All modal actions SHALL be keyboard-accessible (Tab between fields, Enter to submit, Escape to close).
- The keyboard shortcut to open the modal SHALL be discoverable (e.g., tooltip on the "Add Article" button showing the shortcut).
- Card/separation styling SHALL maintain WCAG AA contrast ratios in both light and dark modes.
- Animations SHALL respect `prefers-reduced-motion`.
- Discard confirmation dialog SHALL be keyboard-accessible.

### NFR-3: Compatibility

- All changes SHALL work in modern evergreen browsers (Chrome, Firefox, Edge — latest two versions).
- No new JavaScript dependencies SHALL be introduced (vanilla JS only).
- Fixed header/footer SHALL work correctly with `position: sticky` or `position: fixed`, accounting for browser differences.

### NFR-4: Consistency

- The Article Input Modal SHALL reuse the existing confirm-dialog visual language (`.modal-backdrop`, `.modal-dialog` classes) but extend it for the larger input form.
- The discard confirmation SHALL use the existing `showConfirmDialog()` infrastructure.
- Button styles, colors, and typography SHALL match the existing design system.

---

## 7. User Workflow — Before and After

### Before (Current)

```
1. Paste URL in sidebar         ← sidebar (may need to scroll up to reach it)
2. Click "Fetch Metadata"       ← sidebar
3. Review/edit fields           ← sidebar
4. Click "Add Item"             ← sidebar
5. Scroll down the list         ← main area (PAIN POINT)
6. Find the new item            ← main area (PAIN POINT)
7. Click into talking points    ← main area
8. Type bullet, Enter           ← main area
9. Type bullet, Enter           ← main area (PAIN POINT: no arrow keys)
10. Scroll up to sidebar        ← (PAIN POINT)
11. Repeat for next article     ← start over from step 1
```

### After (Proposed)

```
1. Click "Add Article" (header) ← always visible, or press keyboard shortcut
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

---

## 8. Decisions Log

Resolved during requirements gathering:

| # | Decision | Rationale |
|---|---------|-----------|
| D1 | Discard confirmation when closing modal with unsaved content | Safety — prevents accidental loss of typed notes |
| D2 | Auto-fetch metadata on URL paste, keep manual "Fetch Metadata" button | Speed — saves one click per article; manual button covers re-fetch and edge cases |
| D3 | Keyboard shortcut to open modal (non-conflicting with browser shortcuts) | Speed — power-user convenience without breaking browser behavior |
| D4 | Extend `add_item` API to accept `talking_points` | API should serve the application's needs — one round-trip instead of two |
| D5 | Remove sidebar entirely; "Add Article" button in fixed header | Cleaner layout; sidebar form fields are redundant once the modal exists; fixed header keeps the button always accessible |
| D6 | Fixed header + fixed footer layout | Eliminates scrolling to reach controls; content scrolls independently |
| D7 | FR-5 (edit via modal) deferred to future | Inline editing in the list is sufficient for now; modal edit can be added later |

---

## 9. Assumptions

| # | Assumption |
|---|-----------|
| A1 | The database schema (`talking_points` as newline-separated text) will not change |
| A2 | Recording mode and audience view read talking points from `item.talking_points` data, not from the DOM |
| A3 | Story group visual styling (`.story-group`) is already sufficiently distinct and not part of this improvement |
| A4 | The existing modal infrastructure (`.modal-backdrop`, `.modal-dialog`, `showConfirmDialog()`) can be extended for the input modal and discard confirmation |
| A5 | Inline editing of items in the list (click field to edit) will remain as the way to edit existing items |
| A6 | Episode metadata (week, year, YouTube URL) will remain in or near the header area — exact placement is a design decision |

---

## 10. Risks

| # | Risk | Likelihood | Impact | Mitigation |
|---|------|-----------|--------|-----------|
| R1 | Modal may feel heavy for users who just want to quickly add a URL without notes | Medium | Medium | "Add & Next" provides a fast path; notes are optional — user can skip straight to "Add & Next" |
| R2 | Removing the sidebar changes the page layout significantly — may affect other UI elements (episode meta, drag-and-drop, story groups) | Medium | Medium | Thorough testing of all existing features in the new single-column layout |
| R3 | Auto-fetch on paste may trigger unwanted scrapes (e.g., pasting partial text) | Medium | Low | Only auto-fetch if pasted text matches `https?://` URL pattern; manual "Fetch" button as fallback |
| R4 | Fixed header/footer may interact poorly with the existing confirm dialog modal or recording mode overlay | Low | Medium | Test modal stacking (z-index) and ensure recording mode is unaffected |
| R5 | Keyboard shortcut may conflict with browser extensions or OS-level shortcuts on some systems | Low | Low | Choose a combination unlikely to conflict (`Ctrl+Shift+A`); document the shortcut; make it discoverable via tooltip |

---

## 11. Open Questions

All original open questions have been resolved (see Decisions Log, §8). No outstanding questions remain.

---

## 12. Summary of Changes by File

| File | Expected Changes |
|------|-----------------|
| `www/index.php` | Remove sidebar HTML; restructure page layout to single-column with fixed header/footer; add "Add Article" button to header; add modal HTML skeleton (or generate in JS) |
| `www/js/app.js` | New modal module (open/close/render/submit/discard-confirm); auto-fetch on paste; "Add & Next" flow; textarea-based notes editor replacing `talkingPointsModule`; keyboard shortcut listener; remove sidebar-related code |
| `www/css/style.css` | Remove sidebar styles; fixed header/footer positioning; scrollable content area; modal layout styles (larger than confirm dialog); card-based `.item-row` separation; textarea notes styling; dark mode updates for all new elements |
| `www/api.php` | Extend `add_item` action to accept optional `talking_points` parameter |
| `include/Database.php` | Update `addItem()` method (or equivalent) to persist `talking_points` if provided |
| `include/Scraper.php` | No changes |
| `include/Generator.php` | No changes |
| `etc/config.php` | No changes |
