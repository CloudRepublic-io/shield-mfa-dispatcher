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

    /**
     * REQUIRED if you're adding your own custom method (beyond 'email',
     * 'totp', 'whatsapp', 'passkey') to $methods/$activatorClasses AND
     * also listing it as a value in $requiredMethodsForGroups above.
     *
     * CONFIRMED, REAL GAP THIS FIXES: MethodEnrollmentChecker (used by
     * resolveRequiredMethod() to decide whether a user in a
     * required-method group has actually set that method up yet, or
     * needs to be routed into forced setup) only knows how to check
     * enrollment for the four method keys this package and its
     * siblings already recognize natively - it has no way to know how
     * to check a method key it's never heard of. Without an entry
     * here, a custom method used in $requiredMethodsForGroups would
     * always resolve as "not enrolled", routing every affected user
     * into forced setup on every single login, forever, even
     * immediately after they've genuinely completed it - since nothing
     * would ever tell the checker to look again and find a different
     * answer.
     *
     * CORRECTION - an earlier version of this doc comment recommended
     * a Closure as the primary form, with an example showing one as
     * this array's own default value. That was wrong, and would fail
     * outright for anyone who actually used it: PHP has never allowed
     * a Closure (or any non-constant expression) as a class property's
     * default value, since property defaults are evaluated at compile
     * time, not per-instance at runtime - "Constant expression contains
     * invalid operations" is the fatal error this produces. A real
     * report confirmed this, and also confirmed that moving the
     * assignment into this config class's own __construct() - a
     * seemingly reasonable workaround - still didn't resolve the
     * problem reliably in practice.
     *
     * THE RECOMMENDED FORM: a [ClassName::class, 'staticMethodName']
     * pair, where that method IS genuinely static. Unlike a Closure,
     * this is just an array of two strings - a valid compile-time
     * constant, usable directly as this property's own default value
     * below, with no constructor workaround needed at all:
     *
     *   public array $customEnrollmentCheckers = [
     *       'yubikey' => [\App\Libraries\YubikeyIdentityStore::class, 'checkEnrollment'],
     *   ];
     *
     * Since hasEnrolled()-style methods on this series' own store
     * classes are instance methods, not static ones, add a small static
     * wrapper to your own store rather than trying to reference an
     * instance method directly:
     *
     *   class YubikeyIdentityStore
     *   {
     *       public static function checkEnrollment(User $user): bool
     *       {
     *           return (new self())->hasEnrolled($user);
     *       }
     *
     *       public function hasEnrolled(User $user): bool { ... }
     *   }
     *
     * A Closure or [$instance, 'methodName'] pair also still works
     * technically (MethodEnrollmentChecker::isEnrolled() calls whatever
     * is here as a plain callable, regardless of form) - but neither
     * can be a DEFAULT value for this property directly, only something
     * assigned to it later (e.g. from your own constructor, or
     * mutated at runtime) - and a Closure specifically carries the
     * added, unresolved uncertainty above. The static-method form is
     * recommended specifically because it works safely and directly as
     * this array's own default, with nothing else needed.
     *
     * Only consulted for method keys NOT already known natively -
     * registering a custom checker for 'totp'/'whatsapp'/'passkey'/
     * 'email' has no effect, since those already have a fixed, tested
     * answer.
     *
     * You do NOT need an entry here for a custom method you're only
     * ever using via the plain, non-required, preference-based flow
     * (a user choosing it themselves, with no group requiring it) -
     * this callable is consulted only by the required-method/forced-
     * setup path.
     *
     * @var array<string, callable(\CodeIgniter\Shield\Entities\User): bool>
     */
    public array $customEnrollmentCheckers = [
        // 'yubikey' => [\App\Libraries\YubikeyIdentityStore::class, 'checkEnrollment'],
    ];

    /**
     * OPTIONAL - lets a method's own display label on
     * MfaSettingsController's settings page reflect something that can
     * change at runtime, rather than always using the static
     * lang('MfaDispatcher.methodLabel_' . $key) string.
     *
     * WHY THIS EXISTS: shield-whatsapp-mfa can deliver its one 'whatsapp'
     * method via either WhatsApp or plain SMS
     * (Config\WhatsAppMfa::$channel), and every one of that package's OWN
     * views/messages already reflect whichever is currently configured
     * (via WhatsAppMfa\Libraries\ChannelLabel). Without this property,
     * this package's own settings page would be the one place still
     * hardcoded to say "WhatsApp code" regardless - a real,
     * user-visible inconsistency the moment $channel is switched to
     * 'sms'.
     *
     * Deliberately NOT hardcoded in this package itself: this package
     * has no idea WhatsApp or SMS even exist, by design (see "Adding
     * your own custom MFA method" above) - reaching into a specific
     * sibling package's own class by name here would break that
     * separation for every OTHER method too, including a developer's
     * own custom one. This property keeps the dispatcher completely
     * ignorant of what any given method actually is; it only knows
     * "a resolver is registered for this key, so ask it for the label
     * instead of using the static one."
     *
     * method key => any PHP callable accepting no arguments and
     * returning a string - the method's own current display label. A
     * [ClassName::class, 'staticMethodName'] pair is recommended over a
     * Closure for the same reason given in $customEnrollmentCheckers's
     * own doc comment above (a Closure cannot be this property's own
     * default value at all - a real report confirmed this the hard
     * way). For shield-whatsapp-mfa specifically:
     *
     *   public array $methodLabelResolvers = [
     *       'whatsapp' => [\WhatsAppMfa\Libraries\ChannelLabel::class, 'current'],
     *   ];
     *
     * Only consulted for a method key that has an entry here - any
     * method without one keeps using the static
     * lang('MfaDispatcher.methodLabel_' . $key) string exactly as
     * before this property existed.
     *
     * @var array<string, callable(): string>
     */
    public array $methodLabelResolvers = [
        // 'whatsapp' => [\WhatsAppMfa\Libraries\ChannelLabel::class, 'current'],
    ];

    /**
     * REQUIRED if you want MfaSettingsController::choose() to actually
     * refuse a custom method until the user has set it up - without an
     * entry here, choosing a custom method they've never enrolled in
     * silently sets it as their stored preference anyway.
     *
     * CONFIRMED, REAL GAP THIS FIXES: choose() has always had hardcoded
     * enrollment checks for exactly two method keys - 'totp' and
     * 'whatsapp' - redirecting to each one's own dedicated enrollment
     * route if the user hasn't set it up yet. Any OTHER method key,
     * including a developer's own custom one, had no equivalent check
     * at all - choosing it just set the preference directly, with
     * nothing checking $customEnrollmentCheckers first. The real,
     * user-facing consequence: at the user's next login,
     * MfaDispatcher::resolveAction() (the plain, non-required
     * preference path, not the $requiredMethodsForGroups one - see
     * that resolution logic above, which already has its own,
     * separate enrollment check) resolves straight to the login
     * action for a method the user never actually set up. With no
     * matching identity for Shield to find anything pending for, MFA
     * can end up silently skipped entirely - not an error, not a
     * forced-setup prompt, just bypassed.
     *
     * method key => the named route to redirect to instead of setting
     * the preference, when MethodEnrollmentChecker::isEnrolled()
     * reports the user hasn't set this method up yet - normally your
     * own custom method's own registration/settings-page route. Only
     * consulted for a method key not already handled by one of the
     * hardcoded 'totp'/'whatsapp' checks above. A method with no entry
     * here, and not yet enrolled, is refused outright (an error
     * message, no redirect) rather than silently allowed through -
     * refusing by default is the safer failure mode, since the
     * alternative is the silent MFA bypass this property exists to
     * prevent.
     *
     * @var array<string, string>
     */
    public array $customEnrollmentRoutes = [
        // 'secretword' => 'secretword-enroll',
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
