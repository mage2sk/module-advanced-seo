<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class IssuesCountColumn extends Column
{
    private const MAX_VISIBLE_ISSUES = 3;

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
            $raw   = (string) ($item[$fieldName] ?? '');
            $count = $this->countIssues($raw);
            $color = $this->resolveColor($count);
            $label = $count === 0 ? '0' : (string) $count;
            $title = $this->buildTooltip($raw);

            $item[$fieldName] = $this->renderIssues($raw, $count, $color, $label, $title);
        }

        return $dataSource;
    }

    private function renderIssues(string $json, int $count, string $color, string $label, string $tooltip): string
    {
        $labels = $this->decodeIssues($json);
        if ($labels === []) {
            return sprintf(
                '<span style="color:%s;font-weight:600;cursor:default;">%s</span>',
                $color,
                $label
            );
        }

        $shown = array_slice($labels, 0, self::MAX_VISIBLE_ISSUES);
        $badges = '';
        foreach ($shown as $text) {
            $badges .= sprintf(
                '<span style="display:inline-block;margin:1px 2px 1px 0;padding:1px 6px;border-radius:9px;'
                . 'background:#fdf0d5;color:#8a5a00;font-size:11px;white-space:nowrap;">%s</span>',
                htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        }

        $remaining = count($labels) - count($shown);
        if ($remaining > 0) {
            $badges .= sprintf(
                '<span style="color:%s;font-size:11px;font-weight:600;">+%d more</span>',
                $color,
                $remaining
            );
        }

        return sprintf(
            '<span title="%s">%s</span>',
            htmlspecialchars($tooltip, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $badges
        );
    }

    private function decodeIssues(string $json): array
    {
        if ($json === '' || $json === '[]' || $json === 'null') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $decoded
        ), static fn (string $v): bool => $v !== ''));
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
            return '#185b00';
        }

        if ($count <= 3) {
            return '#b8860b';
        }

        return '#e22626';
    }

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
