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
                return;
            }

            if (!$isNew && $hasManualKey && !$this->config->isAutoUrlKeyForExisting($storeId)) {
                return;
            }

            if (!$isNew && !$this->config->isAutoUrlKeyForExisting($storeId)) {
                return;
            }

            $template = $this->config->getUrlKeyTemplate($storeId);
            if ($template === '') {
                return;
            }

            $rendered = $this->renderer->render($template, $product);
            $slug = $this->slugify($rendered);

            if ($slug === '') {
                return;
            }

            $slug = $this->ensureUniqueSlug($slug, $product, $storeId);

            $product->setUrlKey($slug);
        } catch (\Throwable $e) {
            $this->logger->warning('Panth SEO auto URL key generation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

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

        $origKey = (string) $product->getOrigData('url_key');
        if ($origKey !== '' && $origKey !== $key && $key === $nameSlug) {
            return false;
        }

        return true;
    }

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

    private function slugify(string $text): string
    {
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate(
                'Any-Latin; Latin-ASCII; Lower()',
                $text
            ) ?: $text;
        }

        $text = mb_strtolower($text, 'UTF-8');

        $text = (string) preg_replace('/[^a-z0-9\-]+/', '-', $text);

        $text = (string) preg_replace('/-{2,}/', '-', $text);

        return trim($text, '-');
    }
}
