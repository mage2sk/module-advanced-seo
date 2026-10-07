<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\Column;

use Magento\Store\Ui\Component\Listing\Column\Store;

class StoreView extends Store
{
    protected function prepareItem(array $item)
    {
        if (isset($item[$this->storeKey]) && !is_array($item[$this->storeKey])) {
            $item[$this->storeKey] = [(string) $item[$this->storeKey]];
        }

        return parent::prepareItem($item);
    }
}
