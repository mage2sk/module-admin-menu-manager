<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Model\Config\Source;

use Magento\Backend\Model\Menu\Config as MenuConfig;
use Magento\Backend\Model\Menu\Item;
use Magento\Framework\Option\ArrayInterface;

class MenuItems implements ArrayInterface
{
    public function __construct(
        private readonly MenuConfig $menuConfig
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        $menu = $this->menuConfig->getMenu();

        foreach ($menu as $item) {
            $title = (string)$item->getTitle();
            $id = (string)$item->getId();
            $options[] = [
                'value' => $id,
                'label' => $title !== '' ? sprintf('%s - %s', $title, $id) : $id,
            ];
        }
        usort($options, fn($a, $b) => strcmp((string)$a['label'], (string)$b['label']));
        return $options;
    }
}
