<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Plugin\Backend;

use Magento\Backend\Block\AnchorRenderer;
use Magento\Backend\Model\Menu\Item;
use Panth\AdminMenuManager\Plugin\Backend\AnchorRendererPlugin;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use PHPUnit\Framework\TestCase;

class AnchorRendererPluginTest extends TestCase
{
    private const HTML = '<a href="#"><span>Sales</span></a><span>extra</span>';

    private function render(array $overrides, string $itemId = 'Magento_Sales::sales', string $html = self::HTML): string
    {
        $service = $this->createStub(MenuOverrideService::class);
        $service->method('getActiveOverridesForCurrentUser')->willReturn($overrides);
        $item = $this->createStub(Item::class);
        $item->method('getId')->willReturn($itemId);

        return (new AnchorRendererPlugin($service))
            ->afterRenderAnchor($this->createStub(AnchorRenderer::class), $html, null, $item, 0);
    }

    public function testHtmlUntouchedWithoutOverride(): void
    {
        $this->assertSame(self::HTML, $this->render([]));
        $this->assertSame(self::HTML, $this->render(['Other::x' => ['custom_icon' => 'bi-star']]));
    }

    public function testHtmlUntouchedWhenIconEmptyOrWhitespace(): void
    {
        $this->assertSame(self::HTML, $this->render(['Magento_Sales::sales' => ['custom_icon' => null]]));
        $this->assertSame(self::HTML, $this->render(['Magento_Sales::sales' => ['custom_icon' => '   ']]));
    }

    public function testBootstrapIconIsInsertedBeforeFirstSpanOnly(): void
    {
        $out = $this->render(['Magento_Sales::sales' => ['custom_icon' => ' BI-Cart-Fill ']]);

        $this->assertSame(
            '<a href="#"><i class="bi bi-cart-fill panth-menu-bi-icon" aria-hidden="true"></i>'
            . '<span>Sales</span></a><span>extra</span>',
            $out
        );
    }

    public function testNonBootstrapIconIsRenderedEscapedAsEmojiSpan(): void
    {
        $out = $this->render(['Magento_Sales::sales' => ['custom_icon' => '<script>"x"']]);

        $this->assertStringContainsString(
            '<span class="panth-menu-emoji-icon" aria-hidden="true">&lt;script&gt;&quot;x&quot;</span><span>Sales',
            $out
        );
        $this->assertStringNotContainsString('<script>', $out);
    }

    public function testHtmlWithoutSpanIsReturnedUnchanged(): void
    {
        $html = '<a href="#">Sales</a>';

        $this->assertSame($html, $this->render(['Magento_Sales::sales' => ['custom_icon' => 'bi-star']], 'Magento_Sales::sales', $html));
    }
}
