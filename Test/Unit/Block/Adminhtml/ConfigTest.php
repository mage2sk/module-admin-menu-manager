<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Block\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\AdminMenuManager\Block\Adminhtml\Config;
use Panth\AdminMenuManager\Test\Unit\Fixture\MenuItemsTrait;

class ConfigTest extends BlockTestCase
{
    use MenuItemsTrait;

    private function json(bool $enabled, ?string $targets, ?string $newTab, array $menu = []): string
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn($enabled);
        $scope->method('getValue')->willReturnCallback(static fn(string $path) => [
            'panth_drilldown/general/targets' => $targets,
            'panth_drilldown/general/new_tab_targets' => $newTab,
        ][$path] ?? null);

        return (new Config($this->context(), $scope, new Json(), $this->menuConfig($menu)))->getConfigJson();
    }

    public function testPayloadMergesLockedTargetsAndNormalisesKeys(): void
    {
        $payload = json_decode($this->json(
            true,
            'Magento_Sales::sales, ,Panth_Core::panth',
            "Magento_Catalog::catalog\r\nMagento_Customer::customer,,",
            ['Panth_Core::panth' => 'Panth', 'Panth_Faq::faq' => 'FAQ', 'Magento_Sales::sales' => 'Sales']
        ), true);

        $this->assertSame(
            [
                'enabled' => true,
                'allTargets' => false,
                'matchKeys' => ['panth-core-panth', 'panth-faq-faq', 'magento-sales-sales'],
                'targetIds' => ['Panth_Core::panth', 'Panth_Faq::faq', 'Magento_Sales::sales'],
                'newTabKeys' => ['magento-catalog-catalog', 'magento-customer-customer'],
                'newTabIds' => ['Magento_Catalog::catalog', 'Magento_Customer::customer'],
            ],
            $payload
        );
    }

    public function testEmptyConfigGivesEmptyLists(): void
    {
        $payload = json_decode($this->json(false, null, null), true);

        $this->assertFalse($payload['enabled']);
        $this->assertSame([], $payload['targetIds']);
        $this->assertSame([], $payload['matchKeys']);
        $this->assertSame([], $payload['newTabIds']);
    }

    public function testHtmlSensitiveCharactersAreUnicodeEscaped(): void
    {
        $json = $this->json(true, '</script><b>&amp', null);

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        $this->assertStringNotContainsString('&', $json);
        $this->assertSame(['</script><b>&amp'], json_decode($json, true)['targetIds']);
        $this->assertSame(['script-b-amp'], json_decode($json, true)['matchKeys']);
    }
}
