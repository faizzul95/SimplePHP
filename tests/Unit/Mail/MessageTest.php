<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Core\Mail\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    // ─── Address handling ────────────────────────────────────────────

    public function testInvalidAddressesAreDroppedRatherThanSent(): void
    {
        $message = Message::make()
            ->to('good@example.test')
            ->to('not-an-address')
            ->cc('also bad')
            ->bcc('fine@example.test');

        self::assertCount(1, $message->recipients());
        self::assertSame([], $message->ccList());
        self::assertCount(1, $message->bccList());
    }

    /** PHPMailer returns false on a repeated recipient, which read as a send failure. */
    public function testARepeatedRecipientIsAddedOnce(): void
    {
        $message = Message::make()
            ->to('user@example.test', 'First')
            ->to('USER@example.test', 'Second');

        self::assertCount(1, $message->recipients());
        self::assertSame('First', $message->recipients()[0]['name']);
    }

    public function testAddressesAreLowercasedSoDuplicatesCollapse(): void
    {
        self::assertSame('user@example.test', Message::make()->to('  User@Example.TEST  ')->recipients()[0]['email']);
    }

    // ─── Header injection ────────────────────────────────────────────

    /** A newline in a header starts another one — that is how a Bcc gets added. */
    #[DataProvider('headerInjectionPayloads')]
    public function testNewlinesAreStrippedFromHeaders(string $payload): void
    {
        $subject = Message::make()->subject($payload)->subjectLine();

        self::assertStringNotContainsString("\r", $subject);
        self::assertStringNotContainsString("\n", $subject);
    }

    /** @return array<string, array{0: string}> */
    public static function headerInjectionPayloads(): array
    {
        return [
            'lf'     => ["Subject\nBcc: attacker@evil.test"],
            'crlf'   => ["Subject\r\nBcc: attacker@evil.test"],
            'cr'     => ["Subject\rBcc: attacker@evil.test"],
            'null'   => ["Subject\0Bcc: attacker@evil.test"],
        ];
    }

    public function testNamesAreAlsoStrippedOfNewlines(): void
    {
        $name = Message::make()->to('user@example.test', "Bob\r\nBcc: evil@example.test")->recipients()[0]['name'];

        self::assertStringNotContainsString("\n", $name);
        self::assertStringNotContainsString("\r", $name);
    }

    // ─── Bodies ──────────────────────────────────────────────────────

    public function testThePlainTextBodyIsDerivedFromTheHtmlWhenNotSupplied(): void
    {
        $message = Message::make()->html('<p>Hello <b>there</b></p><p>Bye</p>');

        $text = $message->textBody();

        self::assertStringContainsString('Hello there', $text);
        self::assertStringNotContainsString('<b>', $text);
    }

    public function testAnExplicitTextBodyWins(): void
    {
        self::assertSame(
            'plain',
            Message::make()->html('<p>markup</p>')->text('plain')->textBody()
        );
    }

    public function testEntitiesAreDecodedInTheTextFallback(): void
    {
        self::assertSame('Tom & Jerry', Message::make()->html('<p>Tom &amp; Jerry</p>')->textBody());
    }

    // ─── Queue round trip ────────────────────────────────────────────

    public function testAMessageSurvivesTheQueueRoundTrip(): void
    {
        $original = Message::make()
            ->to('user@example.test', 'User')
            ->cc('cc@example.test')
            ->bcc('bcc@example.test')
            ->replyTo('reply@example.test', 'Support')
            ->from('sender@example.test', 'Sender')
            ->subject('Subject')
            ->html('<p>Body</p>')
            ->text('Body');

        $restored = Message::fromArray($original->toArray());

        self::assertEquals($original->toArray(), $restored->toArray());
        self::assertSame('sender@example.test', $restored->fromEmail());
        self::assertSame('Support', $restored->replyToList()[0]['name']);
    }

    // ─── The legacy shape ────────────────────────────────────────────

    public function testTheLegacyRecipientArrayIsUnderstood(): void
    {
        $message = Message::fromLegacy([
            'recipient_email' => 'user@example.test',
            'recipient_name' => 'User',
            'recipient_cc' => ['a@example.test', 'b@example.test'],
            'recipient_bcc' => 'c@example.test',
        ], 'Subject', '<p>Body</p>');

        self::assertSame('user@example.test', $message->recipients()[0]['email']);
        self::assertCount(2, $message->ccList());
        self::assertCount(1, $message->bccList());
        self::assertSame('Subject', $message->subjectLine());
    }

    public function testALegacyCallWithNoRecipientIsNotSendable(): void
    {
        self::assertFalse(Message::fromLegacy([], 'Subject', 'Body')->hasRecipient());
    }

    public function testAttachmentsAreDeduplicated(): void
    {
        $message = Message::make()->attach('/tmp/a.pdf')->attach('/tmp/a.pdf')->attach('/tmp/b.pdf');

        self::assertSame(['/tmp/a.pdf', '/tmp/b.pdf'], $message->attachmentPaths());
    }
}
