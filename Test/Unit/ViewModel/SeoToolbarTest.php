<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\ViewModel;

use Magento\Framework\App\Request\Http;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DataObject;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Registry;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\AdvancedSEO\Api\CanonicalResolverInterface;
use Panth\AdvancedSEO\Helper\Config;
use Panth\AdvancedSEO\Test\Unit\Plugin\ConnectionRecorder;
use Panth\AdvancedSEO\ViewModel\SeoToolbar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeoToolbarTest extends TestCase
{
    use ConnectionRecorder;

    private function toolbar(array $options = []): SeoToolbar
    {
        $title = $this->createStub(Title::class);
        $title->method('get')->willReturn($options['title'] ?? 'Blue Bottle');
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $pageConfig->method('getDescription')->willReturn('Desc');
        $pageConfig->method('getKeywords')->willReturn('kw');

        $resolver = $this->createStub(CanonicalResolverInterface::class);
        $resolver->method('getCanonicalUrl')->willReturnCallback(
            static fn(string $type, int $id) => 'https://shop.test/' . $type . '/' . $id
        );

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($options['enabled'] ?? true);
        $config->method('isSeoToolbarEnabled')->willReturn($options['toolbar'] ?? true);
        $config->method('getSeoToolbarAllowedIps')->willReturn($options['ips'] ?? '');

        $registry = $this->createStub(Registry::class);
        $entities = $options['registry'] ?? [];
        $registry->method('registry')->willReturnCallback(static fn(string $key) => $entities[$key] ?? null);

        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getModuleName')->willReturn('catalog');
        $request->method('getFullActionName')->willReturn($options['action'] ?? 'catalog_product_view');
        $request->method('getUriString')->willReturn('https://shop.test/bottle.html');
        $request->method('getServer')->willReturnCallback(
            static fn($key) => $key === 'HTTP_CACHE_CONTROL' ? 'no-cache' : null
        );

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getName')->willReturn('Default');
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $theme = $this->createStub(ThemeInterface::class);
        $theme->method('getCode')->willReturn($options['theme_code'] ?? 'Hyva/default');
        $theme->method('getThemePath')->willReturn('frontend/Hyva/default');
        $design = $this->createStub(DesignInterface::class);
        $design->method('getDesignTheme')->willReturn($theme);

        $state = $this->createStub(AppState::class);
        if (($options['mode'] ?? '') === '!') {
            $state->method('getMode')->willThrowException(new \RuntimeException('mode'));
        } else {
            $state->method('getMode')->willReturn($options['mode'] ?? AppState::MODE_PRODUCTION);
        }

        $remote = $this->createStub(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn($options['ip'] ?? '10.0.0.5');

        return new SeoToolbar(
            $pageConfig,
            $resolver,
            $config,
            $registry,
            $request,
            $storeManager,
            $design,
            $this->recordingResource([], [], null, [$options['score_row'] ?? false]),
            $state,
            $remote
        );
    }

    public static function ipRules(): array
    {
        return [
            'exact ipv4'        => ['10.0.0.5', '10.0.0.5', true],
            'list with spaces'  => ['1.1.1.1, 10.0.0.5', '10.0.0.5', true],
            'forwarded chain'   => ['10.0.0.5', '10.0.0.5, 172.16.0.1', true],
            'cidr /24'          => ['10.0.0.0/24', '10.0.0.200', true],
            'cidr /25 outside'  => ['10.0.0.0/25', '10.0.0.200', false],
            'cidr /0'           => ['0.0.0.0/0', '8.8.8.8', true],
            'cidr oversized'    => ['10.0.0.5/40', '10.0.0.5', true],
            'cidr bad prefix'   => ['10.0.0.0/x', '10.0.0.5', false],
            'cidr bad subnet'   => ['nope/24', '10.0.0.5', false],
            'family mismatch'   => ['::1/128', '10.0.0.5', false],
            'ipv6 exact'        => ['2001:db8::1', '2001:db8::1', true],
            'ipv6 cidr'         => ['2001:db8::/32', '2001:db8:ffff::1', true],
            'wildcard'          => ['*', '', true],
            'no client ip'      => ['10.0.0.5', '', false],
            'bad client ip'     => ['10.0.0.5', 'garbage', false],
            'bad entry'         => ['not-an-ip', '10.0.0.5', false],
            'no match'          => ['10.0.0.6', '10.0.0.5', false],
        ];
    }

    #[DataProvider('ipRules')]
    public function testAllowListMatching(string $ips, string $clientIp, bool $expected): void
    {
        $this->assertSame($expected, $this->toolbar(['ips' => $ips, 'ip' => $clientIp])->isAllowed());
    }

    public function testWithoutAnAllowListOnlyDeveloperModeIsAllowed(): void
    {
        $this->assertTrue($this->toolbar(['mode' => AppState::MODE_DEVELOPER])->isAllowed());
        $this->assertFalse($this->toolbar(['mode' => AppState::MODE_PRODUCTION])->isAllowed());
        $this->assertFalse($this->toolbar(['mode' => '!'])->isAllowed());
    }

    public function testDisabledSettingsDenyAccess(): void
    {
        $this->assertFalse($this->toolbar(['enabled' => false, 'ips' => '*'])->isAllowed());
        $this->assertFalse($this->toolbar(['toolbar' => false, 'ips' => '*'])->isAllowed());
    }

    public function testDataForAProductPageIncludesMetaCanonicalAndScore(): void
    {
        $data = $this->toolbar([
            'registry'  => ['current_product' => new DataObject(['id' => 5])],
            'score_row' => [
                'score' => '81', 'grade' => 'B',
                'breakdown' => '{"title":10}', 'issues' => json_encode(['Short description', ['code' => 'x']]),
            ],
        ])->getData();

        $this->assertSame('product', $data['identity']['entity_type']);
        $this->assertSame(5, $data['identity']['entity_id']);
        $this->assertSame('Default', $data['identity']['store_name']);
        $this->assertSame('Hyva/default', $data['identity']['theme_name']);
        $this->assertSame('https://shop.test/bottle.html', $data['identity']['current_url']);
        $this->assertSame('GET', $data['identity']['request_method']);
        $this->assertSame('Blue Bottle', $data['meta']['title']);
        $this->assertSame(11, $data['meta']['title_length']);
        $this->assertSame('https://shop.test/product/5', $data['meta']['canonical']);
        $this->assertSame('no-cache', $data['headers']['Cache-Control']);
        $this->assertSame(
            ['score' => 81, 'grade' => 'B', 'breakdown' => ['title' => 10], 'issues' => ['Short description', '{"code":"x"}']],
            $data['score']
        );
        $this->assertContains(['entity_type = ?', 'product'], $this->db['where']);
    }

    public static function routes(): array
    {
        return [
            ['cms_index_index', 'home'],
            ['cms_noroute_index', '404'],
            ['catalogsearch_result_index', 'search'],
            ['catalog_category_view', 'category'],
            ['cms_page_view', 'cms'],
            ['checkout_cart_index', 'checkout'],
            ['customer_account_login', 'customer'],
            ['contact_index_index', 'contact_index_index'],
            ['', 'unknown'],
        ];
    }

    #[DataProvider('routes')]
    public function testRouteTypeIsDetectedWhenNoEntityIsRegistered(string $action, string $expected): void
    {
        $data = $this->toolbar(['action' => $action])->getData();

        $this->assertSame($expected, $data['identity']['entity_type']);
        $this->assertSame('', $data['meta']['canonical']);
        $this->assertNull($data['score']);
    }

    public function testThemePathIsUsedWhenTheCodeIsEmptyAndMissingScoresAreNull(): void
    {
        $data = $this->toolbar(['theme_code' => '', 'registry' => ['cms_page' => new DataObject(['id' => 2])]])->getData();

        $this->assertSame('frontend/Hyva/default', $data['identity']['theme_name']);
        $this->assertSame('cms', $data['identity']['entity_type']);
        $this->assertNull($data['score']);
    }
}
