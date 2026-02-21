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
        foreach ($items['vulnerability'] as $item) {
            $lines[] = "- [{$item['title']}]({$item['url']})";
        }
        if (!empty($items['vulnerability'])) {
            $lines[] = '';
        }

        // News section — three-line blocks separated by single blank lines.
        $lines[] = "### {$config['sections']['news']}";
        $lines[] = '';

        $newsItems = $items['news'];
        foreach ($newsItems as $index => $item) {
            $lines[] = "Title: {$item['title']}";
            $lines[] = "By: [{$item['author_name']}]({$item['author_url']})";
            $lines[] = "[{$item['url']}]({$item['url']})";

            // Blank line between items, but not after the last one.
            if ($index < count($newsItems) - 1) {
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
