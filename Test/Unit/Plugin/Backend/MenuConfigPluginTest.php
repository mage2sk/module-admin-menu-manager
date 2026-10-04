<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Plugin\Backend;

use Magento\Backend\Model\Menu;
use Magento\Backend\Model\Menu\Config;
use Magento\Backend\Model\Menu\Item;
use Magento\Backend\Model\Menu\Item\Factory;
use Magento\Backend\Model\Menu\Config\Reader as MenuConfigReader;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\SerializerInterface;
use Panth\AdminMenuManager\Plugin\Backend\MenuConfigPlugin;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use Panth\AdminMenuManager\Service\MenuTree;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MenuConfigPluginTest extends TestCase
{
    /** @var array<string, string> titles keyed by item id */
    private array $titles = [];

    private array $stockDefs = [];

    private function menuTree(): MenuTree
    {
        $reader = $this->createStub(MenuConfigReader::class);
        $reader->method('read')->willReturn($this->stockDefs);
        return new MenuTree($reader, $this->createStub(CacheInterface::class), new Json());
    }

    private function menu(array $items): Menu
    {
        $menu = new Menu(
            $this->createStub(LoggerInterface::class),
            '',
            $this->createStub(Factory::class),
            $this->createStub(SerializerInterface::class)
        );
        foreach (array_values($items) as $index => $item) {
            $menu->add($item, null, $index);
        }
        return $menu;
    }

    private function item(string $id, array $children = []): Item
    {
        $this->titles[$id] = 'Title ' . $id;
        $childMenu = $this->menu($children);
        $item = $this->createStub(Item::class);
        $item->method('getId')->willReturn($id);
        $item->method('getChildren')->willReturn($childMenu);
        $item->method('hasChildren')->willReturnCallback(static fn() => $childMenu->count() > 0);
        $item->method('getTitle')->willReturnCallback(fn() => $this->titles[$id]);
        $item->method('setTitle')->willReturnCallback(function ($title) use ($id, &$item) {
            $this->titles[$id] = $title;
            return $item;
        });
        return $item;
    }

    private function ids(Menu $menu): array
    {
        $ids = [];
        foreach ($menu as $item) {
            $ids[] = $item->getId();
        }
        return $ids;
    }

    private function childIds(Menu $menu, string $parentId): array
    {
        foreach ($menu as $item) {
            if ($item->getId() === $parentId) {
                return $this->ids($item->getChildren());
            }
        }
        $this->fail('Parent ' . $parentId . ' not found at root');
    }

    private function cfg(array $data): array
    {
        return $data + [
            'is_disabled' => 0,
            'custom_label' => null,
            'custom_icon' => null,
            'custom_color' => null,
            'custom_parent_menu_item_id' => null,
            'sort_order' => null,
        ];
    }

    private function apply(Menu $menu, array $overrides): Menu
    {
        $service = $this->createStub(MenuOverrideService::class);
        $service->method('getActiveOverridesForCurrentUser')->willReturn($overrides);

        return (new MenuConfigPlugin($service, $this->menuTree()))->afterGetMenu($this->createStub(Config::class), $menu);
    }

    private function sampleMenu(): Menu
    {
        return $this->menu([
            $this->item('A'),
            $this->item('B', [$this->item('B1'), $this->item('B2')]),
            $this->item('C'),
        ]);
    }

    public function testMenuUnchangedWithoutOverrides(): void
    {
        $menu = $this->sampleMenu();

        $this->assertSame($menu, $this->apply($menu, []));
        $this->assertSame(['A', 'B', 'C'], $this->ids($menu));
        $this->assertSame(['B1', 'B2'], $this->childIds($menu, 'B'));
    }

    public function testOverridesAreAppliedOnlyOncePerMenuInstance(): void
    {
        $service = $this->createMock(MenuOverrideService::class);
        $service->expects($this->once())->method('getActiveOverridesForCurrentUser')
            ->willReturn(['A' => $this->cfg(['is_disabled' => 1])]);
        $plugin = new MenuConfigPlugin($service, $this->menuTree());
        $config = $this->createStub(Config::class);
        $menu = $this->sampleMenu();

        $plugin->afterGetMenu($config, $menu);
        $plugin->afterGetMenu($config, $menu);

        $this->assertSame(['B', 'C'], $this->ids($menu));
    }

    public function testCustomLabelRenamesItemButEmptyLabelDoesNot(): void
    {
        $menu = $this->sampleMenu();

        $this->apply($menu, [
            'B1' => $this->cfg(['custom_label' => 'Renamed']),
            'C' => $this->cfg(['custom_label' => '']),
        ]);

        $this->assertSame('Renamed', $this->titles['B1']);
        $this->assertSame('Title C', $this->titles['C']);
    }

    public function testDisabledItemsAreRemovedAtAnyDepth(): void
    {
        $menu = $this->sampleMenu();

        $this->apply($menu, [
            'A' => $this->cfg(['is_disabled' => 1]),
            'B2' => $this->cfg(['is_disabled' => 1]),
        ]);

        $this->assertSame(['B', 'C'], $this->ids($menu));
        $this->assertSame(['B1'], $this->childIds($menu, 'B'));
    }

    public function testProtectedItemsCannotBeDisabledOrMoved(): void
    {
        $menu = $this->menu([
            $this->item('A'),
            $this->item('Magento_Backend::stores', [$this->item('Magento_Config::config')]),
        ]);

        $this->apply($menu, [
            'Magento_Backend::stores' => $this->cfg(['is_disabled' => 1]),
            'Magento_Config::config' => $this->cfg(['custom_parent_menu_item_id' => 'A']),
        ]);

        $this->assertSame(['A', 'Magento_Backend::stores'], $this->ids($menu));
        $this->assertSame(['Magento_Config::config'], $this->childIds($menu, 'Magento_Backend::stores'));
        $this->assertSame([], $this->childIds($menu, 'A'));
    }

    public function testChildMovedToRootWithSortOrderIsPlacedFirst(): void
    {
        $menu = $this->sampleMenu();

        $this->apply($menu, ['B2' => $this->cfg(['custom_parent_menu_item_id' => '__root__', 'sort_order' => 0])]);

        $this->assertSame(['B2', 'A', 'B', 'C'], $this->ids($menu));
        $this->assertSame(['B1'], $this->childIds($menu, 'B'));
    }

    public function testRootItemMovedUnderAnotherParent(): void
    {
        $menu = $this->sampleMenu();

        $this->apply($menu, ['C' => $this->cfg(['custom_parent_menu_item_id' => 'B'])]);

        $this->assertSame(['A', 'B'], $this->ids($menu));
        $this->assertSame(['B1', 'B2', 'C'], $this->childIds($menu, 'B'));
    }

    public function testMoveToUnknownParentKeepsItemAtRoot(): void
    {
        $menu = $this->sampleMenu();

        $this->apply($menu, ['A' => $this->cfg(['custom_parent_menu_item_id' => 'Missing::parent'])]);

        $this->assertSame(['A', 'B', 'C'], $this->ids($menu));
    }

    public function testParentOverrideMatchingCurrentParentIsNotAMove(): void
    {
        $menu = $this->sampleMenu();

        $this->apply($menu, ['B1' => $this->cfg(['custom_parent_menu_item_id' => 'B'])]);

        $this->assertSame(['B1', 'B2'], $this->childIds($menu, 'B'));
    }

    public function testSortOverrideTiedWithStockSortGoesFirstAndKeepsTheRest(): void
    {
        $menu = $this->sampleMenu();

        $this->apply($menu, ['C' => $this->cfg(['sort_order' => 1])]);

        $this->assertSame(['A', 'C', 'B'], $this->ids($menu));
    }

    public function testSortOrderReordersChildLevel(): void
    {
        $menu = $this->sampleMenu();

        $this->apply($menu, [
            'B1' => $this->cfg(['sort_order' => 20]),
            'B2' => $this->cfg(['sort_order' => 10]),
        ]);

        $this->assertSame(['A', 'B', 'C'], $this->ids($menu));
        $this->assertSame(['B2', 'B1'], $this->childIds($menu, 'B'));
    }

    public function testEqualSortOrdersKeepStockOrderAndRankAgainstUnchangedItems(): void
    {
        $menu = $this->sampleMenu();

        $this->apply($menu, [
            'C' => $this->cfg(['sort_order' => 5]),
            'A' => $this->cfg(['sort_order' => 5]),
        ]);

        $this->assertSame(['B', 'A', 'C'], $this->ids($menu));
    }

    private function stockMenu(): Menu
    {
        $menu = $this->menu([]);
        $menu->add($this->item('Magento_Backend::dashboard'), null, 10);
        $menu->add($this->item('Magento_Sales::sales'), null, 15);
        $menu->add($this->item('Magento_Catalog::catalog'), null, 20);
        $menu->add($this->item('Magento_Backend::stores'), null, 60);
        $menu->add($this->item('Magento_Backend::system'), null, 80);
        return $menu;
    }

    public function testOverriddenSortIsRankedAgainstStockSortOrders(): void
    {
        $menu = $this->stockMenu();

        $this->apply($menu, ['Magento_Backend::dashboard' => $this->cfg(['sort_order' => 95])]);

        $this->assertSame(
            [
                'Magento_Sales::sales',
                'Magento_Catalog::catalog',
                'Magento_Backend::stores',
                'Magento_Backend::system',
                'Magento_Backend::dashboard',
            ],
            $this->ids($menu)
        );
    }

    public function testOverriddenSortLandsBetweenStockItems(): void
    {
        $menu = $this->stockMenu();

        $this->apply($menu, [
            'Magento_Backend::system' => $this->cfg(['sort_order' => 17]),
            'Magento_Sales::sales' => $this->cfg(['sort_order' => 70]),
        ]);

        $this->assertSame(
            [
                'Magento_Backend::dashboard',
                'Magento_Backend::system',
                'Magento_Catalog::catalog',
                'Magento_Backend::stores',
                'Magento_Sales::sales',
            ],
            $this->ids($menu)
        );
    }

    public function testResortedLevelKeepsEffectiveSortAsKeys(): void
    {
        $menu = $this->stockMenu();

        $this->apply($menu, ['Magento_Backend::dashboard' => $this->cfg(['sort_order' => 95])]);

        $keys = [];
        foreach ($menu as $key => $item) {
            $keys[$item->getId()] = $key;
        }
        $this->assertSame(95, $keys['Magento_Backend::dashboard']);
        $this->assertSame(15, $keys['Magento_Sales::sales']);
        $this->assertSame(80, $keys['Magento_Backend::system']);
    }

    public function testNegativeSortMovesItemFirst(): void
    {
        $menu = $this->stockMenu();

        $this->apply($menu, ['Magento_Backend::system' => $this->cfg(['sort_order' => -5])]);

        $this->assertSame('Magento_Backend::system', $this->ids($menu)[0]);
        $this->assertCount(5, $this->ids($menu));
    }

    public function testCachedMenuWithPositionalKeysUsesStockSortOrdersFromConfig(): void
    {
        $this->stockDefs = [
            ['id' => 'Magento_Backend::dashboard', 'sortOrder' => 10],
            ['id' => 'Magento_Sales::sales', 'sortOrder' => 15],
            ['id' => 'Magento_Backend::stores', 'sortOrder' => 70],
            ['id' => 'Magento_Backend::system', 'sortOrder' => 80],
            ['id' => 'Magento_Marketplace::partners', 'sortOrder' => 80],
        ];
        $menu = $this->menu([
            $this->item('Magento_Backend::dashboard'),
            $this->item('Magento_Sales::sales'),
            $this->item('Magento_Backend::stores'),
            $this->item('Magento_Backend::system'),
            $this->item('Magento_Marketplace::partners'),
        ]);

        $this->apply($menu, ['Magento_Backend::dashboard' => $this->cfg(['sort_order' => 75])]);

        $this->assertSame(
            [
                'Magento_Sales::sales',
                'Magento_Backend::stores',
                'Magento_Backend::dashboard',
                'Magento_Backend::system',
                'Magento_Marketplace::partners',
            ],
            $this->ids($menu)
        );
    }
}
