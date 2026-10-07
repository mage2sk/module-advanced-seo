<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Score\Check;

use Panth\AdvancedSEO\Model\Score\Check\EntityCheck;
use PHPUnit\Framework\TestCase;

class EntityCheckTest extends TestCase
{
    public function testCode(): void
    {
        $this->assertSame('entity', (new EntityCheck())->getCode());
    }

    public function testCompleteProductScoresFull(): void
    {
        $result = (new EntityCheck())->run([
            'entity_type' => 'product',
            'attributes'  => ['name' => 'Shirt', 'sku' => 'S1', 'brand' => 'Acme', 'image' => '/s.jpg'],
            'content'     => '<p>No images here</p>',
        ]);

        $this->assertSame(100.0, $result['score']);
        $this->assertSame('All required entity attributes present', $result['message']);
        $this->assertSame(['name', 'sku', 'brand', 'image'], $result['details']['present']);
        $this->assertSame(100.0, $result['details']['alt_score']);
    }

    public function testZeroLikeValuesCountAsMissing(): void
    {
        $result = (new EntityCheck())->run([
            'entity_type' => 'product',
            'attributes'  => ['name' => 'Shirt', 'sku' => 'S1', 'brand' => '0', 'image' => 0],
        ]);

        $this->assertEqualsWithDelta(65.0, $result['score'], 0.0001);
        $this->assertSame('Missing: brand, image', $result['message']);
        $this->assertSame(['brand', 'image'], $result['details']['missing']);
    }

    public function testImagesWithoutAltReduceTheScore(): void
    {
        $result = (new EntityCheck())->run([
            'entity_type' => 'category',
            'attributes'  => ['name' => 'Shirts', 'image' => '/c.jpg'],
            'content'     => '<img src="a.jpg" alt="A shirt"><IMG src="b.jpg"><img src="c.jpg" alt="">',
        ]);

        $this->assertEqualsWithDelta(100.0 / 3, $result['details']['alt_score'], 0.0001);
        $this->assertEqualsWithDelta(70.0 + 10.0, $result['score'], 0.0001);
    }

    public function testCmsAndUnknownTypesOnlyRequireAName(): void
    {
        $check = new EntityCheck();
        $this->assertSame(100.0, $check->run(['entity_type' => 'cms_page', 'attributes' => ['name' => 'About']])['score']);

        $missing = $check->run(['entity_type' => 'faq']);
        $this->assertEqualsWithDelta(30.0, $missing['score'], 0.0001);
        $this->assertSame(['name'], $missing['details']['missing']);
    }
}
