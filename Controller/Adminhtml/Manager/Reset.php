<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Controller\Adminhtml\Manager;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use Panth\AdminMenuManager\Service\ViewService;

class Reset extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdminMenuManager::manage_overrides';

    public function __construct(
        Context $context,
        private readonly MenuOverrideService $service,
        private readonly ViewService $views
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (string) $this->getRequest()->getPost('menu_item_id', '');
        $viewId = (int) $this->getRequest()->getPost('view_id', 0);
        $this->views->ensureDefaultView();
        if ($viewId <= 0) {
            $viewId = $this->views->getActiveViewIdForCurrentUser();
        }

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        if ($id === '') {
            $this->messageManager->addErrorMessage(__('Missing menu item id.'));
            return $redirect->setPath('panth_menu_manager/manager/index', ['view_id' => $viewId]);
        }
        try {
            if ($this->service->reset($viewId, $id)) {
                $this->messageManager->addSuccessMessage(__('Override cleared.'));
            } else {
                $this->messageManager->addNoticeMessage(__('No override stored for that item.'));
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect->setPath('panth_menu_manager/manager/index', ['view_id' => $viewId]);
    }
}
