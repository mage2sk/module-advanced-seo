<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Api;

/**
 * Streams URL entries into the sitemap generator. Implementations may push
 * products, categories, CMS pages, or custom entities.
 */
interface SitemapContributorInterface
{
    /**
     * @return \Generator<int,array{
     *     loc:string,
     *     lastmod?:string,
     *     changefreq?:string,
     *     priority?:float,
     *     images?:array<int,array{loc:string,caption?:string,title?:string}>,
     *     hreflang?:array<int,array{locale:string,url:string}>
     *  }>
     */
    public function getUrls(int $storeId, array $config = []): \Generator;

    public function getCode(): string;
}
