<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\ViewModel;

use Magento\Backend\Model\Session as BackendSession;
use Panth\AdvancedSEO\ViewModel\BulkEditorTypeTabs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BulkEditorTypeTabsTest extends TestCase
{
    public static function sessionValues(): array
    {
        return [
            'unset'    => [null, 'product'],
            'category' => ['category', 'category'],
            'cms'      => ['cms', 'cms'],
            'product'  => ['product', 'product'],
            'unknown'  => ['brand', 'product'],
        ];
    }

    #[DataProvider('sessionValues')]
    public function testCurrentTypeComesFromTheSessionAndIsWhitelisted(?string $stored, string $expected): void
    {
        $session = $this->createStub(BackendSession::class);
        $session->method('getData')->willReturnCallback(
            static fn($key = '') => $key === 'panth_seo_bulkeditor_type' ? $stored : null
        );

        $this->assertSame($expected, (new BulkEditorTypeTabs($session))->getCurrentType());
    }
}
