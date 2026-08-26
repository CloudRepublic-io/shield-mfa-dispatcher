<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Support;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Entities\User;

/**
 * A minimal fake activator - distinct from FakeAction/FakeActionTwo,
 * which represent normal LOGIN-time actions. This one represents what
 * Config\MfaDispatcher::$activatorClasses points a required method at
 * when a user hasn't set that method up yet. Its getType() is
 * deliberately different from every other fake in this directory, so
 * a test can prove the dispatcher routed to THIS one specifically,
 * not a login action.
 */
class FakeActivator implements ActionInterface
{
    public static array $calls = [];

    public function show(): string
    {
        self::$calls[] = 'show';

        return 'fake-activator-show-body';
    }

    public function handle(IncomingRequest $request): Response
    {
        self::$calls[] = 'handle';

        return service('response')->setBody('fake-activator-handle-body');
    }

    public function verify(IncomingRequest $request): Response
    {
        self::$calls[] = 'verify';

        return redirect()->to('/fake-activator-redirect-target');
    }

    public function getType(): string
    {
        return 'fake_activator_type';
    }

    public function createIdentity(User $user): string
    {
        self::$calls[] = 'createIdentity:' . $user->id;

        return 'fake-activator-secret';
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
