<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Core\Mail\Mailer;
use Core\Mail\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mailer had no tests at all, and the helper it replaces could not be
 * exercised without a live SMTP server — which is most of why these defects
 * survived.
 */
final class MailerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mailer::flushCaptured();
    }

    protected function tearDown(): void
    {
        Mailer::flushCaptured();
        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function mailer(array $config = []): Mailer
    {
        return new Mailer(array_merge([
            'driver' => Mailer::DRIVER_ARRAY,
            'from_email' => 'noreply@example.test',
            'from_name' => 'Example',
        ], $config));
    }

    // ─── Drivers ─────────────────────────────────────────────────────

    public function testTheArrayDriverCapturesInsteadOfSending(): void
    {
        $result = $this->mailer()->send(
            Message::make()->to('user@example.test', 'User')->subject('Hi')->html('<p>Hello</p>')
        );

        self::assertTrue($result['success']);
        self::assertCount(1, Mailer::captured());
        self::assertSame('Hi', Mailer::lastCaptured()?->subjectLine());
    }

    public function testTheNullDriverReportsSuccessAndCapturesNothing(): void
    {
        $result = $this->mailer(['driver' => Mailer::DRIVER_NULL])->send(
            Message::make()->to('user@example.test')->subject('Hi')
        );

        self::assertTrue($result['success']);
        self::assertSame([], Mailer::captured());
    }

    public function testAnUnknownDriverFallsBackToSmtpRatherThanSendingNowhere(): void
    {
        self::assertSame(Mailer::DRIVER_SMTP, $this->mailer(['driver' => 'carrier-pigeon'])->driver());
    }

    #[DataProvider('driverNames')]
    public function testDriverNamesAreCaseInsensitive(string $configured, string $expected): void
    {
        self::assertSame($expected, $this->mailer(['driver' => $configured])->driver());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function driverNames(): array
    {
        return [
            'upper' => ['ARRAY', Mailer::DRIVER_ARRAY],
            'padded' => ['  log  ', Mailer::DRIVER_LOG],
            'mixed' => ['NuLl', Mailer::DRIVER_NULL],
        ];
    }

    // ─── Refusals ────────────────────────────────────────────────────

    public function testAMessageWithNoValidRecipientIsRefused(): void
    {
        $result = $this->mailer()->send(Message::make()->to('not-an-address')->subject('Hi'));

        self::assertFalse($result['success']);
        self::assertSame('Invalid recipient email address', $result['message']);
        self::assertSame([], Mailer::captured());
    }

    /**
     * The old helper returned $mail->ErrorInfo, which carries the SMTP
     * conversation, to whatever displayed the result.
     */
    public function testAMisconfiguredFromAddressDoesNotLeakConfigurationDetail(): void
    {
        $result = (new Mailer([
            'driver' => Mailer::DRIVER_SMTP,
            'host' => 'smtp.internal.example',
            'username' => 'svc-account',
            'from_email' => '',
        ]))->send(Message::make()->to('user@example.test')->subject('Hi'));

        self::assertFalse($result['success']);
        self::assertStringNotContainsString('smtp.internal.example', $result['message']);
        self::assertStringNotContainsString('svc-account', $result['message']);
    }

    // ─── Configuration ───────────────────────────────────────────────

    public function testTheSmtpTimeoutIsBoundedRatherThanPhpMailersFiveMinutes(): void
    {
        $timeout = new \ReflectionMethod(Mailer::class, 'timeout');

        self::assertSame(10, $timeout->invoke($this->mailer()), 'default');
        self::assertSame(30, $timeout->invoke($this->mailer(['timeout' => 30])), 'configured');
        self::assertSame(10, $timeout->invoke($this->mailer(['timeout' => 0])), 'zero falls back');
        self::assertSame(10, $timeout->invoke($this->mailer(['timeout' => -5])), 'negative falls back');
        self::assertSame(120, $timeout->invoke($this->mailer(['timeout' => 9999])), 'capped');
    }
}
