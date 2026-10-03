<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Cms\Api\Data\PageInterface;

class DescriptionToken implements TokenInterface
{
    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        $text = '';
        if ($entity instanceof ProductInterface) {
            $short = $entity->getCustomAttribute('short_description');
            $desc  = $entity->getCustomAttribute('description');
            $text  = (string) ($short?->getValue() ?? $desc?->getValue() ?? '');
        } elseif ($entity instanceof CategoryInterface) {
            $text = (string) $entity->getDescription();
        } elseif ($entity instanceof PageInterface) {
            $text = (string) ($entity->getMetaDescription() ?: $entity->getContentHeading());
        } elseif (is_object($entity) && method_exists($entity, 'getDescription')) {
            $text = (string) $entity->getDescription();
        }
        if ($text === '') {
            return '';
        }
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
