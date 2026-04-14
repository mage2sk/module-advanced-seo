<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Custom column renderer that colour-codes HTTP status codes.
 *
 * 200       => green
 * 301 / 302 => yellow / orange
 * 404       => red
 * 5xx       => red
 */
class StatusCodeColumn extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritDoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $code  = (int) ($item[$fieldName] ?? 0);
            $color = $this->resolveColor($code);
            $item[$fieldName . '_html'] = sprintf(
                '<span style="color:%s;font-weight:600;">%d</span>',
                $color,
                $code
            );
            // Overwrite the field so the HTML body template renders it.
            $item[$fieldName] = $item[$fieldName . '_html'];
        }

        return $dataSource;
    }

    private function resolveColor(int $code): string
    {
        if ($code >= 200 && $code < 300) {
            return '#185b00'; // green
        }

        if ($code >= 300 && $code < 400) {
            return '#b8860b'; // dark-goldenrod (yellow/amber)
        }

        if ($code >= 400 && $code < 500) {
            return '#e22626'; // red
        }

        if ($code >= 500) {
            return '#e22626'; // red
        }

        return '#333333'; // fallback (unknown)
    }
}
