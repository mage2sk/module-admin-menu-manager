<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Model\Config\Source;

use Panth\AdminMenuManager\Model\Config\Source\MenuItems;
use Panth\AdminMenuManager\Test\Unit\Fixture\MenuItemsTrait;
use PHPUnit\Framework\TestCase;

class MenuItemsTest extends TestCase
{
    use MenuItemsTrait;

    public function testOptionsCombineTitleAndIdSortedByLabel(): void
    {
        $source = new MenuItems($this->menuConfig([
            'Magento_Sales::sales' => 'Sales',
            'Magento_Catalog::catalog' => 'Catalog',
            'Vendor_Module::untitled' => '',
        ]));

        $this->assertSame(
            [
                ['value' => 'Magento_Catalog::catalog', 'label' => 'Catalog - Magento_Catalog::catalog'],
                ['value' => 'Magento_Sales::sales', 'label' => 'Sales - Magento_Sales::sales'],
                ['value' => 'Vendor_Module::untitled', 'label' => 'Vendor_Module::untitled'],
            ],
            $source->toOptionArray()
        );
    }

    public function testEmptyMenuGivesNoOptions(): void
    {
        $this->assertSame([], (new MenuItems($this->menuConfig([])))->toOptionArray());
    }
}
