<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Copy this file to app/Config/MfaDispatcher.php in the host application.
 *
 * This is the single place you register every MFA method your app
 * offers. Add or remove entries here at any time - no code changes
 * needed elsewhere, and nothing about $actions in Auth.php ever
 * changes again once the dispatcher is registered there.
 */
class MfaDispatcher extends BaseConfig
{
    /**
     * method key => fully-qualified class implementing
     * CodeIgniter\Shield\Authentication\Actions\ActionInterface.
     *
     * The key is what gets stored as each user's preference and is
     * what you reference from your own UI ("Use email" / "Use
     * WhatsApp" / "Use an authenticator app").
     *
     * @var array<string, class-string>
     */
    public array $methods = [
        'email' => \CodeIgniter\Shield\Authentication\Actions\Email2FA::class,

        // Uncomment once the matching package is installed:
        // 'whatsapp' => \WhatsAppMfa\Authentication\Actions\WhatsAppMfa::class,
        // 'totp'     => \TotpMfa\Authentication\Actions\TotpMfa::class,
    ];

    /**
     * Used for any user who hasn't chosen a method yet (e.g. existing
     * users at the moment you roll this out). Must be a key from
     * $methods above.
     */
    public string $defaultMethod = 'email';

    /**
     * If false, a user whose resolved method key isn't in $methods
     * (including one with no preference at all when $defaultMethod
     * itself is blank) skips MFA entirely rather than falling back to
     * $defaultMethod. Leave true for "MFA is mandatory for everyone".
     */
    public bool $required = true;

    // -- Role-based requirements -----------------------------------------------

    /**
     * Read via setting('MfaDispatcher.enabledForGroups') everywhere in
     * this package's own code, NEVER via $this->config->enabledForGroups
     * directly - that's what makes this editable at runtime through
     * CodeIgniter's own Settings library
     * (https://settings.codeigniter.com/), the same library Shield
     * itself uses for its permissions matrix, without touching this
     * file or redeploying:
     *
     *   service('settings')->set('MfaDispatcher.enabledForGroups', ['admin']);
     *
     * Groups (checked via $user->inGroup(), Shield's own
     * Config\AuthGroups group keys) that switch MFA on for their
     * members even when $required above is false. A user not in any
     * of these, when $required is false, skips MFA entirely - the same
     * "not enabled at all" outcome as before this property existed.
     * Leave empty and rely on $required if you want MFA on (or off)
     * uniformly regardless of group.
     *
     * @var string[]
     */
    public array $enabledForGroups = [];

    /**
     * Also read via setting('MfaDispatcher.requiredMethodsForGroups'),
     * for the same runtime-editable reason as $enabledForGroups above.
     *
     * group key => method key (from $methods above). A user in one of
     * these groups is REQUIRED to use that specific method - their own
     * stored preference is overridden entirely, not just defaulted -
     * and if they haven't set that method up yet, they're routed into
     * its enrollment flow before login can complete, rather than
     * falling back to email or any other method. See this package's
     * README for the full explanation of why "enabled" and "required"
     * are different things, and why the fallback for a not-yet-required
     * group is deliberately email (needs no setup) rather than forcing
     * setup universally.
     *
     * A user who somehow belongs to more than one group listed here
     * gets whichever entry appears FIRST in this array - list your
     * strictest role first if that's possible in your app (e.g.
     * 'superadmin' before 'admin').
     *
     * @var array<string, string>
     */
    public array $requiredMethodsForGroups = [];

    /**
     * method key => fully-qualified class implementing
     * CodeIgniter\Shield\Authentication\Actions\ActionInterface,
     * specifically the REGISTRATION-time activator for that method
     * (TotpActivator, WhatsAppActivator, PasskeyActivator - NOT the
     * same classes listed in $methods above, which are the LOGIN-time
     * verification actions). Only consulted for a method that appears
     * as a value in $requiredMethodsForGroups; used to route a user
     * who hasn't set up their required method yet into that method's
     * own enrollment flow, inline, before their login can complete.
     *
     * @var array<string, class-string>
     */
    public array $activatorClasses = [
        // Uncomment once the matching package is installed:
        // 'whatsapp' => \WhatsAppMfa\Authentication\Actions\WhatsAppActivator::class,
        // 'totp'     => \TotpMfa\Authentication\Actions\TotpActivator::class,
        // 'passkey'  => \PasskeyMfa\Authentication\Actions\PasskeyActivator::class,
    ];

    // -- Step-up auth for sensitive pages (RequireFreshMfa filter) ----------

    /**
     * Read via setting('MfaDispatcher.stepUpMethod'), not
     * $this->config->stepUpMethod directly - same runtime-editable
     * reasoning as $enabledForGroups.
     *
     *   - null (default): RequireFreshMfa challenges with whichever
     *     method the user's own LOGIN resolves to
     *     (MfaMethodResolver::resolveLoginMethod()) - step-up "matches"
     *     however they actually log in, without needing to keep the
     *     two in sync by hand.
     *   - a method key (e.g. 'totp'): ALWAYS use this one specific
     *     method for step-up, regardless of a given user's own login
     *     method - for a policy like "billing changes always require
     *     an authenticator app, no exceptions". Only takes effect if
     *     that method's package is actually installed
     *     (MethodEnrollmentChecker::isAvailable()) - falls back to the
     *     default (per-user login-method) behavior otherwise, rather
     *     than failing outright.
     */
    public ?string $stepUpMethod = null;

    /**
     * method key => fully-qualified class implementing
     * CodeIgniter\Filters\FilterInterface - specifically each
     * package's OWN step-up filter (RequireFreshTotp,
     * RequireFreshWhatsApp, RequireFreshPasskey), NOT the login-time
     * actions listed in $methods or the activators in
     * $activatorClasses. RequireFreshMfa is a thin router: once a
     * method key is resolved, the actual freshness check, challenge
     * page, and session-stamping are entirely delegated to whichever
     * of these matches - no new challenge UI lives in this package.
     *
     * A resolved method with no entry here (including 'email' -
     * nothing in this whole series builds a step-up filter for
     * Shield's own built-in Email2FA) means RequireFreshMfa lets the
     * request through, the same "nothing to challenge them with"
     * policy default used elsewhere in this series.
     *
     * @var array<string, class-string>
     */
    public array $stepUpFilterClasses = [
        // Uncomment once the matching package is installed:
        // 'totp'     => \TotpMfa\Filters\RequireFreshTotp::class,
        // 'whatsapp' => \WhatsAppMfa\Filters\RequireFreshWhatsApp::class,
        // 'passkey'  => \PasskeyMfa\Filters\RequireFreshPasskey::class,
    ];

    // -- Views ----------------------------------------------------------------

    /**
     * View paths used by this package's settings page, keyed by a
     * logical name - the same pattern Shield itself uses for
     * Config\Auth::$views. Override any of these in your own copy of
     * this file to point at your own view files instead. Whatever you
     * substitute in must accept the same variables the default
     * expects - check the corresponding file under src/Views/ for
     * exactly what's passed.
     */
    public array $views = [
        'mfa_settings_index'                => 'MfaDispatcher\Views\mfa_settings_index',
        'mfa_settings_totp_enroll'          => 'MfaDispatcher\Views\mfa_settings_totp_enroll',
        'mfa_settings_totp_unavailable'     => 'MfaDispatcher\Views\mfa_settings_totp_unavailable',
        'mfa_settings_whatsapp_enroll'      => 'MfaDispatcher\Views\mfa_settings_whatsapp_enroll',
        'mfa_settings_whatsapp_verify'      => 'MfaDispatcher\Views\mfa_settings_whatsapp_verify',
        'mfa_settings_whatsapp_unavailable' => 'MfaDispatcher\Views\mfa_settings_whatsapp_unavailable',
    ];
}
