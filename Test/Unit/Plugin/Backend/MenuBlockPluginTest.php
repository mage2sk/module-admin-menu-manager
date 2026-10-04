<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Plugin\Backend;

use Magento\Backend\Block\Menu as MenuBlock;
use Magento\Framework\App\CacheInterface;
use Panth\AdminMenuManager\Plugin\Backend\MenuBlockPlugin;
use Panth\AdminMenuManager\Service\MenuCache;
use Panth\AdminMenuManager\Service\ViewService;
use PHPUnit\Framework\TestCase;

class MenuBlockPluginTest extends TestCase
{
    private function plugin(?\Throwable $failure = null, int $viewId = 4): MenuBlockPlugin
    {
        $views = $this->createMock(ViewService::class);
        $method = $views->expects($this->atMost(1))->method('getActiveViewIdForCurrentUser');
        if ($failure !== null) {
            $method->willThrowException($failure);
        } else {
            $method->willReturn($viewId);
        }
        return new MenuBlockPlugin($views, new MenuCache($this->createStub(CacheInterface::class)));
    }

    private function block(array $data = []): MenuBlock
    {
        $block = (new \ReflectionClass(MenuBlock::class))->newInstanceWithoutConstructor();
        $block->setData($data);
        return $block;
    }

    public function testCacheKeyInfoGetsViewTagAppended(): void
    {
        $result = $this->plugin()->afterGetCacheKeyInfo($this->block(), ['admin_top_nav', 'en_US']);

        $this->assertSame(['admin_top_nav', 'en_US', 'PANTH_ADMIN_MENU_VIEW_4'], $result);
    }

    public function testViewIdIsResolvedOnlyOnce(): void
    {
        $plugin = $this->plugin();
        $block = $this->block();

        $plugin->afterGetCacheKeyInfo($block, []);
        $plugin->beforeToHtml($block);

        $this->assertSame(['PANTH_ADMIN_MENU_VIEW_4'], $block->getData('cache_tags'));
    }

    public function testBeforeToHtmlAppendsTagToExistingTagsWithoutDuplicates(): void
    {
        $plugin = $this->plugin();
        $block = $this->block(['cache_tags' => ['backend_mainmenu']]);

        $plugin->beforeToHtml($block);
        $plugin->beforeToHtml($block);

        $this->assertSame(['backend_mainmenu', 'PANTH_ADMIN_MENU_VIEW_4'], $block->getData('cache_tags'));
    }

    public function testResolverFailureFallsBackToViewZero(): void
    {
        $result = $this->plugin(new \RuntimeException('db down'))->afterGetCacheKeyInfo($this->block(), []);

        $this->assertSame(['PANTH_ADMIN_MENU_VIEW_0'], $result);
    }
}
