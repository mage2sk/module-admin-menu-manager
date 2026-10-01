<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Model\ResourceModel\Override;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\AdminMenuManager\Model\Override;
use Panth\AdminMenuManager\Model\ResourceModel\Override as OverrideResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Override::class, OverrideResource::class);
    }
}
