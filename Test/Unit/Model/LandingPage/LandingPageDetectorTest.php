<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\LandingPage;

use Magento\Cms\Model\Page;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\AdvancedSEO\Model\LandingPage\LandingPageDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LandingPageDetectorTest extends TestCase
{
    public static function pageProvider(): array
    {
        return [
            'prefix' => [['identifier' => 'landing-summer'], true],
            'layout xml' => [['identifier' => 'about', 'layout_update_xml' => '<update handle="landing_page"/>'], true],
            'custom layout xml' => [['identifier' => 'about', 'custom_layout_update_xml' => 'x landing_page y'], true],
            'plain' => [['identifier' => 'about-landing-'], false],
            'empty' => [[], false],
        ];
    }

    #[DataProvider('pageProvider')]
    public function testIsLandingPage(array $data, bool $expected): void
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->setData($data);

        $detector = new LandingPageDetector(
            $this->createStub(ResourceConnection::class),
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame($expected, $detector->isLandingPage($page));
    }

    public function testGetLandingPagesBuildsSelectAndReturnsRows(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects($this->once())->method('from')->with(['p' => 'cms_page'])->willReturnSelf();
        $select->expects($this->once())->method('join')
            ->with(['ps' => 'cms_page_store'], 'ps.page_id = p.page_id', [])->willReturnSelf();
        $wheres = [];
        $select->method('where')->willReturnCallback(function ($cond, $val = null) use (&$wheres, $select) {
            $wheres[] = [$cond, $val];
            return $select;
        });
        $select->expects($this->once())->method('group')->with('p.page_id')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value) => str_replace('?', "'" . $value . "'", $text)
        );
        $connection->method('select')->willReturn($select);
        $connection->expects($this->once())->method('fetchAll')->with($select)->willReturn([['page_id' => 3]]);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $detector = new LandingPageDetector($resource, $this->createStub(LoggerInterface::class));

        $this->assertSame([['page_id' => 3]], $detector->getLandingPages(4));
        $this->assertSame(['p.is_active = ?', 1], $wheres[0]);
        $this->assertSame(['ps.store_id IN (?)', [0, 4]], $wheres[1]);
        $this->assertStringContainsString("p.identifier LIKE 'landing-%'", $wheres[2][0]);
        $this->assertStringContainsString("p.custom_layout_update_xml LIKE '%landing_page%'", $wheres[2][0]);
    }

    public function testGetLandingPagesLogsAndReturnsEmptyOnFailure(): void
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willThrowException(new \RuntimeException('db down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('Panth SEO: failed to fetch landing pages', ['store_id' => 2, 'error' => 'db down']);

        $this->assertSame([], (new LandingPageDetector($resource, $logger))->getLandingPages(2));
    }
}
