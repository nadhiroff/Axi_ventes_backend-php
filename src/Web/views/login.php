<?php use Axi\Web\View; ?>
<div class="card">
<?php if ($mode === 'login'): ?>
  <h1>Connexion</h1>
  <p class="sub">Agents Axi, ANaGeM, annonceurs et IMF</p>
  <form method="post" action="<?= View::url('/pro/connexion') ?>" class="form">
    <?= View::csrf() ?>
    <div class="full"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required value="<?= View::e($email ?? '') ?>"></div>
    <div class="full"><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
    <div class="full"><button class="btn" type="submit" style="width:100%">Se connecter</button></div>
  </form>
  <p style="margin:16px 0 0"><a class="btn ghost" href="<?= View::url('/pro/mot-de-passe-oublie') ?>">Mot de passe oublié</a></p>
<?php elseif ($mode === 'forgot'): ?>
  <h1>Mot de passe oublié</h1>
  <p class="sub">Un code à 6 chiffres vous sera envoyé par email.</p>
  <form method="post" action="<?= View::url('/pro/mot-de-passe-oublie') ?>" class="form">
    <?= View::csrf() ?>
    <div class="full"><label for="email">Email</label><input id="email" name="email" type="email" required></div>
    <div class="full"><button class="btn" type="submit" style="width:100%">Recevoir un code</button></div>
  </form>
  <p style="margin:16px 0 0"><a class="btn ghost" href="<?= View::url('/pro/connexion') ?>">Retour à la connexion</a></p>
<?php else: ?>
  <h1>Nouveau mot de passe</h1>
  <p class="sub">Saisissez le code reçu par email.</p>
  <form method="post" action="<?= View::url('/pro/reinitialiser') ?>" class="form">
    <?= View::csrf() ?>
    <div class="full"><label for="email">Email</label><input id="email" name="email" type="email" required value="<?= View::e($email ?? '') ?>"></div>
    <div class="full"><label for="code">Code à 6 chiffres</label><input id="code" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required></div>
    <div class="full"><label for="password">Nouveau mot de passe (8 caractères minimum)</label><input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required></div>
    <div class="full"><button class="btn" type="submit" style="width:100%">Changer le mot de passe</button></div>
  </form>
<?php endif; ?>
</div>
