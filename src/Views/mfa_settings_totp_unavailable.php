<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('MfaDispatcher.totpSetupButton') ?></h1>

<div class="alert alert-warning">
    <?= lang('MfaDispatcher.totpNotInstalled') ?>
</div>

<a href="<?= url_to('mfa-settings') ?>" class="btn btn-secondary">Back</a>

<?= $this->endSection() ?>
