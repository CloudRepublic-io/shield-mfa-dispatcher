<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Libraries;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use MfaDispatcher\Libraries\MfaMethodResolver;
use MfaDispatcher\Libraries\MfaPreference;
use Tests\MfaDispatcher\Support\FakeAction;
use Tests\MfaDispatcher\Support\FakeActionTwo;

/**
 * Tests MfaMethodResolver directly, in isolation from MfaDispatcher
 * (the login Action) and RequireFreshMfa (the step-up filter) - both
 * of which depend on this class's behavior being correct, so it's
 * worth verifying on its own rather than only indirectly through
 * MfaDispatcherTest's existing coverage (which never exercises
 * resolveLoginMethod() specifically - MfaDispatcher itself only ever
 * calls isMfaEnabledFor()/requiredMethodFor() directly, not the
 * combined method).
 *
 * Uses fakes throughout - no real packages (TotpMfa etc.) needed,
 * since this is pure policy/config logic with no dependency on any
 * specific method's package actually being installed.
 */
final class MfaMethodResolverTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables - it only looks in that one
    // namespace. null triggers the same behavior as
    // `php spark migrate --all`.
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();

        $config                = config('MfaDispatcher');
        $config->methods       = [
            'fake'  => FakeAction::class,
            'fake2' => FakeActionTwo::class,
        ];
        $config->defaultMethod = 'fake';
        $config->required      = true;

        service('settings')->set('MfaDispatcher.enabledForGroups', []);
        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', []);
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'resolver-test-' . uniqid() . '@example.com',
            'username' => 'resolvertest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    private function preference(): MfaPreference
    {
        return new MfaPreference();
    }

    public function testIsMfaEnabledForIsTrueWhenGloballyRequired(): void
    {
        $user = $this->makeUser();

        $this->assertTrue((new MfaMethodResolver())->isMfaEnabledFor($user));
    }

    public function testIsMfaEnabledForIsFalseWhenNotRequiredAndNoGroupMatches(): void
    {
        config('MfaDispatcher')->required = false;
        $user                              = $this->makeUser();

        $this->assertFalse((new MfaMethodResolver())->isMfaEnabledFor($user));
    }

    public function testIsMfaEnabledForIsTrueForAnEnabledGroupEvenWhenNotGloballyRequired(): void
    {
        config('MfaDispatcher')->required = false;
        service('settings')->set('MfaDispatcher.enabledForGroups', ['admin']);

        $user = $this->makeUser();
        $user->addGroup('admin');

        $this->assertTrue((new MfaMethodResolver())->isMfaEnabledFor($user));
    }

    public function testRequiredMethodForReturnsNullWithNoMatchingGroup(): void
    {
        $user = $this->makeUser();

        $this->assertNull((new MfaMethodResolver())->requiredMethodFor($user));
    }

    public function testRequiredMethodForReturnsTheConfiguredMethodForAMatchingGroup(): void
    {
        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['admin' => 'fake']);

        $user = $this->makeUser();
        $user->addGroup('admin');

        $this->assertSame('fake', (new MfaMethodResolver())->requiredMethodFor($user));
    }

    public function testRequiredMethodForUsesWhicheverGroupIsListedFirst(): void
    {
        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', [
            'superadmin' => 'fake2',
            'admin'      => 'fake',
        ]);

        $user = $this->makeUser();
        $user->addGroup('admin');
        $user->addGroup('superadmin');

        $this->assertSame('fake2', (new MfaMethodResolver())->requiredMethodFor($user));
    }

    public function testResolveLoginMethodReturnsNullWhenMfaNotEnabled(): void
    {
        config('MfaDispatcher')->required = false;
        $user                              = $this->makeUser();

        $this->assertNull((new MfaMethodResolver())->resolveLoginMethod($user));
    }

    public function testResolveLoginMethodReturnsTheUsersOwnPreference(): void
    {
        $user = $this->makeUser();
        $this->preference()->set($user, 'fake2');

        $this->assertSame('fake2', (new MfaMethodResolver())->resolveLoginMethod($user));
    }

    public function testResolveLoginMethodFallsBackToTheDefaultWithNoPreference(): void
    {
        $user = $this->makeUser();

        $this->assertSame('fake', (new MfaMethodResolver())->resolveLoginMethod($user));
    }

    public function testResolveLoginMethodPrefersTheRequiredMethodOverTheUsersPreference(): void
    {
        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['admin' => 'fake']);

        $user = $this->makeUser();
        $user->addGroup('admin');
        $this->preference()->set($user, 'fake2');

        $this->assertSame('fake', (new MfaMethodResolver())->resolveLoginMethod($user));
    }
}
