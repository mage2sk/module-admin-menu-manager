<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\TestCase;

/**
 * Backend block wiring. The backend Template constructor pulls helpers from the static object manager,
 * so a stub instance is installed for each test and removed afterwards.
 */
abstract class BlockTestCase extends TestCase
{
    protected array $params = [];
    protected string $fullActionName = 'adminhtml_dashboard_index';

    protected function setUp(): void
    {
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn(string $class) => $this->createStub($class));
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, null);
    }

    protected function context(): Context
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            fn($key, $default = null) => $this->params[$key] ?? $default
        );
        $request->method('getFullActionName')->willReturnCallback(fn() => $this->fullActionName);

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route = '', $params = []) => 'https://admin.test/' . $route
                . ($params ? '?' . http_build_query($params) : '')
        );

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
        );
        $escaper->method('escapeUrl')->willReturnCallback(
            static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
        );

        $assets = $this->createStub(AssetRepository::class);
        $assets->method('getUrlWithParams')->willReturnCallback(
            static fn($fileId) => 'https://admin.test/static/' . str_replace('::', '/', $fileId)
        );

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);
        $context->method('getEscaper')->willReturn($escaper);
        $context->method('getAssetRepository')->willReturn($assets);
        return $context;
    }
}
