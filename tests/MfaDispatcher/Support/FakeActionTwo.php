<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Support;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Entities\User;

/**
 * A second, distinct fake action - needed because testing "does the
 * user's preference actually select between methods" requires two
 * methods that are genuinely different from each other; using
 * FakeAction for both configured methods wouldn't prove the dispatcher
 * picked the right one, only that it picked *a* configured one.
 */
class FakeActionTwo implements ActionInterface
{
    public function show(): string
    {
        return 'fake-two-show-body';
    }

    public function handle(IncomingRequest $request): Response
    {
        return service('response')->setBody('fake-two-handle-body');
    }

    public function verify(IncomingRequest $request): Response
    {
        return redirect()->to('/fake-two-redirect-target');
    }

    public function getType(): string
    {
        return 'fake_action_two_type';
    }

    public function createIdentity(User $user): string
    {
        return 'fake-two-secret';
    }
}
