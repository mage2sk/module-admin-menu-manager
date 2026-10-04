<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Panth\AdminMenuManager\Service\MenuCache;
use PHPUnit\Framework\TestCase;

class MenuCacheTest extends TestCase
{
    public function testTagIsPrefixedWithViewId(): void
    {
        $cache = new MenuCache($this->createStub(CacheInterface::class));

        $this->assertSame('PANTH_ADMIN_MENU_VIEW_7', $cache->getTag(7));
        $this->assertSame('PANTH_ADMIN_MENU_VIEW_0', $cache->getTag(0));
    }

    public function testCleanViewCleansOnlyThatViewsTag(): void
    {
        $backend = $this->createMock(CacheInterface::class);
        $backend->expects($this->once())->method('clean')->with(['PANTH_ADMIN_MENU_VIEW_3']);

        (new MenuCache($backend))->cleanView(3);
    }
}
