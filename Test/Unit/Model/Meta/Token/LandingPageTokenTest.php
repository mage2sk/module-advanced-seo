<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Cms\Model\Page;
use Panth\AdvancedSEO\Model\LandingPage\LandingPageDetector;
use Panth\AdvancedSEO\Model\Meta\Token\LandingPageToken;
use PHPUnit\Framework\TestCase;

class LandingPageTokenTest extends TestCase
{
    private function token(bool $isLanding): LandingPageToken
    {
        $detector = $this->createStub(LandingPageDetector::class);
        $detector->method('isLandingPage')->willReturn($isLanding);

        return new LandingPageToken($detector);
    }

    private function page(): Page
    {
        $page = $this->createStub(Page::class);
        $page->method('getContentHeading')->willReturn('Summer Sale');
        $page->method('getIdentifier')->willReturn('summer-sale');

        return $page;
    }

    public function testNonPageEntitiesYieldEmpty(): void
    {
        $this->assertSame('', $this->token(true)->getValue(new \stdClass(), [], 'heading'));
    }

    public function testRegularPagesYieldEmpty(): void
    {
        $this->assertSame('', $this->token(false)->getValue($this->page(), [], 'heading'));
    }

    public function testArgumentsSelectTheField(): void
    {
        $token = $this->token(true);
        $this->assertSame('Summer Sale', $token->getValue($this->page(), [], 'HEADING'));
        $this->assertSame('summer-sale', $token->getValue($this->page(), [], 'identifier'));
        $this->assertSame('', $token->getValue($this->page(), [], 'other'));
        $this->assertSame('', $token->getValue($this->page(), []));
    }
}
