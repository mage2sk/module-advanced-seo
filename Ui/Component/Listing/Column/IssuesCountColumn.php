<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Custom column that parses the issues_json field and shows the count of issues
 * with colour indication:
 *
 * 0 issues   => green
 * 1-3 issues => yellow / amber
 * >3 issues  => red
 */
class IssuesCountColumn extends Column
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
            $raw   = (string) ($item[$fieldName] ?? '');
            $count = $this->countIssues($raw);
            $color = $this->resolveColor($count);
            $label = $count === 0 ? '0' : (string) $count;
            $title = $this->buildTooltip($raw);

            $item[$fieldName] = sprintf(
                '<span style="color:%s;font-weight:600;cursor:default;" title="%s">%s</span>',
                $color,
                htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $label
            );
        }

        return $dataSource;
    }

    private function countIssues(string $json): int
    {
        if ($json === '' || $json === '[]' || $json === 'null') {
            return 0;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 0;
        }

        return is_array($decoded) ? count($decoded) : 0;
    }

    private function resolveColor(int $count): string
    {
        if ($count === 0) {
            return '#185b00'; // green
        }

        if ($count <= 3) {
            return '#b8860b'; // dark-goldenrod (yellow/amber)
        }

        return '#e22626'; // red
    }

    /**
     * Build a tooltip string listing the individual issues.
     */
    private function buildTooltip(string $json): string
    {
        if ($json === '' || $json === '[]' || $json === 'null') {
            return 'No issues';
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'Invalid JSON';
        }

        if (!is_array($decoded) || $decoded === []) {
            return 'No issues';
        }

        return implode("\n", array_map(static fn($v): string => '- ' . (string) $v, $decoded));
    }
}
