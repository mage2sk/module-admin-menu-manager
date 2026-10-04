<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Controller\Adminhtml\Manager;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;
use Panth\AdminMenuManager\Service\ViewService;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_AdminMenuManager::manage_overrides';

    public function __construct(
        Action\Context $context,
        private readonly ViewService $views
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $this->views->ensureDefaultView();
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Panth_AdminMenuManager::menu_manager');
        $page->getConfig()->getTitle()->prepend(__('Admin Menu Manager'));
        return $page;
    }
}
