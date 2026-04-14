<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Builds sharded sitemap_index.xml + sitemap-N.xml files for a store.
 */
interface SitemapBuilderInterface
{
    /**
     * Build sitemaps for the given store and return an iterable of written file paths.
     *
     * @return iterable<int,string>
     */
    public function build(int $storeId): iterable;

    /**
     * Build sitemap for the given store and return the XML body as a string.
     *
     * Used by the frontend controller to serve /panth-sitemap.xml inline
     * without writing shard files to disk.
     */
    public function buildForStore(int $storeId): string;

    /**
     * Build sitemaps from a sitemap profile configuration.
     *
     * Generates separate files per entity type (products, categories, cms, custom)
     * and combines them into a single sitemap_index.xml.
     *
     * @param array $profile Profile row from panth_seo_sitemap_profile table
     * @return array{url_count: int, file_count: int, generation_time: float, files: list<string>}
     */
    public function buildFromProfile(array $profile): array;
}
