<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Model\Meta\Token;

use Magento\Cms\Model\Page;
use Panth\AdvancedSEO\Model\Meta\Token\PageToken;
use PHPUnit\Framework\TestCase;

class PageTokenTest extends TestCase
{
    public function testCmsPageTitle(): void
    {
        $page = $this->createStub(Page::class);
        $page->method('getTitle')->willReturn('Contact');

        $this->assertSame('Contact', (new PageToken())->getValue($page, ['page' => 4]));
    }

    public function testObjectWithTitleIsUsedWhenNotEmpty(): void
    {
        $titled = new class {
            public function getTitle(): ?string
            {
                return 'Blog post';
            }
        };
        $untitled = new class {
            public function getTitle(): ?string
            {
                return null;
            }
        };

        $token = new PageToken();
        $this->assertSame('Blog post', $token->getValue($titled, []));
        $this->assertSame('3', $token->getValue($untitled, ['page' => 3]));
    }

    public function testPaginationNumberOnlyFromPageTwo(): void
    {
        $token = new PageToken();
        $this->assertSame('', $token->getValue(null, []));
        $this->assertSame('', $token->getValue(null, ['page' => 1]));
        $this->assertSame('2', $token->getValue(null, ['page' => '2']));
    }
}
