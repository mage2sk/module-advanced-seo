<?php
declare(strict_types=1);

namespace Panth\AdvancedSEO\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Forward;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

trait ControllerHarness
{
    private array $messages = [];

    private ?array $redirect = null;

    private array $page = [];

    private ?array $json = null;

    private array $allowedResources = [];

    private ?string $adminUser = null;

    private function controllerContext(array $params = [], array $post = [], bool $isPost = true): Context
    {
        $this->messages = [];
        $this->redirect = null;
        $this->page = [];
        $this->json = null;

        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $params[$key] ?? $default);
        $request->method('getParams')->willReturn($params);
        $request->method('getPostValue')->willReturnCallback(
            static fn($key = null, $default = null) => $key === null ? $post : ($post[$key] ?? $default)
        );
        $request->method('getPost')->willReturnCallback(
            static fn($key = null, $default = null) => $key === null ? $post : ($post[$key] ?? $default)
        );
        $request->method('isPost')->willReturn($isPost);

        $messages = $this->createStub(ManagerInterface::class);
        foreach (['Success', 'Error', 'Notice', 'Warning', 'Exception'] as $type) {
            $messages->method('add' . $type . 'Message')->willReturnCallback(function ($message) use ($type, $messages) {
                $this->messages[] = [strtolower($type), $message instanceof \Throwable ? $message->getMessage() : (string) $message];
                return $messages;
            });
        }

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->redirect = [$path, $params];
            return $redirect;
        });
        $redirect->method('setRefererUrl')->willReturnCallback(function () use ($redirect) {
            $this->redirect = ['referer', []];
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(fn($resource) => in_array($resource, $this->allowedResources, true));

        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturnCallback(fn($type) => $type === ResultFactory::TYPE_JSON ? $this->jsonResult() : $redirect);

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getAuthorization')->willReturn($authorization);
        $context->method('getResultFactory')->willReturn($resultFactory);
        $auth = $this->createStub(\Magento\Backend\Model\Auth::class);
        if ($this->adminUser !== null) {
            $user = $this->createStub(\Magento\User\Model\User::class);
            $user->method('getUserName')->willReturn($this->adminUser);
            $auth->method('getUser')->willReturn($user);
        }
        $context->method('getAuth')->willReturn($auth);

        return $context;
    }

    private function jsonResult(): Json
    {
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->json = $data;
            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnSelf();
        return $json;
    }

    private function jsonFactory(): JsonFactory
    {
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturnCallback(fn() => $this->jsonResult());
        return $factory;
    }

    private function pageFactory(): PageFactory
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($value): void {
            $this->page['title'] = (string) $value;
        });
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $page->method('setActiveMenu')->willReturnCallback(function ($menu) use ($page) {
            $this->page['menu'] = $menu;
            return $page;
        });
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);
        return $factory;
    }

    private function forwardFactory(): ForwardFactory
    {
        $forward = $this->createStub(Forward::class);
        $forward->method('forward')->willReturnCallback(function ($action) use ($forward) {
            $this->page['forward'] = $action;
            return $forward;
        });
        $factory = $this->createStub(ForwardFactory::class);
        $factory->method('create')->willReturn($forward);
        return $factory;
    }

    private function isAllowed(object $controller): bool
    {
        return (new \ReflectionMethod($controller, '_isAllowed'))->invoke($controller);
    }
}
