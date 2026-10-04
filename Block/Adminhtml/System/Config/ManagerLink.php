<?php
declare(strict_types=1);

namespace Panth\AdminMenuManager\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ManagerLink extends Field
{
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _renderScopeLabel(AbstractElement $element)
    {
        return '';
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $url = $this->getUrl('panth_menu_manager/manager/index');
        $label = __('Open Admin Menu Manager');
        $hint = __('Enable, disable, rename and reorder individual admin menu items (sets per-item sort order).');

        return '<div class="panth-mm-callout">'
            . '<div class="panth-mm-callout__text">' . $this->escapeHtml($hint) . '</div>'
            . '<a href="' . $this->escapeUrl($url) . '" class="action-primary panth-mm-callout__btn">'
            . $this->escapeHtml($label) . ' &rarr;'
            . '</a>'
            . '<style>'
            . '.panth-mm-callout{display:flex;align-items:center;gap:18px;padding:14px 18px;background:#f5f5f5;border:1px solid #ccc;}'
            . '.panth-mm-callout__text{flex:1 1 auto;color:#41362f;font-size:13px;line-height:1.4;}'

            . '.panth-mm-callout__btn{flex:0 0 auto;height:34px;padding:0 18px;background:#eb5202;color:#fff;'
            . 'font-size:13px;font-weight:600;line-height:34px;border:1px solid #eb5202;border-radius:3px;text-decoration:none;'
            . 'display:inline-flex;align-items:center;transition:background-color .1s ease,border-color .1s ease;}'
            . '.panth-mm-callout__btn:hover,.panth-mm-callout__btn:focus{background:#ba4000;border-color:#ba4000;color:#fff;text-decoration:none;}'
            . '</style>'
            . '</div>';
    }
}
