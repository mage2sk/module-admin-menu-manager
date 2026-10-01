<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\AdminMenuManager\Model\ResourceModel\View as ViewResource;

class View extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ViewResource::class);
    }
}
