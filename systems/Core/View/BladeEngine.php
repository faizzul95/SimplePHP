<?php

namespace Core\View;

class BladeEngine
{
    private const COMPILER_CACHE_VERSION = '2026-05-16-a';

    private string $viewPath;
    private string $cachePath;
    private array $sections = [];
    private array $sectionStack = [];
    private array $stacks = [];
    private array $pushStack = [];
    private ?string $extendsView = null;
    private int $renderLevel = 0;
    private ?array $cachedSharedViewData = null;
    // Component / slot system
    private array $componentStack = [];
    private array $componentSlots = [];
    private array $slotStack     = [];
    // Bounded to avoid unbounded memory growth in long-running workers.
    private const COMPILED_PATH_CACHE_MAX = 256;
    private const RESOLVE_CACHE_MAX = 256;
    private static array $compiledPathCache = [];

    public function __construct(string $viewPath, string $cachePath)
    {
        $this->viewPath = rtrim($viewPath, '/\\');
        $this->cachePath = rtrim($cachePath, '/\\');

        if (!is_dir($this->cachePath)) {
            // 0750: compiled views contain application logic; no world access.
            @mkdir($this->cachePath, 0750, true);
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

        $vars = array_merge($this->sharedViewData(), $shared, $data);
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
            if ($this->shouldMinifyRenderedOutput()) {
                $content = $this->minifyRenderedHtml($content);
            }
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

    // -------------------------------------------------------------------------
    // Component / slot system
    // -------------------------------------------------------------------------

    /**
     * Begin a component render scope.
     *
     * Compiled from: @component('view.name', ['prop' => $value])
     *
     * The caller's compiled code is responsible for opening an output buffer
     * immediately after this call (the directive compiles ob_start() separately
     * so the buffer is visible in the compiled output for clarity).
     *
     * @param string $view Dot-notation view name (e.g. 'components.alert')
     * @param array  $data Props passed into the component
     */
    public function startComponent(string $view, array $data = []): void
    {
        $this->componentStack[] = ['view' => $view, 'data' => $data];
        $this->componentSlots[] = [];
    }

    /**
     * Begin capturing a named slot.
     *
     * Compiled from: @slot('title')
     * The compiled code opens ob_start() immediately after this call.
     *
     * @param string $name Slot name; becomes a variable inside the component view
     */
    public function startSlot(string $name): void
    {
        $this->slotStack[] = $name;
    }

    /**
     * End the current named slot capture and store its content.
     *
     * Compiled from: @endslot
     * ob_get_clean() is called inside this method to close the slot buffer.
     */
    public function stopSlot(): void
    {
        $name = array_pop($this->slotStack);
        if ($name === null) {
            throw new \RuntimeException('@endslot called without a matching @slot.');
        }
        $content = (string) ob_get_clean();
        $last = count($this->componentSlots) - 1;
        if ($last >= 0) {
            $this->componentSlots[$last][$name] = $content;
        }
    }

    /**
     * Finalise the innermost component and return its rendered HTML.
     *
     * Compiled from: @endcomponent
     * ob_get_clean() captures the default slot (everything written to the
     * component buffer that was NOT in a named @slot block).
     *
     * Named slots become individual variables inside the component view
     * (e.g., @slot('title') → $title). The default content is always $slot.
     *
     * @return string Rendered component HTML
     */
    public function renderComponent(): string
    {
        $component  = array_pop($this->componentStack);
        $namedSlots = array_pop($this->componentSlots) ?? [];

        // Default slot = everything written to the component ob buffer that
        // was not captured by a named @slot / @endslot pair.
        $defaultSlot = (string) ob_get_clean();

        // Named slots are merged as individual variables; $slot is the default.
        $data = array_merge($component['data'], $namedSlots, ['slot' => $defaultSlot]);

        return $this->render($component['view'], $data);
    }

    public function sharedViewData(): array
    {
        if ($this->cachedSharedViewData !== null) {
            return $this->cachedSharedViewData;
        }

        $shared = [];

        if (function_exists('validationErrors')) {
            $shared['errors'] = validationErrors();
        }

        // Expose the per-request CSP nonce to all views as $csp_nonce.
        // Use in templates: <script nonce="{{ $csp_nonce }}">
        // Or with the @nonce directive: <script @nonce src="app.js"></script>
        $shared['csp_nonce'] = \Core\Security\CspNonce::get();

        $this->cachedSharedViewData = $shared;
        return $shared;
    }

    public function renderDebugDump(array $values, bool $die = false): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $dump = $this->captureDump($values);

        if (!$die) {
            echo $dump;
            return;
        }

        // @dd halts the request. Throwing rather than exiting means the dump still
        // reaches the browser through the normal emission path, and a worker
        // process survives a stray @dd left in a template.
        throw new \Core\Http\ResponseEmitted(
            new \Core\Http\HtmlResponse($dump, 200, ['Content-Type' => 'text/html; charset=UTF-8'])
        );
    }

    /** @param array<int, mixed> $values */
    private function captureDump(array $values): string
    {
        $level = ob_get_level();

        try {
            ob_start();
            echo '<pre>';
            foreach ($values as $value) {
                var_dump($value);
            }
            echo '</pre>';

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            return '<pre>Dump failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        }
    }

    /**
     * Pre-compile all Blade templates found under the configured view path.
     *
     * Walks the view directory recursively and compiles every *.php and
     * *.blade.php file that does not already have an up-to-date cached version.
     *
     * @return array{compiled: int, skipped: int, errors: array<string>}
     */
    public function compileAll(): array
    {
        $result = ['compiled' => 0, 'skipped' => 0, 'errors' => []];

        if (!is_dir($this->viewPath)) {
            $result['errors'][] = "View path does not exist: {$this->viewPath}";
            return $result;
        }

        if (!is_dir($this->cachePath) && !mkdir($this->cachePath, 0750, true) && !is_dir($this->cachePath)) {
            $result['errors'][] = "Cannot create view cache directory: {$this->cachePath}";
            return $result;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->viewPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $ext = $file->getExtension();
            // Process both *.blade.php and plain *.php view files
            if ($ext !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            try {
                $this->compileIfNeeded($filePath);
                $result['compiled']++;
            } catch (\Throwable $e) {
                $result['errors'][] = $filePath . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    private function resetState(): void
    {
        $this->sections = [];
        $this->stacks = [];
        $this->extendsView = null;
        $this->sectionStack = [];
        $this->pushStack = [];
        $this->cachedSharedViewData = null;
        $this->componentStack = [];
        $this->componentSlots = [];
        $this->slotStack     = [];
    }

    private function resetRuntimeStacks(): void
    {
        $this->sectionStack = [];
        $this->pushStack = [];
        $this->extendsView = null;
        $this->cachedSharedViewData = null;
        $this->componentStack = [];
        $this->componentSlots = [];
        $this->slotStack     = [];
    }

    private function isDebugEnabled(): bool
    {
        return (bool) config('error_debug', false);
    }

    private function shouldMinifyRenderedOutput(): bool
    {
        return (bool) config('framework.view_minify_output', false);
    }

    private function shouldCompactCompiledTemplate(): bool
    {
        return (bool) config('framework.view_compact_compiled_cache', false);
    }

    private function minifyRenderedHtml(string $content): string
    {
        if ($content === '' || trim($content) === '') {
            return $content;
        }

        $preserved = [];
        $content = preg_replace_callback('/<(pre|textarea|script|style)\b[^>]*>.*?<\/\1>/is', function ($matches) use (&$preserved) {
            $key = '__BLADE_MINIFY_BLOCK_' . count($preserved) . '__';
            $preserved[$key] = $matches[0];
            return $key;
        }, $content) ?? $content;

        $content = preg_replace('/>[\t ]*\r?\n[\t\r\n ]*</', '><', $content) ?? $content;
        $content = preg_replace('/\r?\n{2,}/', "\n", $content) ?? $content;

        if (!empty($preserved)) {
            $content = strtr($content, $preserved);
        }

        return $content;
    }

    private static array $resolveCache = [];

    /**
     * Reset all static per-request caches for long-running worker mode.
     * Called by WorkerState::flush() at the start of every request cycle.
     */
    public static function reset(): void
    {
        self::$compiledPathCache = [];
        self::$resolveCache = [];
    }

    private function resolveViewFile(string $view): ?string
    {
        if (isset(self::$resolveCache[$view])) {
            return self::$resolveCache[$view];
        }

        $normalizedView = trim($view);
        if ($normalizedView === '' || str_contains($normalizedView, "\0")) {
            return null;
        }

        if (str_contains(str_replace('\\', '/', $normalizedView), '../')) {
            return null;
        }

        $rootPath = rtrim(ROOT_DIR, '/\\');
        $allowedBase = realpath($this->viewPath);
        if ($allowedBase === false) {
            return null;
        }

        $directCandidates = [
            $normalizedView,
            $rootPath . DIRECTORY_SEPARATOR . ltrim($normalizedView, '/\\'),
        ];

        foreach ($directCandidates as $file) {
            $resolved = $this->allowedResolvedPath((string) $file, $allowedBase);
            if ($resolved !== null) {
                self::rememberResolvedView($view, $resolved);
                return $resolved;
            }
        }

        $relative = str_replace('.', DIRECTORY_SEPARATOR, $normalizedView);

        $candidates = [
            $this->viewPath . DIRECTORY_SEPARATOR . $relative . '.blade.php',
            $this->viewPath . DIRECTORY_SEPARATOR . $relative . '.php',
            ROOT_DIR . 'app' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $relative . '.php',
        ];

        foreach ($candidates as $file) {
            $resolved = $this->allowedResolvedPath((string) $file, $allowedBase);
            if ($resolved !== null) {
                self::rememberResolvedView($view, $resolved);
                return $resolved;
            }
        }

        return null;
    }

    private static function rememberResolvedView(string $view, string $resolved): void
    {
        if (count(self::$resolveCache) >= self::RESOLVE_CACHE_MAX) {
            array_shift(self::$resolveCache);
        }
        self::$resolveCache[$view] = $resolved;
    }

    private function compileIfNeeded(string $source): string
    {
        $stat = @stat($source) ?: [];
        $signature = sha1(
            self::COMPILER_CACHE_VERSION . '|' .
            $source . '|' .
            (string) ($stat['mtime'] ?? 0) . '|' .
            (string) ($stat['size'] ?? 0) . '|' .
            ($this->shouldCompactCompiledTemplate() ? 'compact' : 'plain')
        );
        // The is_file() looks redundant next to the signature match, and is not:
        // the memo outlives the file. Run `view:clear` against a warm worker and
        // every render afterwards includes a path that no longer exists, which
        // fails silently and serves blank pages until the worker is restarted.
        // One stat, on a path that already stats the source above.
        $memo = self::$compiledPathCache[$source] ?? null;
        if ($memo !== null && $memo['signature'] === $signature && is_file($memo['path'])) {
            return $memo['path'];
        }

        $cacheKey = md5($source . '|' . $signature);
        $compiled = $this->cachePath . DIRECTORY_SEPARATOR . $cacheKey . '.php';

        if (!file_exists($compiled)) {
            $raw = file_get_contents($source);
            if ($raw === false) {
                throw new \RuntimeException("Unable to read view: {$source}");
            }

            $compiledContent = $this->compileString($raw);
            if ($this->shouldCompactCompiledTemplate()) {
                $compiledContent = $this->compactCompiledTemplate($compiledContent);
            }

            // Atomic write: two workers racing on the same view both compile it
            // independently, but only one rename wins — readers never see a
            // half-written file (unlike in-place file_put_contents).
            $tmp = $compiled . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $compiledContent, LOCK_EX) !== false) {
                if (!@rename($tmp, $compiled)) {
                    @unlink($tmp); // Clean up on rename failure (e.g. cross-device)
                }
            }

            // Invalidate opcache for the compiled file
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($compiled, true);
            }
        }

        if (count(self::$compiledPathCache) >= self::COMPILED_PATH_CACHE_MAX) {
            array_shift(self::$compiledPathCache);
        }
        self::$compiledPathCache[$source] = [
            'signature' => $signature,
            'path' => $compiled,
        ];

        return $compiled;
    }

    private function compactCompiledTemplate(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $compacted = $content;

        if (function_exists('php_strip_whitespace')) {
            $tempFile = tempnam($this->cachePath, 'blade-compact-');
            if ($tempFile !== false) {
                try {
                    if (file_put_contents($tempFile, $content, LOCK_EX) !== false) {
                        $stripped = php_strip_whitespace($tempFile);
                        if (is_string($stripped) && $stripped !== '') {
                            $compacted = $stripped;
                        }
                    }
                } finally {
                    if (is_file($tempFile)) {
                        @unlink($tempFile);
                    }
                }
            }
        }

        $preserved = [];
        $compacted = preg_replace_callback('/<(pre|textarea|script|style)\b[^>]*>.*?<\/\1>/is', function ($matches) use (&$preserved) {
            $key = '__BLADE_COMPILED_BLOCK_' . count($preserved) . '__';
            $preserved[$key] = $matches[0];
            return $key;
        }, $compacted) ?? $compacted;

        $compacted = preg_replace('/>[\t ]*\r?\n[\t\r\n ]*</', '><', $compacted) ?? $compacted;
        $compacted = preg_replace('/\r?\n{2,}/', "\n", $compacted) ?? $compacted;

        if (!empty($preserved)) {
            $compacted = strtr($compacted, $preserved);
        }

        return $compacted !== '' ? $compacted : $content;
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

        $content = $this->compileIncludeDirectives($content);
        $content = $this->compileSectionDirectives($content);
        $content = $this->compileComponentDirectives($content);
        $content = $this->compileForelseDirectives($content);

        // Batch all simple string replacements in one strtr() call for performance
        // (replaces ~28 individual str_replace calls with a single hash lookup pass)
        $content = strtr($content, [
            '@endsection'    => '<?php $__blade->stopSection(); ?>',
            '@show'          => '<?php echo $__blade->stopSection(true); ?>',
            '@parent'        => '##__BLADE_PARENT__##',
            '@endpush'       => '<?php $__blade->stopPush(); ?>',
            '@endprepend'    => '<?php $__blade->stopPush(); ?>',
            '@endcomponent'  => '<?php echo $__blade->renderComponent(); ?>',
            '@endslot'       => '<?php $__blade->stopSlot(); ?>',
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
            '@endempty'      => '<?php endif; ?>',
            '@endforeach'    => '<?php endforeach; ?>',
            '@endfor'        => '<?php endfor; ?>',
            '@endwhile'      => '<?php endwhile; ?>',
            '@php'           => '<?php ',
            '@endphp'        => ' ?>',
            '@endsession'    => '<?php endif; ?>',
            // Outputs nonce="{value}" attribute — use inside <script> or <style> tags:
            // <script @nonce src="app.js"></script>
            '@nonce'         => '<?php echo \'nonce="\' . htmlspecialchars((string)(\Core\Security\CspNonce::get()), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\') . \'"\'; ?>',
            // @sri('url', 'sha384-<hash>') → integrity="sha384-<hash>" crossorigin="anonymous"
            // Usage: <script src="https://cdn.example.com/app.js" @sri('https://cdn.example.com/app.js', 'sha384-ABC...')></script>
            // Generate hashes: php myth security:sri <url>
        ]);

        $content = $this->compileYieldDirectives($content);
        $content = preg_replace('/@hasSection\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php if (\$__blade->hasSection('$1')): ?>", $content) ?? $content;

        $content = preg_replace('/@push\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php \$__blade->startPush('$1'); ?>", $content) ?? $content;
        $content = preg_replace('/@prepend\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php \$__blade->startPrepend('$1'); ?>", $content) ?? $content;
        $content = preg_replace('/@stack\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', "<?php echo \$__blade->yieldStack('$1'); ?>", $content) ?? $content;
        $content = $this->replaceDirectiveCalls($content, 'json', static fn(string $expression): string =>
            '<?php echo json_encode(' . trim($expression)
            . ', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>');
        $content = $this->compileSriDirectives($content);

        $content = preg_replace('/@auth/', '<?php if (auth()->check()): ?>', $content) ?? $content;
        $content = preg_replace('/@guest/', '<?php if (auth()->guest()): ?>', $content) ?? $content;
        $content = $this->replaceDirectiveCalls($content, 'cannot', static fn(string $expression): string =>
            '<?php if (auth()->cannot(' . trim($expression) . ')): ?>');
        $content = $this->replaceDirectiveCalls($content, 'can', static fn(string $expression): string =>
            '<?php if (auth()->can(' . trim($expression) . ')): ?>');
        $content = strtr($content, [
            '@endcan' => '<?php endif; ?>',
            '@endcannot' => '<?php endif; ?>',
        ]);

        // @method('PUT') → hidden input for form method spoofing
        $content = preg_replace_callback('/@method\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', static function ($matches) {
            $value = htmlspecialchars((string) ($matches[1] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<input type="hidden" name="_method" value="' . $value . '">';
        }, $content) ?? $content;

        // @error('field') ... @enderror
        $content = preg_replace('/@error\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', '<?php if (isset($errors) && isset($errors[\'$1\'])): ?><?php $message = is_array($errors[\'$1\']) ? $errors[\'$1\'][0] : $errors[\'$1\']; ?>', $content) ?? $content;

        // @class(['class1', 'class2' => condition])
        $content = $this->replaceDirectiveCalls($content, 'class', static fn(string $expression): string =>
            '<?php echo implode(" ", array_keys(array_filter(' . trim($expression)
            . ', function($v, $k) { return is_numeric($k) ? true : $v; }, ARRAY_FILTER_USE_BOTH))); ?>');

        // @checked, @selected, @disabled, @readonly, @required (batched)
        foreach (['checked', 'selected', 'disabled', 'readonly', 'required'] as $boolAttr) {
            $content = $this->replaceDirectiveCalls(
                $content,
                $boolAttr,
                static fn(string $expression): string =>
                    '<?php echo (' . trim($expression) . ") ? '{$boolAttr}' : ''; ?>"
            );
        }

        // @env('production') ... @endenv
        $content = preg_replace('/@env\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', '<?php if (defined(\'ENVIRONMENT\') && ENVIRONMENT === \'$1\'): ?>', $content) ?? $content;

        // @switch($var) / @case(value)
        $content = $this->replaceDirectiveCalls($content, 'switch', static fn(string $expression): string =>
            '<?php switch (' . trim($expression) . '): ?>');
        $content = $this->replaceDirectiveCalls($content, 'case', static fn(string $expression): string =>
            '<?php case (' . trim($expression) . '): ?>');

        // @once ... @endonce - only render content once per request
        $content = preg_replace_callback('/@once/', function () {
            $id = bin2hex(random_bytes(8));
            return "<?php if (!isset(\$GLOBALS['__blade_once_{$id}'])): \$GLOBALS['__blade_once_{$id}'] = true; ?>";
        }, $content) ?? $content;

        $content = $this->compileEachDirectives($content);

        // @style(['class1', 'class2' => condition]) - like @class but for inline styles
        $content = $this->replaceDirectiveCalls($content, 'style', static fn(string $expression): string =>
            '<?php echo implode("; ", array_keys(array_filter(' . trim($expression)
            . ', function($v, $k) { return is_numeric($k) ? true : $v; }, ARRAY_FILTER_USE_BOTH))); ?>');

        // @session('key') ... @endsession
        $content = preg_replace('/@session\s*\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', '<?php if (isset($_SESSION[\'$1\'])): $value = $_SESSION[\'$1\']; ?>', $content) ?? $content;

        /*
         * Control-flow and debug directives (@dd / @dump are debug-only, so they must
         * not leak state in production — see renderDebugDump()).
         *
         * These must go through replaceDirectiveCalls(), which scans for the matching
         * close parenthesis. The regexes this replaced used a non-greedy `(.*?)\)`,
         * which stops at the FIRST ")", so any expression containing a nested call was
         * truncated. `@if(count($users) > 0)` compiled to an opening `if (count($users)`
         * with `> 0)` left behind as literal text — a PHP parse error. The bundled views
         * happen to contain no nested parentheses, which is why it went unnoticed.
         *
         */
        $expressionDirectives = [
            'dd'       => '<?php $__blade->renderDebugDump([%s], true); ?>',
            'dump'     => '<?php $__blade->renderDebugDump([%s], false); ?>',
            'if'       => '<?php if (%s): ?>',
            'elseif'   => '<?php elseif (%s): ?>',
            'unless'   => '<?php if (!(%s)): ?>',
            'isset'    => '<?php if (isset(%s)): ?>',
            // Standalone @empty($var); the forelse form is compiled earlier.
            'empty'    => '<?php if (empty(%s)): ?>',
            'foreach'  => '<?php foreach (%s): ?>',
            'for'      => '<?php for (%s): ?>',
            'while'    => '<?php while (%s): ?>',
            'break'    => '<?php if (%s) break; ?>',
            'continue' => '<?php if (%s) continue; ?>',
        ];

        foreach ($expressionDirectives as $directive => $template) {
            $content = $this->replaceDirectiveCalls(
                $content,
                $directive,
                static fn(string $expression): string => sprintf($template, trim($expression))
            );
        }

        // Bare @break / @continue — needed by @switch/@case, which previously had no way
        // to terminate a case. Must run after the conditional forms above, or the
        // trailing "($x)" of "@break($x)" would survive as literal text. The lookahead
        // keeps it from eating a longer word such as "@breakpoint".
        $content = preg_replace('/@break(?![A-Za-z0-9_(])/', '<?php break; ?>', $content) ?? $content;
        $content = preg_replace('/@continue(?![A-Za-z0-9_(])/', '<?php continue; ?>', $content) ?? $content;

        $content = preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?php echo $1; ?>', $content) ?? $content;
        $content = preg_replace('/\{{\s*(.+?)\s*\}}/s', '<?php echo htmlspecialchars((string)($1), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); ?>', $content) ?? $content;

        if (!empty($verbatimStore)) {
            $content = strtr($content, $verbatimStore);
        }

        return $content;
    }

    private function compileSriDirectives(string $content): string
    {
        return $this->replaceDirectiveCalls($content, 'sri', function (string $expression): string {
            $expression = trim($expression);
            if ($expression === '') {
                return '';
            }

            $args = $this->splitTopLevelArguments($expression, 2);
            $asset = trim($args[0] ?? '');
            if ($asset === '') {
                return '@sri(' . $expression . ')';
            }

            $manualHash = isset($args[1]) ? trim($args[1]) : 'null';

            return "<?php echo \\Core\\Assets\\AssetIntegrity::attributes((string) ({$asset}), {$manualHash}); ?>";
        });
    }

    private function compileSectionDirectives(string $content): string
    {
        return $this->replaceDirectiveCalls($content, 'section', function (string $expression): string {
            $args = $this->splitTopLevelArguments($expression, 2);
            $name = $this->parseQuotedString($args[0] ?? '');
            if ($name === null) {
                return '@section(' . $expression . ')';
            }

            if (isset($args[1])) {
                return "<?php \$__blade->section('{$name}', " . trim($args[1]) . "); ?>";
            }

            return "<?php \$__blade->startSection('{$name}'); ?>";
        });
    }

    private function compileIncludeDirectives(string $content): string
    {
        $content = $this->replaceDirectiveCalls($content, 'include', function (string $expression): string {
            $args = $this->splitTopLevelArguments($expression, 2);
            $view = $this->parseQuotedString($args[0] ?? '');
            if ($view === null) {
                return '@include(' . $expression . ')';
            }

            $data = isset($args[1]) ? trim($args[1]) : '[]';
            return "<?php echo \$__blade->includeView('{$view}', {$data}, get_defined_vars()); ?>";
        });

        $content = $this->replaceDirectiveCalls($content, 'includeIf', function (string $expression): string {
            $args = $this->splitTopLevelArguments($expression, 2);
            $view = $this->parseQuotedString($args[0] ?? '');
            if ($view === null) {
                return '@includeIf(' . $expression . ')';
            }

            $data = isset($args[1]) ? trim($args[1]) : '[]';
            return "<?php try { echo \$__blade->includeView('{$view}', {$data}, get_defined_vars()); } catch (\\Throwable \$e) {} ?>";
        });

        $content = $this->replaceDirectiveCalls($content, 'includeWhen', function (string $expression): string {
            $args = $this->splitTopLevelArguments($expression, 3);
            $condition = trim($args[0] ?? '');
            $view = $this->parseQuotedString($args[1] ?? '');
            if ($condition === '' || $view === null) {
                return '@includeWhen(' . $expression . ')';
            }

            $data = isset($args[2]) ? trim($args[2]) : '[]';
            return "<?php if ({$condition}): ?><?php echo \$__blade->includeView('{$view}', {$data}, get_defined_vars()); ?><?php endif; ?>";
        });

        return $this->replaceDirectiveCalls($content, 'includeUnless', function (string $expression): string {
            $args = $this->splitTopLevelArguments($expression, 3);
            $condition = trim($args[0] ?? '');
            $view = $this->parseQuotedString($args[1] ?? '');
            if ($condition === '' || $view === null) {
                return '@includeUnless(' . $expression . ')';
            }

            $data = isset($args[2]) ? trim($args[2]) : '[]';
            return "<?php if (!({$condition})): ?><?php echo \$__blade->includeView('{$view}', {$data}, get_defined_vars()); ?><?php endif; ?>";
        });
    }

    private function compileYieldDirectives(string $content): string
    {
        return $this->replaceDirectiveCalls($content, 'yield', function (string $expression): string {
            $args = $this->splitTopLevelArguments($expression, 2);
            $name = $this->parseQuotedString($args[0] ?? '');
            if ($name === null) {
                return '@yield(' . $expression . ')';
            }

            if (isset($args[1])) {
                return "<?php echo \$__blade->yieldContent('{$name}', " . trim($args[1]) . "); ?>";
            }

            return "<?php echo \$__blade->yieldContent('{$name}'); ?>";
        });
    }

    /**
     * Compile @component('view', [data]) / @slot('name') directives.
     *
     * @component('components.alert', ['type' => 'danger'])
     *   @slot('title') Alert @endslot
     *   Body text here — this becomes $slot inside the component.
     * @endcomponent
     *
     * @endcomponent and @endslot are compiled in the strtr batch in compileString().
     */
    private function compileComponentDirectives(string $content): string
    {
        // @component('view', [...optional data...])
        $content = $this->replaceDirectiveCalls($content, 'component', function (string $expression): string {
            $args = $this->splitTopLevelArguments($expression, 2);
            $view = $this->parseQuotedString($args[0] ?? '');
            if ($view === null) {
                return '@component(' . $expression . ')';
            }
            $data = isset($args[1]) ? trim($args[1]) : '[]';
            $safeView = addslashes($view);
            return "<?php \$__blade->startComponent('{$safeView}', {$data}); ob_start(); ?>";
        });

        // @slot('name')
        $content = $this->replaceDirectiveCalls($content, 'slot', function (string $expression): string {
            $name = $this->parseQuotedString(trim($expression));
            if ($name === null) {
                return '@slot(' . $expression . ')';
            }
            $safeName = addslashes($name);
            return "<?php \$__blade->startSlot('{$safeName}'); ob_start(); ?>";
        });

        return $content;
    }

    private function compileEachDirectives(string $content): string
    {
        return $this->replaceDirectiveCalls($content, 'each', function (string $expression): string {
            $args = $this->splitTopLevelArguments($expression, 4);
            $view = $this->parseQuotedString($args[0] ?? '');
            $data = trim($args[1] ?? '');
            $varName = $this->parseQuotedString($args[2] ?? '');
            $emptyView = isset($args[3]) ? $this->parseQuotedString($args[3]) : '';

            if ($view === null || $data === '' || $varName === null) {
                return '@each(' . $expression . ')';
            }

            if (is_string($emptyView) && $emptyView !== '') {
                return "<?php if (!empty({$data})): foreach ({$data} as \${$varName}): ?>"
                    . "<?php echo \$__blade->includeView('{$view}', ['{$varName}' => \${$varName}], get_defined_vars()); ?>"
                    . "<?php endforeach; else: ?>"
                    . "<?php echo \$__blade->includeView('{$emptyView}', get_defined_vars()); ?>"
                    . "<?php endif; ?>";
            }

            return "<?php foreach ({$data} as \${$varName}): ?>"
                . "<?php echo \$__blade->includeView('{$view}', ['{$varName}' => \${$varName}], get_defined_vars()); ?>"
                . "<?php endforeach; ?>";
        });
    }

    private function compileForelseDirectives(string $content): string
    {
        $forelseCounter = 0;
        $content = $this->replaceDirectiveCalls($content, 'forelse', function (string $expression) use (&$forelseCounter): string {
            $parts = $this->splitTopLevelAsExpression($expression);
            if ($parts === null) {
                return '@forelse(' . $expression . ')';
            }

            $forelseCounter++;
            [$iterable, $alias] = $parts;
            return "<?php \$__bladeForelseStack = \$__bladeForelseStack ?? []; \$__forelseEmpty_{$forelseCounter} = true; \$__bladeForelseStack[] = {$forelseCounter}; foreach ({$iterable} as {$alias}): \$__forelseEmpty_{$forelseCounter} = false; ?>";
        });

        $content = preg_replace('/@empty(?!\s*\()/', '<?php endforeach; $__bladeForelseCurrent = end($__bladeForelseStack); if ($__bladeForelseCurrent !== false && ${"__forelseEmpty_" . $__bladeForelseCurrent}): ?>', $content) ?? $content;
        $content = str_replace('@endforelse', '<?php array_pop($__bladeForelseStack); endif; ?>', $content);

        return $content;
    }

    /**
     * Compile every `@directive(...)` occurrence using a balanced-parenthesis scan.
     *
     * The name must be followed by a word boundary, so `@for` does not match
     * `@foreach`, and whitespace between the name and `(` is tolerated to match
     * the `\s*` the regex-based compilation used to allow. An occurrence with no
     * following `(`, or with unbalanced parentheses, is left untouched — that is
     * how bare `@break` survives to be handled separately.
     */
    private function replaceDirectiveCalls(string $content, string $directive, callable $compiler): string
    {
        $needle = '@' . $directive;
        $needleLength = strlen($needle);
        $length = strlen($content);
        $offset = 0;
        $result = '';

        while (($position = strpos($content, $needle, $offset)) !== false) {
            $afterName = $position + $needleLength;

            // Word boundary: @for must not consume @foreach.
            $nextChar = $content[$afterName] ?? '';
            if ($nextChar !== '' && ($nextChar === '_' || ctype_alnum($nextChar))) {
                $result .= substr($content, $offset, $afterName - $offset);
                $offset = $afterName;
                continue;
            }

            $parenPosition = $afterName;
            while ($parenPosition < $length && ctype_space($content[$parenPosition])) {
                $parenPosition++;
            }

            $parsed = ($content[$parenPosition] ?? '') === '('
                ? $this->extractBalancedExpression($content, $parenPosition)
                : null;

            if ($parsed === null) {
                $result .= substr($content, $offset, $afterName - $offset);
                $offset = $afterName;
                continue;
            }

            [$expression, $endPosition] = $parsed;
            $result .= substr($content, $offset, $position - $offset);
            $result .= $compiler($expression);
            $offset = $endPosition + 1;
        }

        return $result . substr($content, $offset);
    }

    private function extractBalancedExpression(string $content, int $openParenPosition): ?array
    {
        $length = strlen($content);
        if ($openParenPosition < 0 || $openParenPosition >= $length || $content[$openParenPosition] !== '(') {
            return null;
        }

        $depth = 0;
        $quote = null;
        $escape = false;

        for ($index = $openParenPosition; $index < $length; $index++) {
            $char = $content[$index];

            if ($quote !== null) {
                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($char === '\\') {
                    $escape = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return [substr($content, $openParenPosition + 1, $index - $openParenPosition - 1), $index];
                }
            }
        }

        return null;
    }

    private function splitTopLevelArguments(string $expression, ?int $limit = null): array
    {
        $arguments = [];
        $current = '';
        $depthParentheses = 0;
        $depthBrackets = 0;
        $depthBraces = 0;
        $quote = null;
        $escape = false;
        $length = strlen($expression);

        for ($index = 0; $index < $length; $index++) {
            $char = $expression[$index];

            if ($quote !== null) {
                $current .= $char;
                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($char === '\\') {
                    $escape = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === '(') {
                $depthParentheses++;
                $current .= $char;
                continue;
            }

            if ($char === ')') {
                $depthParentheses--;
                $current .= $char;
                continue;
            }

            if ($char === '[') {
                $depthBrackets++;
                $current .= $char;
                continue;
            }

            if ($char === ']') {
                $depthBrackets--;
                $current .= $char;
                continue;
            }

            if ($char === '{') {
                $depthBraces++;
                $current .= $char;
                continue;
            }

            if ($char === '}') {
                $depthBraces--;
                $current .= $char;
                continue;
            }

            if ($char === ',' && $depthParentheses === 0 && $depthBrackets === 0 && $depthBraces === 0 && ($limit === null || count($arguments) < $limit - 1)) {
                $arguments[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if ($current !== '' || $expression === '') {
            $arguments[] = trim($current);
        }

        return $arguments;
    }

    private function splitTopLevelAsExpression(string $expression): ?array
    {
        $depthParentheses = 0;
        $depthBrackets = 0;
        $depthBraces = 0;
        $quote = null;
        $escape = false;
        $length = strlen($expression);

        for ($index = 0; $index < $length; $index++) {
            $char = $expression[$index];

            if ($quote !== null) {
                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($char === '\\') {
                    $escape = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depthParentheses++;
                continue;
            }

            if ($char === ')') {
                $depthParentheses--;
                continue;
            }

            if ($char === '[') {
                $depthBrackets++;
                continue;
            }

            if ($char === ']') {
                $depthBrackets--;
                continue;
            }

            if ($char === '{') {
                $depthBraces++;
                continue;
            }

            if ($char === '}') {
                $depthBraces--;
                continue;
            }

            if ($depthParentheses === 0 && $depthBrackets === 0 && $depthBraces === 0 && substr($expression, $index, 4) === ' as ') {
                $iterable = trim(substr($expression, 0, $index));
                $alias = trim(substr($expression, $index + 4));
                if ($iterable !== '' && $alias !== '') {
                    return [$iterable, $alias];
                }

                return null;
            }
        }

        return null;
    }

    private function parseQuotedString(string $value): ?string
    {
        $value = trim($value);
        $length = strlen($value);
        if ($length < 2) {
            return null;
        }

        $quote = $value[0];
        if (($quote !== '\'' && $quote !== '"') || $value[$length - 1] !== $quote) {
            return null;
        }

        return stripcslashes(substr($value, 1, -1));
    }

    private function allowedResolvedPath(string $candidate, string $allowedBase): ?string
    {
        if ($candidate === '') {
            return null;
        }

        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }

        $normalizedResolved = str_replace('\\', '/', $resolved);
        $normalizedBase = rtrim(str_replace('\\', '/', $allowedBase), '/') . '/';

        if (!str_starts_with($normalizedResolved, $normalizedBase)) {
            return null;
        }

        return $resolved;
    }
}
