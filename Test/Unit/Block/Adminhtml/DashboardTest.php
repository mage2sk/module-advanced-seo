<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\UrlInterface;
use Panth\AdvancedSEO\Block\Adminhtml\Dashboard;
use Panth\AdvancedSEO\Test\Unit\Block\BackendTemplateObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DashboardTest extends TestCase
{
    use BackendTemplateObjectManager;

    private array $queries = [];

    private function block(array $answers = [], array $missingTables = [], array $modules = [], bool $fails = false): Dashboard
    {
        $this->queries = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturnCallback(
            static fn(string $table): bool => !in_array($table, $missingTables, true)
        );
        $connection->method('fetchOne')->willReturnCallback(function ($sql, $bind = []) use ($answers, $fails) {
            if ($fails) {
                throw new \RuntimeException('db');
            }
            $this->queries[] = [$sql, $bind];
            foreach ($answers as $needle => $value) {
                if (str_contains((string) $sql . '|' . implode(',', (array) $bind), $needle)) {
                    return $value;
                }
            }
            return false;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(static function (string $name) use ($modules): bool {
            if (($modules[$name] ?? null) === 'throw') {
                throw new \RuntimeException('module list');
            }
            return (bool) ($modules[$name] ?? false);
        });

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn($route = null, $params = []) => '/' . $route . ($params ? '?' . http_build_query($params) : ''));
        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($url);

        return new Dashboard($context, $resource, $moduleManager);
    }

    public function testCatalogOverviewSubtractsEntitiesWithMeta(): void
    {
        $overview = $this->block([
            'FROM catalog_product_entity_varchar'           => '7',
            'FROM catalog_product_entity_text'              => '4',
            'SELECT COUNT(*) FROM catalog_product_entity'   => '10',
            'FROM catalog_category_entity_varchar'          => '2',
            'SELECT COUNT(*) FROM catalog_category_entity'  => '5',
            'backend_type'                                  => 'text',
            'a.attribute_id'                                => '83',
            'FROM cms_page'                                 => '3',
        ])->getCatalogOverview();

        $this->assertSame([
            'total_products'           => 10,
            'products_missing_title'   => 3,
            'products_missing_desc'    => 6,
            'total_categories'         => 5,
            'categories_missing_title' => 3,
            'categories_missing_desc'  => 5,
            'total_cms'                => 3,
            'products_missing_meta'    => 7,
            'categories_missing_meta'  => 2,
        ], $overview);
    }

    public function testMissingMetaCountsEntitiesWithoutTitleOrDescriptionInTheDefaultScope(): void
    {
        $overview = $this->block([
            'NOT EXISTS (SELECT 1 FROM catalog_product_entity_varchar' => '9',
            'NOT EXISTS (SELECT 1 FROM catalog_category_entity_varchar' => '4',
            'FROM catalog_product_entity_varchar'           => '7',
            'SELECT COUNT(*) FROM catalog_product_entity'   => '10',
            'SELECT COUNT(*) FROM catalog_category_entity'  => '5',
            'a.attribute_id'                                => '83',
        ])->getCatalogOverview();

        $this->assertSame(9, $overview['products_missing_meta']);
        $this->assertSame(4, $overview['categories_missing_meta']);
        $sql = implode("\n", array_column($this->queries, 0));
        $this->assertStringContainsString('t.store_id = 0', $sql);
        $this->assertStringContainsString('AND e.level > 1', $sql);
    }

    public function testMissingAttributesCountEverythingAsMissing(): void
    {
        $overview = $this->block([
            'SELECT COUNT(*) FROM catalog_product_entity'  => '4',
            'SELECT COUNT(*) FROM catalog_category_entity' => '2',
        ])->getCatalogOverview();

        $this->assertSame(4, $overview['products_missing_title']);
        $this->assertSame(4, $overview['products_missing_desc']);
        $this->assertSame(2, $overview['categories_missing_title']);
        $this->assertSame(2, $overview['categories_missing_desc']);
    }

    public function testCatalogOverviewIsZeroWhenTablesAreMissingOrQueriesFail(): void
    {
        $overview = $this->block([], ['catalog_product_entity', 'catalog_category_entity', 'cms_page'])->getCatalogOverview();
        $this->assertSame(0, array_sum($overview));

        $this->assertSame(0, array_sum($this->block([], [], [], true)->getCatalogOverview()));
    }

    public function testModuleStatsCountExistingTables(): void
    {
        $stats = $this->block([
            'panth_seo_template WHERE is_active = 1' => '4',
            'panth_seo_rule WHERE is_active = 1' => '2',
            'panth_seo_custom_canonical'  => '9',
        ], ['panth_seo_hreflang_group'])->getModuleStats();

        $this->assertSame(['templates' => 4, 'rules' => 2, 'filter_rewrites' => 0, 'custom_canonicals' => 9, 'hreflang_groups' => 0], $stats);
        $this->assertSame(0, array_sum($this->block([], [], [], true)->getModuleStats()));
    }

    public function testSitemapAndFeedStats(): void
    {
        $stats = $this->block([
            'COUNT(*) FROM panth_seo_sitemap_profile'          => '2',
            'MAX(last_generated_at) FROM panth_seo_sitemap'    => '2026-10-01',
            'SUM(url_count)'                                   => '1500',
            'COUNT(*) FROM panth_seo_feed_profile'             => '1',
            'MAX(last_generated_at) FROM panth_seo_feed'       => '2026-10-02',
            'SUM(product_count)'                               => '300',
        ])->getSitemapFeedStats();

        $this->assertSame([
            'sitemap_profiles' => 2, 'sitemap_last_generated' => '2026-10-01', 'sitemap_total_urls' => 1500,
            'feed_profiles' => 1, 'feed_last_generated' => '2026-10-02', 'feed_total_products' => 300,
        ], $stats);

        $empty = $this->block([], ['panth_seo_sitemap_profile'], [], false)->getSitemapFeedStats();
        $this->assertSame(0, $empty['sitemap_profiles']);
        $failing = $this->block([], [], [], true)->getSitemapFeedStats();
        $this->assertSame('', $failing['feed_last_generated']);
    }

    public function testQuickActionsIncludeInstalledSiblingModules(): void
    {
        $actions = $this->block([], [], ['Panth_XmlSitemap' => true, 'Panth_Hreflang' => 'throw'])->getQuickActions();

        $labels = array_column($actions, 'label');
        $this->assertCount(8, $actions);
        $this->assertSame('Manage Sitemaps', end($labels));
        $this->assertNotContains('Hreflang Groups', $labels);
        $this->assertSame('/adminhtml/system_config/edit?section=panth_seo', $actions[6]['url']);
        $this->assertSame('/panth_xml_sitemap/profile/index', $actions[7]['url']);
    }

    public static function percentages(): array
    {
        return [
            [0, 0, 0.0],
            [1, 3, 33.3],
            [5, 5, 100.0],
        ];
    }

    #[DataProvider('percentages')]
    public function testPercentage(int $part, int $total, float $expected): void
    {
        $this->assertSame($expected, $this->block()->pct($part, $total));
    }

    public function testHealthColourBands(): void
    {
        $block = $this->block();

        $this->assertSame('panth-card--red', $block->healthColor(20.1));
        $this->assertSame('panth-card--yellow', $block->healthColor(20.0));
        $this->assertSame('panth-card--yellow', $block->healthColor(5.0));
        $this->assertSame('panth-card--green', $block->healthColor(4.9));
    }
}
