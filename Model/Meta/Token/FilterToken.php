<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Meta\Token;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;

/**
 * {{filter:color}} -- resolves to the active layered-navigation value for the given attribute.
 *
 * If no filter is currently active for the requested attribute, returns an empty string.
 */
class FilterToken implements TokenInterface
{
    public function __construct(
        private readonly LayerResolver $layerResolver
    ) {
    }

    public function getValue(mixed $entity, array $context, ?string $argument = null): string
    {
        if ($argument === null || $argument === '') {
            return '';
        }

        $attributeCode = preg_replace('/[^a-z0-9_]/i', '', $argument) ?? '';
        if ($attributeCode === '') {
            return '';
        }

        try {
            $layer = $this->layerResolver->get();
            $state = $layer->getState();
            foreach ($state->getFilters() as $filterItem) {
                $requestVar = $filterItem->getFilter()->getRequestVar();
                if ($requestVar === $attributeCode) {
                    $label = $filterItem->getLabel();
                    if (is_array($label)) {
                        return implode(', ', array_map('strval', $label));
                    }
                    return (string) $label;
                }
            }
        } catch (\Throwable) {
            // Layer not initialised or no active state -- return empty
        }

        return '';
    }
}
