<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Http\JsonResponse;
use Core\Http\RedirectResponse;
use Core\Http\Reply;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Every controller in this application answered with jsonResponse(), so a form
 * submitted from a browser got a JSON body instead of a redirect, and no flash
 * message ever reached a page. Writing both branches by hand at every exit point
 * is the boilerplate nobody keeps up.
 *
 * A Reply carries the answer and picks the form at emit time.
 */
final class ReplyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        Request::setCurrent(null);

        // Response::sanitizeRedirectTarget() normalises the host through the
        // security service, and fails closed to '/' when it is unavailable — so
        // without this every redirect assertion below would pass against '/'
        // rather than against the target it was given.
        register_framework_service('security', static fn(): \Components\Security => new \Components\Security());
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        Request::setCurrent(null);
        reset_framework_service();
        parent::tearDown();
    }

    /** @param array<string, string> $headers */
    private function actAs(string $path, array $headers = []): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'example.test',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $_SERVER = array_merge($_SERVER, $server);
        Request::setCurrent(new Request([], [], $server));
    }

    private function browser(string $path = '/templates/save'): void
    {
        $this->actAs($path, ['Accept' => 'text/html']);
    }

    private function apiClient(string $path = '/templates/save'): void
    {
        $this->actAs($path, ['Accept' => 'application/json']);
    }

    /** Resolution happens through the Responsable interface, so drive it that way. */
    private function resolve(Reply $reply): object
    {
        $status = $reply->status();
        $headers = $reply->headers();

        return new class ($status, $headers, $reply) {
            public function __construct(
                public int $status,
                public array $headers,
                public Reply $reply
            ) {
            }
        };
    }

    // ─── The API branch ──────────────────────────────────────────────

    public function testAnApiClientGetsTheJsonPayload(): void
    {
        $this->apiClient();

        $reply = Reply::make('Email template saved', ['id' => 7]);

        self::assertSame(200, $reply->status());
        self::assertSame(
            ['code' => 200, 'message' => 'Email template saved', 'data' => ['id' => 7]],
            $reply->payload()
        );
    }

    public function testTheJsonBranchSendsAJsonContentType(): void
    {
        $this->apiClient();

        $headers = Reply::make('Saved')->headers();

        self::assertStringContainsString('application/json', $headers['Content-Type'] ?? '');
    }

    /** Omitted rather than null: a client checking `isset($body.data)` should get false. */
    public function testAnAbsentPayloadIsOmittedFromTheBody(): void
    {
        $this->apiClient();

        self::assertArrayNotHasKey('data', Reply::make('Deleted')->payload());
    }

    /** But an explicit null payload is a payload, and has to survive. */
    public function testAnExplicitNullPayloadIsKept(): void
    {
        $this->apiClient();

        $payload = Reply::make('Nothing found')->data(null)->payload();

        self::assertArrayHasKey('data', $payload);
        self::assertNull($payload['data']);
    }

    public function testWithAddsKeysToTheJsonBody(): void
    {
        $this->apiClient();

        $payload = Reply::make('Listed')->with('total', 42)->with(['page' => 2, 'per_page' => 25])->payload();

        self::assertSame(42, $payload['total']);
        self::assertSame(2, $payload['page']);
        self::assertSame(25, $payload['per_page']);
    }

    public function testTheStatusCodeAppearsInBothTheBodyAndTheResponse(): void
    {
        $this->apiClient();

        $reply = Reply::make('Created')->code(201);

        self::assertSame(201, $reply->status());
        self::assertSame(201, $reply->payload()['code']);
    }

    public function testErrorsBecomeAnErrorsObject(): void
    {
        $this->apiClient();

        $payload = Reply::make('Check the form')->errors(['email' => 'Already taken'])->payload();

        self::assertSame(['email' => 'Already taken'], $payload['errors']);
    }

    /** Errors without a status mean a validation failure; 200 would be a lie. */
    public function testErrorsImplyAFailureStatus(): void
    {
        $this->apiClient();

        self::assertSame(422, Reply::make('Check the form')->errors(['email' => 'Taken'])->status());
    }

    public function testAnExplicitStatusSurvivesErrors(): void
    {
        $this->apiClient();

        self::assertSame(409, Reply::make('Conflict')->code(409)->errors(['id' => 'Exists'])->status());
    }

    // ─── The browser branch ──────────────────────────────────────────

    public function testABrowserGetsARedirect(): void
    {
        $this->browser();

        $reply = Reply::make('Email template saved')->to('/email-templates');

        self::assertSame(303, $reply->status());
        self::assertArrayHasKey('Location', $reply->headers());
    }

    /**
     * 422 with a Location header is not a redirect and a browser will not follow
     * it. The logical status survives as the flash key instead.
     */
    public function testAFailureRedirectIsStill302(): void
    {
        $this->browser();

        self::assertSame(302, Reply::make('Failed to save')->code(422)->to('/templates')->status());
    }

    public function testTheRedirectTargetIsTheRequestedPath(): void
    {
        $this->browser();

        $location = Reply::make('Saved')->to('/email-templates')->headers()['Location'];

        self::assertStringContainsString('/email-templates', $location);
    }

    /** No target means back to the form, which is what a rejected submit wants. */
    public function testWithNoTargetTheBrowserGoesBack(): void
    {
        $this->browser();
        $_SERVER['HTTP_REFERER'] = 'https://example.test/email-templates/edit/7';

        $location = Reply::make('Failed to save')->code(422)->headers()['Location'];

        self::assertStringContainsString('/email-templates/edit/7', $location);
    }

    public function testBackFallsBackWhenThereIsNoReferer(): void
    {
        $this->browser();
        unset($_SERVER['HTTP_REFERER']);

        $location = Reply::make('Failed')->code(422)->back('/dashboard')->headers()['Location'];

        self::assertStringContainsString('/dashboard', $location);
    }

    public function testACustomHeaderReachesBothBranches(): void
    {
        $this->apiClient();
        self::assertSame('no-store', Reply::make('Saved')->header('Cache-Control', 'no-store')->headers()['Cache-Control']);

        $this->browser();
        self::assertSame('no-store', Reply::make('Saved')->to('/x')->header('Cache-Control', 'no-store')->headers()['Cache-Control']);
    }

    // ─── Forcing a branch ────────────────────────────────────────────

    public function testAJsonBranchCanBeForcedForABrowser(): void
    {
        $this->browser();

        self::assertSame(200, Reply::make('Saved')->asJson()->status());
        self::assertArrayNotHasKey('Location', Reply::make('Saved')->asJson()->headers());
    }

    public function testARedirectCanBeForcedForAnApiClient(): void
    {
        $this->apiClient();

        self::assertArrayHasKey('Location', Reply::make('Saved')->to('/x')->asRedirect()->headers());
    }

    /**
     * A console command or a queued job has no request. Redirecting there is
     * meaningless, so the JSON form is the only sensible answer.
     */
    public function testWithNoRequestAtAllTheAnswerIsJson(): void
    {
        Request::setCurrent(null);

        self::assertArrayNotHasKey('Location', Reply::make('Done')->to('/x')->headers());
    }

    // ─── Immutability ────────────────────────────────────────────────

    /** Every builder returns a clone, so a shared base Reply cannot be mutated. */
    public function testBuildersDoNotMutateTheOriginal(): void
    {
        $this->apiClient();

        $base = Reply::make('Saved');
        $modified = $base->code(201)->with('extra', true);

        self::assertSame(200, $base->status());
        self::assertArrayNotHasKey('extra', $base->payload());
        self::assertSame(201, $modified->status());
    }

    /**
     * Resolution is memoised for consistency between status()/headers()/emitBody().
     * A builder called after that must not hand back a stale response.
     */
    public function testAMutationAfterResolutionStillTakesEffect(): void
    {
        $this->apiClient();

        $reply = Reply::make('Saved');
        self::assertSame(200, $reply->status());

        self::assertSame(201, $reply->code(201)->status());
    }

    // ─── Internals stay internal ─────────────────────────────────────

    /** back()'s fallback is stored alongside with() values; it must not leak out. */
    public function testTheBackFallbackNeverAppearsInTheJsonBody(): void
    {
        $this->apiClient();

        $payload = Reply::make('Failed')->code(422)->back('/dashboard')->payload();

        foreach (array_keys($payload) as $key) {
            self::assertStringStartsNotWith('__reply_', (string) $key);
        }
    }

    public function testAnEmptyWithKeyIsIgnored(): void
    {
        $this->apiClient();

        $payload = Reply::make('Saved')->with('   ', 'value')->payload();

        self::assertSame(['code' => 200, 'message' => 'Saved'], $payload);
    }

    public function testAnOutOfRangeStatusIsNormalised(): void
    {
        $this->apiClient();

        self::assertSame(500, Reply::make('Broken')->code(9999)->status());
    }

    // ─── ok() and fail() ─────────────────────────────────────────────
    //
    // reply('…')->code(422) reads as an afterthought at exactly the moment the
    // status is the point. These two name the two shapes that actually occur.

    public function testOkIsASuccessfulReply(): void
    {
        $this->apiClient();

        $reply = ok('User saved', ['id' => 7]);

        self::assertSame(200, $reply->status());
        self::assertSame(['code' => 200, 'message' => 'User saved', 'data' => ['id' => 7]], $reply->payload());
    }

    public function testOkTakesNoArgumentsAtAll(): void
    {
        $this->apiClient();

        self::assertSame(['code' => 200], ok()->payload());
    }

    /** A rejected write is 422: understood, and not carried out. */
    public function testFailDefaultsTo422(): void
    {
        $this->apiClient();

        self::assertSame(422, fail('Failed to delete role')->status());
    }

    public function testFailTakesAnExplicitStatus(): void
    {
        $this->apiClient();

        self::assertSame(404, fail('User not found', 404)->status());
        self::assertSame(403, fail('Not allowed', 403)->status());
    }

    public function testFailCarriesTheMessageIntoTheBody(): void
    {
        $this->apiClient();

        self::assertSame(
            ['code' => 404, 'message' => 'User not found'],
            fail('User not found', 404)->payload()
        );
    }

    /** They return the same object, so every builder still chains off them. */
    public function testBothReturnAChainableReply(): void
    {
        $this->apiClient();

        self::assertInstanceOf(Reply::class, ok('Saved'));
        self::assertInstanceOf(Reply::class, fail('Nope'));

        self::assertSame(
            ['email' => 'Required'],
            fail('Check the form')->errors(['email' => 'Required'])->payload()['errors']
        );
    }

    public function testOkStillNegotiatesForABrowser(): void
    {
        $this->browser();

        self::assertSame(303, ok('Saved')->to('/things')->status());
    }

    public function testFailStillSendsABrowserBack(): void
    {
        $this->browser();
        $_SERVER['HTTP_REFERER'] = 'https://example.test/things/edit/7';

        $response = fail('Could not save');

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/things/edit/7', $response->headers()['Location']);
    }

    /** An out-of-range status has to clamp the same way through every entry point. */
    public function testFailNormalisesAnInvalidStatus(): void
    {
        $this->apiClient();

        self::assertSame(500, fail('Broken', 0)->status());
    }
}
