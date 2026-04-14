<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Sitemap;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Validates generated sitemap files against the Sitemaps protocol constraints:
 *
 *  - Well-formed XML (libxml)
 *  - URL count must be < 50 000 per file
 *  - Uncompressed file size must be < 50 MB
 *  - Every <loc> value must start with the site's base URL
 */
class SitemapValidator
{
    /** @see https://www.sitemaps.org/protocol.html */
    private const MAX_URLS      = 50_000;
    private const MAX_FILE_SIZE = 52_428_800; // 50 MB

    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Validate a sitemap XML file and return an array of error strings.
     *
     * An empty array means the file is valid.
     *
     * @return string[]
     */
    public function validate(string $filePath): array
    {
        $errors = [];

        // ── File existence ──────────────────────────────────────────
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['Sitemap file does not exist or is not readable: ' . $filePath];
        }

        // ── File size ───────────────────────────────────────────────
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            $errors[] = 'Unable to determine file size for: ' . $filePath;
        } elseif ($fileSize > self::MAX_FILE_SIZE) {
            $errors[] = sprintf(
                'Sitemap file exceeds 50 MB limit: %s (%.2f MB)',
                basename($filePath),
                $fileSize / 1_048_576
            );
        }

        // ── Well-formed XML (libxml) ────────────────────────────────
        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = new \DOMDocument();
        $loaded = $xml->load($filePath);

        $libxmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (!$loaded) {
            $errors[] = 'Sitemap file is not valid XML: ' . $filePath;
            foreach ($libxmlErrors as $libxmlError) {
                $errors[] = sprintf(
                    'XML error (line %d, col %d): %s',
                    $libxmlError->line,
                    $libxmlError->column,
                    trim($libxmlError->message)
                );
            }
            // Cannot continue further checks without valid XML
            return $errors;
        }

        foreach ($libxmlErrors as $libxmlError) {
            if ($libxmlError->level >= LIBXML_ERR_ERROR) {
                $errors[] = sprintf(
                    'XML error (line %d, col %d): %s',
                    $libxmlError->line,
                    $libxmlError->column,
                    trim($libxmlError->message)
                );
            }
        }

        // ── URL count ───────────────────────────────────────────────
        $xpath    = new \DOMXPath($xml);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locNodes = $xpath->query('//sm:url/sm:loc');

        if ($locNodes !== false && $locNodes->length >= self::MAX_URLS) {
            $errors[] = sprintf(
                'Sitemap contains %d URLs, which exceeds the 50,000 URL limit.',
                $locNodes->length
            );
        }

        // ── Base URL check ──────────────────────────────────────────
        $baseUrls = $this->collectBaseUrls();

        if ($locNodes !== false && $baseUrls !== []) {
            foreach ($locNodes as $node) {
                $loc = trim((string) $node->textContent);
                if ($loc === '') {
                    $errors[] = 'Empty <loc> element found.';
                    continue;
                }

                $matchesBase = false;
                foreach ($baseUrls as $base) {
                    if (str_starts_with($loc, $base)) {
                        $matchesBase = true;
                        break;
                    }
                }
                if (!$matchesBase) {
                    $errors[] = sprintf(
                        'URL does not match any configured base URL: %s',
                        $loc
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Collect base URLs from all stores so we can validate <loc> values.
     *
     * @return string[]
     */
    private function collectBaseUrls(): array
    {
        $urls = [];
        try {
            foreach ($this->storeManager->getStores() as $store) {
                $base = rtrim((string) $store->getBaseUrl(), '/');
                if ($base !== '') {
                    $urls[$base] = $base;
                }
            }
        } catch (\Throwable) {
            // StoreManager may throw during CLI setup; degrade gracefully
        }

        return array_values($urls);
    }
}
