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
    function sidebarMenu($type = 'main')
    {
        global $currentPage, $currentSubPage, $menuList;

        foreach ($menuList[$type] as $pageKey => $menu) {
            $currentActive = $currentPage == $pageKey ? 'active' : '';

            if (permission($menu['permission'] ?? null) === false) {
                continue; // Skip this menu item if permission is not granted
            }

            if (!$menu['active']) {
                continue; // Skip this menu item if status is not active
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
                        continue; // Skip this sub menu item if permission is not granted
                    }

                    if (!$subpage['active']) {
                        continue; // Skip this sub menu item if status is not active
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

if (!function_exists('includeTemplate')) {
    function includeTemplate($filename = '', $folder = 'app' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . '_templates')
    {
        // Only allow alphanumeric, dash, underscore, and dot in filename
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
            return false;
        }

        $filePath = rtrim(TEMPLATE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename . '.php';

        if (file_exists($filePath)) {
            include_once $filePath;
        }
    }
}

if (!function_exists('showBreadcrumb')) {
    function showBreadcrumb()
    {
        $mainPage = pageTitle();
        $subPage = titleSubPage();

        $showSubpage = !empty($subPage) ? " <div class='col-sm-auto'>
                            <ul class='breadcrumb'>
                                <li class='breadcrumb-item'>
                                    <a href='javascript: void(0)'> {$subPage} </a>
                                </li>
                            </ul>
                        </div>" : null;

        return "<!-- [ breadcrumb ] start -->
            <div class='page-header'>
                <div class='page-block'>
                    <div class='row align-items-center g-0'>
                        <div class='col-sm-auto'>
                            <div class='page-header-title'>
                                <h5 class='mb-0'>{$mainPage}</h5>
                            </div>
                        </div>
                    {$showSubpage}
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end --><!-- [ Main Content ] start -->";
    }
}

if (!function_exists('sidebarMenuGroup')) {
    function sidebarMenuGroup(string $type, array $mappingGroup): string
    {
        global $currentPage, $currentSubPage, $menuList;

        $listMenu = [];

        // 1. Filter menu based on permission and active
        foreach ($mappingGroup as $grpName => $menuConfig) {
            foreach ($menuConfig as $menu) {
                $menuDetails = $menuList[$type][$menu] ?? null;
                if (!$menuDetails) {
                    continue;
                }
                if (permission($menuDetails['permission'] ?? null) === false) {
                    continue;
                }
                if (empty($menuDetails['active'])) {
                    continue;
                }
                $listMenu[$grpName][] = $menuDetails;
            }
        }

        // 2. Closure for recursive building (returns [html, isActive])
        $buildMenuItem = function (array $menu) use (&$buildMenuItem, $currentPage, $currentSubPage): array {
            $icon = !empty($menu['icon'])
                ? "<span class='pc-micon'><i class='{$menu['icon']}'></i></span>"
                : '';
            $desc = htmlspecialchars($menu['desc'], ENT_QUOTES, 'UTF-8');

            $isActive = false;
            $html = '';

            // Has subpages?
            if (!empty($menu['subpage'])) {
                $subHtml = '';
                $hasActiveChild = false;

                foreach ($menu['subpage'] as $sub) {
                    if (permission($sub['permission'] ?? null) === false) {
                        continue;
                    }
                    if (empty($sub['active'])) {
                        continue;
                    }

                    [$childHtml, $childActive] = $buildMenuItem($sub);
                    if ($childActive) {
                        $hasActiveChild = true;
                    }
                    $subHtml .= $childHtml;
                }

                $liClass = "pc-item pc-hasmenu" . ($hasActiveChild ? " pc-trigger" : "");
                $html = "<li class='{$liClass}'>
                            <a href='javascript:void(0);' class='pc-link'>
                                {$icon}
                                <span class='pc-mtext' data-i18n='{$desc}'>{$desc}</span>
                                <span class='pc-arrow'><i data-feather='chevron-right'></i></span>
                            </a>
                            <ul class='pc-submenu'>{$subHtml}</ul>
                         </li>";

                return [$html, $hasActiveChild];
            }

            // Normal single menu item
            $url = htmlspecialchars($menu['url'], ENT_QUOTES, 'UTF-8');

            // Check if current menu is active
            $isActive = (
                (isset($menu['file']) && $menu['file'] === $currentPage) ||
                (isset($menu['subpage_key']) && $menu['subpage_key'] === $currentSubPage) ||
                (isset($menu['url']) && strpos($menu['url'], $currentPage) !== false)
            );

            $liClass = "pc-item" . ($isActive ? " active" : "");
            $html = "<li class='{$liClass}'>
                        <a href='{$url}' class='pc-link' data-i18n='{$desc}'>
                            {$icon}<span class='pc-mtext'>{$desc}</span>
                        </a>
                     </li>";

            return [$html, $isActive];
        };

        // 3. Build the final HTML
        $output = [];
        foreach ($listMenu as $grpName => $menuConfig) {
            $captionMenu = strtoupper($grpName);
            $output[] = "<li class='pc-item pc-caption'>
                            <label data-i18n='{$captionMenu}'>{$captionMenu}</label>
                         </li>";

            foreach ($menuConfig as $menuDetails) {
                [$html] = $buildMenuItem($menuDetails);
                $output[] = $html;
            }
        }

        return implode("\n", $output);
    }
}