<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\Admin;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Framework\App\RequestInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Plugin\Admin\CategorySeoScorePlugin;
use Panth\AdvancedSEO\Plugin\Admin\SeoScoreWidgetPlugin;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScoreWidgetPluginsTest extends TestCase
{
    use ConnectionRecorder;

    private array $errors = [];

    private function meta(
        string $pluginClass,
        string $subjectClass,
        int $id,
        array|false $row = false,
        array $result = [],
        bool $enabled = true,
        ?\Throwable $fails = null
    ): array {
        $this->errors = [];
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn($key, $default = null) => ['id' => $id, 'store' => 2][$key] ?? $default
        );
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->errors[] = [$message, $context];
        });
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        $plugin = new $pluginClass($request, $this->recordingResource([], [], $fails, [$row]), $logger, $config);

        return $plugin->afterGetMeta($this->createStub($subjectClass), $result);
    }

    private static function content(array $meta, string $group = 'search-engine-optimization'): string
    {
        return $meta[$group]['children']['panth_seo_score']['arguments']['data']['config']['content'];
    }

    public static function plugins(): array
    {
        return [
            'product'  => [SeoScoreWidgetPlugin::class, ProductDataProvider::class, 'product'],
            'category' => [CategorySeoScorePlugin::class, CategoryDataProvider::class, 'category'],
        ];
    }

    #[DataProvider('plugins')]
    public function testScoreGradeAndEscapedIssuesAreRendered(string $plugin, string $subject, string $type): void
    {
        $html = self::content($this->meta($plugin, $subject, 5, [
            'score' => '72', 'grade' => 'b', 'issues' => json_encode(['Title <too> long', 3, 'Missing alt']),
        ]));

        $this->assertStringContainsString('>72</span>', $html);
        $this->assertStringContainsString('#1565c0', $html);
        $this->assertStringContainsString('>B</div>', $html);
        $this->assertStringContainsString('<li style="margin:0 0 4px 0;">Title &lt;too&gt; long</li>', $html);
        $this->assertStringContainsString('Missing alt', $html);
        $this->assertSame(2, substr_count($html, '<li '));
        $this->assertContains(['entity_type = ?', $type], $this->db['where']);
        $this->assertContains(['store_id = ?', 2], $this->db['where']);
    }

    #[DataProvider('plugins')]
    public function testUnsavedOrUnscoredEntitiesShowAPlaceholder(string $plugin, string $subject, string $type): void
    {
        $this->assertStringContainsString('save ' . $type . ' to generate score', self::content($this->meta($plugin, $subject, 0)));
        $this->assertSame([], $this->db['where']);

        $this->assertStringContainsString('Not scored yet', self::content($this->meta($plugin, $subject, 5)));
    }

    #[DataProvider('plugins')]
    public function testQueryFailuresAreLoggedAndShowThePlaceholder(string $plugin, string $subject, string $type): void
    {
        $html = self::content($this->meta($plugin, $subject, 5, false, [], true, new \RuntimeException('no table')));

        $this->assertStringContainsString('Not scored yet', $html);
        $this->assertSame(['entity_type' => $type, 'entity_id' => 5, 'exception' => 'no table'], $this->errors[0][1]);
    }

    #[DataProvider('plugins')]
    public function testDisabledModuleLeavesMetaAlone(string $plugin, string $subject, string $type): void
    {
        $this->assertSame([$type => 1], $this->meta($plugin, $subject, 5, false, [$type => 1], false));
    }

    public static function grades(): array
    {
        return [
            ['A', '#2e7d32'],
            [' c ', '#f9a825'],
            ['D', '#ef6c00'],
            ['F', '#c62828'],
        ];
    }

    #[DataProvider('grades')]
    public function testGradeColours(string $grade, string $colour): void
    {
        $html = self::content($this->meta(SeoScoreWidgetPlugin::class, ProductDataProvider::class, 5, [
            'score' => 50, 'grade' => $grade, 'issues' => '',
        ]));

        $this->assertStringContainsString('background:' . $colour, $html);
        $this->assertStringNotContainsString('<ul', $html);
    }

    public function testInvalidIssueJsonIsIgnored(): void
    {
        $html = self::content($this->meta(SeoScoreWidgetPlugin::class, ProductDataProvider::class, 5, [
            'score' => 50, 'grade' => 'A', 'issues' => '{broken',
        ]));

        $this->assertStringNotContainsString('<ul', $html);
    }

    public function testCategoryWidgetUsesTheUnderscoredGroupWhenPresent(): void
    {
        $meta = $this->meta(CategorySeoScorePlugin::class, CategoryDataProvider::class, 0, false, ['search_engine_optimization' => []]);

        $this->assertStringContainsString('Not scored yet', self::content($meta, 'search_engine_optimization'));
        $this->assertArrayNotHasKey('search-engine-optimization', $meta);
    }
}
