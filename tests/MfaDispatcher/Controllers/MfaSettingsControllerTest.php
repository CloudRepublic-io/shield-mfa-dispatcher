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
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'dispatcher-settings-test-' . uniqid() . '@example.com',
            'username' => 'dispatchersettingstest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    private function makeController(array $post = []): MfaSettingsController
    {
        $_POST = $post;

        /** @var IncomingRequest $request */
        $request = service('request', null, false);

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
}
