<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Controller\Adminhtml\View;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Panth\AdminMenuManager\Service\ViewService;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdminMenuManager::manage_overrides';

    public function __construct(Context $context, private readonly ViewService $views)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $req = $this->getRequest();
        $viewId = (int) $req->getPost('view_id', 0);
        $label = (string) $req->getPost('label', '');

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        try {
            if ($viewId > 0) {
                $this->views->rename($viewId, $label);
                $this->messageManager->addSuccessMessage(__('View renamed.'));
                $resultViewId = $viewId;
            } else {
                $resultViewId = $this->views->createView($label);
                $this->messageManager->addSuccessMessage(__('View "%1" created.', $label));
            }
            return $redirect->setPath('panth_menu_manager/manager/index', ['view_id' => $resultViewId]);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('panth_menu_manager/manager/index', ['view_id' => $viewId ?: null]);
        }
    }
}
