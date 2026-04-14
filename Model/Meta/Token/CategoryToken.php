<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * {{category}} → primary category name of product, or name for category entity.
 */
class CategoryToken implements TokenInterface
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
    }

    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if ($entity instanceof CategoryInterface) {
            return (string) $entity->getName();
        }
        if ($entity instanceof ProductInterface) {
            $ids = $entity->getCategoryIds();
            if (!is_array($ids) || $ids === []) {
                return '';
            }
            $storeId = (int) ($context['store_id'] ?? 0);
            foreach ($ids as $id) {
                try {
                    $cat = $this->categoryRepository->get((int) $id, $storeId ?: null);
                    if ($cat->getLevel() >= 2) {
                        return (string) $cat->getName();
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        return '';
    }
}
