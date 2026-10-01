<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class SeedDefaultView implements DataPatchInterface
{
    public function __construct(private readonly ModuleDataSetupInterface $setup)
    {
    }

    public function apply(): self
    {
        $conn = $this->setup->getConnection();
        $viewTable = $this->setup->getTable('panth_admin_menu_view');

        $existing = $conn->fetchOne(
            $conn->select()->from($viewTable, ['view_id'])->where('view_id = ?', 1)
        );
        if (!$existing) {
            $conn->insert($viewTable, [
                'view_id'    => 1,
                'label'      => 'Default',
                'is_active'  => 1,
                'is_default' => 1,
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
