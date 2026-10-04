<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Service;

use Magento\Framework\App\CacheInterface;

class MenuCache
{
    public const TAG_PREFIX = 'PANTH_ADMIN_MENU_VIEW_';

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getTag(int $viewId): string
    {
        return self::TAG_PREFIX . $viewId;
    }

    public function cleanView(int $viewId): void
    {
        $this->cache->clean([$this->getTag($viewId)]);
    }
}
