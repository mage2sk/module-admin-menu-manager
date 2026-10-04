<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Plugin\Backend;

use Magento\Backend\Block\AnchorRenderer;
use Magento\Backend\Model\Menu\Item;
use Panth\AdminMenuManager\Service\MenuOverrideService;

class AnchorRendererPlugin
{
    public function __construct(private readonly MenuOverrideService $service)
    {
    }

    public function afterRenderAnchor(
        AnchorRenderer $subject,
        string $html,
        $activeItem,
        Item $menuItem,
        $level
    ): string {
        $overrides = $this->service->getActiveOverridesForCurrentUser();
        $cfg = $overrides[(string)$menuItem->getId()] ?? null;
        if (!$cfg || empty($cfg['custom_icon'])) {
            return $html;
        }

        $icon = trim((string)$cfg['custom_icon']);
        if ($icon === '') {
            return $html;
        }

        if (preg_match('/^bi-[a-z0-9-]+$/i', $icon)) {
            $iconHtml = '<i class="bi ' . htmlspecialchars(strtolower($icon), ENT_QUOTES, 'UTF-8')
                . ' panth-menu-bi-icon" aria-hidden="true"></i>';
        } else {
            $iconHtml = '<span class="panth-menu-emoji-icon" aria-hidden="true">'
                . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        $modified = preg_replace('/<span>/', $iconHtml . '<span>', $html, 1);
        return is_string($modified) && $modified !== '' ? $modified : $html;
    }
}
