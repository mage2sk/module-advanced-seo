<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

/**
 * Emits an Article node for CMS pages tagged as articles (heuristic: any CMS
 * page whose identifier begins with "blog/", "news/" or "articles/", or any
 * CMS page with a meta_keywords entry containing "article").
 */
class CmsArticleProvider extends AbstractProvider
{
    public function getCode(): string
    {
        return 'article';
    }

    public function isApplicable(): bool
    {
        return $this->getCurrentCmsPage() !== null;
    }

    public function getJsonLd(): array
    {
        $page = $this->getCurrentCmsPage();
        if ($page === null) {
            return [];
        }
        $identifier = (string) $page->getIdentifier();
        $keywords = (string) ($page->getMetaKeywords() ?? '');
        $isArticleLike = str_starts_with($identifier, 'blog/')
            || str_starts_with($identifier, 'news/')
            || str_starts_with($identifier, 'articles/')
            || stripos($keywords, 'article') !== false;
        if (!$isArticleLike) {
            return [];
        }

        $base = $this->getBaseUrl();
        $url = $base . ltrim($identifier, '/');
        $headline = (string) $page->getTitle();
        $description = (string) ($page->getMetaDescription() ?? '');
        $body = trim(strip_tags((string) $page->getContent()));

        $node = [
            '@type' => 'Article',
            '@id' => $url . '#article',
            'headline' => $headline !== '' ? $headline : $identifier,
            'description' => $description !== '' ? $description : mb_substr($body, 0, 300),
            'mainEntityOfPage' => $url,
            'url' => $url,
            'author' => ['@id' => $base . '#organization'],
            'publisher' => ['@id' => $base . '#organization'],
            'articleBody' => mb_substr($body, 0, 5000),
        ];

        $datePublished = $this->formatIso8601((string) ($page->getCreationTime() ?? ''));
        if ($datePublished !== '') {
            $node['datePublished'] = $datePublished;
        }
        $dateModified = $this->formatIso8601((string) ($page->getUpdateTime() ?? ''));
        if ($dateModified !== '') {
            $node['dateModified'] = $dateModified;
        }

        return $node;
    }

    /**
     * Format a MySQL datetime string as ISO 8601.
     */
    private function formatIso8601(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($datetime);
            return $dt->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return '';
        }
    }
}
