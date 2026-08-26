<?php

declare(strict_types=1);

namespace MfaDispatcher\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Entities\User;
use Config\MfaDispatcher as MfaDispatcherConfig;
use MfaDispatcher\Libraries\MfaPreference;

/**
 * Lets an already-logged-in user see and change their MFA method.
 *
 * Switching to "email" takes effect immediately - it doesn't need
 * anything set up in advance. Switching to "totp" or "whatsapp" is
 * different: an authenticator app has to be linked, or a phone number
 * verified, first - which is why choosing either routes to a small
 * self-service setup flow below instead of just flipping the
 * preference.
 *
 * All TOTP enrollment/verification logic here is delegated to
 * TotpMfa\Libraries\TotpIdentityStore, and all WhatsApp phone
 * verification logic to WhatsAppMfa\Libraries\PhoneNumberStore - the
 * exact same classes those packages' own login actions and
 * self-service settings pages use. This ensures a secret or verified
 * number set up here is readable by the login action (and vice versa,
 * and by the standalone shield-totp-mfa/shield-whatsapp-mfa settings
 * pages too, if a user reaches those directly instead), without
 * duplicating the enrollment/verification logic in three places.
 */
class MfaSettingsController extends Controller
{
    protected MfaDispatcherConfig $config;
    protected MfaPreference $preference;

    public function __construct()
    {
        $this->config     = config('MfaDispatcher');
        $this->preference = new MfaPreference();
    }

    public function index(): string
    {
        $user = auth()->user();

        return view($this->config->views['mfa_settings_index'], [
            'methods'           => $this->config->methods,
            'current'           => $this->preference->get($user) ?? $this->config->defaultMethod,
            'totpAvailable'     => $this->totpLibraryAvailable(),
            'totpEnrolled'      => $this->totpLibraryAvailable() && $this->totpStore()->hasEnrolled($user),
            'whatsappAvailable' => $this->whatsappLibraryAvailable(),
            'whatsappVerified'  => $this->whatsappLibraryAvailable() && $this->whatsappStore()->hasVerifiedPhoneNumber($user),
        ]);
    }

    public function choose(): RedirectResponse
    {
        $user   = auth()->user();
        $method = (string) $this->request->getPost('method');

        if (! array_key_exists($method, $this->config->methods)) {
            return redirect()->back()->with('error', lang('MfaDispatcher.unknownMethod'));
        }

        if ($method === 'totp' && ! ($this->totpLibraryAvailable() && $this->totpStore()->hasEnrolled($user))) {
            return redirect()->route('mfa-settings-totp-enroll');
        }

        if ($method === 'whatsapp' && ! ($this->whatsappLibraryAvailable() && $this->whatsappStore()->hasVerifiedPhoneNumber($user))) {
            return redirect()->route('mfa-settings-whatsapp-enroll');
        }

        $this->preference->set($user, $method);

        return redirect()->route('mfa-settings')->with('message', lang('MfaDispatcher.updated'));
    }

    // -------------------------------------------------------------------
    // TOTP self-service enrollment (only relevant if the TotpMfa
    // package is installed alongside this one)
    // -------------------------------------------------------------------

    public function totpEnroll(): string
    {
        if (! $this->totpLibraryAvailable()) {
            return view($this->config->views['mfa_settings_totp_unavailable']);
        }

        $user = auth()->user();

        if ($this->totpStore()->hasEnrolled($user)) {
            return redirect()->route('mfa-settings')->with('message', lang('MfaDispatcher.totpAlreadyEnrolled'));
        }

        $enrollment = $this->totpStore()->beginEnrollment($user, $user->email ?? ('user-' . $user->id));

        return view($this->config->views['mfa_settings_totp_enroll'], [
            'provisioningUri' => $enrollment['provisioningUri'],
            'manualKey'       => $enrollment['manualKey'],
        ]);
    }

    public function totpConfirm(): RedirectResponse
    {
        if (! $this->totpLibraryAvailable()) {
            return redirect()->route('mfa-settings')->with('error', lang('MfaDispatcher.totpNotInstalled'));
        }

        $user = auth()->user();
        $code = trim((string) $this->request->getPost('code'));

        if ($code === '' || ! $this->totpStore()->confirmEnrollment($user, $code)) {
            return redirect()->back()->with('error', lang('MfaDispatcher.totpInvalidCode'));
        }

        $this->preference->set($user, 'totp');

        return redirect()->route('mfa-settings')->with('message', lang('MfaDispatcher.totpEnabled'));
    }

    public function totpDisable(): RedirectResponse
    {
        $user = auth()->user();

        if ($this->totpLibraryAvailable()) {
            // Also revokes every remembered device for this user -
            // see TotpIdentityStore::disable()'s doc comment for why
            // that has to happen alongside removing the secret itself.
            $this->totpStore()->disable($user);
        }

        $fallback = $this->config->defaultMethod;

        if (($this->preference->get($user) ?? '') === 'totp') {
            $this->preference->set($user, $fallback);
        }

        $response = redirect()->route('mfa-settings')->with(
            'message',
            str_replace('{method}', $fallback, lang('MfaDispatcher.totpDisabled'))
        );

        // Cleared on the same response object being returned, not a
        // separately-fetched service('response') - see the TotpMfa
        // package's README on why that distinction matters. The
        // database row is already gone regardless, so this is
        // tidiness for the current browser, not a security
        // requirement.
        if ($this->totpLibraryAvailable()) {
            $response->deleteCookie(config('TotpMfa')->rememberCookieName);
        }

        return $response;
    }

    // -------------------------------------------------------------------
    // WhatsApp self-service phone verification (only relevant if the
    // WhatsAppMfa package is installed alongside this one). Three
    // steps rather than TOTP's two, since there's no equivalent to
    // scanning a QR code - a phone number has to be entered before a
    // code can even be sent.
    // -------------------------------------------------------------------

    public function whatsappEnroll(): string
    {
        if (! $this->whatsappLibraryAvailable()) {
            return view($this->config->views['mfa_settings_whatsapp_unavailable']);
        }

        $user = auth()->user();

        if ($this->whatsappStore()->hasVerifiedPhoneNumber($user)) {
            return redirect()->route('mfa-settings')->with('message', lang('MfaDispatcher.whatsappAlreadyVerified'));
        }

        return view($this->config->views['mfa_settings_whatsapp_enroll']);
    }

    public function whatsappSend(): RedirectResponse
    {
        if (! $this->whatsappLibraryAvailable()) {
            return redirect()->route('mfa-settings')->with('error', lang('MfaDispatcher.whatsappNotInstalled'));
        }

        $user  = auth()->user();
        $phone = trim((string) $this->request->getPost('phone'));

        if (! $this->looksLikeAPhoneNumber($phone)) {
            return redirect()->back()->withInput()->with('error', lang('MfaDispatcher.whatsappInvalidPhoneNumber'));
        }

        $code = $this->whatsappStore()->beginVerification($user, $phone);

        // Reuses whichever sender WhatsAppMfa itself is configured
        // with, rather than this package having its own copy of that
        // wiring - see WhatsAppSettingsController (in shield-whatsapp-mfa)
        // for the same pattern.
        $whatsAppConfig = config('WhatsAppMfa');
        $senderClass    = $whatsAppConfig->sender;
        /** @var \WhatsAppMfa\Sender\WhatsAppSenderInterface $sender */
        $sender = new $senderClass();
        $sender->send($phone, $code, $whatsAppConfig);

        return redirect()->route('mfa-settings-whatsapp-verify');
    }

    public function whatsappVerify(): string|RedirectResponse
    {
        if (! $this->whatsappLibraryAvailable()) {
            return view($this->config->views['mfa_settings_whatsapp_unavailable']);
        }

        $user  = auth()->user();
        $phone = $this->whatsappStore()->getPendingPhoneNumber($user);

        if ($phone === null) {
            return redirect()->route('mfa-settings-whatsapp-enroll')
                ->with('error', lang('MfaDispatcher.whatsappNoPendingCode'));
        }

        return view($this->config->views['mfa_settings_whatsapp_verify'], [
            'phone_masked' => $this->maskPhone($phone),
        ]);
    }

    public function whatsappConfirm(): RedirectResponse
    {
        if (! $this->whatsappLibraryAvailable()) {
            return redirect()->route('mfa-settings')->with('error', lang('MfaDispatcher.whatsappNotInstalled'));
        }

        $user = auth()->user();
        $code = trim((string) $this->request->getPost('code'));

        if ($code === '' || ! $this->whatsappStore()->confirmVerification($user, $code)) {
            return redirect()->back()->with('error', lang('MfaDispatcher.whatsappInvalidCode'));
        }

        $this->preference->set($user, 'whatsapp');

        return redirect()->route('mfa-settings')->with('message', lang('MfaDispatcher.whatsappEnabled'));
    }

    public function whatsappDisable(): RedirectResponse
    {
        $user = auth()->user();

        if ($this->whatsappLibraryAvailable()) {
            $this->whatsappStore()->removeVerifiedPhoneNumber($user);
        }

        $fallback = $this->config->defaultMethod;

        if (($this->preference->get($user) ?? '') === 'whatsapp') {
            $this->preference->set($user, $fallback);
        }

        return redirect()->route('mfa-settings')->with(
            'message',
            str_replace('{method}', $fallback, lang('MfaDispatcher.whatsappDisabled'))
        );
    }

    // -------------------------------------------------------------------

    private function totpLibraryAvailable(): bool
    {
        return class_exists('\\TotpMfa\\Libraries\\TotpIdentityStore') && array_key_exists('totp', $this->config->methods);
    }

    private function totpStore(): \TotpMfa\Libraries\TotpIdentityStore
    {
        $class = '\\TotpMfa\\Libraries\\TotpIdentityStore';

        return new $class();
    }

    private function whatsappLibraryAvailable(): bool
    {
        return class_exists('\\WhatsAppMfa\\Libraries\\PhoneNumberStore') && array_key_exists('whatsapp', $this->config->methods);
    }

    private function whatsappStore(): \WhatsAppMfa\Libraries\PhoneNumberStore
    {
        $class = '\\WhatsAppMfa\\Libraries\\PhoneNumberStore';

        return new $class();
    }

    /**
     * Minimal sanity check, not full E.164 validation - just enough to
     * catch empty/obviously-wrong input before sending a real WhatsApp
     * message on it. Duplicated from WhatsAppSettingsController rather
     * than shared, deliberately - this package needs to keep working
     * (with this one path unavailable) even if shield-whatsapp-mfa
     * isn't installed at all, so it can't depend on a class that lives
     * only in that package for something this trivial.
     */
    private function looksLikeAPhoneNumber(string $phone): bool
    {
        return (bool) preg_match('/^\+?[0-9\s\-()]{7,20}$/', $phone);
    }

    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($phone, -4);
    }
}
