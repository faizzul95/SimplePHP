<?php

if (!function_exists('pageTitle')) {
    function pageTitle()
    {
        global $titlePage;
        return $titlePage;
    }
}

if (!function_exists('pageSubTitle')) {
    function pageSubTitle()
    {
        global $titleSubPage;
        return $titleSubPage;
    }
}

if (!function_exists('titleSubPage')) {
    function titleSubPage()
    {
        return pageSubTitle();
    }
}

if (!function_exists('showPageTitle')) {
    function showPageTitle()
    {
        $mainPage = pageTitle();
        $subPage = pageSubTitle();

        $showSubpage = !empty($subPage) ? '/ ' . $subPage : null;

        return "<span class='text-muted fw-light'> {$mainPage} </span> {$showSubpage}";
    }
}

if (!function_exists('sidebarMenu')) {
    function sidebarMenu($type = 'main', $renderer = 'sidebar')
    {
        echo menu_manager()->render((string) $type, $renderer, currentMenuTrail());
    }
}

if (!function_exists('currentMenuTrail')) {
    function currentMenuTrail(): array
    {
        global $currentMenuTrail, $currentPage, $currentSubPage;

        if (isset($currentMenuTrail) && is_array($currentMenuTrail) && !empty($currentMenuTrail)) {
            return array_values(array_filter(array_map(static fn($item) => trim((string) $item), $currentMenuTrail), static fn($item) => $item !== ''));
        }

        $trail = [];
        if (!empty($currentPage)) {
            $trail[] = (string) $currentPage;
        }
        if (!empty($currentSubPage)) {
            $trail[] = (string) $currentSubPage;
        }

        return $trail;
    }
}

if (!function_exists('showBreadcrumb')) {
    function showBreadcrumb()
    {
        $mainPage = pageTitle();
        $subPage = pageSubTitle();

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
