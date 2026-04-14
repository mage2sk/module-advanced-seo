<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\StructuredData\Provider;

/**
 * Emits `positiveNotes` / `negativeNotes` structured data on product pages.
 *
 * Reads from configurable product attributes (default: `product_pros` and
 * `product_cons`) containing one item per line. Outputs ItemList nodes
 * per the Google 2025+ Pros/Cons structured data spec.
 *
 * Only fires when:
 *  - We are on a product page (current_product exists)
 *  - At least one pro or con line is present
 *  - Config flag `panth_seo/structured_data/pros_cons_enabled` is enabled
 */
class ProsConsProvider extends AbstractProvider
{
    public function getCode(): string
    {
        return 'pros_cons';
    }

    public function isApplicable(): bool
    {
        if ($this->getCurrentProduct() === null) {
            return false;
        }

        return $this->config->isStructuredDataEnabled('pros_cons_enabled');
    }

    public function getJsonLd(): array
    {
        $product = $this->getCurrentProduct();
        if ($product === null) {
            return [];
        }

        $prosAttr = $this->getProsAttribute();
        $consAttr = $this->getConsAttribute();

        $pros = $this->parseLines((string) $product->getData($prosAttr));
        $cons = $this->parseLines((string) $product->getData($consAttr));

        if ($pros === [] && $cons === []) {
            return [];
        }

        $url = (string) $product->getProductUrl();

        $node = [
            '@type' => 'Product',
            '@id'   => $url . '#product',
        ];

        if ($pros !== []) {
            $node['positiveNotes'] = $this->buildItemList($pros);
        }

        if ($cons !== []) {
            $node['negativeNotes'] = $this->buildItemList($cons);
        }

        return $node;
    }

    /**
     * Parse a textarea value into trimmed, non-empty lines.
     *
     * @return string[]
     */
    private function parseLines(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/\r?\n/', $raw);
        if ($lines === false) {
            return [];
        }

        $result = [];
        foreach ($lines as $line) {
            $line = trim(strip_tags($line));
            if ($line !== '') {
                $result[] = $line;
            }
        }

        return $result;
    }

    /**
     * Build an ItemList node with ListItem elements.
     *
     * @param  string[] $items
     * @return array<string,mixed>
     */
    private function buildItemList(array $items): array
    {
        $elements = [];
        $position = 1;

        foreach ($items as $item) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => $item,
            ];
            $position++;
        }

        return [
            '@type'           => 'ItemList',
            'itemListElement' => $elements,
        ];
    }

    private function getProsAttribute(): string
    {
        $value = (string) ($this->config->getValue(
            'panth_seo/structured_data/pros_attribute'
        ) ?? '');

        return $value !== '' ? $value : 'product_pros';
    }

    private function getConsAttribute(): string
    {
        $value = (string) ($this->config->getValue(
            'panth_seo/structured_data/cons_attribute'
        ) ?? '');

        return $value !== '' ? $value : 'product_cons';
    }
}
