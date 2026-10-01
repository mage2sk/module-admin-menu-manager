<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Controller\Adminhtml\Manager;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Panth\AdminMenuManager\Service\MenuOverrideService;
use Panth\AdminMenuManager\Service\ViewService;

class Save extends Action implements HttpPostActionInterface
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
        $req = $this->getRequest();
        $this->views->ensureDefaultView();
        $viewId = (int) $req->getParam('view_id', 0);
        if ($viewId <= 0 || !$this->views->getView($viewId)) {
            $viewId = $this->views->getActiveViewIdForCurrentUser();
        }

        $rawItems = (array) $req->getPost('items', []);
        $payload = [];
        foreach ($rawItems as $id => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }

            $defaultSort = isset($cfg['default_sort']) && $cfg['default_sort'] !== ''
                ? (int) $cfg['default_sort']
                : null;

            $sortRaw = $cfg['sort_order'] ?? '';
            $sortVal = ($sortRaw === '' || $sortRaw === null) ? null : (int) $sortRaw;

            if ($sortVal !== null && $defaultSort !== null && $sortVal === $defaultSort) {
                $sortVal = null;
            }

            $payload[] = [
                'menu_item_id'                => (string) $id,
                'is_disabled'                 => !empty($cfg['is_disabled']) ? 1 : 0,
                'custom_label'                => isset($cfg['custom_label']) ? (string) $cfg['custom_label'] : '',
                'custom_icon'                 => isset($cfg['custom_icon']) ? (string) $cfg['custom_icon'] : '',
                'custom_color'                => isset($cfg['custom_color']) ? (string) $cfg['custom_color'] : '',
                'custom_parent_menu_item_id'  => isset($cfg['custom_parent_menu_item_id']) ? (string) $cfg['custom_parent_menu_item_id'] : '',
                'sort_order'                  => $sortVal,
            ];
        }

        try {
            $this->service->bulkUpsert($viewId, $payload);
            $this->messageManager->addSuccessMessage(
                __('Saved view "%1". Reload the page to see the new menu.', $this->views->getView($viewId)['label'] ?? '')
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('panth_menu_manager/manager/index', ['view_id' => $viewId]);
    }
}
