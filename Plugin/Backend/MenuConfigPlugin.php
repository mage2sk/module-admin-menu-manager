<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Plugin\Backend;

use Magento\Backend\Model\Menu;
use Magento\Backend\Model\Menu\Config;
use Magento\Backend\Model\Menu\Item;
use Panth\AdminMenuManager\Service\MenuOverrideService;

class MenuConfigPlugin
{
    private const SELF_PROTECT_IDS = [
        'Panth_AdminMenuManager::menu_manager' => true,
        'Magento_Backend::stores'                => true,
        'Magento_Backend::stores_settings'       => true,
        'Magento_Config::config'                 => true,
    ];

    private \WeakMap $applied;

    public function __construct(private readonly MenuOverrideService $service)
    {
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
        $items = [];
        $position = 0;
        foreach ($level as $item) {
            $items[] = ['item' => $item, 'natural' => $position++];
        }
        if ($items === []) {
            return;
        }

        $hasOverride = false;
        foreach ($items as $entry) {
            $cfg = $overrides[(string)$entry['item']->getId()] ?? null;
            if ($cfg !== null && $cfg['sort_order'] !== null) {
                $hasOverride = true;
                break;
            }
        }
        if (!$hasOverride) {
            return;
        }

        usort($items, function (array $a, array $b) use ($overrides): int {
            $aId = (string)$a['item']->getId();
            $bId = (string)$b['item']->getId();
            $aSort = $overrides[$aId]['sort_order'] ?? null;
            $bSort = $overrides[$bId]['sort_order'] ?? null;

            $aKey = $aSort ?? ($a['natural'] + 100000);
            $bKey = $bSort ?? ($b['natural'] + 100000);
            if ($aKey === $bKey) {
                return $a['natural'] <=> $b['natural'];
            }
            return $aKey <=> $bKey;
        });

        foreach ($items as $entry) {
            $level->remove((string)$entry['item']->getId());
        }
        foreach ($items as $idx => $entry) {
            $level->add($entry['item'], null, $idx);
        }
    }
}
