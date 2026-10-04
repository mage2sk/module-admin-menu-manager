<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Plugin\Backend;

use Magento\Backend\Block\Menu as MenuBlock;
use Panth\AdminMenuManager\Service\MenuCache;
use Panth\AdminMenuManager\Service\ViewService;

class MenuBlockPlugin
{
    private ?int $viewId = null;

    public function __construct(
        private readonly ViewService $viewService,
        private readonly MenuCache $menuCache
    ) {
    }

    public function afterGetCacheKeyInfo(MenuBlock $subject, array $result): array
    {
        $result[] = $this->menuCache->getTag($this->getViewId());
        return $result;
    }

    public function beforeToHtml(MenuBlock $subject): void
    {
        $tags = (array)($subject->getData('cache_tags') ?? []);
        $tag = $this->menuCache->getTag($this->getViewId());
        if (!in_array($tag, $tags, true)) {
            $tags[] = $tag;
            $subject->setData('cache_tags', $tags);
        }
    }

    private function getViewId(): int
    {
        if ($this->viewId === null) {
            try {
                $this->viewId = $this->viewService->getActiveViewIdForCurrentUser();
            } catch (\Throwable $e) {
                $this->viewId = 0;
            }
        }
        return $this->viewId;
    }
}
