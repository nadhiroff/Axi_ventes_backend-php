<?php use Axi\Web\View; ?>
<?php if (!empty($hero)): ?>
<section class="hero">
  <p class="hero-eyebrow"><?= View::e($hero['eyebrow']) ?></p>
  <h1><?= View::e($title) ?></h1>
  <?php if (!empty($subtitle)): ?><p class="sub"><?= View::e($subtitle) ?></p><?php endif; ?>
  <?php if (!empty($hero['links'])): ?>
  <div class="hero-actions"><?php foreach ($hero['links'] as $i => [$label, $href]): ?><a class="<?= $i > 0 ? 'alt' : '' ?>" href="<?= View::url($href) ?>"><?= View::e($label) ?></a><?php endforeach; ?></div>
  <?php endif; ?>
</section>
<?php else: ?>
<h1><?= View::e($title) ?></h1>
<?php if (!empty($subtitle)): ?><p class="sub"><?= View::e($subtitle) ?></p><?php endif; ?>
<?php endif; ?>
<?= $body ?>
