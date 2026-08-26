<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('MfaDispatcher.whatsappSetupButton') ?></h1>

<div class="alert alert-warning">
    <?= lang('MfaDispatcher.whatsappNotInstalled') ?>
</div>

<a href="<?= url_to('mfa-settings') ?>" class="btn btn-secondary">Back</a>

<?= $this->endSection() ?>
