<?php use Axi\Web\View; ?>
<h1><?= View::e($title) ?></h1>
<?php if (!empty($subtitle)): ?><p class="sub"><?= View::e($subtitle) ?></p><?php endif; ?>
<?= $body ?>
