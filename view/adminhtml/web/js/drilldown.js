/**
 * Panth Admin Menu Drilldown
 *
 * Rides on top of Magento's globalNavigation: lets it open/close the L0 panel
 * via its _show class. We only:
 *   - tag selected L0 LIs with .panth-target so CSS applies our chrome
 *   - inject back button + search box into each target panel
 *   - handle drill-down clicks on L1+ parents inside the panel
 *   - reset drill / search state when Magento closes the panel
 *
 * No L0 anchor click is intercepted - Magento's existing click-to-open
 * (and click-overlay-to-close) is preserved.
 */
require(['jquery', 'domReady!'], function ($) {
    'use strict';

    var DEFAULT_CONFIG = {
        enabled: true,
        allTargets: true,
        matchKeys: [],
        targetIds: [],
        newTabKeys: [],   // normalised match keys for items that should open in a new tab
        newTabIds: []     // raw menu IDs (kept for tooltip + fallback equality match)
    };
    var config = window.panthDrilldownConfig || DEFAULT_CONFIG;
    config.newTabKeys = config.newTabKeys || [];
    config.newTabIds = config.newTabIds || [];

    if (!config.enabled) return;

    var nav = document.querySelector('nav.admin__menu');
    if (!nav) return;
    nav.classList.add('panth-drilldown-enabled');
    nav.setAttribute('data-panth-init', '1');
    window.__panthDrilldownLoaded = true;

    function matchesMenuKey(value, key) {
        if (!value || !key) return false;
        if (value === key) return true;
        var suffix = '-' + key;
        return value.length > suffix.length && value.slice(-suffix.length) === suffix;
    }

    function isTargetLi(li) {
        if (config.allTargets) return true;
        var uiId = li.getAttribute('data-ui-id') || '';
        var idAttr = li.id || '';
        return config.matchKeys.some(function (key) {
            if (!key) return false;
            return matchesMenuKey(uiId, key) || matchesMenuKey(idAttr, key);
        });
    }

    var topLis = nav.querySelectorAll(':scope > ul > li');
    Array.prototype.forEach.call(topLis, function (li) {
        var submenu = li.querySelector(':scope > .submenu');
        if (!submenu) return;
        if (!isTargetLi(li)) return;

        li.classList.add('panth-target');
        initPanel(li, submenu);
        watchOpenClose(li, submenu);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            // Trigger Magento's close via the close button on any open target panel.
            var openClose = nav.querySelector(':scope > ul > li.panth-target._show > .submenu > .action-close');
            if (openClose) openClose.click();
        }
    });

    function watchOpenClose(li, submenu) {
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                if (m.attributeName !== 'class') return;
                if (!li.classList.contains('_show')) {
                    resetPanelState(submenu);
                    clearFilter(submenu);
                    if (submenu.__panthSearch) submenu.__panthSearch.value = '';
                } else {
                    var search = submenu.querySelector('.panth-menu-search');
                    if (search) setTimeout(function () { search.focus(); }, 60);
                }
            });
        });
        observer.observe(li, { attributes: true, attributeFilter: ['class'] });
    }

    function initPanel(li, panel) {
        var title = panel.querySelector(':scope > .submenu-title');
        if (!title) return;

        var backBtn = document.createElement('button');
        backBtn.type = 'button';
        backBtn.className = 'panth-back-btn';
        backBtn.setAttribute('aria-label', 'Back');
        backBtn.innerHTML = '<span class="panth-back-arrow" aria-hidden="true">&#8249;</span>';
        title.parentNode.insertBefore(backBtn, title);

        var searchWrap = document.createElement('div');
        searchWrap.className = 'panth-search-wrap';

        var searchIcon = document.createElement('span');
        searchIcon.className = 'panth-search-icon';
        searchIcon.setAttribute('aria-hidden', 'true');
        searchIcon.innerHTML =
            '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">' +
            '<circle cx="7" cy="7" r="4.5" stroke="currentColor" stroke-width="1.5"/>' +
            '<line x1="10.5" y1="10.5" x2="14" y2="14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>' +
            '</svg>';

        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'panth-menu-search';
        search.placeholder = 'Filter menu...';
        search.setAttribute('autocomplete', 'off');
        search.setAttribute('spellcheck', 'false');

        searchWrap.appendChild(searchIcon);
        searchWrap.appendChild(search);

        var insertAfter = panel.querySelector(':scope > .action-close') || title;
        if (insertAfter.nextSibling) {
            panel.insertBefore(searchWrap, insertAfter.nextSibling);
        } else {
            panel.appendChild(searchWrap);
        }

        panel.__panthRootTitle = title.textContent.trim();
        panel.__panthBackBtn = backBtn;
        panel.__panthSearch = search;
        panel.__panthTitle = title;
        panel.__panthStack = [];

        markParents(panel);

        panel.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('.panth-back-btn, .panth-search-wrap, .action-close')) return;

            var liInside = e.target.closest && e.target.closest('li.panth-has-children');
            if (!liInside || !panel.contains(liInside)) return;

            var ownSubmenu = liInside.querySelector(':scope > .submenu');
            if (ownSubmenu && ownSubmenu.contains(e.target)) return;

            e.preventDefault();
            e.stopPropagation();

            if (panel.__panthSearch.value) {
                panel.__panthSearch.value = '';
                clearFilter(panel);
            }

            var label = getOwnLabel(liInside);
            drillInto(panel, liInside, label);
        });

        backBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            drillBack(panel);
        });

        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            if (!q) {
                clearFilter(panel);
                return;
            }
            if (panel.__panthStack.length) resetPanelState(panel);
            applyFilter(panel, q);
        });
        search.addEventListener('click', function (e) { e.stopPropagation(); });
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                if (search.value) {
                    e.stopPropagation();
                    search.value = '';
                    clearFilter(panel);
                }
            }
        });
    }

    function markParents(panel) {
        var lis = panel.querySelectorAll('li');
        Array.prototype.forEach.call(lis, function (li) {
            if (li.querySelector(':scope > .submenu')) {
                li.classList.add('panth-has-children');
            }
        });
        markNewTabLinks(panel);
    }

    /**
     * Tag every <a> inside the panel that the merchant has flagged for
     * "open in new tab" - sets target="_blank" + rel + appends a small
     * external-link glyph so the user knows clicking will open a new
     * window. Items are matched by either their `data-ui-id` or their
     * stock Magento id attribute (matches the same logic isTargetLi
     * uses for the L0 selection).
     */
    function markNewTabLinks(panel) {
        if (!config.newTabKeys.length && !config.newTabIds.length) return;

        var items = panel.querySelectorAll('li');
        Array.prototype.forEach.call(items, function (li) {
            var uiId = li.getAttribute('data-ui-id') || '';
            var idAttr = li.id || '';
            var isMatch = config.newTabKeys.some(function (key) {
                if (!key) return false;
                return matchesMenuKey(uiId, key) || matchesMenuKey(idAttr, key);
            });
            if (!isMatch) return;

            var anchor = li.querySelector(':scope > a');
            if (!anchor || anchor.getAttribute('data-panth-newtab') === '1') return;

            anchor.setAttribute('target', '_blank');
            anchor.setAttribute('rel', 'noopener');
            anchor.setAttribute('data-panth-newtab', '1');
            anchor.setAttribute('title', (anchor.getAttribute('title') || anchor.textContent.trim()) + ' (opens in new tab)');

            // Append an external-link SVG once. Sized to match the row's
            // existing chevron so it doesn't visually dominate.
            if (!anchor.querySelector('.panth-newtab-icon')) {
                var icon = document.createElement('span');
                icon.className = 'panth-newtab-icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.innerHTML = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" '
                    + 'xmlns="http://www.w3.org/2000/svg">'
                    + '<path d="M6 3H3.5C3.22386 3 3 3.22386 3 3.5V12.5C3 12.7761 3.22386 13 3.5 13H12.5C12.7761 13 13 12.7761 13 12.5V10" '
                    + 'stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
                    + '<path d="M9 3H13V7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>'
                    + '<path d="M8 8L13 3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
                    + '</svg>';
                anchor.appendChild(icon);
            }
        });
    }

    function getOwnLabel(li) {
        var span = li.querySelector(':scope > a > span') || li.querySelector(':scope > strong > span');
        if (span) return span.textContent.trim();

        var anchor = li.querySelector(':scope > a') || li.querySelector(':scope > strong');
        if (!anchor) return '';

        var text = '';
        var children = anchor.childNodes;
        for (var i = 0; i < children.length; i++) {
            var node = children[i];
            if (node.nodeType === 3) {
                text += node.nodeValue;
            } else if (node.nodeType === 1 && node.tagName === 'SPAN') {
                text += node.textContent;
            }
        }
        return text.trim();
    }

    function drillInto(panel, li, label) {
        panel.__panthStack.push({ li: li, label: label });
        applyDrillState(panel);
    }

    function drillBack(panel) {
        panel.__panthStack.pop();
        applyDrillState(panel);
    }

    function resetPanelState(panel) {
        panel.__panthStack = [];
        applyDrillState(panel);
    }

    function applyDrillState(panel) {
        var marked = panel.querySelectorAll('.panth-active, .panth-collapsed');
        Array.prototype.forEach.call(marked, function (el) {
            el.classList.remove('panth-active');
            el.classList.remove('panth-collapsed');
        });

        var stack = panel.__panthStack;
        if (!stack || stack.length === 0) {
            panel.__panthBackBtn.classList.remove('panth-visible');
            panel.__panthTitle.textContent = panel.__panthRootTitle;
            panel.classList.remove('panth-is-drilled');
            return;
        }

        // Mark every LI on the active path so its <a>/<strong> hides
        // and its > .submenu reveals (CSS handles the rest).
        stack.forEach(function (entry) {
            entry.li.classList.add('panth-active');
        });

        // Hide every LI in the panel that's not (a) on the active path
        // or (b) inside the deepest active LI's subtree. Independent of
        // Magento's column / wrapper nesting depth.
        var deepest = stack[stack.length - 1].li;
        var allLis = panel.querySelectorAll('li');
        Array.prototype.forEach.call(allLis, function (li) {
            if (li === deepest) return;
            if (li.contains(deepest)) return;   // ancestor of deepest active
            if (deepest.contains(li)) return;   // descendant of deepest active
            li.classList.add('panth-collapsed');
        });

        panel.__panthBackBtn.classList.add('panth-visible');
        panel.__panthTitle.textContent = stack[stack.length - 1].label;
        panel.classList.add('panth-is-drilled');
    }

    function applyFilter(panel, query) {
        panel.classList.add('panth-searching');
        clearFilterClasses(panel);
        clearBreadcrumbs(panel);

        var tokens = query.split(/\s+/).filter(Boolean);
        var lis = panel.querySelectorAll('li');
        var matchCount = 0;

        Array.prototype.forEach.call(lis, function (li) {
            var label = getOwnLabel(li).toLowerCase();
            if (!label) return;
            var matchesAll = tokens.every(function (t) { return label.indexOf(t) !== -1; });
            if (matchesAll) {
                li.classList.add('panth-filter-match');
                matchCount++;
            }
        });

        Array.prototype.forEach.call(lis, function (li) {
            if (li.classList.contains('panth-filter-match')) return;
            if (li.querySelector('.panth-filter-match')) {
                li.classList.add('panth-filter-expand');
            } else {
                li.classList.add('panth-filtered-out');
            }
        });

        // Disambiguate matches: 6 identical "Configuration" rows is useless
        // without context, so each match gets a small breadcrumb subtitle
        // showing which parent chain it lives under.
        Array.prototype.forEach.call(lis, function (li) {
            if (!li.classList.contains('panth-filter-match')) return;
            var crumb = buildBreadcrumb(li, panel);
            if (!crumb) return;
            var anchor = li.querySelector(':scope > a, :scope > strong');
            if (!anchor) return;
            var bcEl = document.createElement('span');
            bcEl.className = 'panth-breadcrumb';
            bcEl.textContent = crumb;
            anchor.appendChild(bcEl);
        });

        panel.classList.toggle('panth-no-results', matchCount === 0);
    }

    function buildBreadcrumb(li, panel) {
        var parts = [];
        var cur = li.parentElement;
        while (cur && cur !== panel) {
            if (cur.tagName === 'LI' && cur.classList.contains('panth-has-children')) {
                var label = getOwnLabel(cur);
                if (label) parts.unshift(label);
            }
            cur = cur.parentElement;
        }
        return parts.join(' › ');
    }

    function clearBreadcrumbs(panel) {
        var bcs = panel.querySelectorAll('.panth-breadcrumb');
        Array.prototype.forEach.call(bcs, function (b) { b.parentNode && b.parentNode.removeChild(b); });
    }

    function clearFilter(panel) {
        panel.classList.remove('panth-searching');
        panel.classList.remove('panth-no-results');
        clearFilterClasses(panel);
        clearBreadcrumbs(panel);
    }

    function clearFilterClasses(panel) {
        var marked = panel.querySelectorAll('.panth-filter-match, .panth-filter-expand, .panth-filtered-out');
        Array.prototype.forEach.call(marked, function (el) {
            el.classList.remove('panth-filter-match');
            el.classList.remove('panth-filter-expand');
            el.classList.remove('panth-filtered-out');
        });
    }
});
