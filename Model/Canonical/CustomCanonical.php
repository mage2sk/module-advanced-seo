<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Canonical;

use Magento\Framework\Model\AbstractModel;
use Panth\AdvancedSEO\Model\ResourceModel\CustomCanonical as CustomCanonicalResource;

/**
 * Custom canonical URL mapping model.
 *
 * Allows administrators to override the auto-generated canonical URL for any
 * entity (product, category, CMS page) with a hand-picked target URL or a
 * reference to another entity.
 */
class CustomCanonical extends AbstractModel
{
    protected $_idFieldName = 'canonical_id';

    protected function _construct(): void
    {
        $this->_init(CustomCanonicalResource::class);
    }
}
