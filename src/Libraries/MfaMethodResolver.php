<?php

declare(strict_types=1);

namespace MfaDispatcher\Libraries;

use CodeIgniter\Shield\Entities\User;
use Config\MfaDispatcher as MfaDispatcherConfig;

/**
 * Resolves which MFA method KEY applies to a given user - shared
 * between MfaDispatcher (the login Action, which then instantiates the
 * matching ActionInterface/activator) and RequireFreshMfa (the step-up
 * filter, which uses the key to pick which underlying package's OWN
 * step-up filter to delegate to).
 *
 * Extracted here rather than duplicated between those two classes:
 * this resolution logic - enabled-for-groups, required-method-for-
 * groups, preference fallback - is genuinely the core value this whole
 * package provides. Keeping it in exactly one place means a policy
 * change (a new required-group rule, say) applies identically to both
 * login and step-up, rather than needing to remember to update two
 * separate implementations that happen to agree today.
 *
 * Deliberately returns a plain method key STRING, not an
 * ActionInterface instance - instantiating an action, or a
 * registration-time activator for an unenrolled required method, is
 * MfaDispatcher's own job, not something a step-up filter needs or
 * should do.
 */
class MfaMethodResolver
{
    protected MfaDispatcherConfig $config;
    protected MfaPreference $preference;

    public function __construct()
    {
        $this->config     = config('MfaDispatcher');
        $this->preference = new MfaPreference();
    }

    /**
     * $required=true means everyone; otherwise only users in one of
     * $enabledForGroups. inGroup() is variadic and matches ANY of the
     * groups passed to it, so an empty array here correctly matches
     * nobody rather than throwing.
     */
    public function isMfaEnabledFor(User $user): bool
    {
        if ($this->config->required) {
            return true;
        }

        $enabledForGroups = setting('MfaDispatcher.enabledForGroups') ?? [];

        return $enabledForGroups !== [] && $user->inGroup(...$enabledForGroups);
    }

    /**
     * First entry (in array order) in $requiredMethodsForGroups whose
     * group key this user belongs to - see that property's own doc
     * comment on Config\MfaDispatcher for why a user in more than one
     * such group gets whichever is listed first, and why that ordering
     * is the caller's responsibility, not resolved automatically by
     * some notion of "strictness".
     */
    public function requiredMethodFor(User $user): ?string
    {
        $requiredMethodsForGroups = setting('MfaDispatcher.requiredMethodsForGroups') ?? [];

        foreach ($requiredMethodsForGroups as $group => $method) {
            if ($user->inGroup($group)) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Resolves the method KEY that applies to this user for LOGIN -
     * their group's required method if one applies, otherwise their
     * own stored preference, falling back to $config->defaultMethod.
     * Returns null if MFA isn't enabled for this user at all, or if
     * nothing valid resolves and $config->required is false.
     *
     * Doesn't check whether the user has actually ENROLLED in the
     * resolved method - MfaDispatcher's own resolveRequiredMethod()
     * handles that distinction (routing to a registration-time
     * activator instead) for the required-method case, which doesn't
     * apply to step-up at all (RequireFreshMfa delegates enrollment
     * checking to whichever underlying step-up filter it calls - see
     * that class's own doc comment).
     */
    public function resolveLoginMethod(User $user): ?string
    {
        if (! $this->isMfaEnabledFor($user)) {
            return null;
        }

        $requiredMethod = $this->requiredMethodFor($user);

        if ($requiredMethod !== null) {
            return $requiredMethod;
        }

        $method = $this->preference->get($user) ?? $this->config->defaultMethod;

        if ($method === '' || $method === null || ! isset($this->config->methods[$method])) {
            return $this->config->required ? $this->config->defaultMethod : null;
        }

        return $method;
    }
}
