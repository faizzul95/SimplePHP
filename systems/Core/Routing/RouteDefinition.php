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

    public function name(string $name): self
    {
        $this->name = $name;
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
