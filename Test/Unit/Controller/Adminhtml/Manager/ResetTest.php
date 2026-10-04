<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Controller\Adminhtml\Manager;

use Panth\AdminMenuManager\Controller\Adminhtml\Manager\Reset;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use Panth\AdminMenuManager\Service\ViewService;
use Panth\AdminMenuManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class ResetTest extends ControllerTestCase
{
    private function views(): ViewService
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->once())->method('ensureDefaultView');
        $views->method('getActiveViewIdForCurrentUser')->willReturn(7);
        return $views;
    }

    public function testMissingItemIdIsAnErrorAndNothingIsReset(): void
    {
        $service = $this->createMock(MenuOverrideService::class);
        $service->expects($this->never())->method('reset');

        (new Reset($this->context(['view_id' => '3']), $service, $this->views()))->execute();

        $this->assertSame(['Missing menu item id.'], $this->messages['error']);
        $this->assertSame(['path' => 'panth_menu_manager/manager/index', 'params' => ['view_id' => 3]], $this->redirect);
    }

    public function testSuccessfulResetUsesGivenView(): void
    {
        $service = $this->createMock(MenuOverrideService::class);
        $service->expects($this->once())->method('reset')->with(3, 'A::a')->willReturn(true);

        (new Reset($this->context(['view_id' => '3', 'menu_item_id' => 'A::a']), $service, $this->views()))->execute();

        $this->assertSame(['Override cleared.'], $this->messages['success']);
        $this->assertSame(['view_id' => 3], $this->redirect['params']);
    }

    public function testMissingViewFallsBackToActiveViewAndReportsNoOverride(): void
    {
        $service = $this->createMock(MenuOverrideService::class);
        $service->expects($this->once())->method('reset')->with(7, 'A::a')->willReturn(false);

        (new Reset($this->context(['menu_item_id' => 'A::a']), $service, $this->views()))->execute();

        $this->assertSame(['No override stored for that item.'], $this->messages['notice']);
        $this->assertSame(['view_id' => 7], $this->redirect['params']);
    }

    public function testExceptionBecomesErrorMessage(): void
    {
        $service = $this->createStub(MenuOverrideService::class);
        $service->method('reset')->willThrowException(new \RuntimeException('boom'));

        (new Reset($this->context(['menu_item_id' => 'A::a', 'view_id' => 2]), $service, $this->views()))->execute();

        $this->assertSame(['boom'], $this->messages['error']);
        $this->assertSame('panth_menu_manager/manager/index', $this->redirect['path']);
    }
}
