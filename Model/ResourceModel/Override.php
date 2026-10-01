<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Override extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_admin_menu_override', 'override_id');
    }
}
