<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Block\Adminhtml\Manager;

use Magento\Backend\Model\Menu\Config\Reader as MenuConfigReader;
use Magento\Framework\App\State as AppState;
use Magento\Framework\AuthorizationInterface;
use Panth\AdminMenuManager\Block\Adminhtml\Manager\Tree;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use Panth\AdminMenuManager\Service\ViewService;
use Panth\AdminMenuManager\Test\Unit\Block\Adminhtml\BlockTestCase;
use Panth\AdminMenuManager\Test\Unit\Fixture\AuthSessionDouble;

class TreeTest extends BlockTestCase
{
    private function tree(
        array $defs = [],
        array $denied = [],
        ?ViewService $views = null,
        ?int $userId = null,
        ?MenuOverrideService $overrides = null,
        ?MenuConfigReader $reader = null
    ): Tree {
        if ($reader === null) {
            $reader = $this->createStub(MenuConfigReader::class);
            $reader->method('read')->willReturn($defs);
        }
        $state = $this->createStub(AppState::class);
        $state->method('getAreaCode')->willReturn('adminhtml');
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturnCallback(static fn($resource) => !in_array($resource, $denied, true));

        return new Tree(
            $this->context(),
            $reader,
            $overrides ?? $this->createStub(MenuOverrideService::class),
            $views ?? $this->createStub(ViewService::class),
            new AuthSessionDouble($userId),
            $state,
            $auth
        );
    }

    private function sampleDefs(): array
    {
        return [
            ['id' => 'Sales', 'title' => 'Sales', 'sortOrder' => 20, 'resource' => 'Magento_Sales::sales'],
            ['id' => 'Catalog', 'title' => 'Catalog', 'sortOrder' => 10],
            ['id' => 'Orders', 'title' => 'Orders', 'parent' => 'Sales', 'sortOrder' => 5],
            ['id' => 'Invoices', 'title' => 'Invoices', 'parent' => 'Sales', 'sortOrder' => 5],
            ['id' => 'Products', 'parent' => 'Catalog'],
            ['id' => 'Gone', 'title' => 'Gone', 'type' => 'remove'],
            ['title' => 'No id'],
            'garbage',
        ];
    }

    public function testRowsAreDepthFirstSortedBySortOrderThenTitle(): void
    {
        $rows = $this->tree($this->sampleDefs())->getMenuRows();

        $this->assertSame(['Catalog', 'Products', 'Sales', 'Invoices', 'Orders'], array_column($rows, 'id'));
        $this->assertSame(
            [
                'id' => 'Catalog', 'parent_id' => '', 'title' => 'Catalog', 'depth' => 0,
                'has_children' => true, 'default_sort' => 10,
            ],
            $rows[0]
        );
        $this->assertSame(
            [
                'id' => 'Products', 'parent_id' => 'Catalog', 'title' => 'Products', 'depth' => 1,
                'has_children' => false, 'default_sort' => 0,
            ],
            $rows[1]
        );
    }

    public function testDeniedResourceHidesItemAndItsDescendants(): void
    {
        $rows = $this->tree($this->sampleDefs(), ['Magento_Sales::sales'])->getMenuRows();

        $this->assertSame(['Catalog', 'Products'], array_column($rows, 'id'));
    }

    public function testOrphanedItemsWithUnknownParentAreNotListed(): void
    {
        $rows = $this->tree([['id' => 'Lost', 'parent' => 'Missing']])->getMenuRows();

        $this->assertSame([], $rows);
    }

    public function testRowsAreReadOnceAndIdTitleMapIsDerived(): void
    {
        $reader = $this->createMock(MenuConfigReader::class);
        $reader->expects($this->once())->method('read')->with('adminhtml')->willReturn($this->sampleDefs());
        $tree = $this->tree([], [], null, null, null, $reader);

        $tree->getMenuRows();
        $map = $tree->getIdTitleMap();

        $this->assertSame(['title' => 'Orders', 'depth' => 1], $map['Orders']);
        $this->assertSame(['title' => 'Catalog', 'depth' => 0], $map['Catalog']);
        $this->assertCount(5, $map);
    }

    public function testCurrentViewIdUsesExistingRequestedView(): void
    {
        $views = $this->createStub(ViewService::class);
        $views->method('getView')->willReturnCallback(static fn(int $id) => $id === 4 ? ['view_id' => 4] : null);
        $views->method('getActiveViewIdForCurrentUser')->willReturn(1);

        $this->params = ['view_id' => '4'];
        $this->assertSame(4, $this->tree([], [], $views)->getCurrentViewId());

        $this->params = ['view_id' => '8'];
        $this->assertSame(1, $this->tree([], [], $views)->getCurrentViewId());

        $this->params = [];
        $this->assertSame(1, $this->tree([], [], $views)->getCurrentViewId());
    }

    public function testOverridesAreLoadedForCurrentView(): void
    {
        $views = $this->createStub(ViewService::class);
        $views->method('getActiveViewIdForCurrentUser')->willReturn(3);
        $overrides = $this->createMock(MenuOverrideService::class);
        $overrides->expects($this->once())->method('getOverridesForView')->with(3)->willReturn(['A' => []]);

        $this->assertSame(['A' => []], $this->tree([], [], $views, null, $overrides)->getOverrides());
    }

    public function testActiveViewDependsOnLoggedInUser(): void
    {
        $views = $this->createStub(ViewService::class);
        $views->method('getActiveViewIdForUser')->willReturnCallback(static fn(int $uid) => $uid * 10);
        $views->method('getGloballyActiveViewId')->willReturn(2);
        $views->method('listViews')->willReturn([['view_id' => 2]]);

        $withUser = $this->tree([], [], $views, 5);
        $this->assertSame(5, $withUser->getCurrentAdminUserId());
        $this->assertSame(50, $withUser->getActiveViewIdForCurrentUser());

        $anonymous = $this->tree([], [], $views, null);
        $this->assertSame(0, $anonymous->getCurrentAdminUserId());
        $this->assertSame(2, $anonymous->getActiveViewIdForCurrentUser());
        $this->assertSame([['view_id' => 2]], $anonymous->getAllViews());
    }

    public function testActionUrls(): void
    {
        $tree = $this->tree();

        $this->assertSame('https://admin.test/panth_menu_manager/manager/save', $tree->getSaveUrl());
        $this->assertSame('https://admin.test/panth_menu_manager/manager/reset', $tree->getResetUrl());
        $this->assertSame('https://admin.test/panth_menu_manager/view/save', $tree->getViewSaveUrl());
        $this->assertSame('https://admin.test/panth_menu_manager/view/delete', $tree->getViewDeleteUrl());
        $this->assertSame('https://admin.test/panth_menu_manager/view/activate', $tree->getViewActivateUrl());
        $this->assertSame('https://admin.test/panth_menu_manager/view/duplicate', $tree->getViewDuplicateUrl());
        $this->assertSame('https://admin.test/panth_menu_manager/manager/index?view_id=7', $tree->getViewSwitchUrl(7));
    }

    public function testProtectedItemsAreLockedWithAReason(): void
    {
        $overrides = new MenuOverrideService(
            $this->createStub(\Magento\Framework\App\ResourceConnection::class),
            $this->createStub(\Magento\Framework\Stdlib\DateTime\DateTime::class),
            $this->createStub(ViewService::class),
            $this->createStub(\Panth\AdminMenuManager\Service\MenuCache::class)
        );
        $tree = $this->tree([], [], null, null, $overrides);

        foreach (['Magento_Backend::stores', 'Magento_Backend::stores_settings', 'Magento_Config::system_config', Tree::SELF_PROTECT_ID] as $id) {
            $this->assertTrue($tree->isLockedItem($id), $id);
        }
        $this->assertFalse($tree->isLockedItem('Magento_Sales::sales'));
        $this->assertSame('Cannot disable this page.', $tree->getLockReason(Tree::SELF_PROTECT_ID));
        $this->assertStringContainsString('Stores > Configuration stays reachable', $tree->getLockReason('Magento_Config::system_config'));
    }
}
