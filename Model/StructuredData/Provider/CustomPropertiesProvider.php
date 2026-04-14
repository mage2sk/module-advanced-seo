<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;
use Psr\Log\LoggerInterface;

/**
 * Merges custom JSON properties (configured in admin) into the Product
 * structured-data node.
 *
 * Config path: panth_seo/structured_data/custom_properties (textarea, JSON).
 * Example value: {"brand":{"@type":"Brand","name":"MyBrand"},"material":"Cotton"}
 *
 * Active only on product pages and only when the textarea contains valid JSON.
 */
class CustomPropertiesProvider extends AbstractProvider
{
    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'custom_properties';
    }

    public function isApplicable(): bool
    {
        return $this->getCurrentProduct() !== null;
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return [];
        }

        $raw = trim((string) ($this->config->getValue(Config::XML_SD_CUSTOM_PROPERTIES) ?? ''));
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(
                sprintf(
                    '[Panth_AdvancedSEO] Invalid JSON in custom_properties config: %s',
                    $e->getMessage()
                )
            );
            return [];
        }

        if (!is_array($decoded) || $decoded === []) {
            return [];
        }

        $url = (string) $product->getProductUrl();

        // Return a Product node so the Aggregator deep-merges these
        // properties into the existing Product node emitted by ProductProvider.
        return array_merge(
            [
                '@type' => 'Product',
                '@id'   => $url . '#product',
            ],
            $decoded
        );
    }
}
