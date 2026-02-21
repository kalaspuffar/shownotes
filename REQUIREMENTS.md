# Requirements Document: Cozy News Corner — Show Notes Generator

**Version:** 1.1
**Date:** 2026-02-21
**Author:** Requirements Analyst (Claude Code)
**Status:** Draft — Updated with Stakeholder Clarifications (2026-02-21)

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

The **Cozy News Corner Show Notes Generator** is a personal, single-user web application that automates the creation of weekly podcast show notes in a specific Markdown format. The tool eliminates the repetitive, error-prone process of manually copying titles, authors, bylines, and URLs from browser tabs into a document.

The user provides article URLs, the tool fetches page metadata (title, author name, author profile link), organises items into predefined sections, and generates a ready-to-copy Markdown output compatible with the user's WordPress blog.

**Key success criteria:** The user can go from a list of browser tabs to a complete, correctly formatted show notes document in significantly less time than the current manual process.

---

## 2. Business Context

### Background

The user produces a weekly open-source news podcast called **"Cozy News Corner"**. Each episode requires show notes published to a WordPress blog, containing:
- A YouTube video embed
- A structured list of news items grouped by type

Currently, the user spends significant time manually visiting each browser tab, copying the article title, author name, author profile link, and URL, then formatting them correctly in a document. This is described as "painstaking."

### Current State

- Links are gathered from RSS feeds and other online sources throughout the week
- Browser tabs are kept open as a working list
- Show notes are created manually by copying metadata from each tab
- The process is time-consuming and prone to formatting inconsistencies

### Desired State

- User adds a URL to the tool with one action
- Tool automatically fetches the required metadata
- User reviews and corrects any scraping errors
- User arranges items into the desired episode order
- Tool generates formatted Markdown with one click

---

## 3. Goals and Objectives

### Business Goals
- Reduce time spent producing show notes each week
- Eliminate manual copy-paste errors
- Ensure consistent Markdown formatting every episode

### User Goals
- Quickly add links gathered during the week
- Trust the tool to fetch and format metadata correctly
- Have the flexibility to reorder, edit, and delete items before generating
- Copy the final Markdown output directly to the clipboard

### Measurable Success Criteria
- Show notes generation time reduced from current manual effort to under 5 minutes of active interaction
- Zero formatting errors in generated Markdown output
- All required metadata fields (title, author name, author link, article URL) populated with at most one manual correction per item on average

---

## 4. Scope

### In Scope

- Web application (PHP, HTML, CSS, vanilla JavaScript)
- Two content sections: **Vulnerability** and **News**
- URL metadata scraping (page title, author name, author profile URL)
- Editable fields for all scraped metadata
- Manual entry of YouTube embed URL
- Auto-suggestion of current ISO week number (editable)
- Drag-and-drop reordering of items within each section
- Add / Edit / Delete items within each section
- Single-episode persistence via SQLite (one active episode at a time)
- Markdown generation in the exact required format
- Copy-to-clipboard button for the generated Markdown
- Configuration file (`etc/config.php`) for customisable static values
- Standard project directory structure: `bin/`, `etc/`, `include/`, `www/`, `docs/`

### Out of Scope

- User authentication / login system (access controlled at network level)
- Multiple simultaneous episodes or episode history/archive
- Chrome extension for sending links from the browser (future phase)
- RSS feed reader or automatic link discovery
- Direct posting to WordPress (copy-paste is sufficient)
- Additional content sections beyond Vulnerability and News (future consideration)
- Mobile-optimised design (desktop browser assumed)

### Future Considerations

- Chrome/Firefox browser extension to send the current tab's URL directly to the tool
- Additional content sections (e.g., "Releases", "Tutorials", "Community")
- Episode archive / history

---

## 5. Stakeholders

| Stakeholder | Role | Interest |
|-------------|------|----------|
| Podcast Host (sole user) | Owner, User, Decision-maker | Faster workflow, correct formatting, author credit |

---

## 6. User Personas / Actors

### Persona: Podcast Host

| Attribute | Detail |
|-----------|--------|
| **Who** | Solo podcast producer of "Cozy News Corner" |
| **Technical level** | High — comfortable with self-hosting, PHP, homelab infrastructure |
| **Usage frequency** | Once per week, during show notes preparation (after recording) |
| **Access method** | Desktop browser on home network |
| **Primary goal** | Produce correctly formatted show notes as quickly as possible |
| **Key concern** | Author credit must be accurate; formatting must match WordPress expectations exactly |

---

## 7. Functional Requirements

### 7.1 Use Cases

#### UC-01: Start / Continue Episode

- The user opens the web app
- The app loads any previously saved (in-progress) episode from SQLite
- If no in-progress episode exists, a new blank episode is initialised
- The current ISO week number is auto-populated in the Week field but is editable

#### UC-02: Set Episode Metadata

- The user can view and edit:
  - **Week number** (integer, auto-suggested as current ISO week)
  - **Year** (integer, auto-suggested as current year)
  - **YouTube URL** (text field, manually entered after recording)

#### UC-03: Add a Link to a Section

- The user pastes a URL into an input field and selects a target section (Vulnerability or News)
- The tool fetches the page and extracts:
  - Page title
  - Author name
  - Author profile URL
- The extracted data is displayed in editable fields
- The user can correct any fields before confirming
- The item is added to the selected section's list

#### UC-04: Edit an Existing Item

- The user can click to edit any field of any item already in the list
- Editable fields vary by section type:
  - **Vulnerability:** URL, display title
  - **News:** URL, article title, author name, author profile URL

#### UC-05: Delete an Item

- The user can remove any item from either section
- A confirmation prompt prevents accidental deletion

#### UC-06: Reorder Items Within a Section

- The user can drag and drop items within a section to change their order
- Order is preserved in the saved state

#### UC-07: Generate Markdown

- The user clicks a "Generate" (or "Preview") button
- The tool renders the complete Markdown output in the exact required format (see Appendix A)
- The output is displayed in a read-only text area or preview panel

#### UC-08: Copy to Clipboard

- A "Copy to Clipboard" button copies the full generated Markdown
- Visual confirmation is shown (e.g., button changes to "Copied!")

#### UC-09: Reset / New Episode

- The user can clear the current episode and start fresh
- A confirmation prompt is required before data is deleted

### 7.2 Features and Capabilities

#### F-01: Episode Header Generation *(Must-have)*
- Generates: `# Cozy News Corner for Week {N} of {YEAR} - Your source for Open Source news`
- Week and year are drawn from the editable metadata fields

#### F-02: YouTube Embed Generation *(Must-have)*
- Generates: `[youtube https://www.youtube.com/watch?v=XXXX]`
- The YouTube URL is a **required field** — show notes are only produced for recorded episodes, so a URL is always available before the user generates output
- The embed line is always present in the generated Markdown; there is no "omit if empty" path

#### F-03: Vulnerability Section Generation *(Must-have)*
- Generates a `### Vulnerability` heading followed by a **tight bulleted list** (no blank lines between items)
- Each item: `- [Page Title](https://full-url.com)`
- The list is compact — items are consecutive lines with no separators between them

#### F-04: News Section Generation *(Must-have)*
- Generates a `### News` heading followed by item blocks
- Each item format:
  ```
  Title: {Article Title}
  By: [{Author Name}]({Author Profile URL})
  [{Full Article URL}]({Full Article URL})
  ```
- Items are separated by a **single blank line** — no horizontal rule (`---`) or other separator is used

#### F-05: URL Metadata Scraping *(Must-have)*
- On URL submission, the tool makes a server-side HTTP request to the provided URL
- Extracts from the page:
  - **Title:** From `<title>` tag or Open Graph `og:title` meta tag — this is the primary scraping goal
  - **Author name:** Attempted via common meta tags and byline elements; considered a best-effort secondary goal
  - **Author profile URL:** Attempted via `rel="author"` links, byline anchor tags, or schema.org — best-effort
- Falls back gracefully: if a field cannot be determined, the field is left editable for manual entry
- **Author scraping is not the primary pain point** — title retrieval is. Author entry is expected to require occasional manual input and is supported by the author history feature (see F-11)

#### F-06: Editable Scraped Fields *(Must-have)*
- All scraped fields are editable before and after adding an item to the list
- Inline editing within the item list (click to edit)

#### F-07: Drag-and-Drop Reordering *(Must-have)*
- Items within each section can be reordered via drag and drop
- Visual drag handle indicator on each item

#### F-08: SQLite Persistence *(Must-have)*
- Current episode state is saved automatically to a local SQLite database file
- Only one episode is stored at a time
- State persists across browser sessions and page refreshes
- "New Episode" action clears and resets the database record

#### F-09: Configuration File *(Must-have)*
- Static/customisable values stored in `etc/config.php`
- Configurable values include:
  - Show title (`Cozy News Corner`)
  - Show tagline (`Your source for Open Source news`)
  - Default section names

#### F-10: Visual Episode Overview *(Should-have)*
- The main UI provides a clear overview of all items in both sections
- Suitable for planning the episode flow and grouping related items

#### F-11: Author History Dropdown *(Must-have)*
- The tool maintains a persistent list of all author name + author profile URL pairs ever used (stored in SQLite)
- When the author name field is blank or being edited, a dropdown is populated with previously used authors
- The dropdown is **domain-aware**: authors previously associated with the same domain as the current URL are shown first / ranked highest
- This reflects the real-world pattern that each domain (news source) tends to have the same recurring authors
- Selecting an author from the dropdown fills both the name and profile URL fields automatically
- New author entries (manually typed and saved) are automatically added to the history for future reuse
- The history grows passively — no separate author management UI is required

---

## 8. Non-Functional Requirements

### 8.1 Performance

| Requirement | Target |
|-------------|--------|
| Page load time | < 2 seconds on local network |
| URL metadata fetch time | < 5 seconds per URL (network-dependent) |
| Markdown generation time | < 1 second |

- If metadata fetching takes longer than 5 seconds, the request should time out and return blank editable fields with an error message

### 8.2 Security

- **No authentication required** — access control is handled at the network/homelab level
- Server-side URL fetching must **not** expose internal network resources (SSRF protection: only fetch public HTTP/HTTPS URLs)
- No user-supplied data is executed as code
- SQLite file stored outside the web root in the `var/` directory

### 8.3 Availability & Reliability

- Single-user local tool; no uptime SLA required
- Data loss prevention: auto-save to SQLite on every change (or on item add/edit/delete/reorder)

### 8.4 Usability

- **Desktop browser only** (Chrome assumed as primary; standard modern browser support)
- UI should provide a clear, at-a-glance overview of the full episode structure
- Drag-and-drop should have visible affordances (drag handles)
- Inline editing should be intuitive (click to edit pattern)
- Error states (scraping failures) must be clearly communicated with actionable guidance

### 8.5 Maintainability

- Standard PHP project structure: `bin/`, `etc/`, `include/`, `www/`, `docs/`
- Configuration separated from code (`etc/config.php`)
- No external PHP frameworks required (vanilla PHP preferred)
- SQLite database file path configurable

---

## 9. Data Requirements

### 9.1 Data Entities

#### Episode
| Field | Type | Notes |
|-------|------|-------|
| `week_number` | Integer | ISO week number (1–53) |
| `year` | Integer | Four-digit year |
| `youtube_url` | String | Full YouTube URL — always required before generating output |

#### Item (Vulnerability or News)
| Field | Type | Notes |
|-------|------|-------|
| `id` | Integer | Auto-increment primary key |
| `section` | Enum | `vulnerability` or `news` |
| `url` | String | The article/page URL |
| `title` | String | Scraped or manually entered page title |
| `author_name` | String | Scraped or manually entered (News only) |
| `author_url` | String | Scraped or manually entered author profile URL (News only) |
| `sort_order` | Integer | Position within section |

#### AuthorHistory
| Field | Type | Notes |
|-------|------|-------|
| `id` | Integer | Auto-increment primary key |
| `domain` | String | Hostname extracted from the article URL (e.g., `9to5linux.com`) |
| `author_name` | String | Author's display name |
| `author_url` | String | Author's profile URL |
| `use_count` | Integer | Number of times this author has been used (for ranking) |
| `last_used_at` | Timestamp | Date last used (for ranking / recency) |
| *(unique constraint)* | | `domain` + `author_name` + `author_url` must be unique |

### 9.2 Data Volume

- One episode record at a time
- Typically 5–10 Vulnerability items + 5–15 News items per episode
- SQLite is appropriate; no scaling concerns

### 9.3 Data Retention

- Only the current in-progress episode is stored
- Data is cleared when the user starts a new episode
- No historical archive required

---

## 10. Integration Requirements

### 10.1 WordPress Blog

- Generated Markdown must use WordPress shortcode syntax for YouTube embeds: `[youtube {URL}]`
- No direct API integration with WordPress — copy-paste workflow

### 10.2 External URL Fetching (Scraping)

- Server-side HTTP GET requests made from PHP to article URLs
- Must send a realistic `User-Agent` header to avoid being blocked
- Must handle redirects
- Must handle HTTPS
- Timeout: 5 seconds
- **Primary sources** (user typically uses ~3 RSS feed sources — specific domains unknown but scraping strategy must be generalised)
- Scraping strategy priority:
  1. Open Graph / Twitter Card meta tags (`og:title`, `og:author`)
  2. Standard meta tags (`<meta name="author">`, `<meta name="article:author">`)
  3. Schema.org JSON-LD (`@type: Person`, `name`, `url`)
  4. HTML byline patterns (elements with class names like `author`, `byline`, `article-author`)
  5. `<link rel="author">` tag

### 10.3 Future: Browser Extension *(Out of Scope)*

- A Chrome/Firefox extension may send URLs directly to the tool in a later phase
- Architecture should not preclude adding a simple REST endpoint later

---

## 11. Constraints

### 11.1 Technical Constraints

- **Language:** PHP (server-side), HTML, CSS, vanilla JavaScript (no JS frameworks)
- **Database:** SQLite (via PHP's PDO SQLite driver)
- **No Composer dependencies** preferred; if needed, keep minimal
- **Server:** Debian Linux, self-hosted homelab
- **Web server:** Apache or Nginx (standard Debian setup)

### 11.2 Business Constraints

- Single developer / solo project
- No budget constraints specified (open-source tooling only)
- Must be operational quickly — personal productivity tool

### 11.3 Directory Structure Constraint

```
project-root/
├── bin/          # CLI scripts (if any)
├── etc/          # Configuration files (config.php)
├── include/      # PHP include files / classes
├── var/          # Runtime data — SQLite database file lives here
├── www/          # Web root (index.php, assets)
└── docs/         # Documentation
```

---

## 12. Assumptions

1. The tool is accessed exclusively from the user's local network; no public internet exposure
2. The user's primary browser is Google Chrome (desktop)
3. The three main RSS feed sources are publicly accessible websites that do not require authentication to scrape
4. The SQLite PDO extension is available on the Debian PHP installation
5. The show title and tagline are effectively permanent but stored in config for flexibility
6. The YouTube URL is always a standard `https://www.youtube.com/watch?v=` format URL and is always available before show notes are generated (show notes are only produced for recorded episodes)
7. The user will run the tool on a standard web server (Apache/Nginx) already configured in the homelab
8. Week numbers follow ISO 8601 standard (weeks start on Monday)
9. Authors recur across episodes — the same writer tends to appear repeatedly on a given domain. The author history dropdown will therefore become useful quickly and reduce manual author entry over time
10. Author scraping from page HTML is a best-effort secondary feature; the author history dropdown (F-11) is the primary author-entry assistance mechanism

---

## 13. Dependencies

| Dependency | Type | Notes |
|------------|------|-------|
| PHP with PDO SQLite | Runtime | Must be installed on Debian host |
| Web server (Apache/Nginx) | Runtime | Must be configured in homelab |
| Internet access from server | Runtime | Required for URL metadata fetching |
| User's blog (WordPress) | External | Defines the required Markdown/shortcode format |

---

## 14. Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Scraping blocked by some news sites | Medium | Medium | Editable fields allow manual correction; realistic User-Agent header |
| Author metadata not findable via scraping | High | Low | Fields left blank with clear indication; user can fill manually |
| SQLite file permissions issue on Debian | Low | High | Store outside web root; document correct permissions in setup guide |
| YouTube URL format changes | Very Low | Low | Plain text field; user pastes whatever URL is needed |
| Episode data lost on server restart | Low | Medium | Auto-save on every change mitigates this |

---

## 15. Success Criteria

| Criterion | Measure |
|-----------|---------|
| Correct Markdown output | Generated output matches the required format exactly (see Appendix A) |
| Metadata scraping | At least title is successfully scraped for the majority of articles |
| Persistence | Refreshing the browser restores all items in progress |
| Reordering | Drag-and-drop works reliably within both sections |
| Copy to clipboard | Full Markdown is copied with one button click |
| Editing | All fields (title, author, URL) can be edited inline |

---

## 16. Open Questions

All previously open questions have been resolved by stakeholder clarification (2026-02-21):

| # | Question | Resolution |
|---|----------|------------|
| OQ-01 | Primary RSS feed sources? | Not required — scraping strategy remains general; author history dropdown (F-11) is the primary author-assistance mechanism. Domain-specific scraping optimisations are a future consideration only. |
| OQ-02 | YouTube embed omitted if URL blank? | **Resolved:** YouTube URL is always present. Show notes are only generated for recorded episodes. The embed line is always included in output. |
| OQ-03 | Blank lines between Vulnerability items? | **Resolved:** Tight bulleted list — no blank lines between items. |
| OQ-04 | Separator between News items? | **Resolved:** Single blank line between items. No `---` or other separator. |
| OQ-05 | SQLite file location: `etc/` or `var/`? | **Resolved:** `var/` directory. |

---

## 17. Appendices

### Appendix A: Required Markdown Output Format

```markdown
# Cozy News Corner for Week 8 of 2026 - Your source for Open Source news

[youtube https://www.youtube.com/watch?v=JjXNspLI2XU]

### Vulnerability

- [Page Title of CVE Article](https://example.com/cve-article)
- [Another Vulnerability Title](https://example.com/another-vuln)

### News

Title: Vim 9.2 Is Out with Comprehensive Completion, Wayland Support, and More
By: [Marcus Nestor](https://9to5linux.com/author/admin)
[https://9to5linux.com/vim-9-2-is-out-with-comprehensive-completion-wayland-support-and-more](https://9to5linux.com/vim-9-2-is-out-with-comprehensive-completion-wayland-support-and-more)

Title: Another News Article Title
By: [Author Name](https://source.com/author/profile)
[https://source.com/full/article/url](https://source.com/full/article/url)
```

**Notes:**
- YouTube shortcode uses WordPress format: `[youtube URL]` — this line is **always present** (show notes are only produced for recorded episodes)
- Vulnerability section: **tight bulleted list** — items are consecutive lines with no blank lines between them
- News section: three-line blocks per item, separated by a **single blank line** — no `---` or other separator
- Article URL is used as both link text and href (full URL, no truncation)
- Week number follows ISO 8601

### Appendix B: Project Directory Structure

```
show-notes-generator/
├── bin/                    # CLI utilities (future use)
├── etc/
│   └── config.php          # Show title, tagline, section names
├── include/
│   ├── db.php              # Database connection and queries
│   ├── scraper.php         # URL metadata fetching logic
│   └── generator.php       # Markdown generation logic
├── var/
│   └── shownotes.sqlite    # SQLite database (outside web root)
├── www/
│   ├── index.php           # Main application entry point
│   ├── api.php             # AJAX endpoints (add item, edit, delete, reorder)
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── app.js          # Drag-and-drop, inline editing, clipboard
└── docs/
    └── setup.md            # Installation and configuration guide
```

### Appendix C: Scraping Strategy (Priority Order)

For each URL submitted, the server-side scraper attempts to extract metadata in this order:

| Priority | Source | Fields |
|----------|--------|--------|
| 1 | Open Graph meta tags (`og:title`, `og:author`) | Title, Author name |
| 2 | Standard meta tags (`name="author"`) | Author name |
| 3 | Schema.org JSON-LD (`@type: Person`) | Author name, Author URL |
| 4 | `<link rel="author" href="...">` | Author URL |
| 5 | HTML byline elements (class: `author`, `byline`, `article-author`, etc.) | Author name, Author URL |
| 6 | `<title>` tag | Title (fallback) |

### Appendix D: Glossary

| Term | Definition |
|------|------------|
| Show notes | The written accompaniment to a podcast episode, published on the host's blog |
| Vulnerability | A section of the show notes covering security vulnerabilities; formatted as a simple link list |
| News | A section of the show notes covering general open-source news; formatted with title, byline, and link |
| Byline | The author credit line on an article |
| ISO week | Week number as defined by ISO 8601; week 1 is the week containing the first Thursday of the year |
| WordPress shortcode | WordPress-specific embed syntax, e.g., `[youtube URL]` |
| Homelab | A self-hosted server environment maintained by the user at home |
