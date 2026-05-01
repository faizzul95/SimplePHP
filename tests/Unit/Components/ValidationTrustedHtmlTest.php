<?php

declare(strict_types=1);

use Components\Validation;
use PHPUnit\Framework\TestCase;

final class ValidationTrustedHtmlTest extends TestCase
{
    public function testAllowsTrustedHtmlWithTemplatePlaceholderHref(): void
    {
        $validator = Validation::make([
            'email_body' => '<p>Hello %name%</p><p><a href="%login_url%" target="_blank" rel="noopener">Login</a></p>',
        ], [
            'email_body' => 'required|string|safe_html',
        ])->validate();

        self::assertTrue($validator->passed());
        self::assertSame([], $validator->getErrors());
    }

    public function testRejectsTrustedHtmlWithJavascriptHrefEvenWhenPlaceholderIsPresent(): void
    {
        $validator = Validation::make([
            'email_body' => '<p><a href="javascript:%login_url%">Login</a></p>',
        ], [
            'email_body' => 'required|string|safe_html',
        ])->validate();

        self::assertFalse($validator->passed());
        self::assertArrayHasKey('email_body', $validator->getErrors());
    }

    public function testAllowsTrustedHtmlFullEmailDocumentWithSafeStyleBlock(): void
    {
        $validator = Validation::make([
            'email_body' => '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Password Reset</title><style>body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; } .header { background: #007bff; color: white; padding: 20px; text-align: center; }</style></head><body><div class="header"><h1>%app_name%</h1><h2>Password Reset</h2></div><p>Hello %user_fullname%,</p><div class="password-box"><div class="password">%new_password%</div></div></body></html>',
        ], [
            'email_body' => 'required|string|safe_html',
        ])->validate();

        self::assertTrue($validator->passed());
        self::assertSame([], $validator->getErrors());
    }

    public function testRejectsTrustedHtmlFullEmailDocumentWithUnsafeStyleImport(): void
    {
        $validator = Validation::make([
            'email_body' => '<html><head><style>@import url("https://evil.example/malware.css");</style></head><body><p>Hello</p></body></html>',
        ], [
            'email_body' => 'required|string|safe_html',
        ])->validate();

        self::assertFalse($validator->passed());
        self::assertArrayHasKey('email_body', $validator->getErrors());
    }
}