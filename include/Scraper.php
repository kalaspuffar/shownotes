<?php

declare(strict_types=1);

/**
 * Fetches a URL and extracts article metadata (title, author name, author URL, domain).
 *
 * All extraction is best-effort: any field that cannot be determined is returned
 * as an empty string. The method never throws; errors are surfaced via the 'error'
 * key of the returned array.
 */
class Scraper
{
    public function __construct(private readonly array $config) {}

    /**
     * Validates the URL, fetches the page, and extracts metadata.
     *
     * @return array{title: string, author_name: string, author_url: string, domain: string, error: string|null}
     */
    public function scrape(string $url): array
    {
        $empty = ['title' => '', 'author_name' => '', 'author_url' => '', 'domain' => '', 'error' => null];

        $validationError = $this->validateUrl($url);
        if ($validationError !== null) {
            return array_merge($empty, ['error' => $validationError]);
        }

        $empty['domain'] = $this->extractDomain($url);

        [$html, $fetchError] = $this->fetchUrl($url);
        if ($fetchError !== null) {
            return array_merge($empty, ['error' => $fetchError]);
        }

        return $this->extractMetadata($html, $url, $empty);
    }

    // -------------------------------------------------------------------------
    // URL validation (SSRF protection)
    // -------------------------------------------------------------------------

    private function validateUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (!$parts || !isset($parts['scheme']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            return 'Invalid URL: only http and https schemes are allowed';
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return 'Invalid URL: missing host';
        }

        // Reject bare IPv4 literals.
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host)) {
            return 'Invalid URL: IP address literals are not allowed';
        }

        // Reject IPv6 literals (contain ':').
        if (str_contains($host, ':')) {
            return 'Invalid URL: IPv6 address literals are not allowed';
        }

        // Resolve the hostname; gethostbyname returns the input unchanged on failure.
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return 'Invalid URL: hostname could not be resolved';
        }

        // Reject private and reserved IP ranges.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'Invalid URL: host resolves to a private or reserved address';
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // HTTP fetch
    // -------------------------------------------------------------------------

    /** @return array{0: string|null, 1: string|null} [html, error] */
    private function fetchUrl(string $url): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->config['scrape_timeout'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => $this->config['max_redirects'],
            CURLOPT_USERAGENT      => $this->config['scrape_useragent'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',   // Accept any encoding; auto-decompress.
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9'],
        ]);

        $html     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return [null, 'Could not fetch URL: ' . $curlError];
        }

        if ($httpCode >= 400) {
            return [null, 'Could not fetch URL: HTTP ' . $httpCode];
        }

        return [$html ?: '', null];
    }

    // -------------------------------------------------------------------------
    // Metadata extraction
    // -------------------------------------------------------------------------

    private function extractMetadata(string $html, string $url, array $result): array
    {
        // Suppress libxml warnings from malformed real-world HTML.
        $useErrors = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML($html, LIBXML_NONET);
        libxml_use_internal_errors($useErrors);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        $result['title']       = $this->extractTitle($xpath);
        $result['author_name'] = $this->extractAuthorName($xpath, $html);
        $result['author_url']  = $this->extractAuthorUrl($xpath, $html);

        // If author metadata is absent, flag it as a partial result.
        if ($result['author_name'] === '') {
            $result['error'] = 'Author metadata not found — please enter manually';
        }

        return $result;
    }

    private function extractTitle(\DOMXPath $xpath): string
    {
        // 1. Open Graph title
        $og = $xpath->query('//meta[@property="og:title"]/@content');
        if ($og && $og->length > 0 && trim($og->item(0)->nodeValue) !== '') {
            return trim($og->item(0)->nodeValue);
        }

        // 2. Twitter Card title
        $tw = $xpath->query('//meta[@name="twitter:title"]/@content');
        if ($tw && $tw->length > 0 && trim($tw->item(0)->nodeValue) !== '') {
            return trim($tw->item(0)->nodeValue);
        }

        // 3. HTML <title> element
        $titleNodes = $xpath->query('//title');
        if ($titleNodes && $titleNodes->length > 0) {
            return trim($titleNodes->item(0)->textContent);
        }

        return '';
    }

    private function extractAuthorName(\DOMXPath $xpath, string $html): string
    {
        // 1. og:author (non-standard but some sites use it)
        $og = $xpath->query('//meta[@property="og:author"]/@content');
        if ($og && $og->length > 0 && trim($og->item(0)->nodeValue) !== '') {
            return trim($og->item(0)->nodeValue);
        }

        // 2. Standard meta author
        $metaAuthor = $xpath->query('//meta[@name="author"]/@content');
        if ($metaAuthor && $metaAuthor->length > 0 && trim($metaAuthor->item(0)->nodeValue) !== '') {
            return trim($metaAuthor->item(0)->nodeValue);
        }

        // 3. article:author (Open Graph article namespace)
        $articleAuthor = $xpath->query('//meta[@name="article:author"]/@content');
        if ($articleAuthor && $articleAuthor->length > 0 && trim($articleAuthor->item(0)->nodeValue) !== '') {
            return trim($articleAuthor->item(0)->nodeValue);
        }

        // 4. Schema.org JSON-LD
        $name = $this->extractFromJsonLd($html, 'name');
        if ($name !== '') {
            return $name;
        }

        // 5. HTML byline class heuristics
        $bylineClasses = ['author', 'byline', 'article-author', 'entry-author', 'post-author'];
        foreach ($bylineClasses as $cls) {
            $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $cls ')]");
            if ($nodes && $nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function extractAuthorUrl(\DOMXPath $xpath, string $html): string
    {
        // 1. Schema.org JSON-LD: author.url or author.sameAs
        $url = $this->extractFromJsonLd($html, 'url');
        if ($url !== '') {
            return $url;
        }

        $sameAs = $this->extractFromJsonLd($html, 'sameAs');
        if ($sameAs !== '') {
            return $sameAs;
        }

        // 2. <link rel="author" href="...">
        $linkAuthor = $xpath->query('//link[@rel="author"]/@href');
        if ($linkAuthor && $linkAuthor->length > 0 && trim($linkAuthor->item(0)->nodeValue) !== '') {
            return trim($linkAuthor->item(0)->nodeValue);
        }

        // 3. <a href> inside the first matching byline element
        $bylineClasses = ['author', 'byline', 'article-author', 'entry-author', 'post-author'];
        foreach ($bylineClasses as $cls) {
            $anchors = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $cls ')]//a[@href]/@href");
            if ($anchors && $anchors->length > 0 && trim($anchors->item(0)->nodeValue) !== '') {
                return trim($anchors->item(0)->nodeValue);
            }
        }

        return '';
    }

    /**
     * Parses JSON-LD script blocks looking for Article/NewsArticle/BlogPosting types
     * and extracts the given author field ('name', 'url', or 'sameAs').
     */
    private function extractFromJsonLd(string $html, string $field): string
    {
        $articleTypes = ['Article', 'NewsArticle', 'BlogPosting'];

        // Extract all <script type="application/ld+json"> blocks from the raw HTML.
        if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches)) {
            return '';
        }

        foreach ($matches[1] as $jsonRaw) {
            $data = json_decode($jsonRaw, true);
            if (!is_array($data)) {
                continue;
            }

            // Support both single objects and @graph arrays.
            $candidates = isset($data['@graph']) ? $data['@graph'] : [$data];

            foreach ($candidates as $node) {
                if (!isset($node['@type']) || !in_array($node['@type'], $articleTypes, true)) {
                    continue;
                }

                $author = $node['author'] ?? null;
                if (!is_array($author)) {
                    continue;
                }

                $value = $author[$field] ?? '';
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Domain extraction
    // -------------------------------------------------------------------------

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        // Strip the www. prefix for cleaner domain-based author history lookups.
        return preg_replace('/^www\./i', '', $host);
    }
}
