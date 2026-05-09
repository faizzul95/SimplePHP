<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Core\Security\SignedUrl;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Core\Security\SignedUrl
 *
 * Covers: generate(), verify() — valid, expired, tampered
 */
class SignedUrlTest extends TestCase
{
    protected function setUp(): void
    {
        // Set APP_KEY so sign() works
        $GLOBALS['config']['app']['key'] = 'test-key-' . str_repeat('a', 32);
    }

    public function test_generate_appends_expires_and_signature(): void
    {
        $signed = SignedUrl::generate('https://example.com/reset', 3600);

        $this->assertStringContainsString('expires=', $signed);
        $this->assertStringContainsString('signature=', $signed);
    }

    public function test_verify_returns_true_for_valid_url(): void
    {
        $signed = SignedUrl::generate('https://example.com/reset', 3600);

        $this->assertTrue(SignedUrl::verify($signed));
    }

    public function test_verify_returns_false_for_expired_url(): void
    {
        $signed = SignedUrl::generate('https://example.com/reset', -1); // Already expired

        $this->assertFalse(SignedUrl::verify($signed));
    }

    public function test_verify_returns_false_for_tampered_signature(): void
    {
        $signed  = SignedUrl::generate('https://example.com/reset', 3600);
        $tampered = str_replace('signature=', 'signature=TAMPERED', $signed);

        $this->assertFalse(SignedUrl::verify($tampered));
    }

    public function test_verify_returns_false_for_tampered_url_base(): void
    {
        $signed   = SignedUrl::generate('https://example.com/reset', 3600);
        $tampered = str_replace('reset', 'admin', $signed);

        $this->assertFalse(SignedUrl::verify($tampered));
    }

    public function test_generate_adds_ampersand_when_url_has_query(): void
    {
        $signed = SignedUrl::generate('https://example.com/reset?email=a%40b.com', 3600);

        // Should use & not ?
        $this->assertStringContainsString('?email=', $signed);
        $this->assertStringContainsString('&expires=', $signed);
    }

    public function test_generate_adds_question_mark_when_no_query(): void
    {
        $signed = SignedUrl::generate('https://example.com/verify', 3600);

        $this->assertStringContainsString('?expires=', $signed);
    }

    public function test_for_file_generates_signed_url(): void
    {
        $signed = SignedUrl::forFile('avatars/abc123.jpg', 300);

        $this->assertStringContainsString('/files/serve/', $signed);
        $this->assertStringContainsString('signature=', $signed);
        $this->assertTrue(SignedUrl::verify($signed));
    }

    public function test_throws_when_app_key_not_set(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY/');

        // Temporarily clear the key
        $saved = $GLOBALS['config']['app']['key'] ?? null;
        $GLOBALS['config']['app']['key'] = null;

        try {
            SignedUrl::generate('/test', 60);
        } finally {
            $GLOBALS['config']['app']['key'] = $saved;
        }
    }
}
