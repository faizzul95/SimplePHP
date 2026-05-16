<?php

declare(strict_types=1);

use Core\Assets\AssetIntegrity;
use PHPUnit\Framework\TestCase;

final class AssetIntegrityTest extends TestCase
{
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
            'fallback_version' => 'fallback-version',
        ];

        $dir = ROOT_DIR . 'public/testing';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->assetPath = $dir . '/asset-integrity.js';
        file_put_contents($this->assetPath, 'console.log("integrity-test");');
    }

    protected function tearDown(): void
    {
        if (is_file($this->assetPath)) {
            unlink($this->assetPath);
        }

        parent::tearDown();
    }

    public function testDigestForPublicAssetReturnsConfiguredAlgorithmDigest(): void
    {
        $digest = AssetIntegrity::digestForPublicAsset('testing/asset-integrity.js');

        self::assertStringStartsWith('sha384-', $digest);
        self::assertNotSame('', $digest);
    }

    public function testAttributesForLocalAssetReturnsIntegrityWithoutCrossorigin(): void
    {
        $attributes = AssetIntegrity::attributes('testing/asset-integrity.js');

        self::assertStringContainsString('integrity="sha384-', $attributes);
        self::assertStringNotContainsString('crossorigin=', $attributes);
    }

    public function testAttributesForExternalAssetUsesManualHashAndCrossorigin(): void
    {
        $attributes = AssetIntegrity::attributes('https://cdn.example.test/app.js', 'sha384-manualhash');

        self::assertSame('integrity="sha384-manualhash" crossorigin="anonymous"', $attributes);
    }
}