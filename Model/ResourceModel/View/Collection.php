<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Model\ResourceModel\View;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\AdminMenuManager\Model\View;
use Panth\AdminMenuManager\Model\ResourceModel\View as ViewResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(View::class, ViewResource::class);
    }
}
