<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\AdminMenuManager\Model\ResourceModel\Override as OverrideResource;

class Override extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(OverrideResource::class);
    }
}
