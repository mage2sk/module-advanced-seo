<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\Registry;
use Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction;
use Panth\AdvancedSEO\Controller\Adminhtml\Audit\CrawlResults;
use Panth\AdvancedSEO\Controller\Adminhtml\Audit\Index as AuditIndex;
use Panth\AdvancedSEO\Controller\Adminhtml\BulkEditor\Index as BulkEditorIndex;
use Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical\Edit as CanonicalEdit;
use Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical\Index as CanonicalIndex;
use Panth\AdvancedSEO\Controller\Adminhtml\CustomCanonical\NewAction as CanonicalNew;
use Panth\AdvancedSEO\Controller\Adminhtml\Dashboard\Index as DashboardIndex;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\Edit as FeedEdit;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\Index as FeedIndex;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\NewAction as FeedNew;
use Panth\AdvancedSEO\Controller\Adminhtml\Feed\NewField;
use Panth\AdvancedSEO\Controller\Adminhtml\Rule\Index as RuleIndex;
use Panth\AdvancedSEO\Controller\Adminhtml\Rule\NewAction as RuleNew;
use Panth\AdvancedSEO\Controller\Adminhtml\Template\Edit as TemplateEdit;
use Panth\AdvancedSEO\Controller\Adminhtml\Template\Index as TemplateIndex;
use Panth\AdvancedSEO\Controller\Adminhtml\Template\NewAction as TemplateNew;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use Panth\AdvancedSEO\Test\Unit\Ui\Component\Listing\DataProvider\CollectionRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PageControllersTest extends TestCase
{
    use ControllerHarness;
    use ConnectionRecorder;
    use CollectionRecorder;

    public static function indexPages(): array
    {
        return [
            'dashboard' => [DashboardIndex::class, 'Panth_AdvancedSEO::dashboard', 'Panth_AdvancedSEO::seo_dashboard', 'SEO Dashboard'],
            'audit'     => [AuditIndex::class, 'Panth_AdvancedSEO::audit', 'Panth_AdvancedSEO::audit', 'SEO Audit'],
            'crawl'     => [CrawlResults::class, 'Panth_AdvancedSEO::crawl_audit', 'Panth_AdvancedSEO::crawl_results', 'Crawl Audit Results'],
            'feeds'     => [FeedIndex::class, 'Panth_AdvancedSEO::feeds', 'Panth_AdvancedSEO::feeds', 'Product Feeds'],
            'rules'     => [RuleIndex::class, 'Panth_AdvancedSEO::rules', 'Panth_AdvancedSEO::rules', 'SEO Rules'],
            'templates' => [TemplateIndex::class, 'Panth_AdvancedSEO::templates', 'Panth_AdvancedSEO::templates', 'SEO Templates'],
            'canonical' => [CanonicalIndex::class, 'Panth_AdvancedSEO::custom_canonical', 'Panth_AdvancedSEO::custom_canonical', 'Custom Canonical URLs'],
        ];
    }

    #[DataProvider('indexPages')]
    public function testIndexPagesSetMenuTitleAndAcl(string $class, string $resource, string $menu, string $title): void
    {
        $controller = new $class($this->controllerContext(), $this->pageFactory());
        $controller->execute();

        $this->assertSame(['menu' => $menu, 'title' => $title], $this->page);
        $this->assertSame($resource, $class::ADMIN_RESOURCE);
        $this->assertFalse($this->isAllowed($controller));
        $this->allowedResources = [$resource];
        $this->assertTrue($this->isAllowed($controller));
        $this->allowedResources = [];
    }

    public static function newActions(): array
    {
        return [
            [FeedNew::class, 'Panth_AdvancedSEO::feeds'],
            [TemplateNew::class, 'Panth_AdvancedSEO::templates'],
            [CanonicalNew::class, 'Panth_AdvancedSEO::custom_canonical'],
            [RuleNew::class, 'Panth_AdvancedSEO::rules'],
        ];
    }

    #[DataProvider('newActions')]
    public function testNewActionsForwardToEdit(string $class, string $resource): void
    {
        (new $class($this->controllerContext(), $this->forwardFactory()))->execute();

        $this->assertSame(['forward' => 'edit'], $this->page);
        $this->assertSame($resource, $class::ADMIN_RESOURCE);
    }

    public function testAbstractActionDefaultsToTheManageResource(): void
    {
        $this->assertSame('Panth_AdvancedSEO::manage', AbstractAction::ADMIN_RESOURCE);
    }

    public function testCustomCanonicalEditTitle(): void
    {
        (new CanonicalEdit($this->controllerContext(['id' => '7']), $this->pageFactory()))->execute();
        $this->assertSame('Edit Custom Canonical #7', $this->page['title']);

        (new CanonicalEdit($this->controllerContext(), $this->pageFactory()))->execute();
        $this->assertSame('New Custom Canonical', $this->page['title']);
    }

    public static function editPages(): array
    {
        return [
            'feed'     => [FeedEdit::class, 'panth_seo_feed_profile', 'feed_id = ?', 'Edit Feed Profile', 'New Feed Profile'],
            'template' => [TemplateEdit::class, 'panth_seo_template', 'template_id = ?', 'Edit Template', 'New Template'],
        ];
    }

    #[DataProvider('editPages')]
    public function testEditPagesRegisterTheLoadedRow(string $class, string $key, string $where, string $editTitle, string $newTitle): void
    {
        $registered = [];
        $registry = $this->createStub(Registry::class);
        $registry->method('register')->willReturnCallback(static function ($k, $v) use (&$registered): void {
            $registered[$k] = $v;
        });

        (new $class($this->controllerContext(['id' => 4]), $this->pageFactory(), $registry, $this->recordingResource([], [], null, [['name' => 'x']])))->execute();
        $this->assertSame(['name' => 'x'], $registered[$key]);
        $this->assertSame($editTitle, $this->page['title']);
        $this->assertSame([[$where, 4]], $this->db['where']);

        (new $class($this->controllerContext(['id' => 4]), $this->pageFactory(), $registry, $this->recordingResource()))->execute();
        $this->assertSame([], $registered[$key]);

        (new $class($this->controllerContext(), $this->pageFactory(), $registry, $this->recordingResource()))->execute();
        $this->assertSame([], $registered[$key]);
        $this->assertSame($newTitle, $this->page['title']);
        $this->assertSame([], $this->db['where']);
    }

    public function testBulkEditorRemembersTheEntityType(): void
    {
        (new BulkEditorIndex($this->controllerContext(['type' => 'cms']), $this->pageFactory(), $this->recordingSession([])))->execute();
        $this->assertSame(['panth_seo_bulkeditor_type' => 'cms'], $this->sessionWrites);
        $this->assertSame('Bulk Meta Editor - CMS Pages', $this->page['title']);

        (new BulkEditorIndex($this->controllerContext(['type' => 'x']), $this->pageFactory(), $this->recordingSession(['panth_seo_bulkeditor_type' => 'category'])))->execute();
        $this->assertSame('Bulk Meta Editor - Categories', $this->page['title']);

        (new BulkEditorIndex($this->controllerContext(), $this->pageFactory(), $this->recordingSession(['panth_seo_bulkeditor_type' => 'bogus'])))->execute();
        $this->assertSame('Bulk Meta Editor - Products', $this->page['title']);
        $this->assertSame(['panth_seo_bulkeditor_type' => 'product'], $this->sessionWrites);
    }

    public function testNewFieldNeedsAFeed(): void
    {
        (new NewField($this->controllerContext(), $this->pageFactory(), $this->recordingSession([])))->execute();
        $this->assertSame([['error', 'Invalid feed profile ID.']], $this->messages);
        $this->assertSame(['*/*/index', []], $this->redirect);

        (new NewField($this->controllerContext(['feed_id' => 3]), $this->pageFactory(), $this->recordingSession([])))->execute();
        $this->assertSame(['panth_seo_feed_field_feed_id' => 3], $this->sessionWrites);
        $this->assertSame('Add Field Mapping', $this->page['title']);

        (new NewField($this->controllerContext(['feed_id' => 3, 'field_id' => 8]), $this->pageFactory(), $this->recordingSession([])))->execute();
        $this->assertSame('Edit Field Mapping', $this->page['title']);
    }
}
