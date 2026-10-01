<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Menu\Config as MenuConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;

class Config extends Template
{
    private const XML_PATH_ENABLED         = 'panth_drilldown/general/enabled';
    private const XML_PATH_TARGETS         = 'panth_drilldown/general/targets';
    private const XML_PATH_NEW_TAB_TARGETS = 'panth_drilldown/general/new_tab_targets';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $jsonSerializer,
        private readonly MenuConfig $menuConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getConfigJson(): string
    {
        $enabled = $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
        $rawTargets = (string)$this->scopeConfig->getValue(self::XML_PATH_TARGETS);
        $targets = array_values(array_filter(array_map('trim', explode(',', $rawTargets))));

        $locked = $this->getLockedTargets();
        $targets = array_values(array_unique(array_merge($locked, $targets)));

        $matchKeys = array_map([$this, 'normaliseKey'], $targets);

        $rawNewTab = (string)$this->scopeConfig->getValue(self::XML_PATH_NEW_TAB_TARGETS);
        $newTabIds = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', $rawNewTab) ?: []
        )));
        $newTabKeys = array_map([$this, 'normaliseKey'], $newTabIds);

        $payload = [
            'enabled'    => $enabled,
            'allTargets' => false,
            'matchKeys'  => $matchKeys,
            'targetIds'  => $targets,
            'newTabKeys' => $newTabKeys,
            'newTabIds'  => $newTabIds,
        ];

        return str_replace(
            ['<', '>', '&'],
            ['\u003c', '\u003e', '\u0026'],
            (string)$this->jsonSerializer->serialize($payload)
        );
    }

    private function normaliseKey(string $menuId): string
    {
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($menuId)), '-');
    }

    private function getLockedTargets(): array
    {
        $locked = [];
        foreach ($this->menuConfig->getMenu() as $item) {
            $id = (string)$item->getId();
            if ($id !== '' && strpos($id, 'Panth_') === 0) {
                $locked[] = $id;
            }
        }
        return $locked;
    }
}
