<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Block\Adminhtml;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Panth\AdvancedSEO\Block\Adminhtml\Feed\AddFieldButton;
use Panth\AdvancedSEO\Block\Adminhtml\Feed\AddGoogleDefaultsButton;
use Panth\AdvancedSEO\Block\Adminhtml\Feed\BackToProfilesButton;
use Panth\AdvancedSEO\Block\Adminhtml\Feed\FieldBackButton;
use Panth\AdvancedSEO\Block\Adminhtml\Feed\FieldSaveButton;
use Panth\AdvancedSEO\Block\Adminhtml\Feed\GenerateButton;
use Panth\AdvancedSEO\Block\Adminhtml\Feed\ManageFieldsButton;
use Panth\AdvancedSEO\Block\Adminhtml\Feed\TestConnectionButton;
use Panth\AdvancedSEO\Block\Adminhtml\GenericBackButton;
use Panth\AdvancedSEO\Block\Adminhtml\GenericDeleteButton;
use Panth\AdvancedSEO\Block\Adminhtml\GenericSaveAndContinueButton;
use Panth\AdvancedSEO\Block\Adminhtml\GenericSaveButton;
use Panth\AdvancedSEO\Block\Adminhtml\Template\Edit\ApplyButton;
use Panth\AdvancedSEO\Block\Adminhtml\Template\Edit\BackButton;
use Panth\AdvancedSEO\Block\Adminhtml\Template\Edit\SaveAndContinueButton;
use Panth\AdvancedSEO\Block\Adminhtml\Template\Edit\SaveButton;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ButtonProvidersTest extends TestCase
{
    private function url(): UrlInterface
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static function ($route = null, $params = []) {
            $query = $params ? '?' . http_build_query($params) : '';
            return 'https://admin.test/' . $route . $query;
        });
        return $url;
    }

    private function request(array $params = []): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $request->method('getRouteName')->willReturn('panth_seo');
        $request->method('getControllerName')->willReturn('rule');
        return $request;
    }

    private function session(?int $feedId = null): BackendSession
    {
        $session = $this->createStub(BackendSession::class);
        $session->method('getData')->willReturnCallback(
            static fn($key = '') => $key === 'panth_seo_feed_field_feed_id' ? $feedId : null
        );
        return $session;
    }

    public static function staticButtons(): array
    {
        return [
            [GenericSaveButton::class, 'Save', 'save', 90],
            [SaveButton::class, 'Save', 'save', 90],
            [FieldSaveButton::class, 'Save Field', 'save', 90],
            [GenericSaveAndContinueButton::class, 'Save and Continue Edit', 'saveAndContinueEdit', 80],
            [SaveAndContinueButton::class, 'Save and Continue Edit', 'saveAndContinueEdit', 80],
        ];
    }

    #[DataProvider('staticButtons')]
    public function testSaveButtonsTriggerTheirFormEvent(string $class, string $label, string $event, int $sort): void
    {
        $data = (new $class())->getButtonData();

        $this->assertSame($label, (string) $data['label']);
        $this->assertSame($event, $data['data_attribute']['mage-init']['button']['event']);
        $this->assertSame($sort, $data['sort_order']);
    }

    public function testBackButtonsPointToTheirListing(): void
    {
        $this->assertSame("location.href = 'https://admin.test/*/*/';", (new GenericBackButton($this->url()))->getButtonData()['on_click']);
        $this->assertSame("location.href = 'https://admin.test/*/*/';", (new BackButton($this->url()))->getButtonData()['on_click']);
        $this->assertSame(
            "location.href = 'https://admin.test/panth_seo/feed/index';",
            (new BackToProfilesButton($this->url()))->getButtonData()['on_click']
        );
    }

    public function testDeleteButtonUsesTheCurrentRouteAndOnlyShowsForSavedRecords(): void
    {
        $data = (new GenericDeleteButton($this->url(), $this->request(['id' => '4'])))->getButtonData();

        $this->assertStringContainsString("'https://admin.test/panth_seo/rule/delete?id=4'", $data['on_click']);
        $this->assertStringStartsWith('deleteConfirm(', $data['on_click']);
        $this->assertSame([], (new GenericDeleteButton($this->url(), $this->request()))->getButtonData());
    }

    public static function idButtons(): array
    {
        return [
            'generate'   => [GenerateButton::class, 'panth_seo/feed/generate?id=6'],
            'fields'     => [ManageFieldsButton::class, 'panth_seo/feed/fields?feed_id=6'],
            'connection' => [TestConnectionButton::class, 'panth_seo/feed/testConnection'],
            'apply'      => [ApplyButton::class, 'panth_seo/template/apply?template_id=6'],
        ];
    }

    #[DataProvider('idButtons')]
    public function testRecordButtonsNeedASavedRecord(string $class, string $route): void
    {
        $data = (new $class($this->url(), $this->request(['id' => 6])))->getButtonData();

        $this->assertStringContainsString('https://admin.test/' . $route, $data['on_click']);
        $this->assertSame([], (new $class($this->url(), $this->request()))->getButtonData());
    }

    public function testConnectionButtonPostsTheFeedId(): void
    {
        $data = (new TestConnectionButton($this->url(), $this->request(['id' => 6])))->getButtonData();

        $this->assertStringContainsString("body.append('feed_id', '6');", $data['on_click']);
    }

    public static function feedButtons(): array
    {
        return [
            'add field'    => [AddFieldButton::class, 'panth_seo/feed/newField?feed_id='],
            'add defaults' => [AddGoogleDefaultsButton::class, 'panth_seo/feed/addDefaultFields?feed_id='],
        ];
    }

    #[DataProvider('feedButtons')]
    public function testFeedFieldButtonsPreferTheRequestThenTheSession(string $class, string $route): void
    {
        $fromRequest = (new $class($this->url(), $this->request(['feed_id' => 3]), $this->session(9)))->getButtonData();
        $this->assertStringContainsString($route . '3', $fromRequest['on_click']);

        $fromSession = (new $class($this->url(), $this->request(), $this->session(9)))->getButtonData();
        $this->assertStringContainsString($route . '9', $fromSession['on_click']);
    }

    public function testFieldBackButtonFallsBackToTheProfileList(): void
    {
        $toFields = (new FieldBackButton($this->url(), $this->request(), $this->session(9)))->getButtonData();
        $this->assertSame("location.href = 'https://admin.test/panth_seo/feed/fields?feed_id=9';", $toFields['on_click']);

        $toIndex = (new FieldBackButton($this->url(), $this->request(), $this->session()))->getButtonData();
        $this->assertSame("location.href = 'https://admin.test/panth_seo/feed/index';", $toIndex['on_click']);
    }
}
