<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use Core\Telemetry\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gate decides whether query text, request bodies and mail bodies get
 * written to disk, so the interesting cases are the ones where it must say no.
 */
final class GateTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function gate(array $config): Gate
    {
        return new Gate($config);
    }

    // ─── Off by default ──────────────────────────────────────────────

    public function testRecordingIsOffWhenNothingIsConfigured(): void
    {
        self::assertFalse($this->gate([])->allows(1));
        self::assertSame(Gate::MODE_OFF, $this->gate([])->mode());
    }

    public function testEnabledAloneIsNotEnoughWithoutAMode(): void
    {
        self::assertFalse($this->gate(['enabled' => true])->allows(1));
    }

    public function testDisabledBeatsEveryOtherSetting(): void
    {
        self::assertFalse($this->gate([
            'enabled' => false,
            'mode' => Gate::MODE_ALL,
            'allow_in_production' => true,
        ])->allows(1));
    }

    // ─── Mode: all ───────────────────────────────────────────────────

    public function testModeAllRecordsEveryone(): void
    {
        $gate = $this->gate(['enabled' => true, 'mode' => Gate::MODE_ALL]);

        self::assertTrue($gate->allows(42));
    }

    public function testModeAllRecordsAnonymousVisitorsToo(): void
    {
        self::assertTrue($this->gate(['enabled' => true, 'mode' => Gate::MODE_ALL])->allows(null));
    }

    // ─── Mode: users ─────────────────────────────────────────────────

    public function testModeUsersRecordsOnlyTheListedIds(): void
    {
        $config = ['enabled' => true, 'mode' => Gate::MODE_USERS, 'user_ids' => [7, 9]];

        self::assertTrue($this->gate($config)->allows(7));
        self::assertTrue($this->gate($config)->allows(9));
        self::assertFalse($this->gate($config)->allows(8));
    }

    /** Ids from .env arrive as strings; they still have to match. */
    #[DataProvider('userIdConfigs')]
    public function testUserIdsAreMatchedByValue(array $configured): void
    {
        $gate = $this->gate(['enabled' => true, 'mode' => Gate::MODE_USERS, 'user_ids' => $configured]);

        self::assertTrue($gate->allows(7));
    }

    /** @return array<string, array{0: list<mixed>}> */
    public static function userIdConfigs(): array
    {
        return [
            'ints' => [[7]],
            'strings' => [['7']],
            'padded' => [[' 7 ']],
            'mixed' => [['7', 9]],
        ];
    }

    /** "These users" cannot include somebody who is not signed in. */
    public function testModeUsersRefusesAnonymousVisitors(): void
    {
        $gate = $this->gate(['enabled' => true, 'mode' => Gate::MODE_USERS, 'user_ids' => [7]]);

        self::assertFalse($gate->allows(null));
        self::assertFalse($gate->allows(0));
        self::assertFalse($gate->allows(-1));
    }

    /** Junk in the list must not become a wildcard. */
    public function testNonNumericUserIdsAdmitNobody(): void
    {
        $gate = $this->gate([
            'enabled' => true,
            'mode' => Gate::MODE_USERS,
            'user_ids' => ['everyone', '*', null, ''],
        ]);

        self::assertSame([], $gate->allowedUserIds());
        self::assertFalse($gate->allows(7));
    }

    public function testAnUnknownModeIsTreatedAsOff(): void
    {
        self::assertSame(Gate::MODE_OFF, $this->gate(['mode' => 'everything'])->mode());
        self::assertFalse($this->gate(['enabled' => true, 'mode' => 'everything'])->allows(1));
    }

    // ─── The decision is cached, and forgettable ─────────────────────

    /**
     * The gate is asked on every recorded event; resolving the user each time
     * would mean an auth lookup per query.
     */
    public function testTheDecisionIsCachedUntilForgotten(): void
    {
        $gate = $this->gate(['enabled' => true, 'mode' => Gate::MODE_USERS, 'user_ids' => [7]]);

        self::assertFalse($gate->allows(null), 'anonymous at first');
        self::assertFalse($gate->allows(7), 'cached answer stands');

        $gate->forget();

        self::assertTrue($gate->allows(7), 'after login the answer changes');
    }
}
