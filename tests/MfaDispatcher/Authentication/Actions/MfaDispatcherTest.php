<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Authentication\Actions;

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use MfaDispatcher\Authentication\Actions\MfaDispatcher;
use MfaDispatcher\Libraries\MfaPreference;
use Tests\MfaDispatcher\Support\FakeAction;
use Tests\MfaDispatcher\Support\FakeActivator;
use Tests\MfaDispatcher\Support\FakeActionTwo;

// Loaded explicitly rather than autoloaded: a typical CodeIgniter app's
// composer.json only maps Tests\Support\ (to tests/_support), so
// Tests\MfaDispatcher\Support\* isn't autoloadable, and PHPUnit only
// loads *Test.php files itself.
require_once __DIR__ . '/../../Support/FakeAction.php';
require_once __DIR__ . '/../../Support/FakeActionTwo.php';
require_once __DIR__ . '/../../Support/FakeActivator.php';

/**
 * Tests MfaDispatcher's own delegation logic in isolation, using two
 * fake ActionInterface implementations (Support/FakeAction.php and
 * FakeActionTwo.php) rather than depending on Email2FA, WhatsAppMfa,
 * or TotpMfa actually being installed - this package's job is picking
 * the right method, not implementing one.
 *
 * Uses a real Session::attempt() call with real credentials to put the
 * authenticator into a genuinely pending state, rather than actingAs()
 * (fully logged in - a different state from what getPendingUser()
 * checks for) - the same approach worked out, through a lot of trial
 * and error, in the shield-totp-mfa package's own test suite.
 *
 * setUp() forces Config\Auth::$actions['login'] to MfaDispatcher::class
 * directly, rather than relying on the test-running app's own
 * app/Config/Auth.php already having this set (an earlier version of
 * this file only documented it as a prerequisite, in a comment, rather
 * than actually guaranteeing it) - this is what makes attemptLogin()
 * genuinely exercise Shield's own setAuthAction(), which calls
 * $action->getType() as part of deciding whether a login is pending at
 * all. A real, confirmed bug in getType() (calling getPendingUser(),
 * which is unconditionally null at that exact point - see
 * MfaDispatcher::getType()'s own doc comment for the full explanation)
 * went undetected through this whole package's development specifically
 * because this wasn't previously guaranteed - tearDown() restores the
 * original value, since config objects are shared across a whole
 * PHPUnit run and leaving this mutated could affect unrelated test
 * files.
 *
 * ALSO: setUp() calls resetServices() and session()->destroy() before
 * anything else, and Services::routes()->loadRoutes() afterward - a
 * separate, confirmed issue found while debugging shield-totp-mfa's
 * equivalent tests against its identical architecture (see that
 * package's README and TotpMfaTest/TotpActivatorTest class doc
 * comments for the full, diagnostic-backed account, not repeated here
 * in full): DatabaseTestTrait resets the database between test methods
 * but not the session or CI4's own cached service instances, and a
 * leftover authenticator/session from whichever test ran immediately
 * before this one can make attemptLogin() collide with Shield's own
 * "already logged in or in pending login state" guard.
 * resetServices() itself wipes the route collection as a side effect
 * (confirmed via CodeIgniter's own docs), which loadRoutes() undoes.
 *
 * Deliberately never calls show() when no action is expected to
 * resolve - MfaDispatcher::show() calls exit directly in that case
 * (see its own doc comment for why), which would kill the test
 * runner. getType()/createIdentity() exercise the same "nothing to
 * delegate to" path safely, since they just return ''.
 */
final class MfaDispatcherTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables - it only looks in that one
    // namespace. null triggers the same behavior as
    // `php spark migrate --all`, picking up every registered
    // namespace's migrations.
    protected $namespace = null;

    private const PASSWORD = 'secret123456';

    private $originalLoginAction;

    protected function setUp(): void
    {
        parent::setUp();

        // The Settings library's DatabaseHandler caches every value it has
        // read in memory on the shared 'settings' service. $refresh resets
        // the database between tests, but not that cache - and user ids
        // restart at 1 after each refresh, so a preference saved for
        // "user:1" in one test was still returned for a brand-new user:1
        // in the next (seen as 'fake2' leaking into tests that set nothing).
        // A fresh service per test reads the freshly-reset database.
        \CodeIgniter\Config\Services::resetSingle('settings');

        $this->resetServices();
        session()->destroy();
        Services::routes()->loadRoutes();

        FakeAction::reset();

        $config                = config('MfaDispatcher');
        $config->methods       = ['fake' => FakeAction::class];
        $config->defaultMethod = 'fake';
        $config->required      = true;

        $authConfig                   = config('Auth');
        $this->originalLoginAction    = $authConfig->actions['login'] ?? null;
        $authConfig->actions['login'] = MfaDispatcher::class;
    }

    protected function tearDown(): void
    {
        config('Auth')->actions['login'] = $this->originalLoginAction;

        parent::tearDown();
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'dispatcher-test-' . uniqid() . '@example.com',
            'username' => 'dispatchertest' . uniqid(),
            'password' => self::PASSWORD,
        ]);
    }

    private function attemptLogin(User $user): void
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $result        = $authenticator->attempt([
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertTrue($result->isOK(), 'attempt() did not succeed with the test user\'s real credentials.');
    }

    public function testAttemptLoginDoesNotThrowCannotGetPendingUser(): void
    {
        // Regression test for a real, confirmed bug: attempt() itself
        // used to throw "MfaDispatcher: cannot get the pending login
        // user" for every real login, because Shield calls getType()
        // from inside setAuthAction() - before pending state is ever
        // set, not after. See MfaDispatcher::getType()'s own doc
        // comment for the full explanation. Every other test in this
        // file now implicitly covers this too, since setUp() forces
        // MfaDispatcher to be the real configured login action - this
        // one just names the specific regression directly.
        $user = $this->makeUser();

        // If the bug regresses, the RuntimeException is thrown from
        // inside Shield's own attempt() call (via setAuthAction()
        // calling getType()) - PHPUnit reports that as an error on
        // this line directly, before attemptLogin()'s own
        // assertTrue($result->isOK()) is ever reached.
        $this->attemptLogin($user);

        $this->assertSame('fake_action_type', (new MfaDispatcher())->getType());
    }

    public function testShowDelegatesToTheResolvedAction(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        $body = (new MfaDispatcher())->show();

        $this->assertSame('fake-show-body', $body);
        $this->assertContains('show', FakeAction::$calls);
    }

    public function testGetTypeDelegatesToTheResolvedAction(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        $type = (new MfaDispatcher())->getType();

        $this->assertSame('fake_action_type', $type);
    }

    // -------------------------------------------------------------------
    // ensureResponse() - regression coverage for a real, confirmed bug
    // where a delegated action's handle()/verify() returned a plain
    // string (Shield's own built-in Email2FA, for at least one real
    // user) rather than a Response. Tested via reflection directly,
    // rather than through a fake ActionInterface implementation that
    // deliberately returns a string: whether the real ActionInterface
    // strictly enforces `: Response` on handle()/verify() is itself
    // the exact thing in question here, so building a fake that
    // violates that contract risks its own "declaration incompatible"
    // fatal depending on the answer. Reflection sidesteps that
    // uncertainty and tests the actual fix logic directly.
    // -------------------------------------------------------------------

    public function testEnsureResponseWrapsAPlainStringIntoARealResponse(): void
    {
        $method = new \ReflectionMethod(MfaDispatcher::class, 'ensureResponse');
        $method->setAccessible(true);

        $result = $method->invoke(new MfaDispatcher(), 'a plain string, not a Response');

        $this->assertInstanceOf(\CodeIgniter\HTTP\Response::class, $result);
        $this->assertSame('a plain string, not a Response', $result->getBody());
    }

    public function testEnsureResponsePassesThroughARealResponseUnchanged(): void
    {
        $method = new \ReflectionMethod(MfaDispatcher::class, 'ensureResponse');
        $method->setAccessible(true);

        $realResponse = service('response')->setBody('already a response');

        $result = $method->invoke(new MfaDispatcher(), $realResponse);

        $this->assertSame($realResponse, $result);
    }

    public function testCreateIdentityDelegatesToTheResolvedAction(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);

        $secret = (new MfaDispatcher())->createIdentity($user);

        $this->assertSame('fake-secret', $secret);
        $this->assertContains('createIdentity:' . $user->id, FakeAction::$calls);
    }

    public function testUserPreferenceSelectsBetweenConfiguredMethods(): void
    {
        $config          = config('MfaDispatcher');
        $config->methods = [
            'fake'  => FakeAction::class,
            'fake2' => FakeActionTwo::class,
        ];
        $config->defaultMethod = 'fake';

        $user = $this->makeUser();
        $this->attemptLogin($user);

        $preference = new MfaPreference();
        $preference->set($user, 'fake2');

        // Proves the dispatcher actually picked the PREFERRED method,
        // not just *a* configured one - FakeActionTwo's getType() is
        // deliberately different from FakeAction's for exactly this
        // reason.
        $this->assertSame('fake_action_two_type', (new MfaDispatcher())->getType());
    }

    public function testFallsBackToDefaultWhenStoredPreferenceIsNoLongerConfigured(): void
    {
        $user = $this->makeUser();

        $preference = new MfaPreference();
        $preference->set($user, 'a-method-that-was-since-uninstalled');

        $this->attemptLogin($user);

        // 'fake' (the configured default) should be used instead of
        // the stale, no-longer-configured preference.
        $this->assertSame('fake_action_type', (new MfaDispatcher())->getType());
    }

    public function testSkipsEntirelyWhenNotRequiredAndNoUsableMethod(): void
    {
        $config                = config('MfaDispatcher');
        $config->required      = false;
        $config->defaultMethod = ''; // nothing valid to fall back to

        $user = $this->makeUser();
        $this->attemptLogin($user);

        // getType()/createIdentity() should both degrade to '' when
        // resolveAction() returns null - see this class's doc comment
        // for why show() itself is never exercised in this scenario.
        $this->assertSame('', (new MfaDispatcher())->getType());
        $this->assertSame('', (new MfaDispatcher())->createIdentity($user));
    }

    // -------------------------------------------------------------------
    // $enabledForGroups
    // -------------------------------------------------------------------

    public function testUserNotInAnyEnabledGroupSkipsWhenNotGloballyRequired(): void
    {
        config('MfaDispatcher')->required = false;
        service('settings')->set('MfaDispatcher.enabledForGroups', ['admin']);

        $user = $this->makeUser(); // not added to any group
        $this->attemptLogin($user);

        $this->assertSame('', (new MfaDispatcher())->getType());
    }

    public function testUserInAnEnabledGroupProceedsWhenNotGloballyRequired(): void
    {
        config('MfaDispatcher')->required = false;
        service('settings')->set('MfaDispatcher.enabledForGroups', ['admin']);

        $user = $this->makeUser();
        $user->addGroup('admin');
        $this->attemptLogin($user);

        // Falls through to the normal preference/default resolution -
        // 'fake' is still the configured default from setUp().
        $this->assertSame('fake_action_type', (new MfaDispatcher())->getType());
    }

    public function testEnabledForGroupsOverrideTakesEffectImmediatelyAtRuntime(): void
    {
        config('MfaDispatcher')->required = false;
        // Deliberately NOT setting enabledForGroups via service('settings')
        // here - config()'s own default ([]) should apply, proving the
        // read genuinely goes through setting() (DB override, falling
        // back to the config file's default), not just $this->config
        // directly.
        $user = $this->makeUser();
        $user->addGroup('admin');
        $this->attemptLogin($user);

        $this->assertSame('', (new MfaDispatcher())->getType(), 'No override yet - should behave as if $enabledForGroups were empty.');

        service('settings')->set('MfaDispatcher.enabledForGroups', ['admin']);

        $this->assertSame('fake_action_type', (new MfaDispatcher())->getType(), 'Override should take effect without touching the config object.');
    }

    // -------------------------------------------------------------------
    // $requiredMethodsForGroups / $activatorClasses
    // -------------------------------------------------------------------

    public function testRequiredMethodOverridesUserPreferenceAndRoutesToItsActivatorWhenUnenrolled(): void
    {
        $config                   = config('MfaDispatcher');
        $config->activatorClasses = ['fake' => FakeActivator::class];
        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['admin' => 'fake']);

        $user = $this->makeUser();
        $user->addGroup('admin');
        $this->attemptLogin($user);

        $preference = new MfaPreference();
        $preference->set($user, 'fake2'); // deliberately the OTHER configured method

        // MethodEnrollmentChecker doesn't recognize 'fake' as any real
        // package's method key, so it always reports "not enrolled" -
        // meaning this should route to the configured activator, not
        // FakeAction OR FakeActionTwo (the preferred one).
        $this->assertSame('fake_activator_type', (new MfaDispatcher())->getType());
    }

    public function testMultipleMatchingGroupsUsesWhicheverIsListedFirst(): void
    {
        $config                   = config('MfaDispatcher');
        $config->methods          = [
            'fake'  => FakeAction::class,
            'fake2' => FakeActionTwo::class,
        ];
        $config->activatorClasses = ['fake' => FakeActivator::class];
        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', [
            'superadmin' => 'fake2', // listed first - should win
            'admin'      => 'fake',
        ]);

        $user = $this->makeUser();
        $user->addGroup('admin');
        $user->addGroup('superadmin');

        // Expected from the login attempt itself: Shield calls the login
        // action's createIdentity() inside attempt(), which is where the
        // dispatcher first resolves the required method - so a missing
        // activator surfaces right at login, not later. The getType()
        // call below is kept as a backstop.
        $this->expectException(\CodeIgniter\Shield\Exceptions\RuntimeException::class);
        $this->expectExceptionMessage("'fake2'");

        $this->attemptLogin($user);

        // 'fake2' has no configured activator, 'fake' does. If
        // 'superadmin' (listed first) correctly wins, resolution
        // attempts 'fake2' and throws - the exception message naming
        // 'fake2' specifically is what proves ordering here, not just
        // that some exception occurred. If the bug were reversed
        // (second-listed 'admin'/'fake' used instead), no exception
        // would be thrown at all, since 'fake' does have an activator
        // configured - so this assertion fails correctly either way.
        (new MfaDispatcher())->getType();
    }

    public function testNoActivatorConfiguredForARequiredMethodThrowsAClearError(): void
    {
        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['admin' => 'fake']);
        // Deliberately leaving $activatorClasses empty.

        $user = $this->makeUser();
        $user->addGroup('admin');

        // Raised during the login attempt itself (attempt() calls the
        // login action's createIdentity(), where the required method is
        // first resolved) - see testMultipleMatchingGroupsUsesWhicheverIsListedFirst.
        $this->expectException(\CodeIgniter\Shield\Exceptions\RuntimeException::class);
        $this->expectExceptionMessage('activatorClasses');

        $this->attemptLogin($user);

        (new MfaDispatcher())->getType();
    }
}
