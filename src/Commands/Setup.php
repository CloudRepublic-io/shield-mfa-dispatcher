<?php

declare(strict_types=1);

namespace MfaDispatcher\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Publisher\Publisher;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Throwable;

/**
 * php spark mfa-dispatcher:setup
 *
 * Publishes Config/MfaDispatcher.php and Language/en/MfaDispatcher.php
 * into the host app. Same Publisher-based approach as
 * shield:setup / totp-mfa:setup - see those for the reasoning behind
 * not also trying to auto-edit Auth.php/Routes.php.
 */
class Setup extends BaseCommand
{
    protected $group       = 'MfaDispatcher';
    protected $name        = 'mfa-dispatcher:setup';
    protected $description = 'Publishes the MFA dispatcher config and language file into your app.';
    protected $usage       = 'mfa-dispatcher:setup [--force]';
    protected $options     = [
        '--force' => 'Overwrite files that were already published by a previous run.',
    ];

    public function run(array $params)
    {
        if (! class_exists(UserIdentityModel::class)) {
            CLI::error('CodeIgniter Shield does not appear to be installed.');
            CLI::write('Install it first: composer require codeigniter4/shield');

            return;
        }

        $namespaces = service('autoloader')->getNamespace('MfaDispatcher');

        if ($namespaces === []) {
            CLI::error('Could not resolve the "MfaDispatcher" namespace.');
            CLI::write('Make sure it is registered in composer.json or app/Config/Autoload.php.');

            return;
        }

        $force  = (bool) CLI::getOption('force');
        $source = rtrim($namespaces[0], '/\\');

        $publisher = new Publisher($source, APPPATH);

        try {
            $publisher->addPaths(['Config', 'Language'])->merge($force);
        } catch (Throwable $e) {
            CLI::error('Publishing failed: ' . $e->getMessage());
            $this->printPublisherErrors($publisher);

            return;
        }

        $published = $publisher->getPublished();

        if ($published === []) {
            CLI::write('Nothing to publish - files already exist. Re-run with --force to overwrite.', 'yellow');
        } else {
            foreach ($published as $file) {
                CLI::write('  Published: ' . str_replace(APPPATH, 'app/', $file), 'green');
            }
        }

        $this->printPublisherErrors($publisher);
        $this->printRemainingSteps();
    }

    private function printPublisherErrors(Publisher $publisher): void
    {
        foreach ($publisher->getErrors() as $file => $error) {
            CLI::error('  ' . $file . ': ' . $error->getMessage());
        }
    }

    private function printRemainingSteps(): void
    {
        CLI::newLine();
        CLI::write('A few manual steps left:', 'yellow');

        CLI::newLine();
        CLI::write('1) Edit app/Config/MfaDispatcher.php and register whichever MFA');
        CLI::write('   method packages you have installed (WhatsAppMfa, TotpMfa, ...).');

        CLI::newLine();
        CLI::write('2) Register the dispatcher (and only the dispatcher) in app/Config/Auth.php:');
        CLI::write('   public array $actions = [');
        CLI::write("       'register' => null,");
        CLI::write("       'login'    => \\MfaDispatcher\\Authentication\\Actions\\MfaDispatcher::class,");
        CLI::write('   ];');

        CLI::newLine();
        CLI::write('3) Add the settings-page routes to app/Config/Routes.php');
        CLI::write('   See routes-snippet.php in this package for the exact lines.');

        CLI::newLine();
        CLI::write('4) Link to account/mfa from wherever your account settings page lives.');
    }
}
