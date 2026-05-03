<?php

namespace Core\Routing;

class RouteDefinition
{
    public string $method;
    public string $uri;
    public $action;
    public array $middleware = [];
    public ?string $name = null;

    /** @var array<string, string> Parameter constraints (param => regex pattern) */
    public array $wheres = [];

    public function __construct(string $method, string $uri, $action)
    {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->action = $action;
    }

    public function middleware($middleware): self
    {
        $list = is_array($middleware) ? $middleware : [$middleware];
        $this->middleware = array_values(array_unique(array_merge($this->middleware, $list)));
        return $this;
    }

    public function auth(array|string|null $guards = null): self
    {
        if ($guards === null) {
            return $this->middleware('auth');
        }

        $guardList = is_array($guards) ? $guards : [$guards];
        $guardList = array_values(array_filter(array_map(static fn($guard) => trim((string) $guard), $guardList)));

        return empty($guardList)
            ? $this->middleware('auth')
            : $this->middleware('auth:' . implode(',', $guardList));
    }

    public function webAuth(): self
    {
        return $this->middleware('auth.web');
    }

    public function apiAuth(): self
    {
        return $this->middleware('auth.api');
    }

    public function guestOnly(): self
    {
        return $this->middleware('guest');
    }

    public function permission(string $permission): self
    {
        $permission = trim($permission);
        if ($permission === '') {
            return $this;
        }

        return $this->middleware('permission:' . $permission);
    }

    public function can(string $permission): self
    {
        return $this->permission($permission);
    }

    public function permissionAny(array|string $permissions): self
    {
        $permissionList = is_array($permissions) ? $permissions : explode(',', (string) $permissions);
        $permissionList = array_values(array_filter(array_map(static fn($permission) => trim((string) $permission), $permissionList)));

        if (empty($permissionList)) {
            return $this;
        }

        return $this->middleware('permission.any:' . implode(',', $permissionList));
    }

    public function canAny(array|string $permissions): self
    {
        return $this->permissionAny($permissions);
    }

    public function featureFlag(array|string $features): self
    {
        $featureList = is_array($features) ? $features : explode(',', (string) $features);
        $featureList = array_values(array_filter(array_map(static fn($feature) => trim((string) $feature), $featureList)));

        if (empty($featureList)) {
            return $this;
        }

        return $this->middleware('feature:' . implode(',', $featureList));
    }

    public function feature(array|string $features): self
    {
        return $this->featureFlag($features);
    }

    public function role(array|string $roles): self
    {
        $roleList = is_array($roles) ? $roles : explode(',', (string) $roles);
        $roleList = array_values(array_filter(array_map(static fn($role) => trim((string) $role), $roleList)));

        if (empty($roleList)) {
            return $this;
        }

        return $this->middleware('role:' . implode(',', $roleList));
    }

    public function ability(array|string $abilities): self
    {
        $abilityList = is_array($abilities) ? $abilities : explode(',', (string) $abilities);
        $abilityList = array_values(array_filter(array_map(static fn($ability) => trim((string) $ability), $abilityList)));

        if (empty($abilityList)) {
            return $this;
        }

        return $this->middleware('ability:' . implode(',', $abilityList));
    }

    public function name(string $name): self
    {
        $this->name = $name;
        Router::registerNamedRoute($name, $this->uri);
        return $this;
    }

    /**
     * Add route parameter constraints (regex patterns).
     * 
     * Usage:
     *   $router->get('/users/{id}', ...)->where('id', '[0-9]+');
     *   $router->get('/users/{id}/posts/{slug}', ...)->where(['id' => '[0-9]+', 'slug' => '[a-z0-9-]+']);
     *
     * @param string|array $param  Parameter name or associative array [param => pattern]
     * @param string|null  $pattern Regex pattern (when $param is a string)
     * @return self
     */
    public function where($param, ?string $pattern = null): self
    {
        if (is_array($param)) {
            foreach ($param as $key => $pat) {
                $this->wheres[(string) $key] = (string) $pat;
            }
        } elseif ($pattern !== null) {
            $this->wheres[(string) $param] = $pattern;
        }

        return $this;
    }

    /**
     * Constrain parameter to numeric values only
     */
    public function whereNumber(string $param): self
    {
        return $this->where($param, '[0-9]+');
    }

    /**
     * Constrain parameter to alphabetic characters only
     */
    public function whereAlpha(string $param): self
    {
        return $this->where($param, '[a-zA-Z]+');
    }

    /**
     * Constrain parameter to alphanumeric characters only
     */
    public function whereAlphaNumeric(string $param): self
    {
        return $this->where($param, '[a-zA-Z0-9]+');
    }
}
