<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Column renderer that displays "MISSING" in red when the cell value is empty.
 *
 * Used in the Missing Meta Report grid for meta_title and meta_description.
 */
class MissingMetaHighlight extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $value = trim((string) ($item[$fieldName] ?? ''));
            if ($value === '') {
                $item[$fieldName] = '<span style="color:#e22626;font-weight:600;">MISSING</span>';
            } else {
                $item[$fieldName] = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $dataSource;
    }
}
