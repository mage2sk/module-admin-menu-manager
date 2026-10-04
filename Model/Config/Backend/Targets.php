<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Model\Config\Backend;

use Magento\Backend\Model\Menu\Config as MenuConfig;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Event\ManagerInterface;

class Targets extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly MenuConfig $menuConfig,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $raw = $this->getValue();
        if (is_array($raw)) {
            $selected = array_values(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                $raw
            )));
        } else {
            $selected = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $raw)
            )));
        }

        $locked = [];
        foreach ($this->menuConfig->getMenu() as $item) {
            $id = (string)$item->getId();
            if ($id !== '' && strpos($id, 'Panth_') === 0) {
                $locked[] = $id;
            }
        }

        $merged = array_values(array_unique(array_merge($locked, $selected)));
        $this->setValue(implode(',', $merged));

        return parent::beforeSave();
    }
}
