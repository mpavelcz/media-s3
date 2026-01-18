<?php declare(strict_types=1);

namespace MediaS3\Service;

/**
 * Extracts image URLs from HTML content using common patterns
 */
final class HtmlImageExtractor
{
    /** @var string[] Default patterns for image extraction */
    private const DEFAULT_PATTERNS = [
        // Fancybox links (class before href)
        '/<a[^>]*class="[^"]*fancybox[^"]*"[^>]*href="([^"]+)"[^>]*>/i',
        // Fancybox links (href before class)
        '/<a[^>]*href="([^"]+)"[^>]*class="[^"]*fancybox[^"]*"[^>]*>/i',
        // Lightbox data attributes
        '/data-(?:src|full|large|original|fancybox|href)="([^"]+\.(?:jpg|jpeg|png|webp|gif))"/i',
        // PhotoSwipe data attributes
        '/data-pswp-src="([^"]+)"/i',
        // Generic lightbox href
        '/<a[^>]*(?:data-lightbox|data-gallery|rel="lightbox)[^>]*href="([^"]+\.(?:jpg|jpeg|png|webp|gif))"/i',
    ];

    /** @var string[] Allowed image extensions */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Extract image URLs from HTML using default patterns
     *
     * @param string $html HTML content to parse
     * @param string|null $baseUrl Base URL for normalizing relative paths
     * @return string[] Array of unique, normalized image URLs
     */
    public function extract(string $html, ?string $baseUrl = null): array
    {
        return $this->extractWithPatterns($html, self::DEFAULT_PATTERNS, $baseUrl);
    }

    /**
     * Extract image URLs with custom regex patterns
     *
     * @param string $html HTML content to parse
     * @param string[] $patterns Array of regex patterns (must have capture group 1 for URL)
     * @param string|null $baseUrl Base URL for normalizing relative paths
     * @return string[] Array of unique, normalized image URLs
     */
    public function extractWithPatterns(string $html, array $patterns, ?string $baseUrl = null): array
    {
        $urls = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches) && !empty($matches[1])) {
                $urls = array_merge($urls, $matches[1]);
            }
        }

        // Normalize URLs
        $urls = array_map(
            fn(string $url) => $this->normalizeUrl($url, $baseUrl),
            $urls
        );

        // Remove duplicates
        $urls = array_unique($urls);

        // Filter to valid image URLs only
        $urls = array_filter($urls, fn(string $url) => $this->isValidImageUrl($url));

        return array_values($urls);
    }

    /**
     * Extract all <img> src attributes (useful for thumbnails/galleries)
     *
     * @param string $html HTML content to parse
     * @param string|null $baseUrl Base URL for normalizing relative paths
     * @param string|null $pathFilter Optional path substring filter (e.g., '/fotky/' to match only gallery images)
     * @return string[] Array of unique, normalized image URLs
     */
    public function extractImgSrc(string $html, ?string $baseUrl = null, ?string $pathFilter = null): array
    {
        $pattern = $pathFilter !== null
            ? '/<img[^>]*src="([^"]*' . preg_quote($pathFilter, '/') . '[^"]*\.(?:jpg|jpeg|png|webp|gif))"/i'
            : '/<img[^>]*src="([^"]+\.(?:jpg|jpeg|png|webp|gif))"/i';

        return $this->extractWithPatterns($html, [$pattern], $baseUrl);
    }

    /**
     * Normalize a URL (handle relative paths, protocol-relative URLs)
     */
    private function normalizeUrl(string $url, ?string $baseUrl): string
    {
        $url = trim($url);

        // Already absolute
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        // No base URL provided, return as-is
        if ($baseUrl === null) {
            return $url;
        }

        // Root-relative
        if (str_starts_with($url, '/')) {
            // Extract origin from base URL
            $parsed = parse_url($baseUrl);
            $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
            if (isset($parsed['port'])) {
                $origin .= ':' . $parsed['port'];
            }
            return $origin . $url;
        }

        // Relative path
        return rtrim($baseUrl, '/') . '/' . $url;
    }

    /**
     * Check if URL is a valid image URL
     */
    private function isValidImageUrl(string $url): bool
    {
        // Must be http/https
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return false;
        }

        // Check extension
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === false) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }
}
