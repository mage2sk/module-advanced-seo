<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Panth\AdvancedSEO\Model\Config\Source\CompressType;
use Panth\AdvancedSEO\Model\Config\Source\DeliveryType;
use Panth\AdvancedSEO\Model\Config\Source\FeedFieldSourceType;
use Panth\AdvancedSEO\Model\Config\Source\FeedOutputFormat;
use Panth\AdvancedSEO\Model\Config\Source\FeedType;
use Panth\AdvancedSEO\Model\Config\Source\GoogleProductCondition;
use Panth\AdvancedSEO\Model\Config\Source\LayeredNavCanonical;
use Panth\AdvancedSEO\Model\Config\Source\MetaRobots;
use Panth\AdvancedSEO\Model\Config\Source\PaginationPosition;
use Panth\AdvancedSEO\Model\Config\Source\ProductCanonicalType;
use Panth\AdvancedSEO\Model\Config\Source\ProductImageSource;
use Panth\AdvancedSEO\Model\Config\Source\TrailingSlashHomepage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StaticOptionSourcesTest extends TestCase
{
    public static function sourceProvider(): array
    {
        return [
            'compress' => [CompressType::class, ['', 'zip', 'gzip']],
            'delivery' => [DeliveryType::class, ['ftp', 'sftp']],
            'field source' => [FeedFieldSourceType::class, ['attribute', 'static', 'template', 'parent_attribute']],
            'output format' => [FeedOutputFormat::class, ['xml', 'csv']],
            'feed type' => [FeedType::class, ['google_shopping', 'facebook', 'custom_xml', 'custom_csv']],
            'condition' => [GoogleProductCondition::class, ['new', 'used', 'refurbished']],
            'pagination' => [PaginationPosition::class, ['prefix', 'suffix', 'none']],
            'canonical type' => [ProductCanonicalType::class, ['without_category', 'shortest', 'longest']],
            'image source' => [ProductImageSource::class, ['base_image', 'small_image', 'thumbnail']],
            'trailing slash' => [TrailingSlashHomepage::class, ['add', 'remove', 'none']],
            'layered nav' => [LayeredNavCanonical::class, ['use_global', 'category', 'filtered', 'noindex']],
            'meta robots' => [
                MetaRobots::class,
                ['', 'INDEX,FOLLOW', 'NOINDEX,FOLLOW', 'INDEX,NOFOLLOW', 'NOINDEX,NOFOLLOW'],
            ],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testOptionValuesAndLabels(string $class, array $expectedValues): void
    {
        $source = new $class();
        $this->assertInstanceOf(OptionSourceInterface::class, $source);

        $options = $source->toOptionArray();

        $this->assertSame($expectedValues, array_column($options, 'value'));
        foreach ($options as $option) {
            $this->assertNotSame('', (string) $option['label']);
        }
    }

    public function testLayeredNavCanonicalCachesOptions(): void
    {
        $source = new LayeredNavCanonical();
        $first = $source->getAllOptions();

        $this->assertSame($first, $source->getAllOptions());
        $this->assertSame($first, $source->toOptionArray());
        $this->assertSame('use_global', LayeredNavCanonical::USE_GLOBAL);
    }

    public function testMetaRobotsAllOptionsMatchOptionArray(): void
    {
        $source = new MetaRobots();

        $this->assertSame($source->getAllOptions(), $source->toOptionArray());
        $this->assertCount(5, $source->getAllOptions());
    }

    public function testConstantsMatchOptionValues(): void
    {
        $this->assertSame('without_category', ProductCanonicalType::WITHOUT_CATEGORY);
        $this->assertSame('add', TrailingSlashHomepage::ADD);
        $this->assertSame('remove', TrailingSlashHomepage::REMOVE);
        $this->assertSame('none', TrailingSlashHomepage::NONE);
    }
}
