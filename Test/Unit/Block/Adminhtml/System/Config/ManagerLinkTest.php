<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Test\Unit\Block\Adminhtml\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Panth\AdminMenuManager\Block\Adminhtml\System\Config\ManagerLink;
use Panth\AdminMenuManager\Test\Unit\Block\Adminhtml\BlockTestCase;

class ManagerLinkTest extends BlockTestCase
{
    private function invoke(string $method): string
    {
        $block = new ManagerLink($this->context());
        $ref = new \ReflectionMethod($block, $method);
        return (string)$ref->invoke($block, $this->createStub(AbstractElement::class));
    }

    public function testElementHtmlLinksToManagerWithEscapedText(): void
    {
        $html = $this->invoke('_getElementHtml');

        $this->assertStringContainsString('href="https://admin.test/panth_menu_manager/manager/index"', $html);
        $this->assertStringContainsString('Open Admin Menu Manager &rarr;</a>', $html);
        $this->assertStringContainsString('Enable, disable, rename and reorder individual admin menu items', $html);
        $this->assertStringStartsWith('<div class="panth-mm-callout">', $html);
        $this->assertStringEndsWith('</style></div>', $html);
    }

    public function testScopeLabelIsSuppressed(): void
    {
        $this->assertSame('', $this->invoke('_renderScopeLabel'));
    }
}
