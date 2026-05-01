<?php

namespace Components;

use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;

class MenuManager
{
    private const AUTH_METHODS = ['session', 'token', 'oauth2', 'oauth', 'jwt', 'api_key', 'basic', 'digest'];
    private const STATE_ENABLED = 'enabled';
    private const STATE_DISABLED = 'disabled';
    private const STATE_RELEASE = 'release';
    private const STATE_UNRELEASED = 'unreleased';
    private const STATE_MAINTENANCE = 'maintenance';

    private array $rawConfig;
    private ?array $cachedActorContext = null;
    private ?array $normalizedGroupsCache = null;
    private array $rendererProfileCache = [];

    public function __construct(array $config = [])
    {
        $this->rawConfig = $config;
    }

    public function config(): array
    {
        return $this->rawConfig;
    }

    public function actorContext(): array
    {
        return $this->currentActorContext();
    }

    public function normalizeGroups(array $groups): array
    {
        if ($groups === $this->rawConfig && $this->normalizedGroupsCache !== null) {
            return $this->normalizedGroupsCache;
        }

        $normalizedGroups = [];

        foreach ($groups as $groupKey => $menus) {
            if (!is_array($menus)) {
                continue;
            }

            $normalizedGroups[$groupKey] = $this->normalizeConfig($menus);
        }

        if ($groups === $this->rawConfig) {
            $this->normalizedGroupsCache = $normalizedGroups;
        }

        return $normalizedGroups;
    }

    public function normalizeConfig(array $menus): array
    {
        $normalizedMenus = [];

        foreach ($menus as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalizedMenus[$key] = $this->normalizeMenuItem((string) $key, $item);
        }

        return $normalizedMenus;
    }

    public function resolveConfiguredUrl(array $item): string
    {
        $routeName = trim((string) ($item['route'] ?? ''));
        if ($routeName !== '' && function_exists('route')) {
            $routeUrl = route($routeName, (array) ($item['route_params'] ?? []), true);
            if ($routeUrl !== '') {
                return $routeUrl;
            }
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '' || $url === 'javascript:void(0);') {
            return 'javascript:void(0);';
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return function_exists('url') ? url(ltrim($url, '/')) : $url;
    }

    public function filterAccessibleItems(array $items): array
    {
        $filtered = [];

        foreach ($items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalizedItem = $this->isNormalizedMenuItem((string) $key, $item)
                ? $item
                : $this->normalizeMenuItem((string) $key, $item);

            $normalizedItem['state'] = $this->resolveMenuState($normalizedItem);

            if (($normalizedItem['active'] ?? true) !== true) {
                continue;
            }

            if (!$this->stateAllowsVisibility((string) ($normalizedItem['state'] ?? self::STATE_ENABLED))) {
                continue;
            }

            $subpages = $normalizedItem['subpage'] ?? [];
            $hasSubpages = is_array($subpages) && !empty($subpages);

            if ($hasSubpages) {
                $normalizedItem['subpage'] = $this->filterAccessibleItems($subpages);
                if (empty($normalizedItem['subpage'])) {
                    continue;
                }

                if (!$this->canAccessMenuItem($normalizedItem) && $this->isNavigableUrl($normalizedItem['url'] ?? null)) {
                    continue;
                }

                $filtered[$key] = $normalizedItem;
                continue;
            }

            if (!$this->canAccessMenuItem($normalizedItem)) {
                continue;
            }

            $filtered[$key] = $normalizedItem;
        }

        return $filtered;
    }

    public function resolveAccessibleUrl(array $items): ?string
    {
        foreach ($this->filterAccessibleItems($items) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($this->isNavigableUrl($url)) {
                return $url;
            }

            $subpages = $item['subpage'] ?? [];
            if (is_array($subpages) && !empty($subpages)) {
                $subpageUrl = $this->resolveAccessibleUrl($subpages);
                if ($subpageUrl !== null) {
                    return $subpageUrl;
                }
            }
        }

        return null;
    }

    public function resolveAuthenticatedLandingUrl(): ?string
    {
        foreach ($this->normalizedGroups() as $menus) {
            $url = $this->resolveAccessibleUrl($menus);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public function findItemByPath(string $path): ?array
    {
        $normalizedPath = $this->normalizeRequestPath($path);
        if ($normalizedPath === null) {
            return null;
        }

        foreach ($this->normalizedGroups() as $menus) {
            $matched = $this->findItemByPathInMenus($menus, $normalizedPath);
            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }

    public function canAccessPath(string $path): bool
    {
        $item = $this->findItemByPath($path);
        if ($item === null) {
            return true;
        }

        if (($item['active'] ?? true) !== true) {
            return false;
        }

        if (!$this->stateAllowsVisibility((string) ($item['state'] ?? self::STATE_ENABLED))) {
            return false;
        }

        return $this->canAccessMenuItem($item);
    }

    public function renderSidebar(string $type = 'main', array $currentTrail = [], $renderer = 'sidebar'): string
    {
        return $this->render($type, $renderer, $currentTrail);
    }

    public function render(string $type = 'main', $renderer = 'sidebar', array $currentTrail = []): string
    {
        $normalized = $this->normalizedGroups();
        $menus = $this->filterAccessibleItems($normalized[$type] ?? []);
        return $this->renderMenuItems($menus, $currentTrail, [], 1, $renderer);
    }

    public function renderMenuItems(array $menus, array $currentTrail = [], array $parentPath = [], int $depth = 1, $renderer = 'sidebar'): string
    {
        $html = '';
        $profile = $this->rendererProfile($renderer);

        foreach ($menus as $menuKey => $menu) {
            if (!is_array($menu)) {
                continue;
            }

            $path = array_merge($parentPath, [(string) $menuKey]);
            $hasChildren = !empty($menu['subpage']) && is_array($menu['subpage']) && $depth < 3;
            $isActive = $this->pathIsActive($path, $currentTrail, $hasChildren);
            $itemClass = $this->buildItemClass($profile, $isActive, $hasChildren);
            $iconHtml = $this->renderIcon($profile, (string) ($menu['icon'] ?? ''));
            $desc = (string) ($menu['desc'] ?? '');
            $labelHtml = $this->renderLabel($profile, $desc);
            $badgeHtml = $this->renderBadge($menu['badge'] ?? '', (string) ($menu['state'] ?? self::STATE_ENABLED), $profile, $menu);

            if ($hasChildren) {
                $toggleUrl = 'javascript:void(0);';

                $toggleHtml = $this->renderActionElement(
                    $profile['toggle'] ?? [],
                    $toggleUrl,
                    $iconHtml . $labelHtml . $badgeHtml,
                    $menu,
                    true
                );
                $childrenHtml = $this->renderContainerElement(
                    $profile['children'] ?? [],
                    $this->renderMenuItems($menu['subpage'], $currentTrail, $path, $depth + 1, $renderer),
                    $menu,
                    $depth + 1
                );

                $html .= $this->renderContainerElement(
                    $profile['item'] ?? [],
                    $toggleHtml . $childrenHtml,
                    $menu,
                    $depth,
                    ['class' => $itemClass]
                );
                continue;
            }

            $linkHtml = $this->renderActionElement(
                $profile['link'] ?? [],
                (string) ($menu['url'] ?? 'javascript:void(0);'),
                $iconHtml . $labelHtml . $badgeHtml,
                $menu,
                false
            );
            $html .= $this->renderContainerElement(
                $profile['item'] ?? [],
                $linkHtml,
                $menu,
                $depth,
                ['class' => $itemClass]
            );
        }

        return $html;
    }

    public function pathIsActive(array $path, array $currentTrail = [], bool $hasChildren = false): bool
    {
        $normalizedPath = array_values(array_filter(array_map(static fn($item) => trim((string) $item), $path), static fn($item) => $item !== ''));
        $normalizedTrail = array_values(array_filter(array_map(static fn($item) => trim((string) $item), $currentTrail), static fn($item) => $item !== ''));

        if (empty($normalizedPath) || empty($normalizedTrail) || count($normalizedPath) > count($normalizedTrail)) {
            return false;
        }

        $currentSlice = array_slice($normalizedTrail, 0, count($normalizedPath));
        if ($currentSlice !== $normalizedPath) {
            return false;
        }

        return $hasChildren ? true : count($normalizedPath) === count($normalizedTrail);
    }

    public function isNavigableUrl(?string $url): bool
    {
        $url = trim((string) $url);
        return $url !== '' && $url !== 'javascript:void(0);';
    }

    private function normalizeMenuItem(string $key, array $item): array
    {
        $subpages = $item['subpage'] ?? [];
        $normalizedSubpages = is_array($subpages) ? $this->normalizeConfig($subpages) : [];

        return [
            'key' => $key,
            'desc' => (string) ($item['desc'] ?? ucfirst(str_replace(['-', '_'], ' ', $key))),
            'icon' => (string) ($item['icon'] ?? ''),
            'permission' => $item['permission'] ?? null,
            'role_ids' => $this->normalizeRoleIds($item['role_ids'] ?? $item['access_role_ids'] ?? []),
            'active' => ($item['active'] ?? true) === true,
            'state' => $item['state'] ?? $item['feature'] ?? self::STATE_ENABLED,
            'badge' => $item['badge'] ?? '',
            'url' => $this->resolveConfiguredUrl($item),
            'subpage' => $normalizedSubpages,
        ];
    }

    private function resolveMenuState(array $menu): string
    {
        $rawState = $this->resolveDynamicValue($menu['state'] ?? self::STATE_ENABLED, $menu, [
            $this,
            $this->actorContext(),
        ]);

        return $this->normalizeState(is_scalar($rawState) ? (string) $rawState : '');
    }

    private function canAccessMenuItem(array $item): bool
    {
        $allowedRoleIds = (array) ($item['role_ids'] ?? []);
        if (!empty($allowedRoleIds)) {
            return $this->matchesAllowedRoles($allowedRoleIds);
        }

        return $this->canSeeByPermission($item['permission'] ?? null);
    }

    private function canSeeByPermission($permission): bool
    {
        if ($this->isSuperadminContext()) {
            return true;
        }

        $permission = trim((string) ($permission ?? ''));
        if ($permission === '') {
            return true;
        }

        return function_exists('permission') ? permission($permission) === true : false;
    }

    private function matchesAllowedRoles(array $allowedRoleIds): bool
    {
        $roleId = (int) ($this->currentActorContext()['role_id'] ?? 0);
        if ($roleId < 1) {
            return false;
        }

        return in_array($roleId, $allowedRoleIds, true);
    }

    private function stateAllowsVisibility(string $state): bool
    {
        if ($state === self::STATE_DISABLED) {
            return false;
        }

        if (in_array($state, [self::STATE_MAINTENANCE, self::STATE_UNRELEASED], true)) {
            return $this->isSuperadminContext();
        }

        return true;
    }

    private function normalizeState(string $state): string
    {
        $state = strtolower(trim($state));

        return match ($state) {
            self::STATE_DISABLED => self::STATE_DISABLED,
            self::STATE_MAINTENANCE => self::STATE_MAINTENANCE,
            self::STATE_UNRELEASED, 'unrelease', 'pending' => self::STATE_UNRELEASED,
            self::STATE_RELEASE, self::STATE_ENABLED, '' => self::STATE_RELEASE,
            default => self::STATE_RELEASE,
        };
    }

    private function findItemByPathInMenus(array $menus, string $path): ?array
    {
        foreach ($menus as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalizedItem = $this->isNormalizedMenuItem((string) $key, $item)
                ? $item
                : $this->normalizeMenuItem((string) $key, $item);

            $normalizedItem['state'] = $this->resolveMenuState($normalizedItem);

            if ($this->pathsMatch($normalizedItem['url'] ?? null, $path)) {
                return $normalizedItem;
            }

            $subpages = $normalizedItem['subpage'] ?? [];
            if (is_array($subpages) && !empty($subpages)) {
                $matched = $this->findItemByPathInMenus($subpages, $path);
                if ($matched !== null) {
                    return $matched;
                }
            }
        }

        return null;
    }

    private function pathsMatch($configuredUrl, string $path): bool
    {
        $configuredPath = $this->normalizeRequestPath((string) $configuredUrl);
        return $configuredPath !== null && $configuredPath === $path;
    }

    private function normalizeRequestPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $parsedPath = parse_url($path, PHP_URL_PATH);
        if (!is_string($parsedPath) || $parsedPath === '') {
            $parsedPath = $path;
        }

        $normalized = '/' . trim($parsedPath, '/');
        if ($normalized === '/index.php') {
            return '/';
        }

        $basePath = '/';
        if (defined('BASE_URL')) {
            $resolvedBasePath = parse_url(BASE_URL, PHP_URL_PATH);
            if (is_string($resolvedBasePath) && $resolvedBasePath !== '') {
                $basePath = '/' . trim($resolvedBasePath, '/');
            }
        }

        if ($basePath !== '/' && str_starts_with($normalized, $basePath . '/')) {
            $normalized = substr($normalized, strlen($basePath));
        } elseif ($normalized === $basePath) {
            $normalized = '/';
        }

        return $normalized === '//' ? '/' : $normalized;
    }

    private function isSuperadminContext(): bool
    {
        $context = $this->currentActorContext();
        return (int) ($context['role_id'] ?? 0) === 1 && (int) ($context['role_rank'] ?? 0) >= 9999;
    }

    private function currentActorContext(): array
    {
        if ($this->cachedActorContext !== null) {
            return $this->cachedActorContext;
        }

        $context = [
            'user_id' => 0,
            'role_id' => 0,
            'role_rank' => 0,
            'role_name' => '',
            'via' => null,
            'authenticated' => false,
        ];

        if (function_exists('auth')) {
            try {
                $auth = auth();
                if (is_object($auth) && method_exists($auth, 'user')) {
                    $user = $auth->user(self::AUTH_METHODS);
                    if (!empty($user['id'])) {
                        $context['user_id'] = (int) $user['id'];
                        $context['authenticated'] = method_exists($auth, 'check') ? (bool) $auth->check(self::AUTH_METHODS) : true;
                        $context['via'] = method_exists($auth, 'via') ? $auth->via(self::AUTH_METHODS) : null;

                        if (method_exists($auth, 'roles')) {
                            $roles = $auth->roles((int) $user['id']);
                            $primaryRole = is_array($roles) && !empty($roles) ? (array) $roles[0] : [];
                            $context['role_id'] = (int) ($primaryRole['id'] ?? 0);
                            $context['role_rank'] = (int) ($primaryRole['rank'] ?? 0);
                            $context['role_name'] = (string) ($primaryRole['name'] ?? '');
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Fall back to session-derived values below.
            }
        }

        if ($context['user_id'] < 1 && function_exists('getSession')) {
            $context['user_id'] = (int) getSession('userID');
            $context['role_id'] = (int) getSession('roleID');
            $context['role_rank'] = (int) getSession('roleRank');
            $context['role_name'] = (string) getSession('roleName');
            $context['authenticated'] = !empty(getSession('isLoggedIn'));
        }

        $this->cachedActorContext = $context;
        return $context;
    }

    private function normalizedGroups(): array
    {
        if ($this->normalizedGroupsCache === null) {
            $this->normalizedGroupsCache = $this->normalizeGroups($this->rawConfig);
        }

        return $this->normalizedGroupsCache;
    }

    private function isNormalizedMenuItem(string $key, array $item): bool
    {
        return isset($item['key'], $item['url'], $item['subpage']) && (string) $item['key'] === $key;
    }

    private function normalizeRoleIds($roleIds): array
    {
        if (!is_array($roleIds)) {
            $roleIds = [$roleIds];
        }

        $normalized = [];
        foreach ($roleIds as $roleId) {
            $value = (int) $roleId;
            if ($value > 0) {
                $normalized[] = $value;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    private function renderBadge($badge, string $state, array $profile = [], array $menu = []): string
    {
        $definitions = $this->normalizeBadgeDefinitions(
            $this->resolveDynamicValue($badge, $menu, [$this, $state, $this->actorContext()]),
            $state
        );

        if (empty($definitions)) {
            return '';
        }

        $html = '';
        foreach ($definitions as $definition) {
            $label = trim((string) ($definition['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $badgeProfile = array_replace_recursive(
                $profile['badge'] ?? ['tag' => 'span', 'class' => 'badge bg-label-secondary ms-auto'],
                (array) ($definition['profile'] ?? [])
            );

            $html .= $this->renderGenericElement($badgeProfile, htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), [
                'state' => $state,
                'badge' => $label,
                'desc' => (string) ($menu['desc'] ?? ''),
            ]);
        }

        return $html;
    }

    private function normalizeBadgeDefinitions($badge, string $state): array
    {
        if ($badge === null || $badge === false) {
            $badge = '';
        }

        if ($badge === '') {
            $defaultBadge = $this->defaultBadgeDefinitionForState($state);
            return $defaultBadge === null ? [] : [$defaultBadge];
        }

        if (is_array($badge) && $this->isBadgeDefinitionList($badge)) {
            $definitions = [];
            foreach ($badge as $item) {
                $definition = $this->normalizeBadgeDefinition($item);
                if ($definition !== null) {
                    $definitions[] = $definition;
                }
            }

            return $definitions;
        }

        $definition = $this->normalizeBadgeDefinition($badge);

        return $definition === null ? [] : [$definition];
    }

    private function normalizeBadgeDefinition($badge): ?array
    {
        if (is_array($badge)) {
            $label = $badge['label'] ?? $badge['value'] ?? $badge['text'] ?? '';
            $profile = [];

            if (isset($badge['tag'])) {
                $profile['tag'] = (string) $badge['tag'];
            }

            if (isset($badge['class'])) {
                $profile['class'] = (string) $badge['class'];
            }

            if (isset($badge['attributes']) && is_array($badge['attributes'])) {
                $profile['attributes'] = $badge['attributes'];
            }

            $label = is_scalar($label) ? (string) $label : '';

            return trim($label) === '' ? null : [
                'label' => $label,
                'profile' => $profile,
            ];
        }

        if (!is_scalar($badge)) {
            return null;
        }

        $label = (string) $badge;

        return trim($label) === '' ? null : [
            'label' => $label,
            'profile' => [],
        ];
    }

    private function defaultBadgeDefinitionForState(string $state): ?array
    {
        return match ($state) {
            self::STATE_MAINTENANCE => ['label' => 'Maintenance', 'profile' => []],
            self::STATE_UNRELEASED => ['label' => 'Preview', 'profile' => []],
            default => null,
        };
    }

    private function isBadgeDefinitionList(array $badge): bool
    {
        if ($badge === []) {
            return false;
        }

        $keys = array_keys($badge);

        if ($keys !== range(0, count($badge) - 1)) {
            return false;
        }

        return true;
    }

    private function buildItemClass(array $profile, bool $isActive, bool $hasChildren): string
    {
        $baseClass = trim((string) (($profile['item']['class'] ?? 'menu-item')));
        $classes = $baseClass === '' ? [] : (preg_split('/\s+/', $baseClass) ?: []);

        if ($isActive) {
            $classes[] = (string) (($profile['item']['active_class'] ?? 'active'));
            if ($hasChildren) {
                $classes[] = (string) (($profile['item']['open_class'] ?? 'open'));
            }
        }

        $classes = array_values(array_unique(array_filter(array_map('trim', $classes), static fn($class) => $class !== '')));
        return implode(' ', $classes);
    }

    private function renderIcon(array $profile, string $iconClass): string
    {
        $iconClass = trim($iconClass);
        if ($iconClass === '') {
            return '';
        }

        $iconProfile = $profile['icon'] ?? ['tag' => 'i', 'class' => 'menu-icon'];
        $baseClass = trim((string) ($iconProfile['class'] ?? ''));
        $mergedClass = trim($baseClass . ' ' . $iconClass);

        return $this->renderVoidElement($iconProfile, ['class' => $mergedClass]);
    }

    private function renderLabel(array $profile, string $desc): string
    {
        $labelProfile = $profile['label'] ?? [
            'tag' => 'div',
            'class' => 'text-truncate',
            'attributes' => ['data-i18n' => '{desc}'],
        ];

        return $this->renderGenericElement($labelProfile, htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'), [
            'desc' => $desc,
        ]);
    }

    private function renderActionElement(array $definition, string $url, string $content, array $menu, bool $hasChildren): string
    {
        $tag = strtolower((string) ($definition['tag'] ?? 'a'));
        $attributes = ['class' => (string) ($definition['class'] ?? '')];
        $navigableUrl = $this->isNavigableUrl($url);

        if ($tag === 'a') {
            $attributes['href'] = $navigableUrl ? $url : 'javascript:void(0);';
        } else {
            $attributes['data-url'] = $url;
            if ($navigableUrl) {
                $attributes['onclick'] = "window.location.href='" . addslashes($url) . "'";
                $attributes['tabindex'] = '0';
            }
            if ($hasChildren) {
                $attributes['role'] = 'button';
            }
        }

        return $this->renderGenericElement($definition, $content, [
            'url' => $url,
            'desc' => (string) ($menu['desc'] ?? ''),
            'has_children' => $hasChildren ? '1' : '0',
        ], $attributes);
    }

    private function renderContainerElement(array $definition, string $content, array $menu, int $depth, array $overrideAttributes = []): string
    {
        return $this->renderGenericElement($definition, $content, [
            'desc' => (string) ($menu['desc'] ?? ''),
            'depth' => (string) $depth,
        ], $overrideAttributes);
    }

    private function renderVoidElement(array $definition, array $overrideAttributes = []): string
    {
        $tag = (string) ($definition['tag'] ?? 'i');
        $attributes = $this->buildAttributes($definition, [], $overrideAttributes);
        return '<' . $tag . $attributes . '></' . $tag . '>';
    }

    private function renderGenericElement(array $definition, string $content, array $context = [], array $overrideAttributes = []): string
    {
        $tag = (string) ($definition['tag'] ?? 'div');
        $attributes = $this->buildAttributes($definition, $context, $overrideAttributes);
        return '<' . $tag . $attributes . '>' . $content . '</' . $tag . '>';
    }

    private function buildAttributes(array $definition, array $context = [], array $overrideAttributes = []): string
    {
        $attributes = [];

        if (isset($definition['class'])) {
            $attributes['class'] = (string) $definition['class'];
        }

        foreach ((array) ($definition['attributes'] ?? []) as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            $attributes[$name] = $this->replaceAttributePlaceholders((string) $value, $context);
        }

        foreach ($overrideAttributes as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            $attributes[$name] = (string) $value;
        }

        $html = '';
        foreach ($attributes as $name => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $html .= ' ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $html;
    }

    private function replaceAttributePlaceholders(string $value, array $context): string
    {
        foreach ($context as $key => $item) {
            $value = str_replace('{' . $key . '}', (string) $item, $value);
        }

        return $value;
    }

    private function rendererProfile($renderer): array
    {
        $cacheKey = is_array($renderer)
            ? md5(json_encode($renderer))
            : (string) $renderer;

        if ($cacheKey !== '' && isset($this->rendererProfileCache[$cacheKey])) {
            return $this->rendererProfileCache[$cacheKey];
        }

        $profiles = (array) config('menu_renderers', []);

        if (is_array($renderer)) {
            $template = trim((string) ($renderer['template'] ?? ''));
            $version = trim((string) ($renderer['version'] ?? ''));
            $variant = trim((string) ($renderer['variant'] ?? 'sidebar'));
            $profile = $this->resolveRendererFromTemplateConfig($profiles, $template, $version, $variant);
            if ($cacheKey !== '') {
                $this->rendererProfileCache[$cacheKey] = $profile;
            }
            return $profile;
        }

        $rendererName = trim((string) $renderer);
        if ($rendererName === '') {
            return $this->defaultRendererProfile();
        }

        if (isset($profiles['profiles'][$rendererName]) && is_array($profiles['profiles'][$rendererName])) {
            $profile = array_replace_recursive($this->defaultRendererProfile(), $profiles['profiles'][$rendererName]);
            $this->rendererProfileCache[$cacheKey] = $profile;
            return $profile;
        }

        $segments = array_values(array_filter(explode(':', $rendererName), static fn($segment) => trim($segment) !== ''));
        if (count($segments) >= 2) {
            $template = $segments[0] ?? '';
            $version = count($segments) >= 3 ? ($segments[1] ?? '') : '';
            $variant = count($segments) >= 3 ? ($segments[2] ?? 'sidebar') : ($segments[1] ?? 'sidebar');
            $profile = $this->resolveRendererFromTemplateConfig($profiles, (string) $template, (string) $version, (string) $variant);
            $this->rendererProfileCache[$cacheKey] = $profile;
            return $profile;
        }

        if (isset($profiles[$rendererName]) && is_array($profiles[$rendererName])) {
            $profile = array_replace_recursive($this->defaultRendererProfile(), $profiles[$rendererName]);
            $this->rendererProfileCache[$cacheKey] = $profile;
            return $profile;
        }

        $profile = $this->defaultRendererProfile();
        if ($cacheKey !== '') {
            $this->rendererProfileCache[$cacheKey] = $profile;
        }

        return $profile;
    }

    private function resolveRendererFromTemplateConfig(array $profiles, string $template, string $version, string $variant): array
    {
        $template = strtolower(trim($template));
        $variant = trim($variant) !== '' ? trim($variant) : 'sidebar';

        $templates = (array) ($profiles['templates'] ?? []);
        $templateConfig = (array) ($templates[$template] ?? []);
        if (empty($templateConfig)) {
            return $this->defaultRendererProfile();
        }

        $resolvedVersion = trim($version);
        if ($resolvedVersion === '') {
            $resolvedVersion = trim((string) ($templateConfig['default_version'] ?? ''));
        }

        $versionProfiles = [];
        if ($resolvedVersion !== '') {
            $versionProfiles = (array) ($templateConfig['versions'][$resolvedVersion] ?? []);
        }

        $variantProfile = (array) ($versionProfiles[$variant] ?? $templateConfig[$variant] ?? []);

        return array_replace_recursive($this->defaultRendererProfile(), $variantProfile);
    }

    private function resolveDynamicValue($value, array $menu, array $extraArguments = [])
    {
        if (!is_callable($value)) {
            return $value;
        }

        try {
            $reflection = $this->reflectCallable($value);
            if ($reflection === null) {
                return $value();
            }

            $stateContext = null;
            $actorContext = $this->actorContext();

            foreach ($extraArguments as $argument) {
                if (is_string($argument) && $stateContext === null) {
                    $stateContext = $argument;
                    continue;
                }

                if (is_array($argument)) {
                    $actorContext = $argument;
                }
            }

            $arguments = [];
            $context = [
                'menu' => $menu,
                'manager' => $this,
                'state' => $stateContext,
                'actor' => $actorContext,
            ];

            foreach ($reflection->getParameters() as $parameter) {
                $resolved = $this->resolveCallableParameter($parameter, $context);
                if ($resolved['matched']) {
                    $arguments[] = $resolved['value'];
                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                if ($parameter->allowsNull()) {
                    $arguments[] = null;
                    continue;
                }

                return null;
            }

            return $value(...$arguments);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function reflectCallable(callable $value)
    {
        if ($value instanceof \Closure) {
            return new ReflectionFunction($value);
        }

        if (is_array($value) && count($value) === 2) {
            return new ReflectionMethod($value[0], (string) $value[1]);
        }

        if (is_string($value) && str_contains($value, '::')) {
            [$class, $method] = explode('::', $value, 2);
            return new ReflectionMethod($class, $method);
        }

        if (is_object($value) && method_exists($value, '__invoke')) {
            return new ReflectionMethod($value, '__invoke');
        }

        if (is_string($value)) {
            return new ReflectionFunction($value);
        }

        return null;
    }

    private function resolveCallableParameter($parameter, array $context): array
    {
        $name = strtolower((string) $parameter->getName());
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = ltrim($type->getName(), '\\');
            if ($typeName === self::class || is_a($this, $typeName)) {
                return ['matched' => true, 'value' => $this];
            }
        }

        if (in_array($name, ['manager', 'menumanager'], true)) {
            return ['matched' => true, 'value' => $context['manager']];
        }

        if (in_array($name, ['state', 'status'], true)) {
            return ['matched' => true, 'value' => $context['state']];
        }

        if (in_array($name, ['actor', 'context', 'user', 'auth'], true)) {
            return ['matched' => true, 'value' => $context['actor']];
        }

        if (in_array($name, ['menu', 'item'], true)) {
            return ['matched' => true, 'value' => $context['menu']];
        }

        if ($type instanceof ReflectionNamedType && $type->isBuiltin() && $type->getName() === 'array') {
            if (in_array($name, ['actor', 'context', 'user', 'auth'], true)) {
                return ['matched' => true, 'value' => $context['actor']];
            }

            return ['matched' => true, 'value' => $context['menu']];
        }

        if ($type instanceof ReflectionNamedType && $type->isBuiltin() && $type->getName() === 'string') {
            return ['matched' => true, 'value' => (string) ($context['state'] ?? '')];
        }

        return ['matched' => false, 'value' => null];
    }

    private function defaultRendererProfile(): array
    {
        return [
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
        ];
    }
}
