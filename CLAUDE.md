# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This repository is a library of **Claude Code system prompt personas** — Markdown files designed to be passed to Claude Code via `--system-prompt` or placed in a project's `.claude/system_prompt.md`. Each persona defines a specialized role within a structured, specification-driven development workflow.

## Repository Structure

```
personas/          # The 7 persona system prompt files (the primary deliverable)
openspec/          # OpenSpec CLI data directory — gitignored, local only
  config.yaml      # OpenSpec project config (schema, context, per-artifact rules)
  changes/         # Active changes being worked on (one subdirectory per change)
  specs/           # Compiled/synced delta specs
.claude/           # Claude Code local config — gitignored, local only
  commands/opsx/   # /opsx:* slash commands (new, continue, apply, archive, etc.)
  skills/          # Skill definitions loaded by the slash commands
REQUIREMENTS.md    # Produced by running ANALYST against this repo
SPECIFICATION.md   # Produced by running SOLUTION_ARCHITECT + designers against this repo
```

> **Note:** `.claude/` and `openspec/` are in `.gitignore`. Only `personas/` and the root Markdown files are tracked.

## Personas and Workflow

The personas are meant to be invoked in sequence within a target project. Each role consumes and/or produces shared artifact files:

| Order | Persona | Reads | Produces |
|-------|---------|-------|----------|
| 1 | `ANALYST` | — | `REQUIREMENTS.md` |
| 2 | `SOLUTION_ARCHITECT` | `REQUIREMENTS.md` | `SPECIFICATION.md` |
| 3 | `GRAPHICAL_DESIGNER` | `REQUIREMENTS.md`, `SPECIFICATION.md` | Enhances `SPECIFICATION.md` |
| 4 | `ACCESSIBILITY_REVIEWER` | `REQUIREMENTS.md`, `SPECIFICATION.md` | Enhances `SPECIFICATION.md` |
| 5 | `WEB_DEVELOPER` | `SPECIFICATION.md`, `COMMENTS.md` | Code on feature branches |
| 6 | `CODE_REVIEWER` | `SPECIFICATION.md`, git diff | `COMMENTS.md` |
| 7 | `TECHNICAL_WRITER` | Project docs | User-facing documentation |

### Invoking a Persona

Pass the persona file to Claude Code using the `--system-prompt` flag in a target project:

```bash
claude --system-prompt /path/to/cnc/personas/ANALYST.md
```

Or copy/symlink the relevant file into the target project's `.claude/system_prompt.md`.

## Persona Responsibilities

- **ANALYST** — Elicits requirements through structured questioning; produces `REQUIREMENTS.md` with 17 defined sections.
- **SOLUTION_ARCHITECT** — Translates requirements into a full `SPECIFICATION.md` covering architecture, data models, API specs, security, infrastructure, and implementation phases.
- **GRAPHICAL_DESIGNER** — Identifies underspecified design decisions (colors, typography, spacing, component states, motion, responsive behavior) and fills them in `SPECIFICATION.md` so developers can implement pixel-perfectly without guessing.
- **ACCESSIBILITY_REVIEWER** — Audits specs for WCAG 2.1/2.2 barriers across visual, keyboard, screen reader, cognitive, motion, and responsive dimensions; rates severity (Critical/High/Medium/Low) and enhances `SPECIFICATION.md`.
- **WEB_DEVELOPER** — Implements features in **PHP, HTML, CSS, and vanilla JavaScript**. Creates a new git branch per feature; never merges to `main`. Uses MCP servers OpenSpec (spec validation), Context7 (library docs), and Serena (codebase navigation).
- **CODE_REVIEWER** — Runs `git diff main`, reviews against `SPECIFICATION.md`, and creates `COMMENTS.md` using a structured format (Critical / Major / Minor Issues, Positive Highlights, Specification Compliance, Overall Recommendation).
- **TECHNICAL_WRITER** — Produces user-facing docs (README, getting-started guides, API references, configuration docs) with precision and active voice.

## OpenSpec CLI & Slash Commands

This repo's own development uses the **OpenSpec** CLI for structured, artifact-driven change management. The `/opsx:*` slash commands wrap the CLI:

| Command | Purpose |
|---------|---------|
| `/opsx:new <name>` | Scaffold a new change under `openspec/changes/<name>/` |
| `/opsx:continue` | Create the next artifact for the active change |
| `/opsx:ff` | Fast-forward: generate all remaining artifacts at once |
| `/opsx:apply` | Implement tasks from a change's task list |
| `/opsx:verify` | Check implementation completeness before archiving |
| `/opsx:archive` | Archive a completed change |
| `/opsx:sync` | Sync a change's delta specs into the main specs |
| `/opsx:explore` | Open-ended thinking/exploration mode before committing to a change |

The underlying CLI commands permitted in this session are defined in `.claude/settings.local.json`:
`openspec new`, `openspec status`, `openspec instructions`, `openspec templates`, `openspec schemas`, `openspec list`.

### OpenSpec Config (`openspec/config.yaml`)

The `schema: spec-driven` field selects the artifact workflow. The optional `context` and `rules` fields can be used to inject project-specific context and per-artifact constraints.

## Key Conventions

- `SPECIFICATION.md` is the single source of truth in any target project; it is iteratively enhanced by SOLUTION_ARCHITECT → GRAPHICAL_DESIGNER → ACCESSIBILITY_REVIEWER.
- `COMMENTS.md` bridges CODE_REVIEWER and WEB_DEVELOPER; the developer reads it before implementing to incorporate prior feedback.
- Branching: one branch per implementation step, named `feature/`, `fix/`, or `style/` prefixes. No merges to `main` — merging is always a human decision.
- Primary tech stack for target projects: PHP (server-side), HTML (semantic), CSS, vanilla JavaScript.
- Security baseline: prepared statements for all SQL, CSRF protection, no user input trusted, secrets in environment variables only.
- Accessibility baseline: WCAG AA (4.5:1 contrast minimum), semantic HTML, keyboard operability, visible focus indicators.
