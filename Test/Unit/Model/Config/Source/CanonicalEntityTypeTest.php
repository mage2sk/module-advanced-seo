<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Config\Source;

use Panth\AdvancedSEO\Model\Config\Source\CanonicalEntityType;
use PHPUnit\Framework\TestCase;

class CanonicalEntityTypeTest extends TestCase
{
    public function testSourceTypesAreAListOfValueLabelPairs(): void
    {
        $options = (new CanonicalEntityType())->toOptionArray();

        $this->assertSame(['product', 'category', 'cms_page'], array_column($options, 'value'));
        $this->assertSame(range(0, 2), array_keys($options));
    }

    public function testTargetTypesStartWithNone(): void
    {
        $options = (new CanonicalEntityType(true))->toOptionArray();

        $this->assertSame(['', 'product', 'category', 'cms_page'], array_column($options, 'value'));
    }
}
