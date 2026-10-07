<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class DeliveryType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'ftp',  'label' => __('FTP')],
            ['value' => 'sftp', 'label' => __('SFTP')],
        ];
    }
}
