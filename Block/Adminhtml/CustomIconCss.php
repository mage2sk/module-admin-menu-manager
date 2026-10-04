<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\AdminMenuManager\Service\MenuOverrideService;

class CustomIconCss extends Template
{
    protected $_template = 'Panth_AdminMenuManager::custom-icon-css.phtml';

    public function __construct(
        Context $context,
        private readonly MenuOverrideService $service,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getIconSelectors(): array
    {
        try {
            $overrides = $this->service->getActiveOverridesForCurrentUser();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($overrides as $itemId => $cfg) {
            if (empty($cfg['custom_icon'])) {
                continue;
            }
            $jsid = $this->toJsId((string)$itemId);
            $out[] = $jsid;
        }
        return array_values(array_unique($out));
    }

    public function getColorRules(): array
    {
        try {
            $overrides = $this->service->getActiveOverridesForCurrentUser();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($overrides as $itemId => $cfg) {
            if (empty($cfg['custom_color'])) {
                continue;
            }
            $color = (string)$cfg['custom_color'];

            if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color)) {
                continue;
            }
            $out[] = [
                'jsid'  => $this->toJsId((string)$itemId),
                'color' => strtolower($color),
            ];
        }
        return $out;
    }

    private function toJsId(string $itemId): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower('menu-' . $itemId)), '-');
    }
}
