<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Filters;

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Authentication\Actions\Email2FA;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use MfaDispatcher\Filters\RequireFreshMfa;
use MfaDispatcher\Libraries\MfaPreference;
use WhatsAppMfa\Authentication\Actions\WhatsAppMfa;
use WhatsAppMfa\Filters\RequireFreshWhatsApp;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * Tests RequireFreshMfa's delegation logic - resolving a method (via
 * MfaMethodResolver, already tested directly in
 * MfaMethodResolverTest), then handing off to that method's OWN
 * step-up filter. Requires shield-whatsapp-mfa to actually be
 * installed to test the delegation meaningfully (there needs to be a
 * REAL step-up filter to delegate to); skipped gracefully otherwise,
 * via the same class_exists() pattern
 * MfaWhatsAppSettingsIntegrationTest already established for this
 * package's WhatsApp-specific tests.
 */
final class RequireFreshMfaTest extends CIUnitTestCase
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

        if (! class_exists('\WhatsAppMfa\Filters\RequireFreshWhatsApp')) {
            $this->markTestSkipped('shield-whatsapp-mfa is not installed - this delegation has nothing to test.');
        }

        // Defensive: RequireFreshWhatsApp's own before() calls
        // redirect()->route(), which needs a populated route
        // collection - a call to resetServices() anywhere earlier in
        // the same PHPUnit process (this package's own
        // MfaDispatcherTest calls it) wipes it. loadRoutes() is safe to
        // call even if routes are already loaded. See shield-totp-mfa's
        // RequireFreshTotpTest for the identical fix applied for the
        // identical reason.
        Services::routes()->loadRoutes();

        $config                       = config('MfaDispatcher');
        $config->methods              = [
            'email'    => Email2FA::class,
            'whatsapp' => WhatsAppMfa::class,
        ];
        $config->defaultMethod        = 'email';
        $config->required             = true;
        $config->stepUpFilterClasses  = ['whatsapp' => RequireFreshWhatsApp::class];
        $config->stepUpMethod         = null;

        service('settings')->set('MfaDispatcher.enabledForGroups', []);
        service('settings')->set('MfaDispatcher.requiredMethodsForGroups', []);
        service('settings')->set('MfaDispatcher.stepUpMethod', null);
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'require-fresh-mfa-test-' . uniqid() . '@example.com',
            'username' => 'requirefreshmfatest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    private function verifyWhatsAppPhone(User $user): void
    {
        $store = new PhoneNumberStore();
        $code  = $store->beginVerification($user, '+15551234567');
        $store->confirmVerification($user, $code);
    }

    public function testUnauthenticatedRequestIsIgnored(): void
    {
        $result = (new RequireFreshMfa())->before(service('request'));

        $this->assertNull($result);
    }

    public function testLetsThroughWhenMfaNotEnabledForTheUser(): void
    {
        config('MfaDispatcher')->required = false;

        $user = $this->makeUser();
        $this->actingAs($user);

        $result = (new RequireFreshMfa())->before(service('request'));

        $this->assertNull($result);
    }

    public function testDelegatesToTheResolvedMethodsOwnStepUpFilter(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyWhatsAppPhone($user);
        (new MfaPreference())->set($user, 'whatsapp');

        $result = (new RequireFreshMfa())->before(service('request'));

        // WhatsApp is verified but has no recent step-up session, so
        // RequireFreshWhatsApp's OWN logic (not anything specific to
        // this filter) should redirect - a non-null result here proves
        // delegation actually happened, not just that something
        // returned.
        $this->assertNotNull($result);
    }

    public function testLetsThroughOnceTheUnderlyingFilterConsidersItFresh(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyWhatsAppPhone($user);
        (new MfaPreference())->set($user, 'whatsapp');

        session()->set(config('WhatsAppMfa')->stepUpSessionKey, time());

        $result = (new RequireFreshMfa())->before(service('request'));

        $this->assertNull($result);
    }

    public function testLetsThroughWhenTheResolvedMethodHasNoStepUpFilterConfigured(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        // Default method is 'email', which has no entry in
        // $stepUpFilterClasses - nothing in this series builds a
        // step-up filter for Shield's own built-in Email2FA.

        $result = (new RequireFreshMfa())->before(service('request'));

        $this->assertNull($result);
    }

    public function testOverrideMethodTakesPrecedenceOverTheUsersOwnPreference(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $this->verifyWhatsAppPhone($user);
        // User's own preference stays at the default ('email'), but
        // the override should force 'whatsapp' regardless.
        service('settings')->set('MfaDispatcher.stepUpMethod', 'whatsapp');

        $result = (new RequireFreshMfa())->before(service('request'));

        // 'email' has no step-up filter at all, so if the override
        // were NOT taking effect, this would return null (pass
        // through) instead - a non-null result here specifically
        // proves 'whatsapp' (the override) was used.
        $this->assertNotNull($result);
    }

    public function testOverrideFallsBackToDefaultResolutionWhenNotInstalled(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        // Default method resolves to 'email' - no step-up filter
        // configured for it. 'nonexistent_method_xyz' is guaranteed to
        // never be "available" regardless of what's installed in
        // whatever environment runs this test - MethodEnrollmentChecker's
        // isAvailable() only recognizes the real package keys and
        // returns false for everything else via its default arm - so
        // this test isn't ambiguous the way using a real key like
        // 'totp' would be (which might genuinely be installed
        // alongside shield-whatsapp-mfa in a real dev environment).
        service('settings')->set('MfaDispatcher.stepUpMethod', 'nonexistent_method_xyz');

        $result = (new RequireFreshMfa())->before(service('request'));

        // Falls back to the default (login-method) resolution, which
        // is 'email' here - no step-up filter configured for it, so
        // the request passes through rather than erroring.
        $this->assertNull($result);
    }
}
