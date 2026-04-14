<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Observer\FilterUrl;

use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * Observer on catalog_entity_attribute_save_after.
 *
 * Auto-generates rewrite slugs from option labels and inserts them into
 * panth_seo_filter_rewrite if not already present.
 */
class AttributeOptionSave implements ObserverInterface
{
    /** @var string[] Attribute frontend_input types that use selectable options. */
    private const FILTERABLE_INPUT_TYPES = ['select', 'multiselect'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AttributeOptionManagementInterface $optionManagement,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled()) {
                return;
            }

            $attribute = $observer->getEvent()->getData('attribute');
            if ($attribute === null) {
                return;
            }

            $frontendInput = $attribute->getFrontendInput();
            if (!in_array($frontendInput, self::FILTERABLE_INPUT_TYPES, true)) {
                return;
            }

            $attributeCode = $attribute->getAttributeCode();

            $options = $this->optionManagement->getItems(
                \Magento\Catalog\Api\Data\ProductAttributeInterface::ENTITY_TYPE_CODE,
                $attributeCode
            );

            if ($options === null || $options === []) {
                return;
            }

            $connection = $this->resource->getConnection();
            $tableName  = $this->resource->getTableName('panth_seo_filter_rewrite');

            foreach ($options as $option) {
                $optionId = (int) $option->getValue();
                $label    = (string) $option->getLabel();

                if ($optionId === 0 || $label === '') {
                    continue;
                }

                $slug = $this->slugify($label);
                if ($slug === '') {
                    continue;
                }

                // Check if a rewrite already exists for this attribute+option+store(0).
                $exists = $connection->fetchOne(
                    $connection->select()
                        ->from($tableName, ['rewrite_id'])
                        ->where('attribute_code = ?', $attributeCode)
                        ->where('option_id = ?', $optionId)
                        ->where('store_id = ?', 0)
                        ->limit(1)
                );

                if ($exists) {
                    continue;
                }

                $connection->insert($tableName, [
                    'attribute_code' => $attributeCode,
                    'option_id'      => $optionId,
                    'option_label'   => $label,
                    'rewrite_slug'   => $slug,
                    'store_id'       => 0,
                    'is_active'      => 1,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Panth_AdvancedSEO: Failed to auto-generate filter rewrite slugs.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convert a label to a URL-safe slug.
     *
     * "Midnight Blue" => "midnight-blue"
     */
    private function slugify(string $label): string
    {
        $slug = mb_strtolower($label, 'UTF-8');
        // Replace non-alphanumeric chars with hyphens.
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}
