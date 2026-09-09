<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Plugin\PageConfig;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\MetaResolverInterface;
use Panth\AdvancedSEO\Helper\Config as SeoConfig;
use Panth\AdvancedSEO\Model\Text\Truncator;
use Panth\AdvancedSEO\Plugin\PageConfig\HeadPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HeadPluginTitleTest extends TestCase
{
    private HeadPlugin $plugin;

    protected function setUp(): void
    {
        $this->plugin = new HeadPlugin(
            $this->createMock(MetaResolverInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(Registry::class),
            $this->createMock(SeoConfig::class),
            $this->createMock(RequestInterface::class),
            $this->createMock(AttributeCollectionFactory::class),
            new Truncator()
        );
    }

    private function composeTitle(string $title, string $storeName, int $maxLen): string
    {
        $method = new \ReflectionMethod(HeadPlugin::class, 'composeTitle');

        return $method->invoke($this->plugin, $title, $storeName, $maxLen);
    }

    public function testShortTitleKeepsTheWholeSuffix(): void
    {
        $this->assertSame(
            'Stainless Steel Bottle - Acme Store',
            $this->composeTitle('Stainless Steel Bottle', 'Acme Store', 60)
        );
    }

    public function testLongTitleIsCutOnAWordBoundaryAndKeepsTheSuffix(): void
    {
        $result = $this->composeTitle(
            'Stainless Steel Water Bottle with Vacuum Insulation and Lid',
            'Acme Store',
            60
        );
        $this->assertSame('Stainless Steel Water Bottle with Vacuum... - Acme Store', $result);
        $this->assertLessThanOrEqual(60, mb_strlen($result, 'UTF-8'));
    }

    public static function titleLimits(): array
    {
        $cases = [];
        foreach ([30, 40, 50, 60, 70, 80] as $maxLen) {
            $cases['limit ' . $maxLen] = [$maxLen];
        }

        return $cases;
    }

    #[DataProvider('titleLimits')]
    public function testCombinedTitleNeverExceedsTheConfiguredLimit(int $maxLen): void
    {
        foreach ([
            'Stainless Steel Water Bottle with Vacuum Insulation and Lid',
            'स्टेनलेस स्टील की पानी की बोतल वैक्यूम इन्सुलेशन के साथ',
            str_repeat('x', 200),
        ] as $title) {
            $result = $this->composeTitle($title, 'Acme Store', $maxLen);
            $this->assertLessThanOrEqual(
                $maxLen,
                mb_strlen($result, 'UTF-8'),
                'limit ' . $maxLen . ' overshot with ' . $result
            );
            $this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'invalid UTF-8 at limit ' . $maxLen);
        }
    }

    public function testZeroLimitLeavesTheTitleAlone(): void
    {
        $title = str_repeat('x', 200);
        $this->assertSame($title . ' - Acme Store', $this->composeTitle($title, 'Acme Store', 0));
    }

    public function testStoreNameLongerThanTheLimitFallsBackToAHardCut(): void
    {
        $result = $this->composeTitle('Stainless Steel Bottle', 'A Very Long Store Name Indeed', 20);
        $this->assertSame(20, mb_strlen($result, 'UTF-8'));
    }
}
