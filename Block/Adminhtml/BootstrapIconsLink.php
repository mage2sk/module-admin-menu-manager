<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\AdminMenuManager\Service\MenuOverrideService;

class BootstrapIconsLink extends Template
{
    protected $_template = 'Panth_AdminMenuManager::bootstrap-icons-link.phtml';

    public const STYLESHEET = 'Panth_AdminMenuManager::css/bootstrap-icons/bootstrap-icons.min.css';

    private const MANAGER_ACTION = 'panth_menu_manager_manager_index';

    public function __construct(
        Context $context,
        private readonly MenuOverrideService $service,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getStylesheetUrl(): string
    {
        return $this->getViewFileUrl(self::STYLESHEET);
    }

    public function isNeeded(): bool
    {
        if ($this->getRequest()->getFullActionName() === self::MANAGER_ACTION) {
            return true;
        }
        try {
            $overrides = $this->service->getActiveOverridesForCurrentUser();
        } catch (\Throwable $e) {
            return false;
        }
        foreach ($overrides as $cfg) {
            $icon = trim((string)($cfg['custom_icon'] ?? ''));
            if ($icon !== '' && preg_match('/^bi-[a-z0-9-]+$/i', $icon)) {
                return true;
            }
        }
        return false;
    }
}
