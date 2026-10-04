<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Block\Adminhtml;

use Panth\AdminMenuManager\Block\Adminhtml\BootstrapIconsLink;
use Panth\AdminMenuManager\Block\Adminhtml\CustomIconCss;
use Panth\AdminMenuManager\Service\MenuOverrideService;

class IconBlocksTest extends BlockTestCase
{
    private function service(?array $overrides): MenuOverrideService
    {
        $service = $this->createStub(MenuOverrideService::class);
        if ($overrides === null) {
            $service->method('getActiveOverridesForCurrentUser')->willThrowException(new \RuntimeException('no db'));
        } else {
            $service->method('getActiveOverridesForCurrentUser')->willReturn($overrides);
        }
        return $service;
    }

    public function testIconSelectorsAreSlugifiedAndUnique(): void
    {
        $block = new CustomIconCss($this->context(), $this->service([
            'Magento_Sales::sales' => ['custom_icon' => 'bi-cart'],
            'Magento_Sales__sales' => ['custom_icon' => 'x'],
            'Magento_Catalog::catalog' => ['custom_icon' => ''],
            'Panth_Core::Panth' => ['custom_icon' => 'bi-star', 'custom_color' => '#fff'],
        ]));

        $this->assertSame(['menu-magento-sales-sales', 'menu-panth-core-panth'], $block->getIconSelectors());
    }

    public function testColorRulesKeepOnlyValidHexColors(): void
    {
        $block = new CustomIconCss($this->context(), $this->service([
            'A::a' => ['custom_color' => '#ABC'],
            'B::b' => ['custom_color' => 'red'],
            'C::c' => ['custom_color' => '#12345678'],
            'D::d' => ['custom_color' => '#fff;}body{display:none'],
            'E::e' => ['custom_color' => null],
        ]));

        $this->assertSame(
            [['jsid' => 'menu-a-a', 'color' => '#abc'], ['jsid' => 'menu-c-c', 'color' => '#12345678']],
            $block->getColorRules()
        );
    }

    public function testCssBlockDegradesToEmptyOnServiceFailure(): void
    {
        $block = new CustomIconCss($this->context(), $this->service(null));

        $this->assertSame([], $block->getIconSelectors());
        $this->assertSame([], $block->getColorRules());
    }

    public function testIconsLinkAlwaysNeededOnManagerPage(): void
    {
        $this->fullActionName = 'panth_menu_manager_manager_index';
        $service = $this->createMock(MenuOverrideService::class);
        $service->expects($this->never())->method('getActiveOverridesForCurrentUser');

        $this->assertTrue((new BootstrapIconsLink($this->context(), $service))->isNeeded());
    }

    public function testIconsLinkNeededOnlyWhenBootstrapIconIsUsed(): void
    {
        $this->assertTrue((new BootstrapIconsLink($this->context(), $this->service([
            'A::a' => ['custom_icon' => 'x'],
            'B::b' => ['custom_icon' => ' bi-gear '],
        ])))->isNeeded());

        $this->assertFalse((new BootstrapIconsLink($this->context(), $this->service([
            'A::a' => ['custom_icon' => 'x'],
            'B::b' => ['custom_icon' => 'bi-'],
            'C::c' => [],
        ])))->isNeeded());

        $this->assertFalse((new BootstrapIconsLink($this->context(), $this->service([])))->isNeeded());
    }

    public function testIconsLinkNotNeededWhenServiceFails(): void
    {
        $this->assertFalse((new BootstrapIconsLink($this->context(), $this->service(null)))->isNeeded());
    }

    public function testStylesheetUrlPointsAtBundledCss(): void
    {
        $block = new BootstrapIconsLink($this->context(), $this->service([]));

        $this->assertSame(
            'https://admin.test/static/Panth_AdminMenuManager/css/bootstrap-icons/bootstrap-icons.min.css',
            $block->getStylesheetUrl()
        );
    }
}
