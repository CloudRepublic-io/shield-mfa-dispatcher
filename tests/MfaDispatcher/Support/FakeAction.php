<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Support;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Entities\User;

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

    public function createIdentity(User $user): string
    {
        self::$calls[] = 'createIdentity:' . $user->id;

        return 'fake-secret';
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
