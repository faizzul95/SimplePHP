<?php

namespace Core\View;

class BladeEngine
{
    private string $viewPath;
    private string $cachePath;
    private array $sections = [];
    private array $sectionStack = [];
    private array $stacks = [];
    private array $pushStack = [];
    private ?string $extendsView = null;
    private int $renderLevel = 0;

    public function __construct(string $viewPath, string $cachePath)
    {
        $this->viewPath = rtrim($viewPath, '/\\');
        $this->cachePath = rtrim($cachePath, '/\\');

        if (!is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0775, true);
        }
    }

    public function render(string $view, array $data = [], array $shared = []): string
    {
        $isTopLevel = $this->renderLevel === 0;
        if ($isTopLevel) {
            $this->resetState();
        }

        $this->renderLevel++;

        $viewFile = $this->resolveViewFile($view);
        if ($viewFile === null) {
            $this->renderLevel--;
            throw new \RuntimeException("View not found: {$view}");
        }

        $compiled = $this->compileIfNeeded($viewFile);

        ob_start();
        $__blade = $this;

        $vars = array_merge($shared, $data);
        if (!empty($vars)) {
            extract($vars, EXTR_SKIP);
        }

        include $compiled;
        $content = (string) ob_get_clean();

        $extends = $this->extendsView;
        if ($extends !== null) {
            $this->extendsView = null;
            $content = $this->render($extends, $data, $shared);
        }

        $this->renderLevel--;
        if ($this->renderLevel === 0) {
            $this->resetRuntimeStacks();
        }

        return $content;
    }

    public function includeView(string $view, array $data = [], array $shared = []): string
    {
        return $this->render($view, $data, $shared);
    }

    public function setExtends(string $view): void
    {
        $this->extendsView = $view;
    }

    public function startSection(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function stopSection(bool $render = false): string
    {
        $name = array_pop($this->sectionStack);
        if ($name === null) {
            throw new \RuntimeException('Cannot stop section without starting one.');
        }

        $content = (string) ob_get_clean();
        $content = str_replace('##__BLADE_PARENT__##', $this->sections[$name] ?? '', $content);
        $this->sections[$name] = $content;

        if ($render) {
            return $this->yieldContent($name);
        }

        return $content;
    }

    public function section(string $name, ?string $content = null): string
    {
        if ($content === null) {
            return $this->yieldContent($name);
        }

        $this->sections[$name] = $content;
        return '';
    }

    public function hasSection(string $name): bool
    {
        return array_key_exists($name, $this->sections);
    }

    public function yieldContent(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function startPush(string $stack): void
    {
        $this->pushStack[] = ['name' => $stack, 'mode' => 'append'];
        ob_start();
    }

    public function startPrepend(string $stack): void
    {
        $this->pushStack[] = ['name' => $stack, 'mode' => 'prepend'];
        ob_start();
    }

    public function stopPush(): void
    {
        $current = array_pop($this->pushStack);
        if ($current === null) {
            throw new \RuntimeException('Cannot stop push/prepend without starting one.');
        }

        $content = (string) ob_get_clean();
        $name = $current['name'];
        $existing = $this->stacks[$name] ?? '';

        if ($current['mode'] === 'prepend') {
            $this->stacks[$name] = $content . $existing;
            return;
        }

        $this->stacks[$name] = $existing . $content;
    }

    public function yieldStack(string $stack, string $default = ''): string
    {
        return $this->stacks[$stack] ?? $default;
    }

    private function resetState(): void
    {
        $this->sections = [];
        $this->stacks = [];
        $this->extendsView = null;
        $this->sectionStack = [];
        $this->pushStack = [];
    }

    private function resetRuntimeStacks(): void
    {
        $this->sectionStack = [];
        $this->pushStack = [];
        $this->extendsView = null;
    }

    private static array $resolveCache = [];

    private function resolveViewFile(string $view): ?string
    {
        if (isset(self::$resolveCache[$view])) {
            return self::$resolveCache[$view];
        }

        $normalizedView = trim($view);
        $rootPath = rtrim(ROOT_DIR, '/\\');

        $directCandidates = [
            $normalizedView,
            $rootPath . DIRECTORY_SEPARATOR . ltrim($normalizedView, '/\\'),
        ];

        foreach ($directCandidates as $file) {
            if (is_string($file) && is_file($file)) {
                self::$resolveCache[$view] = $file;
                return $file;
            }
        }

        $relative = str_replace('.', DIRECTORY_SEPARATOR, $normalizedView);

        $candidates = [
            $this->viewPath . DIRECTORY_SEPARATOR . $relative . '.blade.php',
            $this->viewPath . DIRECTORY_SEPARATOR . $relative . '.php',
            ROOT_DIR . 'app' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $relative . '.php',
        ];

        foreach ($candidates as $file) {
            if (is_string($file) && is_file($file)) {
                self::$resolveCache[$view] = $file;
                return $file;
            }
        }

        return null;
    }

    private function compileIfNeeded(string $source): string
    {
        // Use both path and mtime in cache key for reliable invalidation
        $mtime = filemtime($source);
        $cacheKey = md5($source . '|' . $mtime);
        $compiled = $this->cachePath . DIRECTORY_SEPARATOR . $cacheKey . '.php';

        if (!file_exists($compiled)) {
            $raw = file_get_contents($source);
            if ($raw === false) {
                throw new \RuntimeException("Unable to read view: {$source}");
            }

            $compiledContent = $this->compileString($raw);
            file_put_contents($compiled, $compiledContent, LOCK_EX);

            // Invalidate opcache for the compiled file
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($compiled, true);
            }
        }

        return $compiled;
    }

    private function compileString(string $content): string
    {
        $verbatimStore = [];
        $content = preg_replace_callback('/@verbatim(.*?)@endverbatim/s', function ($matches) use (&$verbatimStore) {
            $key = '__BLADE_VERBATIM_' . count($verbatimStore) . '__';
            $verbatimStore[$key] = $matches[1];
            return $key;
        }, $content) ?? $content;

        $content = preg_replace('/\{\{--(.*?)--\}\}/s', '', $content) ?? $content;

        $content = preg_replace_callback('/@extends\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', function ($matches) {
            return "<?php \$__blade->setExtends('{$matches[1]}'); ?>";
        }, $content) ?? $content;

        $content = preg_replace_callback('/@include\(\s*[\'\"]([^\'\"]+)[\'\"]\s*(?:,\s*(.+?))?\)/', function ($matches) {
            $view = $matches[1];
            $data = isset($matches[2]) ? trim($matches[2]) : '[]';
            return "<?php echo \$__blade->includeView('{$view}', {$data}, get_defined_vars()); ?>";
        }, $content) ?? $content;

        $content = preg_replace_callback('/@includeIf\(\s*[\'\"]([^\'\"]+)[\'\"]\s*(?:,\s*(.+?))?\)/', function ($matches) {
            $view = $matches[1];
            $data = isset($matches[2]) ? trim($matches[2]) : '[]';
            return "<?php try { echo \$__blade->includeView('{$view}', {$data}, get_defined_vars()); } catch (\\Throwable \$e) {} ?>";
        }, $content) ?? $content;

        $content = preg_replace_callback('/@includeWhen\(\s*(.+?)\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*(?:,\s*(.+?))?\)/', function ($matches) {
            $condition = trim($matches[1]);
            $view = $matches[2];
            $data = isset($matches[3]) ? trim($matches[3]) : '[]';
            return "<?php if ({$condition}): ?><?php echo \$__blade->includeView('{$view}', {$data}, get_defined_vars()); ?><?php endif; ?>";
        }, $content) ?? $content;

        $content = preg_replace_callback('/@includeUnless\(\s*(.+?)\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*(?:,\s*(.+?))?\)/', function ($matches) {
            $condition = trim($matches[1]);
            $view = $matches[2];
            $data = isset($matches[3]) ? trim($matches[3]) : '[]';
            return "<?php if (!({$condition})): ?><?php echo \$__blade->includeView('{$view}', {$data}, get_defined_vars()); ?><?php endif; ?>";
        }, $content) ?? $content;

        $content = preg_replace('/@section\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*(.+?)\)/', "<?php \$__blade->section('$1', $2); ?>", $content) ?? $content;
        $content = preg_replace('/@section\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php \$__blade->startSection('$1'); ?>", $content) ?? $content;

        // Batch all simple string replacements in one strtr() call for performance
        // (replaces ~28 individual str_replace calls with a single hash lookup pass)
        $content = strtr($content, [
            '@endsection'    => '<?php $__blade->stopSection(); ?>',
            '@show'          => '<?php echo $__blade->stopSection(true); ?>',
            '@parent'        => '##__BLADE_PARENT__##',
            '@endpush'       => '<?php $__blade->stopPush(); ?>',
            '@endprepend'    => '<?php $__blade->stopPush(); ?>',
            '@csrf'          => '<?php echo csrf_field(); ?>',
            '@endauth'       => '<?php endif; ?>',
            '@endguest'      => '<?php endif; ?>',
            '@enderror'      => '<?php endif; ?>',
            '@endenv'        => '<?php endif; ?>',
            '@production'    => '<?php if (defined(\'ENVIRONMENT\') && ENVIRONMENT === \'production\'): ?>',
            '@endproduction' => '<?php endif; ?>',
            '@default'       => '<?php default: ?>',
            '@endswitch'     => '<?php endswitch; ?>',
            '@endonce'       => '<?php endif; ?>',
            '@else'          => '<?php else: ?>',
            '@endif'         => '<?php endif; ?>',
            '@endunless'     => '<?php endif; ?>',
            '@endisset'      => '<?php endif; ?>',
            '@endforelse'    => '<?php endif; ?>',
            '@endempty'      => '<?php endif; ?>',
            '@endforeach'    => '<?php endforeach; ?>',
            '@endfor'        => '<?php endfor; ?>',
            '@endwhile'      => '<?php endwhile; ?>',
            '@php'           => '<?php ',
            '@endphp'        => ' ?>',
            '@endsession'    => '<?php endif; ?>',
        ]);

        $content = preg_replace('/@yield\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*(.+?)\)/', "<?php echo \$__blade->yieldContent('$1', $2); ?>", $content) ?? $content;
        $content = preg_replace('/@yield\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php echo \$__blade->yieldContent('$1'); ?>", $content) ?? $content;
        $content = preg_replace('/@hasSection\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php if (\$__blade->hasSection('$1')): ?>", $content) ?? $content;

        $content = preg_replace('/@push\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php \$__blade->startPush('$1'); ?>", $content) ?? $content;
        $content = preg_replace('/@prepend\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php \$__blade->startPrepend('$1'); ?>", $content) ?? $content;
        $content = preg_replace('/@stack\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php echo \$__blade->yieldStack('$1'); ?>", $content) ?? $content;
        $content = preg_replace('/@json\(\s*(.+?)\s*\)/', '<?php echo json_encode($1, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>', $content) ?? $content;

        $content = preg_replace('/@auth/', '<?php if (auth()->check()): ?>', $content) ?? $content;
        $content = preg_replace('/@guest/', '<?php if (auth()->guest()): ?>', $content) ?? $content;

        // @method('PUT') → hidden input for form method spoofing
        $content = preg_replace('/@method\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', '<input type="hidden" name="_method" value="$1">', $content) ?? $content;

        // @error('field') ... @enderror
        $content = preg_replace('/@error\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', '<?php if (isset($errors) && isset($errors[\'$1\'])): ?><?php $message = is_array($errors[\'$1\']) ? $errors[\'$1\'][0] : $errors[\'$1\']; ?>', $content) ?? $content;

        // @class(['class1', 'class2' => condition])
        $content = preg_replace('/@class\(\s*(\[.+?\])\s*\)/', '<?php echo implode(" ", array_keys(array_filter($1, function($v, $k) { return is_numeric($k) ? true : $v; }, ARRAY_FILTER_USE_BOTH))); ?>', $content) ?? $content;

        // @checked, @selected, @disabled, @readonly, @required (batched)
        foreach (['checked', 'selected', 'disabled', 'readonly', 'required'] as $boolAttr) {
            $content = preg_replace('/@' . $boolAttr . '\s*\((.*?)\)/', '<?php echo ($1) ? \'' . $boolAttr . '\' : \'\'; ?>', $content) ?? $content;
        }

        // @env('production') ... @endenv
        $content = preg_replace('/@env\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', '<?php if (defined(\'ENVIRONMENT\') && ENVIRONMENT === \'$1\'): ?>', $content) ?? $content;

        // @switch($var) / @case(value)
        $content = preg_replace('/@switch\s*\((.*?)\)/', '<?php switch ($1): ?>', $content) ?? $content;
        $content = preg_replace('/@case\s*\((.*?)\)/', '<?php case ($1): ?>', $content) ?? $content;

        // @once ... @endonce - only render content once per request
        $content = preg_replace_callback('/@once/', function () {
            $id = bin2hex(random_bytes(8));
            return "<?php if (!isset(\$GLOBALS['__blade_once_{$id}'])): \$GLOBALS['__blade_once_{$id}'] = true; ?>";
        }, $content) ?? $content;

        // @each('view.name', $array, 'varName', 'view.empty')
        $content = preg_replace_callback('/@each\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*(.+?)\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*(?:,\s*[\'\"]([^\'\"]+)[\'\"]\s*)?\)/', function ($matches) {
            $view = $matches[1];
            $data = trim($matches[2]);
            $varName = $matches[3];
            $emptyView = isset($matches[4]) ? $matches[4] : '';
            if (!empty($emptyView)) {
                return "<?php if (!empty({$data})): foreach ({$data} as \${$varName}): ?>" .
                    "<?php echo \$__blade->includeView('{$view}', ['{$varName}' => \${$varName}], get_defined_vars()); ?>" .
                    "<?php endforeach; else: ?>" .
                    "<?php echo \$__blade->includeView('{$emptyView}', get_defined_vars()); ?>" .
                    "<?php endif; ?>";
            }
            return "<?php foreach ({$data} as \${$varName}): ?>" .
                "<?php echo \$__blade->includeView('{$view}', ['{$varName}' => \${$varName}], get_defined_vars()); ?>" .
                "<?php endforeach; ?>";
        }, $content) ?? $content;

        // @style(['class1', 'class2' => condition]) - like @class but for inline styles
        $content = preg_replace('/@style\s*\(\s*(\[.+?\])\s*\)/', '<?php echo implode("; ", array_keys(array_filter($1, function($v, $k) { return is_numeric($k) ? true : $v; }, ARRAY_FILTER_USE_BOTH))); ?>', $content) ?? $content;

        // @session('key') ... @endsession
        $content = preg_replace('/@session\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', '<?php if (isset($_SESSION[\'$1\'])): $value = $_SESSION[\'$1\']; ?>', $content) ?? $content;

        // @dd($var) - dump and die
        $content = preg_replace('/@dd\s*\((.*?)\)/', '<?php echo \'<pre>\'; var_dump($1); echo \'</pre>\'; exit; ?>', $content) ?? $content;

        // @dump($var) - dump without dying
        $content = preg_replace('/@dump\s*\((.*?)\)/', '<?php echo \'<pre>\'; var_dump($1); echo \'</pre>\'; ?>', $content) ?? $content;

        $content = preg_replace('/@if\s*\((.*?)\)/', '<?php if ($1): ?>', $content) ?? $content;
        $content = preg_replace('/@elseif\s*\((.*?)\)/', '<?php elseif ($1): ?>', $content) ?? $content;

        $content = preg_replace('/@unless\s*\((.*?)\)/', '<?php if (!($1)): ?>', $content) ?? $content;
        $content = preg_replace('/@isset\s*\((.*?)\)/', '<?php if (isset($1)): ?>', $content) ?? $content;

        // @forelse ($items as $item) ... @empty ... @endforelse
        // Must be processed BEFORE standalone @empty($var)
        $forelseCounter = 0;
        $content = preg_replace_callback('/@forelse\s*\((.+?)\s+as\s+(.+?)\)/', function ($m) use (&$forelseCounter) {
            $forelseCounter++;
            return "<?php \$__forelseEmpty_{$forelseCounter} = true; foreach ({$m[1]} as {$m[2]}): \$__forelseEmpty_{$forelseCounter} = false; ?>";
        }, $content) ?? $content;

        // @empty without parentheses inside forelse → end loop + check empty flag
        $emptyCounter = 0;
        $content = preg_replace_callback('/@empty(?!\s*\()/', function () use (&$emptyCounter, $forelseCounter) {
            if ($emptyCounter < $forelseCounter) {
                $emptyCounter++;
                return "<?php endforeach; if (\$__forelseEmpty_{$emptyCounter}): ?>";
            }
            return '<?php if (empty(null)): ?>';
        }, $content) ?? $content;

        // Standalone @empty($var)
        $content = preg_replace('/@empty\s*\((.*?)\)/', '<?php if (empty($1)): ?>', $content) ?? $content;

        $content = preg_replace('/@foreach\s*\((.*?)\)/', '<?php foreach ($1): ?>', $content) ?? $content;

        $content = preg_replace('/@for\s*\((.*?)\)/', '<?php for ($1): ?>', $content) ?? $content;

        $content = preg_replace('/@while\s*\((.*?)\)/', '<?php while ($1): ?>', $content) ?? $content;

        $content = preg_replace('/@break\s*\((.*?)\)/', '<?php if ($1) break; ?>', $content) ?? $content;
        $content = preg_replace('/@continue\s*\((.*?)\)/', '<?php if ($1) continue; ?>', $content) ?? $content;

        $content = preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?php echo $1; ?>', $content) ?? $content;
        $content = preg_replace('/\{{\s*(.+?)\s*\}}/s', '<?php echo htmlspecialchars((string)($1), ENT_QUOTES, "UTF-8"); ?>', $content) ?? $content;

        if (!empty($verbatimStore)) {
            $content = strtr($content, $verbatimStore);
        }

        return $content;
    }
}
