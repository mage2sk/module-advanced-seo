<?php

declare(strict_types=1);

namespace Panth\AdvancedSEO\Plugin\Admin;

use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * Injects an SEO score widget into the product edit form.
 *
 * The widget displays a letter grade (A-F), numeric score (0-100), and a
 * breakdown of detected issues. It reads pre-computed data from the
 * `panth_seo_score` table and renders as an htmlContent UI component inside
 * the "search-engine-optimization" fieldset.
 */
class SeoScoreWidgetPlugin
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly SeoConfig $seoConfig
    ) {
    }

    /**
     * @param ProductDataProvider   $subject
     * @param array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(ProductDataProvider $subject, array $result): array
    {
        if (!$this->seoConfig->isEnabled()) {
            return $result;
        }

        $productId = (int) $this->request->getParam('id', 0);
        $storeId   = (int) $this->request->getParam('store', 0);

        $html = $this->buildScoreHtml($productId, $storeId, 'product');

        $result['search-engine-optimization']['children']['panth_seo_score'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'container',
                        'component'     => 'Magento_Ui/js/form/components/html',
                        'content'       => $html,
                        'sortOrder'     => 4,
                        'additionalClasses' => 'panth-seo-score-widget',
                    ],
                ],
            ],
        ];

        return $result;
    }

    /**
     * Build the full HTML block for the SEO score widget.
     */
    private function buildScoreHtml(int $entityId, int $storeId, string $entityType): string
    {
        if ($entityId === 0) {
            return $this->renderNotScoredHtml();
        }

        $row = $this->fetchScore($entityId, $storeId, $entityType);

        if ($row === null) {
            return $this->renderNotScoredHtml();
        }

        $score  = (int) $row['score'];
        $grade  = (string) $row['grade'];
        $issues = $this->decodeIssues((string) ($row['issues'] ?? ''));

        return $this->renderScoreHtml($grade, $score, $issues);
    }

    /**
     * Fetch the most recent score row for the given entity.
     *
     * @return array<string, mixed>|null
     */
    private function fetchScore(int $entityId, int $storeId, string $entityType): ?array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table      = $this->resourceConnection->getTableName('panth_seo_score');

            $select = $connection->select()
                ->from($table, ['score', 'grade', 'issues', 'breakdown'])
                ->where('entity_type = ?', $entityType)
                ->where('entity_id = ?', $entityId)
                ->where('store_id = ?', $storeId)
                ->limit(1);

            $row = $connection->fetchRow($select);

            return $row !== false ? $row : null;
        } catch (\Exception $e) {
            $this->logger->error(
                '[Panth_AdvancedSEO] Failed to load SEO score for widget',
                ['entity_type' => $entityType, 'entity_id' => $entityId, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Decode the JSON issues column into a string list.
     *
     * @return list<string>
     */
    private function decodeIssues(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * Map a letter grade to its display colour.
     */
    private function gradeColor(string $grade): string
    {
        return match (strtoupper($grade)) {
            'A' => '#2e7d32',
            'B' => '#1565c0',
            'C' => '#f9a825',
            'D' => '#ef6c00',
            default => '#c62828',
        };
    }

    /**
     * Render the "not scored yet" placeholder.
     */
    private function renderNotScoredHtml(): string
    {
        return '<div style="'
            . 'padding:16px 20px;'
            . 'margin:12px 0;'
            . 'background:#fafafa;'
            . 'border:1px solid #e0e0e0;'
            . 'border-radius:6px;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;'
            . 'font-size:14px;'
            . 'color:#757575;'
            . 'line-height:1.5;'
            . '">'
            . '<strong style="color:#424242;">SEO Score</strong><br>'
            . 'Not scored yet &mdash; save product to generate score.'
            . '</div>';
    }

    /**
     * Render the full score widget with grade circle, numeric score, and issue list.
     *
     * @param list<string> $issues
     */
    private function renderScoreHtml(string $grade, int $score, array $issues): string
    {
        $color       = $this->gradeColor($grade);
        $safeGrade   = htmlspecialchars(strtoupper($grade), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $issueMarkup = $this->renderIssueList($issues);

        $containerStyle = implode('', [
            'padding:16px 20px;',
            'margin:12px 0;',
            'background:#fafafa;',
            'border:1px solid #e0e0e0;',
            'border-radius:6px;',
            'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;',
            'line-height:1.5;',
        ]);

        $headerStyle = implode('', [
            'display:flex;',
            'align-items:center;',
            'gap:16px;',
            'margin-bottom:' . ($issues !== [] ? '12px;' : '0;'),
        ]);

        $circleStyle = implode('', [
            'display:flex;',
            'align-items:center;',
            'justify-content:center;',
            'width:52px;',
            'height:52px;',
            'border-radius:50%;',
            'background:' . $color . ';',
            'color:#fff;',
            'font-size:22px;',
            'font-weight:700;',
            'flex-shrink:0;',
        ]);

        $scoreTextStyle = implode('', [
            'font-size:14px;',
            'color:#424242;',
        ]);

        $scoreLabelStyle = implode('', [
            'font-size:12px;',
            'color:#757575;',
            'margin-top:2px;',
        ]);

        $numericStyle = implode('', [
            'font-size:28px;',
            'font-weight:700;',
            'color:' . $color . ';',
        ]);

        return '<div style="' . $containerStyle . '">'
            . '<div style="' . $headerStyle . '">'
            .   '<div style="' . $circleStyle . '">' . $safeGrade . '</div>'
            .   '<div>'
            .     '<div style="' . $scoreTextStyle . '">'
            .       '<span style="' . $numericStyle . '">' . $score . '</span>'
            .       '<span style="font-size:14px;color:#757575;"> / 100</span>'
            .     '</div>'
            .     '<div style="' . $scoreLabelStyle . '">SEO Score</div>'
            .   '</div>'
            . '</div>'
            . $issueMarkup
            . '</div>';
    }

    /**
     * Render the issues list, or nothing when the list is empty.
     *
     * @param list<string> $issues
     */
    private function renderIssueList(array $issues): string
    {
        if ($issues === []) {
            return '';
        }

        $separatorStyle = 'border:none;border-top:1px solid #e0e0e0;margin:0 0 10px 0;';
        $headingStyle   = 'font-size:13px;font-weight:600;color:#424242;margin:0 0 6px 0;';
        $listStyle      = 'margin:0;padding:0 0 0 20px;font-size:13px;color:#616161;';
        $itemStyle      = 'margin:0 0 4px 0;';

        $items = '';
        foreach ($issues as $issue) {
            $safe   = htmlspecialchars($issue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $items .= '<li style="' . $itemStyle . '">' . $safe . '</li>';
        }

        return '<hr style="' . $separatorStyle . '">'
            . '<div style="' . $headingStyle . '">Issues</div>'
            . '<ul style="' . $listStyle . '">' . $items . '</ul>';
    }
}
