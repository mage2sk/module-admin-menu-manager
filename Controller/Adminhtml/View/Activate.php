<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Controller\Adminhtml\View;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Panth\AdminMenuManager\Service\ViewService;

class Activate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdminMenuManager::manage_overrides';

    public function __construct(
        Context $context,
        private readonly ViewService $views,
        private readonly AuthSession $authSession
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $req = $this->getRequest();
        $viewId = (int) $req->getPost('view_id', 0);
        $scope = (string) $req->getPost('scope', 'me');

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        try {
            $userId = (int) ($this->authSession->getUser()?->getId() ?? 0);
            if (!$this->views->getView($viewId)) {
                $this->messageManager->addErrorMessage(__('View not found.'));
            } elseif ($scope === 'global') {
                $this->views->activateGlobally($viewId);
                $this->messageManager->addSuccessMessage(__('View activated as system default.'));
            } elseif ($userId > 0) {
                $this->views->setActiveForUser($viewId, $userId);
                $this->messageManager->addSuccessMessage(__('View activated for your account. Refresh to see it.'));
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect->setPath('panth_menu_manager/manager/index', ['view_id' => $viewId]);
    }
}
