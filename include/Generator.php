<?php

declare(strict_types=1);

/**
 * Assembles the WordPress-compatible Markdown show notes from episode and item data.
 *
 * This class is intentionally pure: it takes data as arguments and returns a string.
 * It does not access the database, read files, or produce side-effects of any kind.
 *
 * Named MarkdownGenerator (not Generator) to avoid colliding with PHP's built-in
 * Generator class, which has been reserved since PHP 5.5 for the yield construct.
 */
class MarkdownGenerator
{
    /**
     * Produces the complete Markdown output for the given episode state.
     *
     * M6 — attribution is now part of the contract:
     *   - "By:" lines are only emitted when an author name exists (no empty
     *     [ ]() links).
     *   - Corroborating sub-articles (items with a parent_id, i.e. nested
     *     under a primary) render as indented "Also:" lines under their
     *     group, each carrying its own title, source URL and attribution.
     *     The primary's block stays the canonical story.
     *
     * @param array $episode  Row from the `episodes` table (id, week_number, year, youtube_url)
     * @param array $items    Grouped items: ['vulnerability' => [...], 'news' => [...]]
     * @param array $config   Application config array (show_title, show_tagline, sections)
     */
    public function generate(array $episode, array $items, array $config): string
    {
        $lines = [];

        // Document header
        $lines[] = "# {$config['show_title']} for Week {$episode['week_number']} of {$episode['year']} - {$config['show_tagline']}";
        $lines[] = '';
        $lines[] = "[youtube {$episode['youtube_url']}]";
        $lines[] = '';

        // Vulnerability section — tight bulleted list, no blank lines between items.
        // The blank line after the heading always appears (empty section = heading + blank).
        // A second blank line after the items is only added when items were actually emitted.
        $lines[] = "### {$config['sections']['vulnerability']}";
        $lines[] = '';
        foreach ($items['vulnerability'] as $index => $item) {
            $lines[] = "- [{$item['title']}]({$item['url']})";

            $author = trim((string) ($item['author_name'] ?? ''));
            if ($author !== '') {
                $profile = trim((string) ($item['author_url'] ?? ''));
                $lines[] = $profile !== ''
                    ? "    By: [{$author}]({$profile})"
                    : "    By: {$author}";
            }

            // M4 — research context, only when present (same treatment as the
            // news section; indented under the bullet).
            $context = trim((string) ($item['my_context'] ?? ''));
            if ($context !== '') {
                foreach (preg_split("/\r?\n/", $context) as $contextLine) {
                    $contextLine = trim($contextLine);
                    if ($contextLine !== '') {
                        $lines[] = "    > {$contextLine}";
                    }
                }
            }

            if ($index < count($items['vulnerability']) - 1) {
                $lines[] = '';
            }
        }
        if (!empty($items['vulnerability'])) {
            $lines[] = '';
        }

        $lines[] = "### {$config['sections']['news']}";
        $lines[] = '';

        // M6 — emit in flat order (fetchNewsItemsOrdered already interleaves
        // each primary with its secondaries in group order). Primary
        // items get the full Title/By/URL block; secondaries get the
        // indented "Also:" line with their own attribution.
        $newsItems = $items['news'];
        $total = count($newsItems);
        foreach ($newsItems as $index => $item) {
            $author  = trim((string) ($item['author_name'] ?? ''));
            $profile = trim((string) ($item['author_url'] ?? ''));
            $isSecondary = is_int($item['parent_id']) || (isset($item['parent_id']) && $item['parent_id'] !== null && $item['parent_id'] !== '');

            if ($isSecondary) {
                // Corroborating sub-article: one indented line, attribution
                // included so no reporter goes uncredited in the notes.
                // Some sites (JS-heavy front pages, paywalled pages) resist
                // title scraping — never emit an empty [ ]() link; the
                // domain then stands in as the link text.
                $linkTitle = trim((string) $item['title']);
                if ($linkTitle === '') {
                    $parts = parse_url((string) $item['url']);
                    $linkTitle = isset($parts['host']) ? $parts['host'] : (string) $item['url'];
                }
                $line = "Also: [{$linkTitle}]({$item['url']})";
                if ($author !== '') {
                    $by = $profile !== '' ? "[{$author}]({$profile})" : $author;
                    $line .= " — By: {$by}";
                }
                $lines[] = "    {$line}";

                $context = trim((string) ($item['my_context'] ?? ''));
                if ($context !== '') {
                    foreach (preg_split("/\r?\n/", $context) as $contextLine) {
                        $contextLine = trim($contextLine);
                        if ($contextLine !== '') {
                            $lines[] = "        > {$contextLine}";
                        }
                    }
                }

                // Secondaries attach to the block above; no blank line between
                // a primary and its own secondaries.
                continue;
            }

            $lines[] = "Title: {$item['title']}";
            if ($author !== '') {
                $lines[] = $profile !== ''
                    ? "By: [{$author}]({$profile})"
                    : "By: {$author}";
            }
            $lines[] = "[{$item['url']}]({$item['url']})";

            // M4 — research context, only when present. Multi-line input is
            // indented so each stored line becomes its own Markdown paragraph
            // line. Trailing newlines are stripped to keep the block tight.
            $context = trim((string) ($item['my_context'] ?? ''));
            if ($context !== '') {
                $lines[] = '';
                foreach (preg_split("/\r?\n/", $context) as $contextLine) {
                    $contextLine = trim($contextLine);
                    if ($contextLine !== '') {
                        $lines[] = "> {$contextLine}";
                    }
                }
            }

            // Blank line between item blocks, but not after the last one.
            if ($index < $total - 1) {
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
