<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\AdminMenuManager\Service\MenuCache;
use Panth\AdminMenuManager\Service\ViewService;
use Panth\AdminMenuManager\Test\Unit\Fixture\AuthSessionDouble;
use Panth\AdminMenuManager\Test\Unit\Fixture\FakeDbTrait;
use PHPUnit\Framework\TestCase;

class ViewServiceTest extends TestCase
{
    use FakeDbTrait;

    private const NOW = '2026-01-02 03:04:05';

    /** @var array<int, array> view rows keyed by id */
    private array $views = [];
    private array $prefs = [];
    private array $overrides = [];
    private array $cleaned = [];
    private ?Select $lastListSelect = null;

    private function service(?int $userId = null, int $lastInsertId = 0): ViewService
    {
        $readers = [
            'fetchRow' => function (Select $s) {
                $id = (int)($this->whereOf($s)['view_id = ?'] ?? 0);
                return $this->views[$id] ?? false;
            },
            'fetchAll' => function (Select $s) {
                $where = $this->whereOf($s);
                if (array_key_exists('view_id = ?', $where)) {
                    return $this->overrides[(int)$where['view_id = ?']] ?? [];
                }
                $this->lastListSelect = $s;
                return array_values($this->views);
            },
            'fetchOne' => function (Select $s) {
                $where = $this->whereOf($s);
                if (array_key_exists('admin_user_id = ?', $where)) {
                    return $this->prefs[(int)$where['admin_user_id = ?']] ?? false;
                }
                foreach (['is_active', 'is_default'] as $flag) {
                    if (array_key_exists($flag . ' = ?', $where)) {
                        foreach ($this->views as $id => $row) {
                            if ((int)($row[$flag] ?? 0) === 1) {
                                return (string)$id;
                            }
                        }
                        return false;
                    }
                }
                return false;
            },
        ];

        $date = $this->createStub(DateTime::class);
        $date->method('gmtDate')->willReturn(self::NOW);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('clean')->willReturnCallback(function (array $tags) {
            $this->cleaned = array_merge($this->cleaned, $tags);
            return true;
        });

        return new ViewService(
            $this->resource($readers, $lastInsertId),
            $date,
            new AuthSessionDouble($userId),
            new MenuCache($cache)
        );
    }

    public function testListViewsReturnsRowsOrderedDefaultFirst(): void
    {
        $this->views = [1 => ['view_id' => 1, 'label' => 'Default'], 2 => ['view_id' => 2, 'label' => 'Ops']];

        $this->assertSame(array_values($this->views), $this->service()->listViews());
        $this->assertSame([[['is_default DESC', 'view_id ASC']]], $this->partsOf($this->lastListSelect, 'order'));
        $this->assertSame('pfx_panth_admin_menu_view', $this->partsOf($this->lastListSelect, 'from')[0][0]);
    }

    public function testGetViewReturnsRowOrNull(): void
    {
        $this->views = [4 => ['view_id' => 4, 'label' => 'Four']];
        $service = $this->service();

        $this->assertSame(['view_id' => 4, 'label' => 'Four'], $service->getView(4));
        $this->assertNull($service->getView(99));
    }

    public function testCreateViewNormalisesLabelAndReturnsNewId(): void
    {
        $id = $this->service(null, 12)->createView('  <b>Sales</b> team  ');

        $this->assertSame(12, $id);
        $this->assertSame(
            ['insert', 'pfx_panth_admin_menu_view', ['label' => 'Sales team', 'is_active' => 0, 'is_default' => 0]],
            $this->writes[0]
        );
    }

    public function testCreateViewTruncatesLongLabelsTo128Characters(): void
    {
        $this->service(null, 1)->createView(str_repeat('a', 200));

        $this->assertSame(128, mb_strlen($this->writes[0][2]['label']));
    }

    public function testCreateViewRejectsBlankLabel(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('View name cannot be blank.');

        $this->service()->createView('  <i></i> ');
    }

    public function testRenameUpdatesOnlyTheGivenView(): void
    {
        $this->service()->rename(5, ' New name ');

        $this->assertSame(
            ['update', 'pfx_panth_admin_menu_view', ['label' => 'New name'], ['view_id = ?' => 5]],
            $this->writes[0]
        );
    }

    public function testRenameRejectsBlankLabelWithoutWriting(): void
    {
        try {
            $this->service()->rename(5, '   ');
            $this->fail('Expected exception');
        } catch (LocalizedException $e) {
            $this->assertSame('View name cannot be blank.', $e->getMessage());
            $this->assertSame([], $this->writes);
        }
    }

    public function testDuplicateThrowsWhenSourceMissing(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Source view not found.');

        $this->service()->duplicate(3, 'Copy');
    }

    public function testDuplicateCopiesOverridesIntoNewView(): void
    {
        $this->views = [3 => ['view_id' => 3, 'label' => 'Src']];
        $this->overrides[3] = [
            ['override_id' => 10, 'view_id' => 3, 'menu_item_id' => 'A::a', 'is_disabled' => 1,
                'created_at' => 'old', 'updated_at' => 'old'],
            ['override_id' => 11, 'view_id' => 3, 'menu_item_id' => 'B::b', 'is_disabled' => 0,
                'created_at' => 'old', 'updated_at' => 'old'],
        ];

        $newId = $this->service(null, 8)->duplicate(3, 'Copy');

        $this->assertSame(8, $newId);
        $this->assertCount(3, $this->writes);
        $this->assertSame('Copy', $this->writes[0][2]['label']);
        $this->assertSame('pfx_panth_admin_menu_override', $this->writes[1][1]);
        $this->assertSame(
            ['view_id' => 8, 'menu_item_id' => 'A::a', 'is_disabled' => 1,
                'created_at' => self::NOW, 'updated_at' => self::NOW],
            $this->writes[1][2]
        );
        $this->assertArrayNotHasKey('override_id', $this->writes[2][2]);
        $this->assertSame('B::b', $this->writes[2][2]['menu_item_id']);
    }

    public function testDuplicateOfViewWithoutOverridesOnlyCreatesView(): void
    {
        $this->views = [3 => ['view_id' => 3, 'label' => 'Src']];

        $this->assertSame(4, $this->service(null, 4)->duplicate(3, 'Empty copy'));
        $this->assertCount(1, $this->writes);
    }

    public function testDeleteViewIgnoresUnknownView(): void
    {
        $this->service()->deleteView(42);

        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->cleaned);
    }

    public function testDeleteViewRefusesDefaultView(): void
    {
        $this->views = [1 => ['view_id' => 1, 'is_default' => '1']];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The Default view cannot be deleted.');

        $this->service()->deleteView(1);
    }

    public function testDeleteViewRemovesRowAndCleansCache(): void
    {
        $this->views = [6 => ['view_id' => 6, 'is_default' => '0']];

        $this->service()->deleteView(6);

        $this->assertSame([['delete', 'pfx_panth_admin_menu_view', ['view_id = ?' => 6]]], $this->writes);
        $this->assertSame(['PANTH_ADMIN_MENU_VIEW_6'], $this->cleaned);
    }

    public function testActivateGloballyDeactivatesAllThenActivatesOne(): void
    {
        $this->service()->activateGlobally(4);

        $this->assertSame(
            [
                ['update', 'pfx_panth_admin_menu_view', ['is_active' => 0], []],
                ['update', 'pfx_panth_admin_menu_view', ['is_active' => 1], ['view_id = ?' => 4]],
            ],
            $this->writes
        );
    }

    public function testSetActiveForUserUpsertsPreference(): void
    {
        $this->service()->setActiveForUser(3, 21);

        $this->assertSame(
            [
                'insertOnDuplicate',
                'pfx_panth_admin_menu_user_pref',
                ['admin_user_id' => 21, 'active_view_id' => 3, 'updated_at' => self::NOW],
                ['active_view_id', 'updated_at'],
            ],
            $this->writes[0]
        );
    }

    public function testUserPreferenceWinsWhenViewExists(): void
    {
        $this->views = [1 => ['view_id' => 1, 'is_active' => 1], 5 => ['view_id' => 5]];
        $this->prefs = [9 => '5'];

        $this->assertSame(5, $this->service()->getActiveViewIdForUser(9));
    }

    public function testStaleUserPreferenceFallsBackToGlobalView(): void
    {
        $this->views = [2 => ['view_id' => 2, 'is_active' => 1]];
        $this->prefs = [9 => '77'];

        $this->assertSame(2, $this->service()->getActiveViewIdForUser(9));
    }

    public function testMissingUserPreferenceFallsBackToGlobalView(): void
    {
        $this->views = [3 => ['view_id' => 3, 'is_active' => 1]];

        $this->assertSame(3, $this->service()->getActiveViewIdForUser(9));
    }

    public function testGloballyActiveViewPrefersActiveThenDefaultThenConstant(): void
    {
        $this->views = [2 => ['view_id' => 2, 'is_default' => 1], 4 => ['view_id' => 4, 'is_active' => 1]];
        $this->assertSame(4, $this->service()->getGloballyActiveViewId());

        $this->views = [2 => ['view_id' => 2, 'is_default' => 1]];
        $this->assertSame(2, $this->service()->getGloballyActiveViewId());

        $this->views = [];
        $this->assertSame(ViewService::DEFAULT_VIEW_ID, $this->service()->getGloballyActiveViewId());
    }

    public function testCurrentUserResolution(): void
    {
        $this->views = [3 => ['view_id' => 3, 'is_active' => 1], 5 => ['view_id' => 5]];
        $this->prefs = [7 => '5'];

        $this->assertSame(3, $this->service(null)->getActiveViewIdForCurrentUser());
        $this->assertSame(5, $this->service(7)->getActiveViewIdForCurrentUser());
        $this->assertSame(3, $this->service(8)->getActiveViewIdForCurrentUser());
    }

    public function testEnsureDefaultViewDoesNothingWhenDefaultExists(): void
    {
        $this->views = [1 => ['view_id' => 1, 'is_default' => 1]];

        $this->service()->ensureDefaultView();

        $this->assertSame([], $this->writes);
    }

    public function testEnsureDefaultViewSeedsActiveDefaultWithFixedId(): void
    {
        $this->service()->ensureDefaultView();

        $this->assertSame(
            ['insert', 'pfx_panth_admin_menu_view',
                ['label' => 'Default', 'is_active' => 1, 'is_default' => 1, 'view_id' => 1]],
            $this->writes[0]
        );
    }

    public function testEnsureDefaultViewKeepsExistingActiveViewAndFreeId(): void
    {
        $this->views = [1 => ['view_id' => 1, 'is_active' => 1, 'is_default' => 0]];

        $this->service()->ensureDefaultView();

        $this->assertSame(
            ['insert', 'pfx_panth_admin_menu_view', ['label' => 'Default', 'is_active' => 0, 'is_default' => 1]],
            $this->writes[0]
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blankLabelProvider(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['    '],
            'tabs and newlines' => ["\t\n \r\n"],
            'markup only' => ['<b> </b><br/>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blankLabelProvider')]
    public function testNormaliseLabelRejectsBlankNames(string $label): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('View name cannot be blank.');

        ViewService::normaliseLabel($label);
    }

    public function testNormaliseLabelTrimsStripsAndLimitsLength(): void
    {
        $this->assertSame('Ops team', ViewService::normaliseLabel("  <em>Ops</em> team\n"));
        $this->assertSame(str_repeat('x', 128), ViewService::normaliseLabel(str_repeat('x', 140)));
    }
}
