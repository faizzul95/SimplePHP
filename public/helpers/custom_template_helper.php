<?php

if (!function_exists('pageTitle')) {
    function pageTitle()
    {
        global $titlePage;
        return $titlePage;
    }
}

if (!function_exists('pageSubTitle')) {
    function titleSubPage()
    {
        global $titleSubPage;
        return $titleSubPage;
    }
}

if (!function_exists('showPageTitle')) {
    function showPageTitle()
    {
        $mainPage = pageTitle();
        $subPage = titleSubPage();

        $showSubpage = !empty($subPage) ? '/ ' . $subPage : null;

        return "<span class='text-muted fw-light'> {$mainPage} </span> {$showSubpage}";
    }
}

if (!function_exists('sidebarMenu')) {
    function sidebarMenu()
    {
        global $currentPage, $currentSubPage, $menuList;

        foreach ($menuList as $pageKey => $menu) {
            $currentActive = $currentPage == $pageKey ? 'active' : '';

            if (permission($menu['permission'] ?? null) === false) {
                continue; // Skip this menu item if permission is not granted
            }

            // Check if this menu has subpages
            if (!empty($menu['subpage'])) {
                // Menu item with subpages
                $currentActive = !empty($currentActive) ? 'active open' : '';
                echo '<li class="menu-item ' . $currentActive . '">
                            <a href="javascript:void(0);" class="menu-link menu-toggle">
                                <i class="menu-icon ' . $menu['icon'] . '"></i>
                                <div class="text-truncate" data-i18n="' . $menu['desc'] . '">' . $menu['desc'] . '</div>
                            </a>
                            <ul class="menu-sub">';

                // Loop through subpages
                foreach ($menu['subpage'] as $subPageKey => $subpage) {

                    if (permission($subpage['permission'] ?? null) === false) {
                        continue; // Skip this menu item if permission is not granted
                    }

                    $currentSubActive = (isset($currentSubPage) && $currentSubPage == $subPageKey) ? 'active' : '';

                    echo '<li class="menu-item ' . $currentSubActive . '">
                                        <a href="' . $subpage['url'] . '" class="menu-link">
                                            <div class="text-truncate" data-i18n="' . $subpage['desc'] . '">' . $subpage['desc'] . '</div>
                                        </a>
                                    </li>';
                }

                echo '</ul>
                    </li>';
            } else {
                // Regular menu item without subpages
                echo '<li class="menu-item ' . $currentActive . '">
                                <a href="' . $menu['url'] . '" class="menu-link">
                                    <i class="menu-icon ' . $menu['icon'] . '"></i>
                                    <div data-i18n="' . $menu['desc'] . '">' . $menu['desc'] . '</div>
                                </a>
                            </li>';
            }
        }
    }
}
