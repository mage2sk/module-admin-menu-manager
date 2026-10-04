<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class View extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_admin_menu_view', 'view_id');
    }
}
