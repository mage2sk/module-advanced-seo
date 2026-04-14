<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Helper\Config;

/**
 * Emits a BreadcrumbList derived from the current category tree, or for
 * products from the primary (highest-level) assigned category.
 */
class BreadcrumbProvider extends AbstractProvider
{
    public function __construct(
        Registry $registry,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($registry, $request, $storeManager, $config);
    }

    public function getCode(): string
    {
        return 'breadcrumb';
    }

    public function getJsonLd(): array
    {
        $items = $this->buildItems();
        if (count($items) < 2) {
            return [];
        }
        $base = $this->getBaseUrl();
        return [
            '@type' => 'BreadcrumbList',
            '@id'   => $base . '#breadcrumb-' . sha1((string) $this->request->getPathInfo()),
            'itemListElement' => $items,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildItems(): array
    {
        $base = $this->getBaseUrl();
        $list = [];
        $pos = 1;
        $list[] = [
            '@type' => 'ListItem',
            'position' => $pos++,
            'name' => 'Home',
            'item' => $base,
        ];

        $category = $this->getCurrentCategory();
        $product = $this->getCurrentProduct();

        if ($product !== null && $category === null) {
            $ids = $product->getCategoryIds();
            if (is_array($ids) && $ids !== []) {
                try {
                    $category = $this->pickPrimaryCategory($ids);
                } catch (\Throwable) {
                    $category = null;
                }
            }
        }

        if ($category !== null) {
            $pathIds = array_filter(explode('/', (string) $category->getPath()));
            foreach ($pathIds as $id) {
                if ((int) $id <= 2) {
                    continue; // skip root ids
                }
                try {
                    $node = $this->categoryRepository->get((int) $id);
                } catch (NoSuchEntityException) {
                    continue;
                } catch (\Throwable) {
                    continue;
                }
                $list[] = [
                    '@type' => 'ListItem',
                    'position' => $pos++,
                    'name' => (string) $node->getName(),
                    'item' => (string) $node->getUrl(),
                ];
            }
        }

        if ($product !== null) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => (string) $product->getName(),
                'item' => (string) $product->getProductUrl(),
            ];
        }

        $cmsPage = $this->getCurrentCmsPage();
        if ($cmsPage !== null && $product === null && $category === null) {
            $list[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'name' => (string) $cmsPage->getTitle(),
                'item' => $base . ltrim((string) $cmsPage->getIdentifier(), '/'),
            ];
        }

        return $list;
    }

    /**
     * @param int[] $ids
     */
    private function pickPrimaryCategory(array $ids): ?\Magento\Catalog\Api\Data\CategoryInterface
    {
        $priorityEnabled = $this->scopeConfig->isSetFlag(
            Config::XML_BREADCRUMBS_PRIORITY_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
        $format = (string) $this->scopeConfig->getValue(
            Config::XML_BREADCRUMBS_FORMAT,
            ScopeInterface::SCOPE_STORE
        );

        $candidates = [];
        foreach ($ids as $id) {
            try {
                $cat = $this->categoryRepository->get((int) $id);
            } catch (\Throwable) {
                continue;
            }
            if ((int) $cat->getLevel() < 2) {
                continue;
            }
            $weight = 0;
            if ($priorityEnabled) {
                $weight = (int) ($cat->getCustomAttribute('breadcrumbs_priority')?->getValue() ?? 0);
            }
            $candidates[] = [
                'cat'    => $cat,
                'level'  => (int) $cat->getLevel(),
                'weight' => $weight,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b) use ($priorityEnabled, $format): int {
            if ($priorityEnabled) {
                $weightCmp = $b['weight'] <=> $a['weight'];
                if ($weightCmp !== 0) {
                    return $weightCmp;
                }
                return match ($format) {
                    'shortest' => $a['level'] <=> $b['level'],
                    default    => $b['level'] <=> $a['level'],
                };
            }
            // Default legacy behavior: deepest category wins.
            return $b['level'] <=> $a['level'];
        });

        return $candidates[0]['cat'];
    }
}
