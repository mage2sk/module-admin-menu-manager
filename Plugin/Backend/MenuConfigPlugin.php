<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Plugin\Backend;

use Magento\Backend\Model\Menu;
use Magento\Backend\Model\Menu\Config;
use Magento\Backend\Model\Menu\Item;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use Panth\AdminMenuManager\Service\MenuTree;

class MenuConfigPlugin
{
    private const SELF_PROTECT_IDS = MenuOverrideService::PROTECTED_ITEM_IDS;

    private \WeakMap $applied;

    public function __construct(
        private readonly MenuOverrideService $service,
        private readonly MenuTree $menuTree
    ) {
        $this->applied = new \WeakMap();
    }

    public function afterGetMenu(Config $subject, Menu $result): Menu
    {
        if (isset($this->applied[$result])) {
            return $result;
        }
        $this->applied[$result] = true;

        $overrides = $this->service->getActiveOverridesForCurrentUser();
        if ($overrides === []) {
            return $result;
        }

        $moves = [];
        $hasSortOverrideAt = [];
        $this->applyToTree($result, $overrides, '', $moves, $hasSortOverrideAt);

        foreach ($moves as $move) {
            $this->moveItem($result, $move['from'], $move['to'], $move['itemId'], $move['sort']);
        }

        foreach ($hasSortOverrideAt as $parentKey => $_) {
            $this->resortLevel($result, $parentKey === '' ? null : $parentKey, $overrides);
        }

        $this->resortLevel($result, null, $overrides);

        return $result;
    }

    private function applyToTree(
        Menu $menu,
        array $overrides,
        string $parentKey,
        array &$moves,
        array &$hasSortOverrideAt
    ): void {
        $idsToRemove = [];

        foreach ($menu as $item) {
            $id = (string)$item->getId();
            $cfg = $overrides[$id] ?? null;

            if ($cfg !== null) {
                if ($cfg['custom_label'] !== null && $cfg['custom_label'] !== '') {
                    $item->setTitle($cfg['custom_label']);
                }

                if ($cfg['is_disabled'] === 1 && !isset(self::SELF_PROTECT_IDS[$id])) {
                    $idsToRemove[] = $id;
                }
                $targetParent = (string)$cfg['custom_parent_menu_item_id'];

                if ($targetParent === '__root__') {
                    $targetParent = '';
                }
                if (
                    $cfg['custom_parent_menu_item_id'] !== null
                    && $targetParent !== $parentKey
                    && !isset(self::SELF_PROTECT_IDS[$id])
                ) {
                    $moves[] = [
                        'from'   => $parentKey === '' ? null : $parentKey,
                        'to'     => $targetParent,
                        'itemId' => $id,
                        'sort'   => $cfg['sort_order'],
                    ];
                    if ($cfg['sort_order'] !== null) {
                        $hasSortOverrideAt[$targetParent] = true;
                    }
                }
                if ($cfg['sort_order'] !== null) {
                    $hasSortOverrideAt[$parentKey] = true;
                }
            }

            $children = $item->getChildren();
            if ($children instanceof Menu && $children->count() > 0) {
                $this->applyToTree($children, $overrides, $id, $moves, $hasSortOverrideAt);
            }
        }

        foreach ($idsToRemove as $rid) {
            $menu->remove($rid);
        }
    }

    private function moveItem(
        Menu $rootMenu,
        ?string $fromParentId,
        string $toParentId,
        string $itemId,
        ?int $sortIndex
    ): void {
        $item = $this->findItem($rootMenu, $itemId);
        if ($item === null) {
            return;
        }

        $fromMenu = $fromParentId === null
            ? $rootMenu
            : $this->getChildrenMenuOf($rootMenu, $fromParentId);
        $index = $sortIndex;
        if ($fromMenu instanceof Menu) {
            foreach ($fromMenu as $key => $candidate) {
                if ((string)$candidate->getId() === $itemId) {
                    $index ??= (int)$key;
                    break;
                }
            }
            $fromMenu->remove($itemId);
        }

        $toMenu = $toParentId === ''
            ? $rootMenu
            : $this->getChildrenMenuOf($rootMenu, $toParentId);
        if ($toMenu instanceof Menu) {
            $toMenu->add($item, null, $index);
        } else {
            $rootMenu->add($item, null, $index);
        }
    }

    private function findItem(Menu $menu, string $id): ?Item
    {
        foreach ($menu as $item) {
            if ((string)$item->getId() === $id) {
                return $item;
            }
            $kids = $item->getChildren();
            if ($kids instanceof Menu) {
                $found = $this->findItem($kids, $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function getChildrenMenuOf(Menu $rootMenu, string $parentId): ?Menu
    {
        $parent = $this->findItem($rootMenu, $parentId);
        if ($parent === null) {
            return null;
        }
        return $parent->getChildren();
    }

    private function resortLevel(Menu $rootMenu, ?string $parentId, array $overrides): void
    {
        $level = $parentId === null ? $rootMenu : $this->getChildrenMenuOf($rootMenu, $parentId);
        if (!$level instanceof Menu) {
            return;
        }
        $hasOverride = false;
        foreach ($level as $item) {
            if (($overrides[(string)$item->getId()]['sort_order'] ?? null) !== null) {
                $hasOverride = true;
                break;
            }
        }
        if (!$hasOverride) {
            return;
        }

        $stockSorts = $this->menuTree->getStockSortOrders();
        $items = [];
        $position = 0;
        foreach ($level as $key => $item) {
            $id = (string)$item->getId();
            $sort = $overrides[$id]['sort_order'] ?? null;
            $stock = $stockSorts[$id] ?? (int)$key;
            $items[] = [
                'item'       => $item,
                'position'   => $position++,
                'stock'      => $stock,
                'effective'  => $sort ?? $stock,
                'overridden' => $sort !== null,
            ];
        }

        usort($items, [$this, 'compareEntries']);

        foreach ($items as $entry) {
            $level->remove((string)$entry['item']->getId());
        }
        foreach ($items as $entry) {
            $level->add($entry['item'], null, $entry['effective']);
        }
    }

    private function compareEntries(array $a, array $b): int
    {
        if ($a['effective'] !== $b['effective']) {
            return $a['effective'] <=> $b['effective'];
        }
        if ($a['overridden'] !== $b['overridden']) {
            return $a['overridden'] ? -1 : 1;
        }
        if ($a['stock'] !== $b['stock']) {
            return $a['stock'] <=> $b['stock'];
        }
        return $a['position'] <=> $b['position'];
    }
}
