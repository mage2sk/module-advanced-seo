<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Audit\Check;

use Panth\AdvancedSEO\Model\Audit\Check\StructuredDataInvalidCheck;
use Panth\AdvancedSEO\Model\Audit\Issue;
use Panth\AdvancedSEO\Model\Audit\SchemaAllowList;

class StructuredDataInvalidCheckTest extends AbstractCheckTestCase
{
    private function check(): StructuredDataInvalidCheck
    {
        return new StructuredDataInvalidCheck($this->catalog(), new SchemaAllowList());
    }

    public function testProductWithDatePublishedAndDateModifiedIsFlagged(): void
    {
        $issues   = $this->check()->check($this->page('structured_data_invalid.positive.html'), $this->ctx());
        $elements = $this->elements($issues, StructuredDataInvalidCheck::CODE);

        $this->assertContains('Product.datePublished', $elements);
        $this->assertContains('Product.dateModified', $elements);
        $this->assertSame(Issue::SEVERITY_ERROR, $issues[0]->severity);
        $this->assertSame(Issue::CATEGORY_STRUCTURED_DATA, $issues[0]->category);
    }

    public function testInvalidJsonMissingContextAndMissingMerchantDataAreFlagged(): void
    {
        $issues   = $this->check()->check($this->page('structured_data_invalid.positive.html'), $this->ctx());
        $elements = $this->elements($issues);
        $details  = implode(' | ', array_map(static fn (Issue $i): string => $i->detail, $issues));

        $this->assertContains('@context', $elements);
        $this->assertContains('Product.offers', $elements);
        $this->assertStringContainsString('Invalid JSON', $details);
    }

    public function testValidProductWebsiteAndBlogPostingPass(): void
    {
        $issues = $this->check()->check($this->page('structured_data_invalid.negative.html'), $this->ctx());

        $this->assertSame([], $issues);
    }

    public function testAllowListKnowsCreativeWorkPropertiesAreNotProductProperties(): void
    {
        $allowList = new SchemaAllowList();

        $this->assertTrue($allowList->isKnownType('Product'));
        $this->assertArrayNotHasKey('datePublished', $allowList->allowedFor(['Product']));
        $this->assertArrayHasKey('datePublished', $allowList->allowedFor(['BlogPosting']));
        $this->assertArrayHasKey('offers', $allowList->allowedFor(['Product']));
    }

    public function testStripRemovesOnlyInvalidProperties(): void
    {
        $node = [
            '@type'         => 'Product',
            'name'          => 'Example',
            'datePublished' => '2024-01-01',
            'offers'        => ['@type' => 'Offer', 'price' => '1.00', 'bogus' => 'x'],
        ];

        $stripped = (new SchemaAllowList())->strip($node);

        $this->assertArrayNotHasKey('datePublished', $stripped);
        $this->assertArrayNotHasKey('bogus', $stripped['offers']);
        $this->assertSame('Example', $stripped['name']);
        $this->assertSame('1.00', $stripped['offers']['price']);
    }
}
