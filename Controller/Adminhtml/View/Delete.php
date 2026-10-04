<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Controller\Adminhtml\View;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Panth\AdminMenuManager\Service\ViewService;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdminMenuManager::manage_overrides';

    public function __construct(Context $context, private readonly ViewService $views)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $viewId = (int) $this->getRequest()->getPost('view_id', 0);
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        try {
            $this->views->deleteView($viewId);
            $this->messageManager->addSuccessMessage(__('View deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect->setPath('panth_menu_manager/manager/index');
    }
}
