<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

class MenuOverrideService
{
    public const PROTECTED_ITEM_IDS = [
        'Panth_AdminMenuManager::menu_manager' => true,
        'Magento_Backend::stores'              => true,
        'Magento_Backend::stores_settings'     => true,
        'Magento_Config::config'               => true,
        'Magento_Config::system_config'        => true,
    ];

    private const TABLE = 'panth_admin_menu_override';

    private array $cacheByView = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly ViewService $viewService,
        private readonly MenuCache $menuCache
    ) {
    }

    public function getOverridesForView(int $viewId): array
    {
        if (isset($this->cacheByView[$viewId])) {
            return $this->cacheByView[$viewId];
        }
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchAll(
            $conn->select()
                ->from(
                    $this->resource->getTableName(self::TABLE),
                    [
                        'menu_item_id',
                        'is_disabled',
                        'custom_label',
                        'custom_icon',
                        'custom_color',
                        'custom_parent_menu_item_id',
                        'sort_order',
                    ]
                )
                ->where('view_id = ?', $viewId)
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['menu_item_id']] = [
                'is_disabled'                => (int)$row['is_disabled'],
                'custom_label'               => $row['custom_label'] !== null ? (string)$row['custom_label'] : null,
                'custom_icon'                => $row['custom_icon'] !== null ? (string)$row['custom_icon'] : null,
                'custom_color'               => $row['custom_color'] !== null ? (string)$row['custom_color'] : null,
                'custom_parent_menu_item_id' => $row['custom_parent_menu_item_id'] !== null ? (string)$row['custom_parent_menu_item_id'] : null,
                'sort_order'                 => $row['sort_order'] !== null ? (int)$row['sort_order'] : null,
            ];
        }
        return $this->cacheByView[$viewId] = $map;
    }

    public function isProtectedItem(string $menuItemId): bool
    {
        return isset(self::PROTECTED_ITEM_IDS[trim($menuItemId)]);
    }

    public function getActiveOverridesForCurrentUser(): array
    {
        return $this->getOverridesForView($this->viewService->getActiveViewIdForCurrentUser());
    }

    public function bulkUpsert(int $viewId, array $rows): int
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $now = $this->dateTime->gmtDate();
        $touched = 0;

        foreach ($rows as $row) {
            $id = trim((string)($row['menu_item_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $isDisabled = !empty($row['is_disabled']) ? 1 : 0;

            $label = isset($row['custom_label']) ? trim((string)$row['custom_label']) : '';
            $label = $label === '' ? null : mb_substr(strip_tags($label), 0, 255);

            $icon = isset($row['custom_icon']) ? trim((string)$row['custom_icon']) : '';
            $icon = $icon === '' ? null : mb_substr(strip_tags($icon), 0, 255);

            $color = isset($row['custom_color']) ? trim((string)$row['custom_color']) : '';

            $color = ($color !== '' && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color))
                ? strtolower($color)
                : null;

            $parent = isset($row['custom_parent_menu_item_id']) ? trim((string)$row['custom_parent_menu_item_id']) : '';
            $parent = $parent === '' ? null : mb_substr(strip_tags($parent), 0, 255);

            $sort = $row['sort_order'] ?? null;
            $sort = ($sort === null || $sort === '') ? null : (int)$sort;

            if ($this->isProtectedItem($id)) {
                $isDisabled = 0;
                $parent = null;
            }

            if ($isDisabled === 0 && $label === null && $icon === null && $color === null && $parent === null && $sort === null) {
                $conn->delete($table, [
                    'view_id = ?'      => $viewId,
                    'menu_item_id = ?' => $id,
                ]);
                $touched++;
                continue;
            }

            $conn->insertOnDuplicate(
                $table,
                [
                    'view_id'                    => $viewId,
                    'menu_item_id'               => $id,
                    'is_disabled'                => $isDisabled,
                    'custom_label'               => $label,
                    'custom_icon'                => $icon,
                    'custom_color'               => $color,
                    'custom_parent_menu_item_id' => $parent,
                    'sort_order'                 => $sort,
                    'created_at'                 => $now,
                    'updated_at'                 => $now,
                ],
                ['is_disabled', 'custom_label', 'custom_icon', 'custom_color', 'custom_parent_menu_item_id', 'sort_order', 'updated_at']
            );
            $touched++;
        }

        unset($this->cacheByView[$viewId]);
        $this->menuCache->cleanView($viewId);
        return $touched;
    }

    public function reset(int $viewId, string $menuItemId): bool
    {
        $id = trim($menuItemId);
        if ($id === '') {
            return false;
        }
        $conn = $this->resource->getConnection();
        $deleted = (int)$conn->delete(
            $this->resource->getTableName(self::TABLE),
            [
                'view_id = ?'      => $viewId,
                'menu_item_id = ?' => $id,
            ]
        );
        unset($this->cacheByView[$viewId]);
        if ($deleted > 0) {
            $this->menuCache->cleanView($viewId);
        }
        return $deleted > 0;
    }
}
