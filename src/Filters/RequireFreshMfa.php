<?php

declare(strict_types=1);

namespace MfaDispatcher\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use MfaDispatcher\Libraries\MethodEnrollmentChecker;
use MfaDispatcher\Libraries\MfaMethodResolver;

/**
 * Step-up auth that follows whichever method a user actually logs in
 * with - the dispatcher's own counterpart to shield-totp-mfa's
 * RequireFreshTotp / shield-whatsapp-mfa's RequireFreshWhatsApp /
 * shield-passkey-mfa's RequireFreshPasskey, for apps using
 * MfaDispatcher rather than committing to a single method directly.
 *
 * DEFAULT POLICY: challenge with whichever method
 * MfaMethodResolver::resolveLoginMethod() resolves for this user - the
 * SAME method they actually log in with, so step-up "matches" login
 * without an admin needing to keep the two in sync by hand.
 *
 * OVERRIDE: set Config\MfaDispatcher::$stepUpMethod to a specific
 * method key (e.g. 'totp') to ALWAYS require that one method for
 * step-up, regardless of what a given user logs in with - e.g.
 * "billing changes always require an authenticator app, even for
 * users who normally log in with WhatsApp". Only takes effect if that
 * method's package is actually installed (checked via
 * MethodEnrollmentChecker::isAvailable()) - falls back to the default,
 * per-user resolution otherwise, rather than failing outright. Read
 * via setting('MfaDispatcher.stepUpMethod'), so this is editable at
 * runtime the same way $enabledForGroups is.
 *
 * THIN ROUTER, NOT A NEW CHALLENGE UI: once a method key is resolved,
 * this filter delegates the entire freshness check, challenge page,
 * and session-stamping to that method's OWN step-up filter
 * (Config\MfaDispatcher::$stepUpFilterClasses) by calling its before()
 * directly. A user challenged this way lands on that package's own
 * existing step-up route (e.g. `totp-step-up`) - there is no
 * dispatcher-specific challenge page anywhere in this package.
 *
 * If the resolved method has no step-up filter configured at all -
 * including Shield's own built-in Email2FA, which has no step-up
 * filter anywhere in this series - or if MFA isn't enabled for this
 * user at all, the request is let through. Same "nothing to challenge
 * them with" policy default every other filter in this series uses;
 * this is not a security hole so much as an honest reflection that
 * this mechanism can't challenge a method nothing was built to
 * re-verify.
 *
 * Register the alias in app/Config/Filters.php:
 *
 *   public array $aliases = [
 *       ...
 *       'mfa-fresh' => \MfaDispatcher\Filters\RequireFreshMfa::class,
 *   ];
 *
 * then apply it alongside your normal login-required filter:
 *
 *   $routes->group('admin/billing', ['filter' => ['session', 'mfa-fresh']], static function ($routes) {
 *       // ... routes for changing Stripe keys, etc.
 *   });
 */
class RequireFreshMfa implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = auth()->user();

        if ($user === null) {
            // Not this filter's job - let whatever login-required
            // filter runs alongside it (e.g. Shield's 'session')
            // handle an unauthenticated request.
            return null;
        }

        $method = $this->resolveStepUpMethod($user);

        if ($method === null) {
            return null;
        }

        $config      = config('MfaDispatcher');
        $filterClass = $config->stepUpFilterClasses[$method] ?? null;

        if ($filterClass === null || ! class_exists($filterClass)) {
            // Nothing built to re-challenge this method with - let
            // through rather than lock the user out of a page they
            // have no way to unlock via this mechanism. See this
            // class's own doc comment for the fuller reasoning.
            return null;
        }

        /** @var FilterInterface $filter */
        $filter = new $filterClass();

        return $filter->before($request, $arguments);
    }

    /**
     * The configured override, if set AND its package is actually
     * installed; otherwise whichever method the user's own LOGIN
     * resolves to.
     */
    private function resolveStepUpMethod(User $user): ?string
    {
        $configuredMethod = setting('MfaDispatcher.stepUpMethod');

        if ($configuredMethod !== null) {
            $checker = new MethodEnrollmentChecker();

            if ($checker->isAvailable($configuredMethod)) {
                return $configuredMethod;
            }

            // Not installed - fall through to the default resolution
            // below rather than failing outright, consistent with this
            // whole package's graceful-degradation philosophy elsewhere
            // (see MfaSettingsController's totpLibraryAvailable()-style
            // checks for the same pattern applied to the settings page).
        }

        return (new MfaMethodResolver())->resolveLoginMethod($user);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nothing to do after whichever underlying filter's own
        // controller runs.
    }
}
