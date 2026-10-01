<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Service;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

class ViewService
{
    private const TABLE_VIEW      = 'panth_admin_menu_view';
    private const TABLE_OVERRIDE  = 'panth_admin_menu_override';
    private const TABLE_USER_PREF = 'panth_admin_menu_user_pref';
    public  const DEFAULT_VIEW_ID = 1;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly AuthSession $authSession,
        private readonly MenuCache $menuCache
    ) {
    }

    public function listViews(): array
    {
        $conn = $this->resource->getConnection();
        return $conn->fetchAll(
            $conn->select()
                ->from($this->resource->getTableName(self::TABLE_VIEW))
                ->order(['is_default DESC', 'view_id ASC'])
        );
    }

    public function getView(int $viewId): ?array
    {
        $conn = $this->resource->getConnection();
        $row = $conn->fetchRow(
            $conn->select()
                ->from($this->resource->getTableName(self::TABLE_VIEW))
                ->where('view_id = ?', $viewId)
        );
        return $row ?: null;
    }

    public function createView(string $label): int
    {
        $label = $this->normaliseLabel($label);
        $conn = $this->resource->getConnection();
        $conn->insert($this->resource->getTableName(self::TABLE_VIEW), [
            'label'      => $label,
            'is_active'  => 0,
            'is_default' => 0,
        ]);
        return (int) $conn->lastInsertId($this->resource->getTableName(self::TABLE_VIEW));
    }

    public function rename(int $viewId, string $label): void
    {
        $label = $this->normaliseLabel($label);
        $conn = $this->resource->getConnection();
        $conn->update(
            $this->resource->getTableName(self::TABLE_VIEW),
            ['label' => $label],
            ['view_id = ?' => $viewId]
        );
    }

    public function duplicate(int $sourceViewId, string $newLabel): int
    {
        $src = $this->getView($sourceViewId);
        if (!$src) {
            throw new LocalizedException(__('Source view not found.'));
        }
        $newId = $this->createView($newLabel);

        $conn = $this->resource->getConnection();
        $now = $this->dateTime->gmtDate();
        $rows = $conn->fetchAll(
            $conn->select()
                ->from($this->resource->getTableName(self::TABLE_OVERRIDE))
                ->where('view_id = ?', $sourceViewId)
        );
        foreach ($rows as $row) {
            unset($row['override_id']);
            $row['view_id'] = $newId;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $conn->insert($this->resource->getTableName(self::TABLE_OVERRIDE), $row);
        }
        return $newId;
    }

    public function deleteView(int $viewId): void
    {
        $row = $this->getView($viewId);
        if (!$row) {
            return;
        }
        if ((int)($row['is_default'] ?? 0) === 1) {
            throw new LocalizedException(__('The Default view cannot be deleted.'));
        }
        $conn = $this->resource->getConnection();
        $conn->delete(
            $this->resource->getTableName(self::TABLE_VIEW),
            ['view_id = ?' => $viewId]
        );

        $this->menuCache->cleanView($viewId);
    }

    public function activateGlobally(int $viewId): void
    {
        $conn = $this->resource->getConnection();
        $conn->update($this->resource->getTableName(self::TABLE_VIEW), ['is_active' => 0], []);
        $conn->update(
            $this->resource->getTableName(self::TABLE_VIEW),
            ['is_active' => 1],
            ['view_id = ?' => $viewId]
        );
    }

    public function setActiveForUser(int $viewId, int $adminUserId): void
    {
        $conn = $this->resource->getConnection();
        $conn->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE_USER_PREF),
            [
                'admin_user_id'  => $adminUserId,
                'active_view_id' => $viewId,
                'updated_at'     => $this->dateTime->gmtDate(),
            ],
            ['active_view_id', 'updated_at']
        );
    }

    public function getActiveViewIdForUser(int $adminUserId): int
    {
        $conn = $this->resource->getConnection();
        $viewId = (int) $conn->fetchOne(
            $conn->select()
                ->from($this->resource->getTableName(self::TABLE_USER_PREF), ['active_view_id'])
                ->where('admin_user_id = ?', $adminUserId)
        );
        if ($viewId > 0 && $this->getView($viewId)) {
            return $viewId;
        }
        return $this->getGloballyActiveViewId();
    }

    public function ensureDefaultView(): void
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_VIEW);
        $hasDefault = (int) $conn->fetchOne(
            $conn->select()->from($table, ['view_id'])->where('is_default = ?', 1)->limit(1)
        );
        if ($hasDefault > 0) {
            return;
        }
        $hasActive = (int) $conn->fetchOne(
            $conn->select()->from($table, ['view_id'])->where('is_active = ?', 1)->limit(1)
        );
        $row = [
            'label'      => 'Default',
            'is_active'  => $hasActive > 0 ? 0 : 1,
            'is_default' => 1,
        ];
        if (!$this->getView(self::DEFAULT_VIEW_ID)) {
            $row['view_id'] = self::DEFAULT_VIEW_ID;
        }
        $conn->insert($table, $row);
    }

    public function getGloballyActiveViewId(): int
    {
        $conn = $this->resource->getConnection();
        $viewId = (int) $conn->fetchOne(
            $conn->select()
                ->from($this->resource->getTableName(self::TABLE_VIEW), ['view_id'])
                ->where('is_active = ?', 1)
                ->order('view_id ASC')
                ->limit(1)
        );
        if ($viewId > 0) {
            return $viewId;
        }
        $defaultId = (int) $conn->fetchOne(
            $conn->select()
                ->from($this->resource->getTableName(self::TABLE_VIEW), ['view_id'])
                ->where('is_default = ?', 1)
                ->order('view_id ASC')
                ->limit(1)
        );
        return $defaultId > 0 ? $defaultId : self::DEFAULT_VIEW_ID;
    }

    public function getActiveViewIdForCurrentUser(): int
    {
        $userId = (int)($this->authSession->getUser()?->getId() ?? 0);
        if ($userId <= 0) {
            return $this->getGloballyActiveViewId();
        }
        return $this->getActiveViewIdForUser($userId);
    }

    private function normaliseLabel(string $label): string
    {
        $label = trim(strip_tags($label));
        $label = mb_substr($label, 0, 128);
        if ($label === '') {
            throw new LocalizedException(__('View name cannot be blank.'));
        }
        return $label;
    }
}
