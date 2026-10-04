<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Controller\Adminhtml\Manager;

use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Panth\AdminMenuManager\Controller\Adminhtml\Manager\Index;
use Panth\AdminMenuManager\Service\ViewService;
use Panth\AdminMenuManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class IndexTest extends ControllerTestCase
{
    public function testSeedsDefaultViewAndPreparesPage(): void
    {
        $title = $this->createMock(Title::class);
        $title->expects($this->once())->method('prepend')
            ->with($this->callback(static fn($t) => (string)$t === 'Admin Menu Manager'));
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);

        $page = $this->createMock(Page::class);
        $page->expects($this->once())->method('setActiveMenu')->with('Panth_AdminMenuManager::menu_manager');
        $page->method('getConfig')->willReturn($config);

        $views = $this->createMock(ViewService::class);
        $views->expects($this->once())->method('ensureDefaultView');

        $this->assertSame($page, (new Index($this->context([], [], $page), $views))->execute());
    }
}
