<?php

/*
|--------------------------------------------------------------------------
| Menu Configuration Guide
|--------------------------------------------------------------------------
| Each top-level key inside $config['menu'] is a menu group, for example:
| - main
| - top-nav
| - mobile
|
| Each menu item can use these common keys:
| - desc: Required display label.
| - route: Named route. Preferred for internal links.
| - url: Direct URL when route is not available.
| - icon: CSS classes for the icon element.
| - permission: Permission key checked by auth()->can()/permission().
| - role_ids: Optional role-id whitelist. If set, it bypasses permission.
| - state: Feature visibility state or closure.
| - active: false hides the item completely.
| - badge: Static text/number, one badge config, many badge configs, or closure.
| - subpage: Child menu items, up to 3 levels.
| - route/url + state are also enforced for direct web access when the current
|   request path matches a configured menu item.
|
| Supported state values:
| - release / enabled: visible to allowed users.
| - maintenance: visible only to superadmin.
| - unreleased / unrelease / pending: visible only to superadmin.
| - disabled: hidden for everyone.
| - closure: resolve state dynamically during filtering/rendering.
|
| State closure supports:
| - 'state' => static fn () => 'release'
| - 'state' => function ($manager) use ($db) { return 'release'; }
| - 'state' => static fn (array $menu) => 'maintenance'
| - 'state' => static fn (array $menu, \Components\MenuManager $manager) => 'disabled'
| - 'state' => static fn (array $menu, \Components\MenuManager $manager, array $actor) =>
|       (($actor['role_id'] ?? 0) === 1 ? 'release' : 'maintenance')
|
| Badge supports:
| - 'badge' => 'New'
| - 'badge' => 12
| - 'badge' => static fn () => 12
| - 'badge' => function ($manager) use ($db) { return $db->table('jobs')->count(); }
| - 'badge' => static fn (array $menu, \Components\MenuManager $manager, string $state) => 12
| - 'badge' => [
|       ['label' => 'Beta', 'class' => 'badge bg-warning ms-auto'],
|       ['label' => 3, 'class' => 'badge bg-danger ms-1'],
|   ]
| - 'badge' => static fn (array $menu, \Components\MenuManager $manager, string $state, array $actor) => [
|       ['label' => 'Sync', 'class' => 'badge bg-info ms-auto'],
|       ['label' => 9, 'class' => 'badge bg-danger ms-1'],
|   ]
| - 'badge' => ['label' => 'Beta', 'class' => 'badge bg-warning ms-auto']
|
| Closure parameters are flexible. You can request only what you need, such
| as $manager, $menu, $state, or $actor, and the manager will bind them by
| parameter name/type. State closures are resolved during filtering, so they
| can decide whether a module is visible for the current actor. Badge
| closures are resolved only when rendering, so they are suitable for dynamic
| counts such as unread items, pending approvals, or queue totals.
*/

$config['menu'] = [
    'main' => [
        'dashboard' => [
            'desc' => 'Dashboard',
            'route' => 'dashboard',
            'icon' => 'tf-icons bx bx-home-smile',
            // Optional: role_ids bypasses permission and allows only specific role IDs.
            // 'role_ids' => [1, 2],
            'permission' => 'management-view',
            // release|enabled = visible, maintenance/unreleased = superadmin only, disabled = hidden
            // or use a closure for dynamic state based on actor/session/token context.
            'state' => 'release',
            'active' => true,
        ],
        'directory' => [
            'desc' => 'Directory',
            'route' => 'directory',
            'icon' => 'tf-icons bx bx-user',
            'permission' => 'user-view',
            'state' => 'release',
            'active' => true,
        ],
        'rbac' => [
            'desc' => 'App Management',
            'icon' => 'tf-icons bx bx-shield-quarter',
            'permission' => 'management-view',
            'state' => 'release',
            'active' => true,
            'subpage' => [
                'roles' => [
                    'desc' => 'Roles',
                    'route' => 'rbac.roles',
                    'permission' => 'rbac-roles-view',
                    'state' => 'release',
                    'active' => true,
                ],
                'email' => [
                    'desc' => 'Email Template',
                    'route' => 'rbac.email',
                    'permission' => 'rbac-email-view',
                    'state' => 'release',
                    // 'badge' => 'Maintenance',
                    // Dynamic badge example:
                    // 'badge' => function ($manager) use ($db) {
                    //     return $db->table('email_queue')->where('status', 'pending')->count();
                    // },
                    'active' => true,
                ],
            ],
        ],
    ],
];

/*
|--------------------------------------------------------------------------
| Menu Renderer Guide
|--------------------------------------------------------------------------
| menu_renderers controls the HTML structure generated by MenuManager.
|
| profiles:
| - Reusable renderer presets like sidebar, top-nav, side-nav.
| - Use when the menu markup is custom for this project.
|
| templates:
| - Template-family + version specific renderers.
| - Use when the same visual family has multiple framework versions.
|
| Each renderer section supports these nodes:
| - item: wrapper for one menu item.
| - toggle: action element for items with children.
| - link: action element for items without children.
| - children: wrapper for child menu items.
| - icon: icon element.
| - label: text element.
| - badge: badge element.
|
| Each node can define:
| - tag: html tag name, for example li, a, button, div, span.
| - class: default CSS classes.
| - attributes: extra HTML attributes. Placeholders like {desc} are supported.
|
| item also supports:
| - active_class: appended when the current menu path is active.
| - open_class: appended when the item has visible children and is active/open.
|
| Important behavior notes:
| - toggle is used only for items that have subpage children.
| - link is used only for leaf items.
| - Some admin templates intercept toggle classes in JavaScript.
|   Example: Sneat blocks default navigation on .menu-toggle.
| - In this project, MenuManager forces redirect for navigable parent items,
|   but renderer classes should still match the template's JS expectations.
|
| Renderer selection examples:
| - 'sidebar'
| - 'top-nav'
| - 'bootstrap:5:sidebar'
| - ['template' => 'bootstrap', 'version' => '4', 'variant' => 'top-nav']
|
| Template examples:
| - bootstrap:3:sidebar
| - bootstrap:4:top-nav
| - bootstrap:5:side-nav
*/

$config['menu_renderers'] = [
    'profiles' => [
        'sidebar' => [
            'item' => [
                'tag' => 'li',
                'class' => 'menu-item',
                'active_class' => 'active',
                'open_class' => 'open',
            ],
            'toggle' => [
                'tag' => 'a',
                'class' => 'menu-link menu-toggle',
            ],
            'link' => [
                'tag' => 'a',
                'class' => 'menu-link',
            ],
            'children' => [
                'tag' => 'ul',
                'class' => 'menu-sub',
            ],
            'icon' => [
                'tag' => 'i',
                'class' => 'menu-icon',
            ],
            'label' => [
                'tag' => 'div',
                'class' => 'text-truncate',
                'attributes' => [
                    'data-i18n' => '{desc}',
                ],
            ],
            'badge' => [
                'tag' => 'span',
                'class' => 'badge bg-label-secondary ms-auto',
            ],
        ],
        'side-nav' => [
            'item' => [
                'tag' => 'li',
                'class' => 'side-nav__item',
                'active_class' => 'is-active',
                'open_class' => 'is-open',
            ],
            'toggle' => [
                'tag' => 'button',
                'class' => 'side-nav__toggle',
                'attributes' => [
                    'type' => 'button',
                    'data-role' => 'menu-toggle',
                ],
            ],
            'link' => [
                'tag' => 'a',
                'class' => 'side-nav__link',
            ],
            'children' => [
                'tag' => 'ul',
                'class' => 'side-nav__children',
            ],
            'icon' => [
                'tag' => 'span',
                'class' => 'side-nav__icon',
            ],
            'label' => [
                'tag' => 'span',
                'class' => 'side-nav__label',
                'attributes' => [
                    'data-i18n' => '{desc}',
                ],
            ],
            'badge' => [
                'tag' => 'span',
                'class' => 'side-nav__badge',
            ],
        ],
        'top-nav' => [
            'item' => [
                'tag' => 'li',
                'class' => 'top-nav__item',
                'active_class' => 'is-active',
                'open_class' => 'is-open',
            ],
            'toggle' => [
                'tag' => 'button',
                'class' => 'top-nav__toggle',
                'attributes' => [
                    'type' => 'button',
                    'data-role' => 'menu-toggle',
                ],
            ],
            'link' => [
                'tag' => 'a',
                'class' => 'top-nav__link',
            ],
            'children' => [
                'tag' => 'div',
                'class' => 'top-nav__dropdown',
            ],
            'icon' => [
                'tag' => 'span',
                'class' => 'top-nav__icon',
            ],
            'label' => [
                'tag' => 'span',
                'class' => 'top-nav__label',
                'attributes' => [
                    'data-i18n' => '{desc}',
                ],
            ],
            'badge' => [
                'tag' => 'span',
                'class' => 'top-nav__badge',
            ],
        ],
        'stacked-div' => [
            'item' => [
                'tag' => 'div',
                'class' => 'menu-node',
                'active_class' => 'is-active',
                'open_class' => 'is-open',
            ],
            'toggle' => [
                'tag' => 'div',
                'class' => 'menu-node__toggle',
                'attributes' => [
                    'data-role' => 'menu-toggle',
                ],
            ],
            'link' => [
                'tag' => 'div',
                'class' => 'menu-node__link',
                'attributes' => [
                    'data-role' => 'menu-link',
                ],
            ],
            'children' => [
                'tag' => 'div',
                'class' => 'menu-node__children',
            ],
            'icon' => [
                'tag' => 'span',
                'class' => 'menu-node__icon',
            ],
            'label' => [
                'tag' => 'span',
                'class' => 'menu-node__label',
                'attributes' => [
                    'data-i18n' => '{desc}',
                ],
            ],
            'badge' => [
                'tag' => 'span',
                'class' => 'menu-node__badge',
            ],
        ],
    ],
    'templates' => [
        'bootstrap' => [
            'default_version' => '5',
            'versions' => [
                '3' => [
                    'sidebar' => [
                        'item' => ['tag' => 'li', 'class' => 'nav-item', 'active_class' => 'active', 'open_class' => 'open'],
                        'toggle' => ['tag' => 'a', 'class' => 'nav-link dropdown-toggle'],
                        'link' => ['tag' => 'a', 'class' => 'nav-link'],
                        'children' => ['tag' => 'ul', 'class' => 'dropdown-menu'],
                        'icon' => ['tag' => 'span', 'class' => 'menu-icon'],
                        'label' => ['tag' => 'span', 'class' => 'menu-text', 'attributes' => ['data-i18n' => '{desc}']],
                        'badge' => ['tag' => 'span', 'class' => 'label label-default pull-right'],
                    ],
                    'top-nav' => [
                        'item' => ['tag' => 'li', 'class' => 'dropdown', 'active_class' => 'active', 'open_class' => 'open'],
                        'toggle' => ['tag' => 'a', 'class' => 'dropdown-toggle', 'attributes' => ['data-toggle' => 'dropdown']],
                        'link' => ['tag' => 'a', 'class' => 'nav-link'],
                        'children' => ['tag' => 'ul', 'class' => 'dropdown-menu'],
                        'icon' => ['tag' => 'span', 'class' => 'menu-icon'],
                        'label' => ['tag' => 'span', 'class' => 'menu-text', 'attributes' => ['data-i18n' => '{desc}']],
                        'badge' => ['tag' => 'span', 'class' => 'label label-default navbar-right'],
                    ],
                    'side-nav' => [
                        'item' => ['tag' => 'li', 'class' => 'nav-item', 'active_class' => 'active', 'open_class' => 'open'],
                        'toggle' => ['tag' => 'a', 'class' => 'nav-link'],
                        'link' => ['tag' => 'a', 'class' => 'nav-link'],
                        'children' => ['tag' => 'ul', 'class' => 'nav nav-stacked'],
                        'icon' => ['tag' => 'span', 'class' => 'menu-icon'],
                        'label' => ['tag' => 'span', 'class' => 'menu-text', 'attributes' => ['data-i18n' => '{desc}']],
                        'badge' => ['tag' => 'span', 'class' => 'label label-default pull-right'],
                    ],
                ],
                '4' => [
                    'sidebar' => [
                        'item' => ['tag' => 'li', 'class' => 'nav-item', 'active_class' => 'active', 'open_class' => 'show'],
                        'toggle' => ['tag' => 'a', 'class' => 'nav-link dropdown-toggle'],
                        'link' => ['tag' => 'a', 'class' => 'nav-link'],
                        'children' => ['tag' => 'div', 'class' => 'dropdown-menu'],
                        'icon' => ['tag' => 'span', 'class' => 'menu-icon mr-2'],
                        'label' => ['tag' => 'span', 'class' => 'menu-text', 'attributes' => ['data-i18n' => '{desc}']],
                        'badge' => ['tag' => 'span', 'class' => 'badge badge-secondary ml-auto'],
                    ],
                    'top-nav' => [
                        'item' => ['tag' => 'li', 'class' => 'nav-item dropdown', 'active_class' => 'active', 'open_class' => 'show'],
                        'toggle' => ['tag' => 'a', 'class' => 'nav-link dropdown-toggle', 'attributes' => ['data-toggle' => 'dropdown']],
                        'link' => ['tag' => 'a', 'class' => 'nav-link'],
                        'children' => ['tag' => 'div', 'class' => 'dropdown-menu'],
                        'icon' => ['tag' => 'span', 'class' => 'menu-icon mr-2'],
                        'label' => ['tag' => 'span', 'class' => 'menu-text', 'attributes' => ['data-i18n' => '{desc}']],
                        'badge' => ['tag' => 'span', 'class' => 'badge badge-secondary ml-auto'],
                    ],
                    'side-nav' => [
                        'item' => ['tag' => 'li', 'class' => 'nav-item', 'active_class' => 'active', 'open_class' => 'show'],
                        'toggle' => ['tag' => 'a', 'class' => 'nav-link'],
                        'link' => ['tag' => 'a', 'class' => 'nav-link'],
                        'children' => ['tag' => 'ul', 'class' => 'nav flex-column'],
                        'icon' => ['tag' => 'span', 'class' => 'menu-icon mr-2'],
                        'label' => ['tag' => 'span', 'class' => 'menu-text', 'attributes' => ['data-i18n' => '{desc}']],
                        'badge' => ['tag' => 'span', 'class' => 'badge badge-secondary ml-auto'],
                    ],
                ],
                '5' => [
                    'sidebar' => [
                        'item' => ['tag' => 'li', 'class' => 'nav-item', 'active_class' => 'active', 'open_class' => 'show'],
                        'toggle' => ['tag' => 'a', 'class' => 'nav-link dropdown-toggle'],
                        'link' => ['tag' => 'a', 'class' => 'nav-link'],
                        'children' => ['tag' => 'ul', 'class' => 'dropdown-menu'],
                        'icon' => ['tag' => 'span', 'class' => 'menu-icon me-2'],
                        'label' => ['tag' => 'span', 'class' => 'menu-text', 'attributes' => ['data-i18n' => '{desc}']],
                        'badge' => ['tag' => 'span', 'class' => 'badge text-bg-secondary ms-auto'],
                    ],
                    'top-nav' => [
                        'item' => ['tag' => 'li', 'class' => 'nav-item dropdown', 'active_class' => 'active', 'open_class' => 'show'],
                        'toggle' => ['tag' => 'a', 'class' => 'nav-link dropdown-toggle', 'attributes' => ['data-bs-toggle' => 'dropdown']],
                        'link' => ['tag' => 'a', 'class' => 'nav-link'],
                        'children' => ['tag' => 'ul', 'class' => 'dropdown-menu'],
                        'icon' => ['tag' => 'span', 'class' => 'menu-icon me-2'],
                        'label' => ['tag' => 'span', 'class' => 'menu-text', 'attributes' => ['data-i18n' => '{desc}']],
                        'badge' => ['tag' => 'span', 'class' => 'badge text-bg-secondary ms-auto'],
                    ],
                    'side-nav' => [
                        'item' => ['tag' => 'li', 'class' => 'nav-item', 'active_class' => 'active', 'open_class' => 'show'],
                        'toggle' => ['tag' => 'button', 'class' => 'nav-link btn btn-link text-start w-100', 'attributes' => ['type' => 'button']],
                        'link' => ['tag' => 'a', 'class' => 'nav-link'],
                        'children' => ['tag' => 'ul', 'class' => 'nav flex-column ms-3'],
                        'icon' => ['tag' => 'span', 'class' => 'menu-icon me-2'],
                        'label' => ['tag' => 'span', 'class' => 'menu-text', 'attributes' => ['data-i18n' => '{desc}']],
                        'badge' => ['tag' => 'span', 'class' => 'badge text-bg-secondary ms-auto'],
                    ],
                ],
            ],
        ],
    ],
];
