<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Support;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserIdentityModel;

/**
 * A minimal ActionInterface implementation used only to test
 * MfaDispatcher's own delegation logic in isolation - deliberately not
 * a real MFA method, so these tests don't depend on Email2FA,
 * WhatsAppMfa, or TotpMfa actually being installed alongside this
 * package.
 */
class FakeAction implements ActionInterface
{
    /** @var string[] */
    public static array $calls = [];

    public function show(): string
    {
        self::$calls[] = 'show';

        return 'fake-show-body';
    }

    public function handle(IncomingRequest $request): Response
    {
        self::$calls[] = 'handle';

        return service('response')->setBody('fake-handle-body');
    }

    public function verify(IncomingRequest $request): Response
    {
        self::$calls[] = 'verify';

        return redirect()->to('/fake-redirect-target');
    }

    public function getType(): string
    {
        return 'fake_action_type';
    }

    /**
     * Stores a real identity of getType()'s type, as a real action does.
     * Shield only puts a user into the "pending MFA" state if, after
     * createIdentity(), it finds an identity of the action's type in
     * the database. Without one, getPendingUser() returned null and
     * MfaDispatcher::show() couldn't find the user it was meant to
     * challenge.
     */
    public function createIdentity(User $user): string
    {
        self::$calls[] = 'createIdentity:' . $user->id;

        $identities = model(UserIdentityModel::class);
        $identities->deleteIdentitiesByType($user, $this->getType());

        // The stored secret includes the user id because auth_identities
        // has a unique key on (type, secret) - two users in one test
        // would otherwise collide. The returned value stays
        // 'fake-secret', which is what the tests assert on.
        $identities->insert([
            'user_id' => $user->id,
            'type'    => $this->getType(),
            'name'    => 'fake',
            'secret'  => 'fake-secret-' . $user->id,
            'extra'   => 'fake',
        ]);

        return 'fake-secret';
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
