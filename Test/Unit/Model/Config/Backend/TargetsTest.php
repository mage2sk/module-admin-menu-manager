<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Registry;
use Panth\AdminMenuManager\Model\Config\Backend\Targets;
use Panth\AdminMenuManager\Test\Unit\Fixture\MenuItemsTrait;
use PHPUnit\Framework\TestCase;

class TargetsTest extends TestCase
{
    use MenuItemsTrait;

    private function save($value, array $menu): string
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));
        $model = new Targets(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            $this->menuConfig($menu)
        );
        $model->setValue($value);
        $model->beforeSave();
        return (string)$model->getValue();
    }

    public function testCommaSeparatedValueIsTrimmedAndOwnMenusLockedFirst(): void
    {
        $value = $this->save(' Magento_Sales::sales , ,Magento_Catalog::catalog ', [
            'Magento_Sales::sales' => 'Sales',
            'Panth_Core::panth' => 'Panth',
        ]);

        $this->assertSame('Panth_Core::panth,Magento_Sales::sales,Magento_Catalog::catalog', $value);
    }

    public function testArrayValueIsNormalisedAndDeduplicated(): void
    {
        $value = $this->save(
            [' Panth_Core::panth', 'Magento_Sales::sales', '', 'Magento_Sales::sales'],
            ['Panth_Core::panth' => 'Panth', 'Panth_Blog::blog' => 'Blog']
        );

        $this->assertSame('Panth_Core::panth,Panth_Blog::blog,Magento_Sales::sales', $value);
    }

    public function testEmptySelectionStillKeepsLockedTargets(): void
    {
        $this->assertSame('Panth_Faq::faq', $this->save('', ['Panth_Faq::faq' => 'FAQ', 'Magento_Sales::sales' => 'S']));
        $this->assertSame('', $this->save(null, ['Magento_Sales::sales' => 'Sales']));
    }

    public function testOnlyIdsStartingWithPrefixAreLocked(): void
    {
        $value = $this->save('', ['Vendor_Panth::x' => 'Not ours', 'panth_lower::x' => 'Lowercase']);

        $this->assertSame('', $value);
    }
}
