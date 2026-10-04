<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdminMenuManager\Service\MenuCache;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use Panth\AdminMenuManager\Service\ViewService;
use Panth\AdminMenuManager\Test\Unit\Fixture\FakeDbTrait;
use PHPUnit\Framework\TestCase;

class MenuOverrideServiceTest extends TestCase
{
    use FakeDbTrait;

    private const NOW = '2026-05-06 07:08:09';

    private array $rowsByView = [];
    private int $fetchCount = 0;
    private array $cleaned = [];

    private function service(int $activeViewId = 1, int $deleteCount = 1): MenuOverrideService
    {
        $readers = [
            'fetchAll' => function (Select $s) {
                $this->fetchCount++;
                return $this->rowsByView[(int)($this->whereOf($s)['view_id = ?'] ?? 0)] ?? [];
            },
        ];
        $date = $this->createStub(DateTime::class);
        $date->method('gmtDate')->willReturn(self::NOW);

        $views = $this->createStub(ViewService::class);
        $views->method('getActiveViewIdForCurrentUser')->willReturn($activeViewId);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willReturnCallback(function (array $tags) {
            $this->cleaned = array_merge($this->cleaned, $tags);
            return true;
        });

        return new MenuOverrideService(
            $this->resource($readers, 0, $deleteCount),
            $date,
            $views,
            new MenuCache($cache)
        );
    }

    private function row(array $data): array
    {
        return $data + [
            'menu_item_id' => 'X::x',
            'is_disabled' => '0',
            'custom_label' => null,
            'custom_icon' => null,
            'custom_color' => null,
            'custom_parent_menu_item_id' => null,
            'sort_order' => null,
        ];
    }

    public function testOverridesAreKeyedByItemAndTyped(): void
    {
        $this->rowsByView[2] = [
            $this->row(['menu_item_id' => 'A::a', 'is_disabled' => '1', 'custom_label' => 'Orders',
                'custom_icon' => 'bi-cart', 'custom_color' => '#ff0000',
                'custom_parent_menu_item_id' => 'B::b', 'sort_order' => '15']),
            $this->row(['menu_item_id' => 42]),
        ];

        $map = $this->service()->getOverridesForView(2);

        $this->assertSame(
            [
                'is_disabled' => 1,
                'custom_label' => 'Orders',
                'custom_icon' => 'bi-cart',
                'custom_color' => '#ff0000',
                'custom_parent_menu_item_id' => 'B::b',
                'sort_order' => 15,
            ],
            $map['A::a']
        );
        $this->assertSame(
            ['is_disabled' => 0, 'custom_label' => null, 'custom_icon' => null, 'custom_color' => null,
                'custom_parent_menu_item_id' => null, 'sort_order' => null],
            $map['42']
        );
    }

    public function testOverridesAreMemoisedPerView(): void
    {
        $this->rowsByView[1] = [$this->row(['menu_item_id' => 'A::a'])];
        $service = $this->service();

        $service->getOverridesForView(1);
        $service->getOverridesForView(1);
        $this->assertSame(1, $this->fetchCount);

        $this->assertSame([], $service->getOverridesForView(2));
        $this->assertSame(2, $this->fetchCount);
    }

    public function testActiveOverridesUseCurrentUsersView(): void
    {
        $this->rowsByView[5] = [$this->row(['menu_item_id' => 'Five::item'])];

        $this->assertSame(['Five::item'], array_keys($this->service(5)->getActiveOverridesForCurrentUser()));
    }

    public function testBulkUpsertSanitisesAndStoresRow(): void
    {
        $touched = $this->service()->bulkUpsert(3, [[
            'menu_item_id' => '  A::a ',
            'is_disabled' => 'on',
            'custom_label' => '  <b>Sales</b> ',
            'custom_icon' => ' bi-star ',
            'custom_color' => '#ABCDEF',
            'custom_parent_menu_item_id' => ' Magento_Backend::stores ',
            'sort_order' => '20',
        ]]);

        $this->assertSame(1, $touched);
        $this->assertSame('insertOnDuplicate', $this->writes[0][0]);
        $this->assertSame('pfx_panth_admin_menu_override', $this->writes[0][1]);
        $this->assertSame(
            [
                'view_id' => 3,
                'menu_item_id' => 'A::a',
                'is_disabled' => 1,
                'custom_label' => 'Sales',
                'custom_icon' => 'bi-star',
                'custom_color' => '#abcdef',
                'custom_parent_menu_item_id' => 'Magento_Backend::stores',
                'sort_order' => 20,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ],
            $this->writes[0][2]
        );
        $this->assertNotContains('created_at', $this->writes[0][3]);
        $this->assertContains('updated_at', $this->writes[0][3]);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function colorProvider(): array
    {
        return [
            'short hex' => ['#FA0', '#fa0'],
            'long hex' => ['#00ff00', '#00ff00'],
            'hex with alpha' => ['#11223344', '#11223344'],
            'missing hash' => ['00ff00', null],
            'five digits' => ['#12345', null],
            'named color' => ['red', null],
            'css injection' => ['#fff;background:url(x)', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('colorProvider')]
    public function testColorValidation(string $input, ?string $expected): void
    {
        $this->service()->bulkUpsert(1, [['menu_item_id' => 'A::a', 'custom_label' => 'x', 'custom_color' => $input]]);

        $this->assertSame($expected, $this->writes[0][2]['custom_color']);
    }

    public function testLongLabelIsTruncatedTo255Characters(): void
    {
        $this->service()->bulkUpsert(1, [['menu_item_id' => 'A::a', 'custom_label' => str_repeat('b', 300)]]);

        $this->assertSame(255, mb_strlen($this->writes[0][2]['custom_label']));
    }

    public function testRowWithoutAnyOverrideIsDeleted(): void
    {
        $touched = $this->service()->bulkUpsert(4, [[
            'menu_item_id' => 'A::a',
            'is_disabled' => 0,
            'custom_label' => '   ',
            'custom_icon' => '',
            'custom_color' => 'not-a-color',
            'custom_parent_menu_item_id' => '',
            'sort_order' => '',
        ]]);

        $this->assertSame(1, $touched);
        $this->assertSame(
            [['delete', 'pfx_panth_admin_menu_override', ['view_id = ?' => 4, 'menu_item_id = ?' => 'A::a']]],
            $this->writes
        );
    }

    public function testZeroSortOrderCountsAsOverride(): void
    {
        $this->service()->bulkUpsert(1, [['menu_item_id' => 'A::a', 'sort_order' => '0']]);

        $this->assertSame('insertOnDuplicate', $this->writes[0][0]);
        $this->assertSame(0, $this->writes[0][2]['sort_order']);
    }

    public function testRowsWithoutIdAreSkippedButCacheStillCleaned(): void
    {
        $touched = $this->service()->bulkUpsert(9, [['menu_item_id' => '  '], ['custom_label' => 'orphan']]);

        $this->assertSame(0, $touched);
        $this->assertSame([], $this->writes);
        $this->assertSame(['PANTH_ADMIN_MENU_VIEW_9'], $this->cleaned);
    }

    public function testBulkUpsertInvalidatesMemoisedOverrides(): void
    {
        $this->rowsByView[1] = [];
        $service = $this->service();
        $this->assertSame([], $service->getOverridesForView(1));

        $service->bulkUpsert(1, [['menu_item_id' => 'A::a', 'is_disabled' => 1]]);
        $this->rowsByView[1] = [$this->row(['menu_item_id' => 'A::a', 'is_disabled' => 1])];

        $this->assertArrayHasKey('A::a', $service->getOverridesForView(1));
        $this->assertSame(2, $this->fetchCount);
    }

    public function testResetRejectsBlankId(): void
    {
        $this->assertFalse($this->service()->reset(1, '   '));
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->cleaned);
    }

    public function testResetDeletesAndCleansCacheWhenRowExisted(): void
    {
        $this->assertTrue($this->service(1, 1)->reset(2, ' A::a '));
        $this->assertSame(
            [['delete', 'pfx_panth_admin_menu_override', ['view_id = ?' => 2, 'menu_item_id = ?' => 'A::a']]],
            $this->writes
        );
        $this->assertSame(['PANTH_ADMIN_MENU_VIEW_2'], $this->cleaned);
    }

    public function testResetWithNothingDeletedLeavesCacheAlone(): void
    {
        $this->assertFalse($this->service(1, 0)->reset(2, 'A::a'));
        $this->assertSame([], $this->cleaned);
    }

    public function testProtectedItemsAreRecognised(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isProtectedItem('Magento_Backend::stores'));
        $this->assertTrue($service->isProtectedItem(' Magento_Backend::stores_settings '));
        $this->assertTrue($service->isProtectedItem('Magento_Config::config'));
        $this->assertTrue($service->isProtectedItem('Magento_Config::system_config'));
        $this->assertTrue($service->isProtectedItem('Panth_AdminMenuManager::menu_manager'));
        $this->assertFalse($service->isProtectedItem('Magento_Sales::sales'));
        $this->assertFalse($service->isProtectedItem(''));
    }

    public function testProtectedItemIsNeverStoredDisabledOrMoved(): void
    {
        $this->service()->bulkUpsert(1, [[
            'menu_item_id' => 'Magento_Config::system_config',
            'is_disabled' => 1,
            'custom_label' => 'Settings',
            'custom_parent_menu_item_id' => 'Magento_Sales::sales',
            'sort_order' => '5',
        ]]);

        $this->assertSame('insertOnDuplicate', $this->writes[0][0]);
        $this->assertSame(0, $this->writes[0][2]['is_disabled']);
        $this->assertNull($this->writes[0][2]['custom_parent_menu_item_id']);
        $this->assertSame('Settings', $this->writes[0][2]['custom_label']);
        $this->assertSame(5, $this->writes[0][2]['sort_order']);
    }

    public function testProtectedItemWithOnlyDisableRequestHasNothingToStore(): void
    {
        $this->service()->bulkUpsert(2, [['menu_item_id' => 'Magento_Backend::stores', 'is_disabled' => 1]]);

        $this->assertSame(
            [['delete', 'pfx_panth_admin_menu_override', ['view_id = ?' => 2, 'menu_item_id = ?' => 'Magento_Backend::stores']]],
            $this->writes
        );
    }
}
