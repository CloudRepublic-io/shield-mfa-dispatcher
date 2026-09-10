<?php

declare(strict_types=1);

namespace MfaDispatcher\Authentication\Actions;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Exceptions\RuntimeException;
use Config\MfaDispatcher as MfaDispatcherConfig;
use MfaDispatcher\Libraries\DiagnosticLog;
use MfaDispatcher\Libraries\MethodEnrollmentChecker;
use MfaDispatcher\Libraries\MfaMethodResolver;
use MfaDispatcher\Libraries\MfaPreference;

/**
 * Register only this one class in app/Config/Auth.php:
 *
 *   public array $actions = [
 *       'register' => null,
 *       'login'    => \MfaDispatcher\Authentication\Actions\MfaDispatcher::class,
 *   ];
 *
 * Which underlying MFA method actually runs (Email2FA, WhatsAppMfa,
 * TotpMfa, or anything else implementing ActionInterface) is resolved
 * per-user at request time, in this order:
 *
 *   1. Is MFA even switched on for this user? Either
 *      Config\MfaDispatcher::$required is true (on for everyone), or
 *      they're in one of the groups listed in $enabledForGroups (read
 *      via setting(), so editable at runtime - see that property's own
 *      doc comment). If neither, MFA is skipped entirely.
 *   2. If switched on: does this user's group require a SPECIFIC
 *      method ($requiredMethodsForGroups)? If so, that method is used
 *      regardless of the user's own stored preference - and if they
 *      haven't set it up yet, they're routed into that method's own
 *      registration-time activator inline, before login can complete,
 *      rather than falling back to anything else.
 *   3. Otherwise: the user's own stored preference (MfaPreference),
 *      falling back to $defaultMethod.
 *
 * Nothing about Auth.php's $actions ever needs to change to add,
 * remove, or let users switch between methods, or to change which
 * groups have MFA enabled or which method a group requires.
 */
class MfaDispatcher implements ActionInterface
{
    protected MfaDispatcherConfig $config;
    protected MfaPreference $preference;
    protected MethodEnrollmentChecker $enrollmentChecker;
    protected MfaMethodResolver $resolver;

    public function __construct()
    {
        $this->config            = config('MfaDispatcher');
        $this->preference        = new MfaPreference();
        $this->enrollmentChecker = new MethodEnrollmentChecker();
        $this->resolver          = new MfaMethodResolver();
    }

    /**
     * TEMPORARY DIAGNOSTIC LOGGING added below - part of the same live
     * investigation as resolveRequiredMethod()'s own logging further
     * down. Logged BEFORE getPendingUser() is even called, specifically
     * so this still fires (confirming show() was entered at all) even
     * if that call throws - which would itself be a different, useful
     * signal (Shield decided nothing is pending for THIS slot at all).
     */
    public function show(): string
    {
        DiagnosticLog::write('info', 'MfaDispatcher show(): entered, current URI {uri}.', ['uri' => (string) current_url(true)]);

        $user   = $this->getPendingUser();
        $action = $this->resolveAction($user);

        if ($action === null) {
            // MFA not required for this user and they have no method
            // configured - complete the login immediately. See the
            // TotpMfa package's README for why a raw redirect + exit is
            // used here rather than trying to return a Response from a
            // method that's contractually typed `: string`.
            $this->completePendingLogin($user);

            redirect()->to(config('Auth')->loginRedirect())->send();
            exit;
        }

        DiagnosticLog::write('info', 'MfaDispatcher show(): resolved action class {class} for user_id {user_id}, delegating to its own show().', ['class' => get_class($action), 'user_id' => $user->id]);

        return $action->show();
    }

    public function handle(IncomingRequest $request): Response
    {
        return $this->ensureResponse(
            $this->resolveActionOrFail($this->getPendingUser())->handle($request)
        );
    }

    public function verify(IncomingRequest $request): Response
    {
        return $this->ensureResponse(
            $this->resolveActionOrFail($this->getPendingUser())->verify($request)
        );
    }

    /**
     * CONFIRMED BUG, FIXED HERE: an earlier version returned whatever
     * the delegated action's handle()/verify() produced directly - but
     * this method's own `: Response` return type then rejected it at
     * runtime with "Return value must be of type Response, string
     * returned" for at least one real user, delegating to Shield's own
     * built-in Email2FA.
     *
     * The error naming THIS method (not Email2FA::handle()) as the
     * violated contract is the key clue: if Email2FA::handle() were
     * itself declared `: Response` and tried to return a string, PHP
     * would throw from inside THAT method, not this one. So
     * Email2FA::handle() apparently isn't declared that strictly in
     * practice - a real, working custom Action example does declare
     * `handle(): Response` in Shield's own GitHub discussions, but
     * that's one developer's choice for their own class, not proof
     * every built-in Action (or every Shield version) enforces the
     * same contract as strictly. Rather than assume every delegated
     * action always honors `: Response` (unlike show(), which IS
     * confirmed strict via multiple real fatal errors elsewhere in
     * this series), this method makes MfaDispatcher itself tolerant of
     * either - wrapping a plain string the same way TotpMfa/PasskeyMfa
     * already wrap their own show()-mirroring handle() bodies.
     */
    private function ensureResponse($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        return service('response')->setBody((string) $result);
    }

    /**
     * {@inheritDoc}
     *
     * Newer Shield versions added getType() and createIdentity() to
     * ActionInterface. Since this class is itself just a delegator with
     * no fixed identity type of its own, both simply forward to
     * whichever real Action is resolved for the current pending user -
     * falling back to an empty/no-op result if MFA can be skipped
     * entirely (see resolveAction()).
     */
    /**
     * CONFIRMED BUG, FIXED HERE: this used to call
     * $this->resolveAction($this->getPendingUser()), which threw
     * "cannot get the pending login user" for every real login
     * attempt. Root cause, confirmed against Shield's own attempt()/
     * setAuthAction() source (see this class's own doc comment and
     * shield-totp-mfa's README for how that source was obtained):
     * Shield calls $action->getType() from INSIDE setAuthAction(),
     * which is itself the method that decides whether to set
     * $userState to STATE_PENDING - getType() is called BEFORE that
     * decision is made, not after. getPendingUser() only ever returns
     * non-null once STATE_PENDING is already set, so it's
     * unconditionally null at the exact moment Shield calls this
     * method - a genuine chicken-and-egg problem, not a bug specific
     * to any particular login attempt.
     *
     * TotpMfa/PasskeyMfa never hit this because their getType()
     * implementations return a static string that doesn't need to
     * know who the user is at all for the common case - only
     * MfaDispatcher genuinely needs a user reference here, since its
     * whole job is picking a *different* type per user.
     *
     * auth()->user() is Shield's own documented way to get "the
     * current User entity" - used here instead, since it's the
     * best-supported alternative found. This has NOT been confirmed
     * against Shield's actual source the way the rest of this
     * project's fixes have been - if you hit the same error again
     * after updating to this version, that's the next thing to
     * verify, not something to assume is already ruled out.
     */
    public function getType(): string
    {
        $user = auth()->user();

        if ($user === null) {
            // Genuinely nothing to resolve against - not the bug this
            // fix addresses, but also not a state Shield should ever
            // call getType() in at all (there must be SOME user being
            // processed for setAuthAction() to be running in the first
            // place). Returning '' here means "no identity of this
            // type could possibly match", which is safe: it just means
            // this specific check doesn't apply, not that MFA gets
            // silently skipped - setAuthAction() simply moves on to
            // check the next configured action.
            return '';
        }

        $action = $this->resolveAction($user);

        return $action?->getType() ?? '';
    }

    public function createIdentity(User $user): string
    {
        $action = $this->resolveAction($user);

        return $action?->createIdentity($user) ?? '';
    }

    /**
     * Looks up the user's stored preference (falling back to the
     * configured default), and returns a fresh instance of whichever
     * Action class that method maps to - or null if MFA can be
     * skipped entirely for this user. See this class's own doc
     * comment for the full three-step resolution order.
     */
    protected function resolveAction(User $user): ?ActionInterface
    {
        if (! $this->isMfaEnabledFor($user)) {
            return null;
        }

        $requiredMethod = $this->requiredMethodFor($user);

        if ($requiredMethod !== null) {
            return $this->resolveRequiredMethod($requiredMethod, $user);
        }

        $method = $this->preference->get($user) ?? $this->config->defaultMethod;

        if ($method === '' || $method === null || ! isset($this->config->methods[$method])) {
            if (! $this->config->required) {
                return null;
            }

            // Configured default itself must exist - if it doesn't,
            // that's a config mistake, not a "skip MFA" situation.
            $method = $this->config->defaultMethod;
        }

        return $this->instantiateMethod($method);
    }

    /**
     * Delegates to MfaMethodResolver - see that class's own doc
     * comment for why this logic now lives there rather than here
     * directly (shared with the new RequireFreshMfa step-up filter).
     */
    protected function isMfaEnabledFor(User $user): bool
    {
        return $this->resolver->isMfaEnabledFor($user);
    }

    /**
     * Delegates to MfaMethodResolver - see that class's own doc
     * comment for why this logic now lives there rather than here
     * directly (shared with the new RequireFreshMfa step-up filter).
     */
    protected function requiredMethodFor(User $user): ?string
    {
        return $this->resolver->requiredMethodFor($user);
    }

    /**
     * A required method always wins over the user's own preference -
     * it's a mandate, not a default, so there's nothing to "fall back"
     * to here. If the user hasn't set this method up yet, routes to
     * its registration-time activator (Config\MfaDispatcher::$activatorClasses)
     * instead of its login-time verification action, so setup happens
     * inline before their login can complete.
     *
     * TEMPORARY DIAGNOSTIC LOGGING added below - a real report showed a
     * user routed back into forced setup at a SUBSEQUENT login, despite
     * already having a stored credential from registration. This method
     * is the single decision point for that routing, so logging exactly
     * what isEnrolled() returns (and for which user_id) here will
     * confirm whether that check itself is the problem, or whether
     * something upstream (e.g. which user this actually runs for) is.
     * Safe to leave in permanently - gated to only ever write when
     * ENVIRONMENT is 'development' (see DiagnosticLog's own doc
     * comment for why), so this never accumulates user_id values in a
     * production log just from ordinary MFA-required logins.
     */
    protected function resolveRequiredMethod(string $method, User $user): ActionInterface
    {
        $enrolled = $this->enrollmentChecker->isEnrolled($method, $user);

        DiagnosticLog::write(
            'info',
            'MfaDispatcher resolveRequiredMethod: user_id {user_id}, method "{method}", isEnrolled() returned {enrolled}.',
            ['user_id' => $user->id, 'method' => $method, 'enrolled' => $enrolled ? 'true' : 'false']
        );

        if ($enrolled) {
            return $this->instantiateMethod($method);
        }

        $activatorClass = $this->config->activatorClasses[$method] ?? null;

        if ($activatorClass === null) {
            throw new RuntimeException(
                "MfaDispatcher: '{$method}' is required for one of this user's groups, but no " .
                'activator class is configured for it in $activatorClasses, and the user hasn\'t ' .
                'set it up yet. Check app/Config/MfaDispatcher.php.'
            );
        }

        DiagnosticLog::write(
            'info',
            'MfaDispatcher resolveRequiredMethod: routing user_id {user_id} into forced setup ({activator}) for method "{method}".',
            ['user_id' => $user->id, 'activator' => $activatorClass, 'method' => $method]
        );

        return new $activatorClass();
    }

    protected function instantiateMethod(string $method): ActionInterface
    {
        $class = $this->config->methods[$method] ?? null;

        if ($class === null) {
            throw new RuntimeException(
                "MfaDispatcher: no Action class is configured for MFA method '{$method}'. " .
                'Check app/Config/MfaDispatcher.php.'
            );
        }

        if (! is_subclass_of($class, ActionInterface::class) && ! in_array(ActionInterface::class, class_implements($class) ?: [], true)) {
            throw new RuntimeException("MfaDispatcher: {$class} does not implement ActionInterface.");
        }

        return new $class();
    }

    protected function resolveActionOrFail(User $user): ActionInterface
    {
        $action = $this->resolveAction($user);

        if ($action === null) {
            // handle()/verify() should only ever be reached after
            // show() already rendered a form for a real Action, so
            // getting here with nothing to delegate to means the
            // user's preference changed mid-flow (or MFA was disabled)
            // between show() and this request.
            throw new RuntimeException('MfaDispatcher: no MFA action to delegate to for this request.');
        }

        return $action;
    }

    protected function getPendingUser(): User
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $user          = $authenticator->getPendingUser();

        if ($user === null) {
            throw new RuntimeException('MfaDispatcher: cannot get the pending login user.');
        }

        return $user;
    }

    /**
     * CONFIRMED against Shield v1.3.0's actual Session::attempt():
     * completeLogin() is the method Shield's own attempt() calls to
     * finish a pending action (login() is a stricter, separate public
     * method that refuses if it finds leftover identities for the
     * action type - which matters for TOTP specifically, since its
     * secret is kept permanently rather than deleted like a one-time
     * code). Worth a quick sanity check against whichever Shield
     * version you're running, in case this changes again:
     * vendor/codeigniter4/shield/src/Authentication/Authenticators/Session.php
     */
    protected function completePendingLogin(User $user): void
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $authenticator->completeLogin($user);
    }
}
