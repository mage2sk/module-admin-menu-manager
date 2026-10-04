<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Controller\Adminhtml\Manager;

use Magento\Backend\Model\Menu\Config\Reader as MenuConfigReader;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdminMenuManager\Controller\Adminhtml\Manager\Save;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use Panth\AdminMenuManager\Service\MenuTree;
use Panth\AdminMenuManager\Service\ViewService;
use Panth\AdminMenuManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class SaveTest extends ControllerTestCase
{
    private ?array $saved = null;

    private array $stockDefs = [];

    private array $storedOverrides = [];

    private function dispatch(array $post, array $params, array $knownViews = [2 => 'Ops'], ?\Throwable $failure = null): void
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->once())->method('ensureDefaultView');
        $views->method('getView')->willReturnCallback(
            static fn(int $id) => isset($knownViews[$id]) ? ['view_id' => $id, 'label' => $knownViews[$id]] : null
        );
        $views->method('getActiveViewIdForCurrentUser')->willReturn(1);

        $service = $this->createStub(MenuOverrideService::class);
        $service->method('isProtectedItem')->willReturnCallback(
            static fn(string $id) => isset(MenuOverrideService::PROTECTED_ITEM_IDS[$id])
        );
        $service->method('getOverridesForView')->willReturnCallback(fn() => $this->storedOverrides);
        $service->method('bulkUpsert')->willReturnCallback(function (int $viewId, array $rows) use ($failure) {
            if ($failure) {
                throw $failure;
            }
            $this->saved = ['view' => $viewId, 'rows' => $rows];
            return count($rows);
        });

        $result = (new Save($this->context($post, $params), $service, $views, $this->menuTree()))->execute();
        $this->assertSame($this->redirectResult, $result);
    }

    public function testItemsAreNormalisedIntoPayload(): void
    {
        $this->dispatch(['items' => [
            'A::a' => [
                'is_disabled' => '1',
                'custom_label' => 'Label',
                'custom_icon' => 'bi-star',
                'custom_color' => '#fff',
                'custom_parent_menu_item_id' => 'B::b',
                'sort_order' => '30',
                'default_sort' => '10',
            ],
            'C::c' => ['sort_order' => ''],
            'junk' => 'not-an-array',
        ]], ['view_id' => '2']);

        $this->assertSame(2, $this->saved['view']);
        $this->assertSame(
            [
                [
                    'menu_item_id' => 'A::a',
                    'is_disabled' => 1,
                    'custom_label' => 'Label',
                    'custom_icon' => 'bi-star',
                    'custom_color' => '#fff',
                    'custom_parent_menu_item_id' => 'B::b',
                    'sort_order' => 30,
                ],
                [
                    'menu_item_id' => 'C::c',
                    'is_disabled' => 0,
                    'custom_label' => '',
                    'custom_icon' => '',
                    'custom_color' => '',
                    'custom_parent_menu_item_id' => '',
                    'sort_order' => null,
                ],
            ],
            $this->saved['rows']
        );
        $this->assertSame(['Saved view "Ops". Reload the page to see the new menu.'], $this->messages['success']);
        $this->assertSame(['path' => 'panth_menu_manager/manager/index', 'params' => ['view_id' => 2]], $this->redirect);
    }

    public function testSortEqualToDefaultIsNotStoredAsOverride(): void
    {
        $this->dispatch(['items' => ['A::a' => ['sort_order' => '10', 'default_sort' => '10']]], ['view_id' => 2]);

        $this->assertNull($this->saved['rows'][0]['sort_order']);
    }

    public function testZeroSortDifferentFromDefaultIsKept(): void
    {
        $this->dispatch(['items' => ['A::a' => ['sort_order' => '0', 'default_sort' => '10']]], ['view_id' => 2]);

        $this->assertSame(0, $this->saved['rows'][0]['sort_order']);
    }

    public function testUnknownOrMissingViewFallsBackToActiveView(): void
    {
        $this->dispatch(['items' => []], ['view_id' => 99], [1 => 'Default']);
        $this->assertSame(1, $this->saved['view']);
        $this->assertSame(['view_id' => 1], $this->redirect['params']);

        $this->dispatch(['items' => []], [], [1 => 'Default']);
        $this->assertSame(1, $this->saved['view']);
        $this->assertSame([], $this->saved['rows']);
    }

    public function testServiceFailureIsReportedAndStillRedirects(): void
    {
        $this->dispatch(['items' => []], ['view_id' => 2], [2 => 'Ops'], new \RuntimeException('Write failed'));

        $this->assertSame(['Write failed'], $this->messages['error']);
        $this->assertSame([], $this->messages['success']);
        $this->assertSame(['view_id' => 2], $this->redirect['params']);
    }

    private function menuTree(): MenuTree
    {
        $reader = $this->createStub(MenuConfigReader::class);
        $reader->method('read')->willReturn($this->stockDefs);
        return new MenuTree($reader, $this->createStub(CacheInterface::class), new Json());
    }

    private function rowFor(string $id): array
    {
        foreach ($this->saved['rows'] as $row) {
            if ($row['menu_item_id'] === $id) {
                return $row;
            }
        }
        $this->fail('No saved row for ' . $id);
    }

    public function testProtectedItemsCannotBeSwitchedOffOrMoved(): void
    {
        $this->dispatch(['items' => [
            'Magento_Backend::stores' => ['is_disabled' => '1', 'custom_label' => 'Shop'],
            'Magento_Backend::stores_settings' => ['custom_parent_menu_item_id' => '__root__'],
            'Magento_Config::system_config' => ['is_disabled' => '1'],
            'Magento_Sales::sales' => ['is_disabled' => '1'],
        ]], ['view_id' => 2]);

        $this->assertSame(0, $this->rowFor('Magento_Backend::stores')['is_disabled']);
        $this->assertSame('Shop', $this->rowFor('Magento_Backend::stores')['custom_label']);
        $this->assertSame('', $this->rowFor('Magento_Backend::stores_settings')['custom_parent_menu_item_id']);
        $this->assertSame(1, $this->rowFor('Magento_Sales::sales')['is_disabled']);
        $this->assertSame(0, $this->rowFor('Magento_Config::system_config')['is_disabled']);
        $this->assertCount(3, $this->messages['error']);
        $this->assertStringContainsString('"Magento_Backend::stores" is always kept in the menu', $this->messages['error'][0]);
        $this->assertStringContainsString('"Magento_Backend::stores_settings"', $this->messages['error'][1]);
        $this->assertCount(1, $this->messages['success']);
    }

    public function testParentCannotBeTheItemItselfOrADescendant(): void
    {
        $this->stockDefs = [
            ['id' => 'Sales'],
            ['id' => 'Orders', 'parent' => 'Sales'],
            ['id' => 'Archive', 'parent' => 'Orders'],
            ['id' => 'Catalog'],
        ];

        $this->dispatch(['items' => [
            'Sales' => ['custom_parent_menu_item_id' => 'Archive'],
            'Orders' => ['custom_parent_menu_item_id' => 'Orders'],
            'Catalog' => ['custom_parent_menu_item_id' => 'Sales'],
        ]], ['view_id' => 2]);

        $this->assertSame('', $this->rowFor('Sales')['custom_parent_menu_item_id']);
        $this->assertSame('', $this->rowFor('Orders')['custom_parent_menu_item_id']);
        $this->assertSame('Sales', $this->rowFor('Catalog')['custom_parent_menu_item_id']);
        $this->assertSame(
            [
                '"Orders" cannot be moved under itself or one of its own sub-items. It stays under its stock parent.',
                '"Sales" cannot be moved under itself or one of its own sub-items. It stays under its stock parent.',
            ],
            $this->messages['error']
        );
    }

    public function testStoredMovesOfItemsNotPostedAreConsidered(): void
    {
        $this->stockDefs = [['id' => 'Sales'], ['id' => 'Catalog'], ['id' => 'Products', 'parent' => 'Catalog']];
        $this->storedOverrides = ['Catalog' => ['custom_parent_menu_item_id' => 'Sales']];

        $this->dispatch(['items' => ['Sales' => ['custom_parent_menu_item_id' => 'Products']]], ['view_id' => 2]);

        $this->assertSame('', $this->rowFor('Sales')['custom_parent_menu_item_id']);
        $this->assertCount(1, $this->messages['error']);
    }
}
