<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Service;

use Magento\Backend\Model\Menu\Config\Reader as MenuConfigReader;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdminMenuManager\Service\MenuTree;
use PHPUnit\Framework\TestCase;

class MenuTreeTest extends TestCase
{
    private const STOCK = [
        'Sales'    => '',
        'Orders'   => 'Sales',
        'Invoices' => 'Sales',
        'Archive'  => 'Orders',
        'Catalog'  => '',
        'Products' => 'Catalog',
    ];

    private function tree(array $defs = []): MenuTree
    {
        $reader = $this->createMock(MenuConfigReader::class);
        $reader->expects($this->atMost(1))->method('read')->with('adminhtml')->willReturn($defs);
        return new MenuTree($reader, $this->createStub(CacheInterface::class), new Json());
    }

    public function testStockParentsSkipRemovedAndInvalidDefinitionsAndAreMemoised(): void
    {
        $tree = $this->tree([
            ['id' => 'Sales', 'title' => 'Sales'],
            ['id' => 'Orders', 'parent' => 'Sales'],
            ['id' => 'Gone', 'type' => 'remove'],
            ['title' => 'No id'],
            'garbage',
        ]);

        $this->assertSame(['Sales' => '', 'Orders' => 'Sales'], $tree->getStockParents());
        $this->assertSame(['Sales' => '', 'Orders' => 'Sales'], $tree->getStockParents());
    }

    public function testStockSortOrdersAreReadAndSavedToConfigCache(): void
    {
        $reader = $this->createMock(MenuConfigReader::class);
        $reader->expects($this->once())->method('read')->willReturn([
            ['id' => 'Sales', 'sortOrder' => '15'],
            ['id' => 'Orders', 'parent' => 'Sales', 'sortOrder' => 10],
            ['id' => 'NoSort'],
        ]);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save')->with(
            $this->isString(),
            MenuTree::CACHE_KEY,
            ['CONFIG']
        );
        $tree = new MenuTree($reader, $cache, new Json());

        $this->assertSame(['Sales' => 15, 'Orders' => 10], $tree->getStockSortOrders());
        $this->assertSame(['Sales' => '', 'Orders' => 'Sales', 'NoSort' => ''], $tree->getStockParents());
    }

    public function testCachedStockTreeSkipsTheXmlReader(): void
    {
        $reader = $this->createMock(MenuConfigReader::class);
        $reader->expects($this->never())->method('read');
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn('{"parents":{"Sales":""},"sorts":{"Sales":15}}');
        $tree = new MenuTree($reader, $cache, new Json());

        $this->assertSame(['Sales' => 15], $tree->getStockSortOrders());
        $this->assertSame(['Sales' => ''], $tree->getStockParents());
    }

    public function testItemCannotBecomeItsOwnParent(): void
    {
        $this->assertSame(['Sales'], $this->tree()->findCyclicParents(self::STOCK, [], ['Sales' => 'Sales']));
    }

    public function testDirectAndDeepDescendantsAreRejected(): void
    {
        $tree = $this->tree();

        $this->assertSame(['Sales'], $tree->findCyclicParents(self::STOCK, [], ['Sales' => 'Orders']));
        $this->assertSame(['Sales'], $tree->findCyclicParents(self::STOCK, [], ['Sales' => 'Archive']));
    }

    public function testValidMovesAndSentinelsAreAccepted(): void
    {
        $tree = $this->tree();

        $this->assertSame([], $tree->findCyclicParents(self::STOCK, [], [
            'Orders'   => 'Catalog',
            'Products' => '__root__',
            'Invoices' => '',
            'Archive'  => 'Sales',
        ]));
    }

    public function testDescendantsFollowOtherMovesInTheSameSave(): void
    {
        $tree = $this->tree();

        $this->assertSame(
            ['Catalog'],
            $tree->findCyclicParents(self::STOCK, [], ['Catalog' => 'Archive', 'Orders' => 'Products'])
        );
    }

    public function testMutualMoveRejectsOnlyOneSide(): void
    {
        $tree = $this->tree();

        $this->assertSame(['Sales'], $tree->findCyclicParents(self::STOCK, [], ['Sales' => 'Products', 'Catalog' => 'Orders']));
    }

    public function testFixedMovesFromStoredOverridesAreHonouredButNeverRejected(): void
    {
        $tree = $this->tree();

        $this->assertSame(['Catalog'], $tree->findCyclicParents(self::STOCK, ['Sales' => 'Products'], ['Catalog' => 'Orders']));
        $this->assertSame([], $tree->findCyclicParents(self::STOCK, ['Sales' => '__root__'], ['Catalog' => 'Orders']));
    }

    public function testUnknownParentIsNotACycle(): void
    {
        $this->assertSame([], $this->tree()->findCyclicParents(self::STOCK, [], ['Orders' => 'Missing::parent']));
    }
}
