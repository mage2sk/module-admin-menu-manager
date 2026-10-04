<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Controller\Adminhtml\View;

use Magento\Framework\Exception\LocalizedException;
use Panth\AdminMenuManager\Controller\Adminhtml\View\Activate;
use Panth\AdminMenuManager\Controller\Adminhtml\View\Delete;
use Panth\AdminMenuManager\Controller\Adminhtml\View\Duplicate;
use Panth\AdminMenuManager\Controller\Adminhtml\View\Save;
use Panth\AdminMenuManager\Service\ViewService;
use Panth\AdminMenuManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;
use Panth\AdminMenuManager\Test\Unit\Fixture\AuthSessionDouble;

class ViewControllersTest extends ControllerTestCase
{
    private const INDEX = 'panth_menu_manager/manager/index';

    private function existingViews(array $ids): ViewService
    {
        $views = $this->createMock(ViewService::class);
        $views->method('getView')->willReturnCallback(
            static fn(int $id) => in_array($id, $ids, true) ? ['view_id' => $id] : null
        );
        return $views;
    }

    public function testActivateUnknownViewIsAnError(): void
    {
        $views = $this->existingViews([]);
        $views->expects($this->never())->method('activateGlobally');
        $views->expects($this->never())->method('setActiveForUser');

        (new Activate($this->context(['view_id' => '9', 'scope' => 'global']), $views, new AuthSessionDouble(5)))
            ->execute();

        $this->assertSame(['View not found.'], $this->messages['error']);
        $this->assertSame(['path' => self::INDEX, 'params' => ['view_id' => 9]], $this->redirect);
    }

    public function testActivateGlobalScope(): void
    {
        $views = $this->existingViews([3]);
        $views->expects($this->once())->method('activateGlobally')->with(3);
        $views->expects($this->never())->method('setActiveForUser');

        (new Activate($this->context(['view_id' => '3', 'scope' => 'global']), $views, new AuthSessionDouble(5)))
            ->execute();

        $this->assertSame(['View activated as system default.'], $this->messages['success']);
    }

    public function testActivateDefaultsToCurrentUserScope(): void
    {
        $views = $this->existingViews([3]);
        $views->expects($this->once())->method('setActiveForUser')->with(3, 5);
        $views->expects($this->never())->method('activateGlobally');

        (new Activate($this->context(['view_id' => '3']), $views, new AuthSessionDouble(5)))->execute();

        $this->assertSame(['View activated for your account. Refresh to see it.'], $this->messages['success']);
    }

    public function testActivateForUserWithoutSessionDoesNothing(): void
    {
        $views = $this->existingViews([3]);
        $views->expects($this->never())->method('setActiveForUser');

        (new Activate($this->context(['view_id' => '3', 'scope' => 'me']), $views, new AuthSessionDouble(null)))
            ->execute();

        $this->assertSame([], $this->messages['success']);
        $this->assertSame([], $this->messages['error']);
        $this->assertSame(self::INDEX, $this->redirect['path']);
    }

    public function testActivateFailureIsReported(): void
    {
        $views = $this->existingViews([3]);
        $views->expects($this->once())->method('activateGlobally')->willThrowException(new \RuntimeException('locked'));

        (new Activate($this->context(['view_id' => '3', 'scope' => 'global']), $views, new AuthSessionDouble(1)))
            ->execute();

        $this->assertSame(['locked'], $this->messages['error']);
    }

    public function testDeleteSuccess(): void
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->once())->method('deleteView')->with(4);

        (new Delete($this->context(['view_id' => '4']), $views))->execute();

        $this->assertSame(['View deleted.'], $this->messages['success']);
        $this->assertSame(['path' => self::INDEX, 'params' => []], $this->redirect);
    }

    public function testDeleteOfDefaultViewShowsServiceError(): void
    {
        $views = $this->createStub(ViewService::class);
        $views->method('deleteView')->willThrowException(
            new LocalizedException(__('The Default view cannot be deleted.'))
        );

        (new Delete($this->context(['view_id' => '1']), $views))->execute();

        $this->assertSame(['The Default view cannot be deleted.'], $this->messages['error']);
        $this->assertSame([], $this->messages['success']);
    }

    public function testDuplicateRedirectsToNewView(): void
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->once())->method('duplicate')->with(2, 'Copy')->willReturn(11);

        (new Duplicate($this->context(['view_id' => '2', 'label' => 'Copy']), $views))->execute();

        $this->assertSame(['View duplicated as "Copy".'], $this->messages['success']);
        $this->assertSame(['view_id' => 11], $this->redirect['params']);
    }

    public function testDuplicateFailureReturnsToSourceOrNull(): void
    {
        $views = $this->createStub(ViewService::class);
        $views->method('duplicate')->willThrowException(new LocalizedException(__('Source view not found.')));

        (new Duplicate($this->context(['view_id' => '2', 'label' => 'Copy']), $views))->execute();
        $this->assertSame(['Source view not found.'], $this->messages['error']);
        $this->assertSame(['view_id' => 2], $this->redirect['params']);

        (new Duplicate($this->context(['label' => 'Copy']), $views))->execute();
        $this->assertSame(['view_id' => null], $this->redirect['params']);
    }

    public function testSaveRenamesExistingView(): void
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->once())->method('rename')->with(6, 'Ops');
        $views->expects($this->never())->method('createView');

        (new Save($this->context(['view_id' => '6', 'label' => 'Ops']), $views))->execute();

        $this->assertSame(['View renamed.'], $this->messages['success']);
        $this->assertSame(['view_id' => 6], $this->redirect['params']);
    }

    public function testSaveWithoutIdCreatesView(): void
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->once())->method('createView')->with('Marketing')->willReturn(12);
        $views->expects($this->never())->method('rename');

        (new Save($this->context(['label' => 'Marketing']), $views))->execute();

        $this->assertSame(['View "Marketing" created.'], $this->messages['success']);
        $this->assertSame(['view_id' => 12], $this->redirect['params']);
    }

    public function testSaveBlankLabelShowsErrorAndKeepsContext(): void
    {
        $views = $this->createStub(ViewService::class);
        $views->method('createView')->willThrowException(new LocalizedException(__('View name cannot be blank.')));
        $views->method('rename')->willThrowException(new LocalizedException(__('View name cannot be blank.')));

        (new Save($this->context(['label' => '']), $views))->execute();
        $this->assertSame(['View name cannot be blank.'], $this->messages['error']);
        $this->assertSame(['view_id' => null], $this->redirect['params']);

        (new Save($this->context(['view_id' => '5', 'label' => '']), $views))->execute();
        $this->assertSame(['view_id' => 5], $this->redirect['params']);
    }

    public function testSaveUsesCleanedLabelForServiceAndMessage(): void
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->once())->method('createView')->with('Marketing team')->willReturn(14);

        (new Save($this->context(['label' => "  <b>Marketing</b> team \n"]), $views))->execute();

        $this->assertSame(['View "Marketing team" created.'], $this->messages['success']);
        $this->assertSame(['view_id' => 14], $this->redirect['params']);
    }

    public function testSaveBlankLabelNeverReachesTheService(): void
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->never())->method('createView');
        $views->expects($this->never())->method('rename');

        (new Save($this->context(['label' => '   ']), $views))->execute();
        (new Save($this->context(['view_id' => '3', 'label' => '<i></i>']), $views))->execute();

        $this->assertSame(['View name cannot be blank.'], $this->messages['error']);
        $this->assertSame(['view_id' => 3], $this->redirect['params']);
    }

    public function testDuplicateBlankLabelIsRejectedBeforeCopying(): void
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->never())->method('duplicate');

        (new Duplicate($this->context(['view_id' => '2', 'label' => ' ']), $views))->execute();

        $this->assertSame(['View name cannot be blank.'], $this->messages['error']);
        $this->assertSame([], $this->messages['success']);
        $this->assertSame(['view_id' => 2], $this->redirect['params']);
    }

    public function testDuplicateUsesCleanedLabel(): void
    {
        $views = $this->createMock(ViewService::class);
        $views->expects($this->once())->method('duplicate')->with(2, 'Ops copy')->willReturn(15);

        (new Duplicate($this->context(['view_id' => '2', 'label' => ' Ops copy ']), $views))->execute();

        $this->assertSame(['View duplicated as "Ops copy".'], $this->messages['success']);
    }
}
