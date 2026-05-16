<?php

declare(strict_types=1);

use Core\View\BladeEngine;
use PHPUnit\Framework\TestCase;

final class BladeSriDirectiveTest extends TestCase
{
    private string $viewDir;
    private string $cacheDir;
    private string $assetPath;

    protected function setUp(): void
    {
        parent::setUp();

        bootstrapTestFrameworkServices([
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
                'prefix' => 'test_',
            ],
        ]);

        $GLOBALS['config']['assets'] = [
            'cache_ttl' => 60,
            'sri_algorithm' => 'sha384',
        ];

        $base = ROOT_DIR . 'storage/framework/testing/blade-sri';
        $this->viewDir = $base . '/views';
        $this->cacheDir = $base . '/cache';

        if (!is_dir($this->viewDir)) {
            mkdir($this->viewDir, 0777, true);
        }

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        $assetDir = ROOT_DIR . 'public/testing';
        if (!is_dir($assetDir)) {
            mkdir($assetDir, 0777, true);
        }

        $this->assetPath = $assetDir . '/blade-sri.js';
        file_put_contents($this->assetPath, 'console.log("blade-sri");');
    }

    protected function tearDown(): void
    {
        $viewFile = $this->viewDir . '/sample.php';
        if (is_file($viewFile)) {
            unlink($viewFile);
        }

        if (is_file($this->assetPath)) {
            unlink($this->assetPath);
        }

        foreach (glob($this->cacheDir . '/*.php') ?: [] as $compiled) {
            unlink($compiled);
        }

        parent::tearDown();
    }

    public function testBladeEngineCompilesSriDirectiveForLocalAssets(): void
    {
        file_put_contents(
            $this->viewDir . '/sample.php',
            '<script @sri(\'testing/blade-sri.js\')></script>'
        );

        $blade = new BladeEngine($this->viewDir, $this->cacheDir);
        $rendered = $blade->render('sample');

        self::assertStringContainsString('integrity="sha384-', $rendered);
        self::assertStringNotContainsString('crossorigin=', $rendered);
    }

    public function testBladeEngineCompilesManualSriDirectiveForExternalAssets(): void
    {
        file_put_contents(
            $this->viewDir . '/sample.php',
            '<script @sri(\'https://cdn.example.test/app.js\', \'sha384-manual\')></script>'
        );

        $blade = new BladeEngine($this->viewDir, $this->cacheDir);
        $rendered = $blade->render('sample');

        self::assertStringContainsString('integrity="sha384-manual" crossorigin="anonymous"', $rendered);
    }
}