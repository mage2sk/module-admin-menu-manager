<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Service;

use Magento\Backend\Model\Menu\Config\Reader as MenuConfigReader;
use Magento\Framework\App\Area;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

class MenuTree
{
    public const ROOT_SENTINEL = '__root__';

    public const CACHE_KEY = 'PANTH_ADMIN_MENU_STOCK_TREE';

    private ?array $stockParents = null;

    private array $stockSorts = [];

    public function __construct(
        private readonly MenuConfigReader $configReader,
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    public function getStockParents(): array
    {
        $this->load();
        return $this->stockParents;
    }

    public function getStockSortOrders(): array
    {
        $this->load();
        return $this->stockSorts;
    }

    private function load(): void
    {
        if ($this->stockParents !== null) {
            return;
        }
        $cached = $this->cache->load(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            $data = $this->json->unserialize($cached);
            if (is_array($data) && isset($data['parents'], $data['sorts'])) {
                $this->stockParents = (array)$data['parents'];
                $this->stockSorts = (array)$data['sorts'];
                return;
            }
        }
        $parents = [];
        $sorts = [];
        foreach ((array)$this->configReader->read(Area::AREA_ADMINHTML) as $def) {
            if (!is_array($def) || empty($def['id'])) {
                continue;
            }
            if (isset($def['type']) && $def['type'] === 'remove') {
                continue;
            }
            $id = (string)$def['id'];
            $parents[$id] = isset($def['parent']) ? (string)$def['parent'] : '';
            if (isset($def['sortOrder']) && is_numeric($def['sortOrder'])) {
                $sorts[$id] = (int)$def['sortOrder'];
            }
        }
        $this->stockParents = $parents;
        $this->stockSorts = $sorts;
        $this->cache->save(
            $this->json->serialize(['parents' => $parents, 'sorts' => $sorts]),
            self::CACHE_KEY,
            [ConfigCache::CACHE_TAG]
        );
    }

    public function findCyclicParents(array $stockParents, array $fixedParents, array $requestedParents): array
    {
        $effective = $stockParents;
        foreach ($fixedParents as $id => $parent) {
            $effective[(string)$id] = $this->resolveParent((string)$id, $parent, $stockParents);
        }
        foreach ($requestedParents as $id => $parent) {
            $effective[(string)$id] = $this->resolveParent((string)$id, $parent, $stockParents);
        }

        $rejected = [];
        do {
            $changed = false;
            foreach (array_keys($requestedParents) as $id) {
                $id = (string)$id;
                if (isset($rejected[$id]) || !$this->hasCycle($id, $effective)) {
                    continue;
                }
                $rejected[$id] = $id;
                $effective[$id] = $stockParents[$id] ?? '';
                $changed = true;
            }
        } while ($changed);

        return array_values($rejected);
    }

    private function resolveParent(string $id, mixed $parent, array $stockParents): string
    {
        $parent = trim((string)$parent);
        if ($parent === '') {
            return $stockParents[$id] ?? '';
        }
        return $parent === self::ROOT_SENTINEL ? '' : $parent;
    }

    private function hasCycle(string $id, array $effective): bool
    {
        $seen = [];
        $current = $effective[$id] ?? '';
        while ($current !== '') {
            if ($current === $id) {
                return true;
            }
            if (isset($seen[$current])) {
                return false;
            }
            $seen[$current] = true;
            $current = $effective[$current] ?? '';
        }
        return false;
    }
}
