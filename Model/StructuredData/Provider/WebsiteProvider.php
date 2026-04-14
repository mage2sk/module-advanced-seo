<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

/**
 * Emits a WebSite node with a SearchAction so Google can show sitelinks
 * search boxes. Uses Magento's on-site search endpoint.
 */
class WebsiteProvider extends AbstractProvider
{
    public function getCode(): string
    {
        return 'website';
    }

    public function getJsonLd(): array
    {
        try {
            $store = $this->storeManager->getStore();
        } catch (\Throwable) {
            return [];
        }
        $url = rtrim($store->getBaseUrl(), '/') . '/';
        $name = $store->getName() ?: 'Store';

        return [
            '@type' => 'WebSite',
            '@id'   => $url . '#website',
            'name'  => $name,
            'url'   => $url,
            'publisher' => ['@id' => $url . '#organization'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $url . 'catalogsearch/result/?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }
}
