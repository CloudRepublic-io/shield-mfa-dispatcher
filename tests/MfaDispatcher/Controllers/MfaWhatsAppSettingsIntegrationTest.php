<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Controllers;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Authentication\Actions\Email2FA;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use MfaDispatcher\Controllers\MfaSettingsController;
use MfaDispatcher\Libraries\MfaPreference;
use Tests\MfaDispatcher\Support\FakeWhatsAppSender;
use WhatsAppMfa\Authentication\Actions\WhatsAppMfa;
use WhatsAppMfa\Libraries\PhoneNumberStore;

/**
 * Tests this package's OWN WhatsApp integration -
 * whatsappEnroll()/whatsappSend()/whatsappVerify()/whatsappConfirm()/whatsappDisable(),
 * and choose()'s redirect-to-enroll behavior for an unverified number -
 * as distinct from shield-whatsapp-mfa's own PhoneNumberStoreTest and
 * WhatsAppSettingsControllerTest, which test PhoneNumberStore and that
 * package's OWN standalone settings controller directly. What's under
 * test here belongs to THIS package: how it wires PhoneNumberStore
 * into its own settings page, choose() flow, and preference
 * fallback-on-disable behavior.
 *
 * Uses the real WhatsAppMfa\Libraries\PhoneNumberStore, not a fake -
 * that class has no network dependency of its own to worry about
 * faking, only the sender does (handled by the local FakeWhatsAppSender
 * in Support/, not shield-whatsapp-mfa's own test-only copy - see that
 * class's doc comment for why).
 *
 * Requires shield-whatsapp-mfa to actually be installed; skipped
 * entirely otherwise (setUp() below), via the exact same class_exists()
 * check MfaSettingsController itself uses internally to decide whether
 * this integration is available at all - consistent with the
 * production code's own graceful-degradation philosophy.
 */
final class MfaWhatsAppSettingsIntegrationTest extends CIUnitTestCase
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

        if (! class_exists('\WhatsAppMfa\Libraries\PhoneNumberStore')) {
            $this->markTestSkipped('shield-whatsapp-mfa is not installed - this integration has nothing to test.');
        }

        // Defensive: this controller's own views use url_to(), which
        // needs a populated route collection - a call to resetServices()
        // anywhere earlier in the same PHPUnit process (this package's
        // own MfaDispatcherTest calls it) wipes it. loadRoutes() is
        // safe to call even if routes are already loaded. See
        // shield-totp-mfa's RequireFreshTotpTest for the identical fix
        // applied for the identical reason.
        Services::routes()->loadRoutes();

        config('WhatsAppMfa')->sender = FakeWhatsAppSender::class;
        FakeWhatsAppSender::reset();

        $config                 = config('MfaDispatcher');
        $config->methods        = [
            'email'    => Email2FA::class,
            'whatsapp' => WhatsAppMfa::class,
        ];
        $config->defaultMethod = 'email';
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'dispatcher-whatsapp-test-' . uniqid() . '@example.com',
            'username' => 'dispatcherwhatsapptest' . uniqid(),
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

    private function preference(): MfaPreference
    {
        return new MfaPreference();
    }

    public function testChooseRedirectsToEnrollWhenNoVerifiedNumber(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->makeController(['method' => 'whatsapp'])->choose();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertNull($this->preference()->get($user));
    }

    public function testWhatsappEnrollShowsThePhoneFormForAFreshUser(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $body = $this->makeController()->whatsappEnroll();

        $this->assertStringContainsString(lang('MfaDispatcher.whatsappPhoneLabel'), $body);
    }

    public function testWhatsappEnrollShowsUnavailableWhenNotConfiguredAsAMethod(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        config('MfaDispatcher')->methods = ['email' => Email2FA::class]; // no 'whatsapp' key

        $body = $this->makeController()->whatsappEnroll();

        $this->assertStringContainsString(lang('MfaDispatcher.whatsappNotInstalled'), $body);
    }

    public function testWhatsappSendDispatchesACodeViaTheConfiguredSender(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->whatsappSend();

        $this->assertSame(1, FakeWhatsAppSender::$sendCount);
        $this->assertSame('+15551234567', FakeWhatsAppSender::$lastPhoneNumber);
    }

    public function testWhatsappConfirmVerifiesAndSetsThePreference(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->whatsappSend();
        $code = FakeWhatsAppSender::$lastCode;

        $this->makeController(['code' => $code])->whatsappConfirm();

        $this->assertSame('whatsapp', $this->preference()->get($user));
    }

    public function testWhatsappConfirmWithWrongCodeDoesNotSetThePreference(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->whatsappSend();

        $this->makeController(['code' => '000000'])->whatsappConfirm();

        $this->assertNull($this->preference()->get($user));
    }

    public function testChooseSucceedsImmediatelyOnceAlreadyVerified(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->whatsappSend();
        $code = FakeWhatsAppSender::$lastCode;
        $this->makeController(['code' => $code])->whatsappConfirm();

        // Switch away, then confirm choosing it again doesn't demand
        // re-verification a second time.
        $this->preference()->set($user, 'email');

        $this->makeController(['method' => 'whatsapp'])->choose();

        $this->assertSame('whatsapp', $this->preference()->get($user));
    }

    public function testWhatsappDisableRemovesTheNumberAndFallsBackThePreference(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->whatsappSend();
        $code = FakeWhatsAppSender::$lastCode;
        $this->makeController(['code' => $code])->whatsappConfirm();

        $this->makeController()->whatsappDisable();

        $this->assertSame('email', $this->preference()->get($user));

        $store = new PhoneNumberStore();
        $this->assertFalse($store->hasVerifiedPhoneNumber($user));
    }

    public function testWhatsappDisableDoesNotChangePreferenceIfNotCurrentlySelected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->makeController(['phone' => '+15551234567'])->whatsappSend();
        $code = FakeWhatsAppSender::$lastCode;
        $this->makeController(['code' => $code])->whatsappConfirm();

        // Switch to something else before disabling.
        $this->preference()->set($user, 'email');

        $this->makeController()->whatsappDisable();

        $this->assertSame('email', $this->preference()->get($user));
    }
}
