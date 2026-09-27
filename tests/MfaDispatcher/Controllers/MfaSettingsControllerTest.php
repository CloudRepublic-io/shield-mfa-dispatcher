<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use MfaDispatcher\Controllers\MfaSettingsController;
use MfaDispatcher\Libraries\MfaPreference;
use Tests\MfaDispatcher\Support\FakeAction;
use Tests\MfaDispatcher\Support\FakeActionTwo;
use Tests\MfaDispatcher\Support\FakeCustomMethodStore;
use Tests\MfaDispatcher\Support\FakeMethodLabelResolver;

// Loaded explicitly rather than autoloaded: a typical CodeIgniter app's
// composer.json only maps Tests\Support\ (to tests/_support), so
// Tests\MfaDispatcher\Support\* isn't autoloadable, and PHPUnit only
// loads *Test.php files itself.
require_once __DIR__ . '/../Support/FakeAction.php';
require_once __DIR__ . '/../Support/FakeActionTwo.php';
require_once __DIR__ . '/../Support/FakeCustomMethodStore.php';
require_once __DIR__ . '/../Support/FakeMethodLabelResolver.php';

/**
 * Tests MfaSettingsController by calling its methods directly, via
 * initController() (as CodeIgniter's own Controller lifecycle would),
 * rather than through a full HTTP round-trip - consistent with the
 * approach used throughout this series of packages, given the
 * unresolved issue documented in shield-totp-mfa's own tests where
 * Shield's registered routes returned PageNotFoundException through
 * FeatureTestTrait for reasons never fully root-caused.
 *
 * Unlike MfaDispatcherTest (which uses a real Session::attempt() to
 * simulate a *pending* login), this uses actingAs() - this controller
 * is for an already-fully-logged-in user managing their own settings,
 * not someone mid-login, so auth()->user() (what actingAs() sets up)
 * is the correct state here, not getPendingUser().
 *
 * Deliberately doesn't exercise the TOTP-specific methods
 * (totpEnroll/totpConfirm/totpDisable) - those depend on the
 * shield-totp-mfa package being installed, and are covered by that
 * package's own test suite via the shared TotpIdentityStore instead.
 * This file only tests the method-switching behavior that's this
 * controller's own responsibility.
 */
final class MfaSettingsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AuthenticationTesting;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables - it only looks in that one
    // namespace. null triggers the same behavior as
    // `php spark migrate --all`.
    protected $namespace = null;

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

        // Defensive: this controller's own views use url_to(), which
        // needs a populated route collection - a call to resetServices()
        // anywhere earlier in the same PHPUnit process (this package's
        // own MfaDispatcherTest calls it) wipes it. loadRoutes() is
        // safe to call even if routes are already loaded. See
        // shield-totp-mfa's RequireFreshTotpTest for the identical fix
        // applied for the identical reason.
        Services::routes()->loadRoutes();

        FakeAction::reset();

        $config                = config('MfaDispatcher');
        $config->methods       = [
            'fake'  => FakeAction::class,
            'fake2' => FakeActionTwo::class,
        ];
        $config->defaultMethod = 'fake';

        // 'fake' and 'fake2' are custom method keys, and choose() refuses
        // a custom method the user hasn't enrolled in (the fix for the
        // "choosing an unenrolled custom method silently skipped MFA"
        // bug). These two stand in for methods that need no setup, so
        // they're registered as always enrolled. The tests for that
        // refusal replace this with their own checker.
        $config->customEnrollmentCheckers = [
            'fake'  => [self::class, 'alwaysEnrolled'],
            'fake2' => [self::class, 'alwaysEnrolled'],
        ];
    }

    public static function alwaysEnrolled(User $user): bool
    {
        return true;
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'dispatcher-settings-test-' . uniqid() . '@example.com',
            'username' => 'dstest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    private function makeController(array $post = []): MfaSettingsController
    {
        $_POST = $post;

        /** @var IncomingRequest $request */
        $request = service('request', null, false);
        // CodeIgniter 4.7+ reads POST from a shared 'superglobals' snapshot
        // taken the first time anything touches the request, so the
        // $_POST assignment above is invisible to it - setGlobal() works
        // on 4.6 and 4.7 alike.
        $request->setGlobal('post', $post);

        $controller = new MfaSettingsController();
        $controller->initController($request, service('response'), service('logger'));

        return $controller;
    }

    public function testIndexRendersSuccessfully(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $body = $this->makeController()->index();

        $this->assertIsString($body);
        $this->assertNotSame('', $body);
    }

    public function testChooseSwitchesToAConfiguredMethod(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $preference = new MfaPreference();

        $this->makeController(['method' => 'fake2'])->choose();

        $this->assertSame('fake2', $preference->get($user));
    }

    public function testChooseRejectsAnUnconfiguredMethod(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $preference = new MfaPreference();

        $this->makeController(['method' => 'not-a-real-method'])->choose();

        $this->assertNull($preference->get($user));
    }

    // -------------------------------------------------------------------
    // Required-method awareness - a real, confirmed source of
    // confusion this addresses: a user whose group requires a specific
    // method has their own preference overridden entirely at login, so
    // "choosing" a different method here would silently never actually
    // be used. index() surfaces the required method to the view (banner
    // + disabled options); choose() also refuses server-side, since the
    // view-level disabling is a UX courtesy, not a security boundary.
    // -------------------------------------------------------------------

    public function testChooseRejectsAMethodOverriddenByARequiredGroup(): void
    {
        $user = $this->makeUser();
        $user->addGroup('superadmin');
        $this->actingAs($user);

        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['superadmin' => 'fake']);

        $preference = new MfaPreference();
        $preference->set($user, 'fake'); // starting preference, unrelated to the choice being rejected below

        $this->makeController(['method' => 'fake2'])->choose();

        // Unchanged - the rejected choice never got applied.
        $this->assertSame('fake', $preference->get($user));
    }

    public function testChoosingTheRequiredMethodItselfStillWorks(): void
    {
        $user = $this->makeUser();
        $user->addGroup('superadmin');
        $this->actingAs($user);

        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['superadmin' => 'fake2']);

        $preference = new MfaPreference();

        $this->makeController(['method' => 'fake2'])->choose();

        $this->assertSame('fake2', $preference->get($user));
    }

    public function testIndexStillRendersSuccessfullyForAUserWithARequiredMethod(): void
    {
        $user = $this->makeUser();
        $user->addGroup('superadmin');
        $this->actingAs($user);

        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['superadmin' => 'fake']);

        $body = $this->makeController()->index();

        $this->assertIsString($body);
        $this->assertNotSame('', $body);
    }

    /**
     * THE regression test for a real, confirmed UX inconsistency: the
     * "Active" badge reflects the user's own STORED preference, which
     * never actually gets used at all once a required method applies -
     * showing it anywhere in that situation is factually misleading,
     * not just redundant with the "Required" badge. Deliberately sets
     * a preference DIFFERENT from the required method, so this can't
     * pass by accident (if both badges happened to land on the same
     * row, a test not checking for "Active" specifically wouldn't
     * catch a regression here).
     */
    public function testActiveBadgeIsSuppressedWhenAMethodIsRequired(): void
    {
        $user = $this->makeUser();
        $user->addGroup('superadmin');
        $this->actingAs($user);

        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['superadmin' => 'fake']);

        $preference = new MfaPreference();
        $preference->set($user, 'fake2'); // deliberately NOT the required method

        $body = $this->makeController()->index();

        $this->assertStringNotContainsString('>Active<', $body);
        $this->assertStringContainsString(lang('MfaDispatcher.requiredMethodBadge'), $body);
    }

    /**
     * THE regression test for a real, confirmed follow-up
     * inconsistency: the required method's own "Use this method" button
     * previously stayed clickable whenever it wasn't already the
     * user's stored preference - misleading, since clicking it doesn't
     * change what's actually enforced at login either way (the
     * required method already wins over any stored preference
     * regardless). Deliberately sets the preference to something
     * OTHER than the required method, so the button in question is
     * actually reachable in this test at all.
     */
    public function testTheRequiredMethodsOwnButtonIsDisabledWhenItIsntTheStoredPreference(): void
    {
        $user = $this->makeUser();
        $user->addGroup('superadmin');
        $this->actingAs($user);

        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['superadmin' => 'fake']);

        $preference = new MfaPreference();
        $preference->set($user, 'fake2'); // deliberately NOT the required method

        $body = $this->makeController()->index();

        $this->assertStringContainsString(lang('MfaDispatcher.requiredMethodAlreadyInEffectNote'), $body);
    }

    /**
     * THE regression test for a real, confirmed follow-up: the note
     * above says the required method is "your effective verification
     * method, regardless of your own preference below" - which is only
     * spatially accurate if the required method's own row genuinely
     * does render above the others. Config\MfaDispatcher::$methods in
     * this test's own setUp() lists 'fake' BEFORE 'fake2' - this test
     * deliberately requires 'fake2' (the SECOND-configured one)
     * specifically so a naive "just use config array order" rendering
     * would fail this test, while the actual reordering in index()
     * passes it.
     */
    public function testTheRequiredMethodIsMovedToTheFrontOfTheListEvenIfConfiguredSecond(): void
    {
        $user = $this->makeUser();
        $user->addGroup('superadmin');
        $this->actingAs($user);

        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['superadmin' => 'fake2']);

        $body = $this->makeController()->index();

        $positionOfFake2 = strpos($body, 'Fake2');
        $positionOfFake  = strpos($body, '>Fake<'); // narrowed so "Fake2" itself doesn't also match a plain "Fake" search

        $this->assertNotFalse($positionOfFake2);
        $this->assertNotFalse($positionOfFake);
        $this->assertLessThan($positionOfFake, $positionOfFake2);
    }

    // -------------------------------------------------------------------
    // Config\MfaDispatcher::$methodLabelResolvers - lets a method's own
    // label reflect something that can change at runtime (e.g.
    // shield-whatsapp-mfa's WhatsApp/SMS channel toggle), without this
    // package needing to know anything about what that method actually
    // is. See that config property's own doc comment for the full
    // account.
    // -------------------------------------------------------------------

    protected function tearDown(): void
    {
        parent::tearDown();

        FakeMethodLabelResolver::reset();
        FakeCustomMethodStore::reset();
        config('MfaDispatcher')->methodLabelResolvers = [];
    }

    public function testARegisteredResolverIsUsedInsteadOfTheStaticLabel(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        config('MfaDispatcher')->methodLabelResolvers = [
            'fake' => [FakeMethodLabelResolver::class, 'current'],
        ];

        $body = $this->makeController()->index();

        $this->assertStringContainsString('Fake Dynamic Label', $body);
    }

    /**
     * THE regression test for the actual point of this feature: the
     * resolver's OWN return value at render time is used, not whatever
     * it happened to return earlier - confirming this genuinely
     * reflects something that can change at runtime, the way
     * shield-whatsapp-mfa's own $channel toggle does.
     */
    public function testTheResolversCurrentValueIsUsedAtRenderTime(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        config('MfaDispatcher')->methodLabelResolvers = [
            'fake' => [FakeMethodLabelResolver::class, 'current'],
        ];

        FakeMethodLabelResolver::$label = 'Updated Label';

        $body = $this->makeController()->index();

        $this->assertStringContainsString('Updated Label', $body);
        $this->assertStringNotContainsString('Fake Dynamic Label', $body);
    }

    public function testAMethodWithNoRegisteredResolverStillUsesTheStaticLabel(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        config('MfaDispatcher')->methodLabelResolvers = [
            'fake' => [FakeMethodLabelResolver::class, 'current'],
        ];

        $body = $this->makeController()->index();

        // 'fake2' has no registered resolver - still falls back to the
        // static ucfirst($key) label exactly as before this feature
        // existed (no lang() entry exists for 'fake2' in this test
        // environment).
        $this->assertStringContainsString('Fake2', $body);
    }

    /**
     * THE regression test for a real, confirmed follow-up: a resolved
     * channel label was being used for the 'whatsapp' method's own
     * display label, but its "Remove {channel} number" disable button
     * and confirmation text were still hardcoded to say "WhatsApp"
     * regardless - a separate string from the method label itself,
     * missed by that first fix. Deliberately configures 'whatsapp' as
     * a real method key here (pointing at a fake action, since the
     * real shield-whatsapp-mfa package isn't installed in this test
     * environment) specifically to exercise this exact code path.
     */
    public function testTheWhatsAppDisableButtonAndConfirmTextReflectTheResolvedChannelLabel(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $config          = config('MfaDispatcher');
        $config->methods = [
            'fake'     => FakeAction::class,
            'whatsapp' => FakeActionTwo::class,
        ];
        $config->methodLabelResolvers = [
            'whatsapp' => [FakeMethodLabelResolver::class, 'current'],
        ];
        FakeMethodLabelResolver::$label = 'SMS';

        (new MfaPreference())->set($user, 'whatsapp');

        $body = $this->makeController()->index();

        $this->assertStringContainsString('Remove SMS number', $body);
        $this->assertStringNotContainsString('Remove WhatsApp number', $body);
        // The confirm() text sits inside a JavaScript string in an
        // onsubmit attribute, so the view escapes it for JS (spaces
        // become \x20) - compare against the same escaping.
        $this->assertStringContainsString(esc('Remove your verified SMS number?', 'js'), $body);
    }

    // -------------------------------------------------------------------
    // choose()'s generic enrollment check for custom methods - see
    // Config\MfaDispatcher::$customEnrollmentRoutes's own doc comment
    // for the full account of the real bug this fixes: choosing a
    // custom method the user had never enrolled in previously set it
    // as their stored preference anyway, with nothing checking
    // $customEnrollmentCheckers first - silently bypassing MFA
    // entirely at their next login, since Shield would have nothing
    // pending to find for a method with no matching identity at all.
    // -------------------------------------------------------------------

    public function testChooseRefusesACustomMethodTheUserHasNotEnrolledIn(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $config          = config('MfaDispatcher');
        $config->methods = [
            'fake'       => FakeAction::class,
            'secretword' => FakeActionTwo::class,
        ];
        $config->customEnrollmentCheckers = [
            'secretword' => [FakeCustomMethodStore::class, 'checkEnrollment'],
        ];
        // Deliberately NOT marking this user as enrolled in
        // FakeCustomMethodStore - this is the "never set it up" case.

        $preference = new MfaPreference();

        $this->makeController(['method' => 'secretword'])->choose();

        $this->assertNull($preference->get($user));
    }

    public function testChooseRedirectsToTheConfiguredEnrollmentRouteWhenNotEnrolled(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $config          = config('MfaDispatcher');
        $config->methods = [
            'fake'       => FakeAction::class,
            'secretword' => FakeActionTwo::class,
        ];
        $config->customEnrollmentCheckers = [
            'secretword' => [FakeCustomMethodStore::class, 'checkEnrollment'],
        ];
        $config->customEnrollmentRoutes = [
            'secretword' => 'login', // any real, existing route name for this test's purposes
        ];

        $response = $this->makeController(['method' => 'secretword'])->choose();

        $this->assertStringContainsString('login', $response->getHeaderLine('Location'));
    }

    public function testChooseAllowsACustomMethodOnceEnrolled(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $config          = config('MfaDispatcher');
        $config->methods = [
            'fake'       => FakeAction::class,
            'secretword' => FakeActionTwo::class,
        ];
        $config->customEnrollmentCheckers = [
            'secretword' => [FakeCustomMethodStore::class, 'checkEnrollment'],
        ];
        FakeCustomMethodStore::$enrolledUserIds[$user->id] = true;

        $preference = new MfaPreference();

        $this->makeController(['method' => 'secretword'])->choose();

        $this->assertSame('secretword', $preference->get($user));
    }

    /**
     * THE regression test confirming 'email' is deliberately exempt
     * from this new check - MethodEnrollmentChecker::isEnrolled()
     * already treats it as always enrolled, so this must keep working
     * exactly as before, with no customEnrollmentCheckers entry needed
     * for it at all.
     */
    public function testChooseStillAllowsEmailWithNoCustomEnrollmentCheckerRegistered(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $config          = config('MfaDispatcher');
        $config->methods = [
            'email' => \CodeIgniter\Shield\Authentication\Actions\Email2FA::class,
            'fake'  => FakeAction::class,
        ];

        $preference = new MfaPreference();

        $this->makeController(['method' => 'email'])->choose();

        $this->assertSame('email', $preference->get($user));
    }
}
