<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Feed;

use Magento\Store\Model\Store;
use Panth\AdvancedSEO\Model\Feed\CsvFeedWriter;
use Panth\AdvancedSEO\Model\Feed\XmlFeedWriter;
use PHPUnit\Framework\TestCase;

class FeedWritersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/panth_seo_feed_writer_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function store(): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getName')->willReturn('Main Store');
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        return $store;
    }

    public function testCsvWriterWritesBomHeaderAndRows(): void
    {
        $path = $this->dir . '/nested/feed.csv';
        $writer = new CsvFeedWriter();
        $writer->open($path, $this->store());
        $writer->writeItem(['id' => 'A1', 'title' => 'Red, "big" boot']);
        $writer->writeItem(['id' => 'A2', 'title' => 'Plain']);
        $writer->flush();
        $writer->close();
        $writer->close();

        $this->assertSame($path, $writer->getFilePath());
        $content = file_get_contents($path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = explode("\n", trim(substr($content, 3)));
        $this->assertSame('id,title', $lines[0]);
        $this->assertSame('A1,"Red, ""big"" boot"', $lines[1]);
        $this->assertSame('A2,Plain', $lines[2]);
    }

    public function testCsvWriterIgnoresItemsBeforeOpen(): void
    {
        $writer = new CsvFeedWriter();
        $writer->writeItem(['id' => 1]);
        $writer->flush();
        $writer->close();

        $this->assertSame('', $writer->getFilePath());
    }

    public function testXmlWriterProducesRssWithGoogleNamespace(): void
    {
        $path = $this->dir . '/feed.xml';
        $writer = new XmlFeedWriter();
        $writer->open($path, $this->store());
        $writer->writeItem([
            'title' => 'Boot & Co',
            'g:id' => 'SKU1',
            'g:shipping' => 'US ::: 5.00 USD',
            'empty' => '',
        ]);
        $writer->writeItem(['g:shipping' => 'no-separator']);
        $writer->flush();
        $writer->close();
        $writer->close();

        $this->assertSame($path, $writer->getFilePath());

        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml);
        $this->assertSame('2.0', (string) $xml['version']);
        $this->assertSame('Main Store', (string) $xml->channel->title);
        $this->assertSame('https://shop.test/', (string) $xml->channel->link);
        $this->assertSame('Product feed for Main Store', (string) $xml->channel->description);

        $items = $xml->channel->item;
        $this->assertCount(2, $items);
        $this->assertSame('Boot & Co', (string) $items[0]->title);
        $this->assertCount(0, $items[0]->empty);

        $g = $items[0]->children('http://base.google.com/ns/1.0');
        $this->assertSame('SKU1', (string) $g->id);
        $this->assertSame('US', (string) $g->shipping->country);
        $this->assertSame('5.00 USD', (string) $g->shipping->price);

        $g2 = $items[1]->children('http://base.google.com/ns/1.0');
        $this->assertSame('no-separator', (string) $g2->shipping);
    }

    public function testXmlWriterNoOpBeforeOpen(): void
    {
        $writer = new XmlFeedWriter();
        $writer->writeItem(['title' => 'x']);
        $writer->flush();
        $writer->close();

        $this->assertSame('', $writer->getFilePath());
        $this->assertDirectoryDoesNotExist($this->dir);
    }
}
