<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;

/**
 * {{attribute:color}} — fetches attribute value (admin label) from product.
 */
class AttributeToken implements TokenInterface
{
    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if ($argument === null || $argument === '') {
            return '';
        }
        $code = preg_replace('/[^a-z0-9_]/i', '', $argument) ?? '';
        if ($code === '') {
            return '';
        }

        if ($entity instanceof ProductInterface) {
            $attr = $entity->getCustomAttribute($code);
            if ($attr !== null) {
                $raw = $attr->getValue();
            } else {
                $raw = method_exists($entity, 'getData') ? $entity->getData($code) : null;
            }
            if ($raw === null || $raw === '') {
                return '';
            }
            // Resolve option label when possible.
            if (method_exists($entity, 'getResource')) {
                try {
                    $resource = $entity->getResource();
                    if ($resource && method_exists($resource, 'getAttribute')) {
                        $attribute = $resource->getAttribute($code);
                        if ($attribute && $attribute->usesSource()) {
                            $label = $attribute->getSource()->getOptionText($raw);
                            if (is_array($label)) {
                                $label = implode(', ', array_map('strval', $label));
                            }
                            if (is_string($label) && $label !== '') {
                                return $label;
                            }
                        }
                    }
                } catch (\Throwable) {
                    // fall through
                }
            }
            return is_scalar($raw) ? (string) $raw : '';
        }

        if ($entity instanceof DataObject) {
            $v = $entity->getData($code);
            return is_scalar($v) ? (string) $v : '';
        }

        if (is_array($entity) && isset($entity[$code]) && is_scalar($entity[$code])) {
            return (string) $entity[$code];
        }

        return '';
    }
}
