<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_DIR . 'app/helpers/custom_general_helper.php';

final class AssetVersioningTest extends TestCase
{
    private string $assetPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('BASE_URL')) {
            define('BASE_URL', 'https://example.test/');
        }

        $GLOBALS['config']['assets'] = [
            'versioning' => true,
            'cache_ttl' => 60,
            'fallback_version' => 'fallback-version',
        ];

        $dir = ROOT_DIR . 'public/testing';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->assetPath = $dir . '/asset-versioning.css';
        file_put_contents($this->assetPath, 'body { color: #123456; }');
    }

    protected function tearDown(): void
    {
        if (is_file($this->assetPath)) {
            unlink($this->assetPath);
        }

        parent::tearDown();
    }

    public function testAssetHelperAppendsContentHashForPublicAssets(): void
    {
        $url = asset('testing/asset-versioning.css');

        self::assertStringStartsWith('https://example.test/public/testing/asset-versioning.css?v=', $url);
        self::assertMatchesRegularExpression('/\?v=[a-f0-9]{12}$/', $url);
    }

    public function testAssetHelperSkipsVersioningForNonPublicAssets(): void
    {
        $url = asset('uploads/avatar.png', false);

        self::assertSame('https://example.test/uploads/avatar.png', $url);
    }
}