<?php

declare(strict_types=1);

namespace Tests\Unit\Components;

use Components\MenuManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MenuManager::canAccessPath() is an authorization decision — EnforceMenuAccess
 * calls it on every request — and it had no tests.
 *
 * The actor context is normally derived from auth()/getSession(), neither of
 * which exists in a unit test, so it is injected into the private cache the
 * manager already keeps.
 */
final class MenuAccessControlTest extends TestCase
{
    private const GUEST = ['user_id' => 0, 'role_id' => 0, 'role_rank' => 0, 'role_name' => '', 'via' => null, 'authenticated' => false];
    private const STAFF = ['user_id' => 7, 'role_id' => 3, 'role_rank' => 10, 'role_name' => 'Staff', 'via' => 'session', 'authenticated' => true];
    private const SUPERADMIN = ['user_id' => 1, 'role_id' => 1, 'role_rank' => 9999, 'role_name' => 'Superadmin', 'via' => 'session', 'authenticated' => true];

    /**
     * @param array<string, mixed> $menu
     * @param array<string, mixed> $actor
     */
    private function manager(array $menu, array $actor = self::GUEST): MenuManager
    {
        $manager = new MenuManager(['main' => $menu]);

        $cache = new \ReflectionProperty(MenuManager::class, 'cachedActorContext');
        $cache->setValue($manager, $actor);

        return $manager;
    }

    /** @return array<string, mixed> */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'desc' => 'Reports',
            'url' => '/reports',
            'state' => 'release',
            'active' => true,
        ], $overrides);
    }

    // ─── The fail-open default, stated on purpose ────────────────────

    /**
     * A path with no menu entry is allowed here. That is the design: the menu
     * governs menu-linked routes, and everything else is governed by its own
     * route middleware. Pinning it down so a change to it has to be deliberate.
     */
    public function testAPathThatIsNotInTheMenuIsNotThisComponentsDecision(): void
    {
        $manager = $this->manager(['reports' => $this->item()]);

        self::assertTrue($manager->canAccessPath('/something-else'));
        self::assertNull($manager->findItemByPath('/something-else'));
    }

    // ─── active ──────────────────────────────────────────────────────

    public function testAnInactiveItemIsRefusedEvenForSuperadmin(): void
    {
        $manager = $this->manager(['reports' => $this->item(['active' => false])], self::SUPERADMIN);

        self::assertFalse($manager->canAccessPath('/reports'));
    }

    // ─── state ───────────────────────────────────────────────────────

    public function testADisabledItemIsRefusedForEveryone(): void
    {
        foreach ([self::GUEST, self::STAFF, self::SUPERADMIN] as $actor) {
            $manager = $this->manager(['reports' => $this->item(['state' => 'disabled'])], $actor);
            self::assertFalse($manager->canAccessPath('/reports'));
        }
    }

    #[DataProvider('superadminOnlyStates')]
    public function testAGatedStateAdmitsOnlySuperadmin(string $state): void
    {
        self::assertFalse(
            $this->manager(['reports' => $this->item(['state' => $state])], self::STAFF)->canAccessPath('/reports'),
            $state . ' must be hidden from staff'
        );

        self::assertTrue(
            $this->manager(['reports' => $this->item(['state' => $state])], self::SUPERADMIN)->canAccessPath('/reports'),
            $state . ' must be visible to superadmin'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function superadminOnlyStates(): array
    {
        return ['maintenance' => ['maintenance'], 'unreleased' => ['unreleased']];
    }

    /** role_id 1 alone is not enough; the rank has to be there too. */
    public function testRoleOneWithoutSuperadminRankIsNotSuperadmin(): void
    {
        $impostor = ['user_id' => 2, 'role_id' => 1, 'role_rank' => 10, 'role_name' => 'Admin', 'via' => 'session', 'authenticated' => true];

        self::assertFalse(
            $this->manager(['reports' => $this->item(['state' => 'maintenance'])], $impostor)->canAccessPath('/reports')
        );
    }

    // ─── role_ids ────────────────────────────────────────────────────

    public function testARoleWhitelistAdmitsAListedRole(): void
    {
        $manager = $this->manager(['reports' => $this->item(['role_ids' => [3, 4]])], self::STAFF);

        self::assertTrue($manager->canAccessPath('/reports'));
    }

    public function testARoleWhitelistRefusesAnUnlistedRole(): void
    {
        $manager = $this->manager(['reports' => $this->item(['role_ids' => [4, 5]])], self::STAFF);

        self::assertFalse($manager->canAccessPath('/reports'));
    }

    public function testARoleWhitelistRefusesAGuest(): void
    {
        $manager = $this->manager(['reports' => $this->item(['role_ids' => [3]])], self::GUEST);

        self::assertFalse($manager->canAccessPath('/reports'));
    }

    /**
     * The ids are compared strictly, so a config written with string ids used
     * to admit nobody at all.
     */
    #[DataProvider('equivalentRoleIdConfigs')]
    public function testRoleIdsAreComparedByValueNotByType(array $configured): void
    {
        $manager = $this->manager(['reports' => $this->item(['role_ids' => $configured])], self::STAFF);

        self::assertTrue($manager->canAccessPath('/reports'));
    }

    /** @return array<string, array{0: list<mixed>}> */
    public static function equivalentRoleIdConfigs(): array
    {
        return [
            'ints' => [[3, 4]],
            'strings' => [['3', '4']],
            'mixed' => [[3, '4']],
            'padded strings' => [[' 3 ']],
        ];
    }

    /** Junk in the whitelist must not become a wildcard. */
    public function testNonNumericWhitelistEntriesAdmitNobody(): void
    {
        $manager = $this->manager(['reports' => $this->item(['role_ids' => ['all', '*', null]])], self::STAFF);

        self::assertFalse($manager->canAccessPath('/reports'));
    }

    // ─── Nesting ─────────────────────────────────────────────────────

    public function testAChildItemIsFoundAndGated(): void
    {
        $menu = [
            'admin' => $this->item([
                'desc' => 'Admin',
                'url' => '/admin',
                'subpage' => [
                    'secret' => $this->item(['desc' => 'Secret', 'url' => '/admin/secret', 'state' => 'maintenance']),
                ],
            ]),
        ];

        self::assertNotNull($this->manager($menu, self::STAFF)->findItemByPath('/admin/secret'));
        self::assertFalse($this->manager($menu, self::STAFF)->canAccessPath('/admin/secret'));
        self::assertTrue($this->manager($menu, self::SUPERADMIN)->canAccessPath('/admin/secret'));
    }

    // ─── Path matching ───────────────────────────────────────────────

    /**
     * EnforceMenuAccess passes Request::path(), which is always leading-slash
     * and trailing-slash-free. These are the shapes a config author writes.
     */
    #[DataProvider('equivalentConfiguredUrls')]
    public function testConfiguredUrlShapesResolveToTheSamePath(string $configuredUrl): void
    {
        $manager = $this->manager(['reports' => $this->item(['url' => $configuredUrl, 'state' => 'disabled'])], self::SUPERADMIN);

        self::assertFalse($manager->canAccessPath('/reports'), $configuredUrl . ' should match /reports');
    }

    /** @return array<string, array{0: string}> */
    public static function equivalentConfiguredUrls(): array
    {
        return [
            'leading slash' => ['/reports'],
            'no slash' => ['reports'],
            'trailing slash' => ['/reports/'],
            'absolute url' => ['https://example.test/reports'],
            'url with query' => ['/reports?tab=all'],
        ];
    }

    /** A near-miss must not match, or the gate would cover routes it never saw. */
    public function testASimilarPathDoesNotMatch(): void
    {
        $manager = $this->manager(['reports' => $this->item(['state' => 'disabled'])], self::SUPERADMIN);

        self::assertTrue($manager->canAccessPath('/reports-archive'));
        self::assertTrue($manager->canAccessPath('/reports/2024'));
    }

    // ─── Closures ────────────────────────────────────────────────────

    public function testAStateClosureIsResolvedBeforeTheGate(): void
    {
        $menu = ['reports' => $this->item(['state' => static fn(): string => 'disabled'])];

        self::assertFalse($this->manager($menu, self::SUPERADMIN)->canAccessPath('/reports'));
    }

    public function testAStateClosureCanSeeTheActor(): void
    {
        $menu = [
            'reports' => $this->item([
                'state' => static fn(array $menu, MenuManager $manager, array $actor): string
                    => ((int) ($actor['role_id'] ?? 0) === 3 ? 'release' : 'disabled'),
            ]),
        ];

        self::assertTrue($this->manager($menu, self::STAFF)->canAccessPath('/reports'));
        self::assertFalse($this->manager($menu, self::GUEST)->canAccessPath('/reports'));
    }
}
