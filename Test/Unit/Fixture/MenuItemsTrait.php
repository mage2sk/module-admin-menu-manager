<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Fixture;

use Magento\Backend\Model\Menu;
use Magento\Backend\Model\Menu\Config as MenuConfig;
use Magento\Backend\Model\Menu\Item;
use Magento\Backend\Model\Menu\Item\Factory;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a menu config whose top level holds stub items with the given id => title pairs.
 */
trait MenuItemsTrait
{
    protected function menuConfig(array $titlesById): MenuConfig
    {
        $menu = new Menu(
            $this->createStub(LoggerInterface::class),
            '',
            $this->createStub(Factory::class),
            $this->createStub(SerializerInterface::class)
        );
        $index = 0;
        foreach ($titlesById as $id => $title) {
            $item = $this->createStub(Item::class);
            $item->method('getId')->willReturn((string)$id);
            $item->method('getTitle')->willReturn($title);
            $menu->add($item, null, $index++);
        }
        $config = $this->createStub(MenuConfig::class);
        $config->method('getMenu')->willReturn($menu);
        return $config;
    }
}
