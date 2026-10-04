<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Block\Adminhtml\Manager;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\Menu\Config\Reader as MenuConfigReader;
use Magento\Framework\App\State as AppState;
use Magento\Framework\AuthorizationInterface;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use Panth\AdminMenuManager\Service\ViewService;

class Tree extends Template
{
    public const SELF_PROTECT_ID = 'Panth_AdminMenuManager::menu_manager';

    private ?array $rows = null;

    private ?array $idTitleMap = null;

    public function __construct(
        Context $context,
        private readonly MenuConfigReader $configReader,
        private readonly MenuOverrideService $overrides,
        private readonly ViewService $views,
        private readonly AuthSession $authSession,
        private readonly AppState $appState,
        private readonly AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getMenuRows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $defs = (array) $this->configReader->read($this->appState->getAreaCode());

        $byId = [];
        foreach ($defs as $def) {
            if (!is_array($def) || empty($def['id'])) {
                continue;
            }
            if (isset($def['type']) && $def['type'] === 'remove') {
                continue;
            }
            $byId[(string)$def['id']] = $def;
        }

        $childrenOf = [];
        foreach ($byId as $def) {
            if (!$this->isItemAllowedForUser($def, $byId)) {
                continue;
            }
            $parent = isset($def['parent']) ? (string) $def['parent'] : '';
            $childrenOf[$parent] ??= [];
            $childrenOf[$parent][] = $def;
        }

        foreach ($childrenOf as &$bucket) {
            usort($bucket, static function (array $a, array $b): int {
                $sa = isset($a['sortOrder']) ? (int) $a['sortOrder'] : 0;
                $sb = isset($b['sortOrder']) ? (int) $b['sortOrder'] : 0;
                if ($sa === $sb) {
                    return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
                }
                return $sa <=> $sb;
            });
        }
        unset($bucket);

        $rows = [];
        $this->walk('', $childrenOf, 0, $rows);
        return $this->rows = $rows;
    }

    public function getIdTitleMap(): array
    {
        if ($this->idTitleMap !== null) {
            return $this->idTitleMap;
        }
        $map = [];
        foreach ($this->getMenuRows() as $row) {
            $map[$row['id']] = ['title' => $row['title'], 'depth' => (int)$row['depth']];
        }
        return $this->idTitleMap = $map;
    }

    public function getOverrides(): array
    {
        return $this->overrides->getOverridesForView($this->getCurrentViewId());
    }

    public function isLockedItem(string $id): bool
    {
        return $this->overrides->isProtectedItem($id);
    }

    public function getLockReason(string $id): string
    {
        if ($id === self::SELF_PROTECT_ID) {
            return (string) __('Cannot disable this page.');
        }
        return (string) __('Always kept in the menu so Stores > Configuration stays reachable. It cannot be switched off or moved.');
    }

    public function getCurrentViewId(): int
    {
        $param = (int) $this->getRequest()->getParam('view_id', 0);
        if ($param > 0 && $this->views->getView($param)) {
            return $param;
        }
        return $this->views->getActiveViewIdForCurrentUser();
    }

    public function getAllViews(): array
    {
        return $this->views->listViews();
    }

    public function getCurrentAdminUserId(): int
    {
        return (int)($this->authSession->getUser()?->getId() ?? 0);
    }

    public function getActiveViewIdForCurrentUser(): int
    {
        $userId = $this->getCurrentAdminUserId();
        return $userId > 0
            ? $this->views->getActiveViewIdForUser($userId)
            : $this->views->getGloballyActiveViewId();
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('panth_menu_manager/manager/save');
    }

    public function getResetUrl(): string
    {
        return $this->getUrl('panth_menu_manager/manager/reset');
    }

    public function getViewSaveUrl(): string
    {
        return $this->getUrl('panth_menu_manager/view/save');
    }

    public function getViewDeleteUrl(): string
    {
        return $this->getUrl('panth_menu_manager/view/delete');
    }

    public function getViewActivateUrl(): string
    {
        return $this->getUrl('panth_menu_manager/view/activate');
    }

    public function getViewDuplicateUrl(): string
    {
        return $this->getUrl('panth_menu_manager/view/duplicate');
    }

    public function getViewSwitchUrl(int $viewId): string
    {
        return $this->getUrl('panth_menu_manager/manager/index', ['view_id' => $viewId]);
    }

    private function isItemAllowedForUser(array $def, array $byId): bool
    {
        $resource = isset($def['resource']) ? (string) $def['resource'] : '';
        if ($resource !== '' && !$this->authorization->isAllowed($resource)) {
            return false;
        }
        $parentId = isset($def['parent']) ? (string) $def['parent'] : '';
        if ($parentId !== '' && isset($byId[$parentId])) {
            return $this->isItemAllowedForUser($byId[$parentId], $byId);
        }
        return true;
    }

    private function walk(string $parentId, array $childrenOf, int $depth, array &$out): void
    {
        $children = $childrenOf[$parentId] ?? [];
        foreach ($children as $def) {
            $id = (string) $def['id'];
            $hasChildren = !empty($childrenOf[$id]);
            $out[] = [
                'id'           => $id,
                'parent_id'    => $parentId,
                'title'        => (string) ($def['title'] ?? $id),
                'depth'        => $depth,
                'has_children' => $hasChildren,
                'default_sort' => isset($def['sortOrder']) ? (int) $def['sortOrder'] : 0,
            ];
            if ($hasChildren) {
                $this->walk($id, $childrenOf, $depth + 1, $out);
            }
        }
    }
}
