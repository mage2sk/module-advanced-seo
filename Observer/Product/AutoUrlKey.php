<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Meta\TemplateRenderer;
use Psr\Log\LoggerInterface;

/**
 * Auto-generates a URL key from a configurable template when a product is
 * created (or when the "apply to existing" flag is set and the product is
 * saved without a manually entered url_key).
 *
 * Listens to `catalog_product_save_before`.
 */
class AutoUrlKey implements ObserverInterface
{
    public function __construct(
        private readonly SeoConfig $config,
        private readonly TemplateRenderer $renderer,
        private readonly LoggerInterface $logger,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            /** @var Product|null $product */
            $product = $observer->getEvent()->getProduct();
            if ($product === null) {
                return;
            }

            $storeId = (int) $product->getStoreId();

            if (!$this->config->isEnabled($storeId)) {
                return;
            }

            if (!$this->config->isAutoUrlKeyEnabled($storeId)) {
                return;
            }

            $isNew = !$product->getId();
            $hasManualKey = $this->hasManualUrlKey($product);

            if ($isNew && $hasManualKey) {
                return; // Merchant typed a url_key explicitly — respect it.
            }

            if (!$isNew && $hasManualKey && !$this->config->isAutoUrlKeyForExisting($storeId)) {
                return; // Existing product with a key, and "apply to existing" is off.
            }

            if (!$isNew && !$this->config->isAutoUrlKeyForExisting($storeId)) {
                return; // Existing product but feature not enabled for existing.
            }

            $template = $this->config->getUrlKeyTemplate($storeId);
            if ($template === '') {
                return;
            }

            $rendered = $this->renderer->render($template, $product);
            $slug = $this->slugify($rendered);

            if ($slug === '') {
                return; // Template resolved to nothing useful — do not overwrite.
            }

            // Ensure uniqueness against existing url_rewrites for this store.
            $slug = $this->ensureUniqueSlug($slug, $product, $storeId);

            $product->setUrlKey($slug);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO auto URL key generation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine whether the product already carries a manually set url_key.
     *
     * Because `Magento\CatalogUrlRewrite\Observer\ProductUrlKeyAutogeneratorObserver`
     * runs on the same `catalog_product_save_before` event and pre-populates
     * `url_key` from the product name, a non-empty `url_key` is NOT a reliable
     * signal of a merchant-typed value. We compare the current url_key to the
     * slug that would have been generated from the product name — if they
     * match, the merchant did not type anything manually.
     */
    private function hasManualUrlKey(Product $product): bool
    {
        $key = (string) $product->getData('url_key');
        if ($key === '') {
            return false;
        }

        $nameSlug = $this->slugify((string) $product->getName());
        if ($nameSlug !== '' && $key === $nameSlug) {
            return false;
        }

        // For existing products: if the stored url_key equals the name-slug,
        // Magento may have just re-derived it from name; treat as non-manual.
        $origKey = (string) $product->getOrigData('url_key');
        if ($origKey !== '' && $origKey !== $key && $key === $nameSlug) {
            return false;
        }

        return true;
    }

    /**
     * Ensure the target slug does not collide with an existing product's
     * url_key. If it collides, append `-1`, `-2`, ... until unique. Mirrors
     * Magento's native collision handling for merchant-typed url_keys which
     * otherwise throws "URL key for specified store already exists".
     */
    private function ensureUniqueSlug(string $slug, Product $product, int $storeId): string
    {
        try {
            $productId = (int) $product->getId();
            $candidate = $slug;
            $suffix = 0;

            while ($suffix < 50) {
                $criteria = $this->searchCriteriaBuilder
                    ->addFilter('url_key', $candidate, 'eq')
                    ->create();
                $result = $this->productRepository->getList($criteria);
                $total = (int) $result->getTotalCount();

                $conflict = false;
                if ($total === 0) {
                    return $candidate;
                }
                foreach ($result->getItems() as $other) {
                    if ((int) $other->getId() !== $productId) {
                        $conflict = true;
                        break;
                    }
                }
                if (!$conflict) {
                    return $candidate;
                }
                $suffix++;
                $candidate = $slug . '-' . $suffix;
            }
            return $candidate;
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO unique slug check failed', [
                'error' => $e->getMessage(),
            ]);
            return $slug;
        }
    }

    /**
     * Convert an arbitrary string into a URL-safe slug.
     *
     * Steps: transliterate -> lowercase -> replace non-alphanumeric with
     * hyphens -> collapse consecutive hyphens -> trim leading/trailing hyphens.
     */
    private function slugify(string $text): string
    {
        // Transliterate to ASCII when possible.
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate(
                'Any-Latin; Latin-ASCII; Lower()',
                $text
            ) ?: $text;
        }

        $text = mb_strtolower($text, 'UTF-8');

        // Replace any character that is not a-z, 0-9, or hyphen with a hyphen.
        $text = (string) preg_replace('/[^a-z0-9\-]+/', '-', $text);

        // Collapse consecutive hyphens.
        $text = (string) preg_replace('/-{2,}/', '-', $text);

        return trim($text, '-');
    }
}
