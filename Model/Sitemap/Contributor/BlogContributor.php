<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Sitemap\Contributor;

use Panth\AdvancedSEO\Api\SitemapContributorInterface;
use Panth\AdvancedSEO\Model\Blog\BlogDetector;

/**
 * Contributes blog post URLs to the XML sitemap when a supported third-party
 * blog module is installed. Yields nothing if no blog module is detected.
 */
class BlogContributor implements SitemapContributorInterface
{
    private const CHANGEFREQ = 'weekly';
    private const PRIORITY   = 0.6;

    public function __construct(
        private readonly BlogDetector $blogDetector
    ) {
    }

    public function getCode(): string
    {
        return 'blog';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        if (!$this->blogDetector->isBlogInstalled()) {
            return;
        }

        $posts = $this->blogDetector->getBlogPosts($storeId);

        foreach ($posts as $post) {
            $url = (string) ($post['url'] ?? '');

            if ($url === '') {
                continue;
            }

            yield [
                'loc'        => $url,
                'changefreq' => self::CHANGEFREQ,
                'priority'   => self::PRIORITY,
            ];
        }
    }
}
