<?php use Axi\Web\View; ?>
<div class="card">
<?php if ($mode === 'login'): ?>
  <h1>Connexion</h1>
  <p class="sub" style="margin-bottom:20px">Agents Axi, ANaGeM, annonceurs et institutions de financement.</p>
  <form method="post" action="<?= View::url('/pro/connexion') ?>" class="form">
    <?= View::csrf() ?>
    <div class="full"><label for="email">Adresse email</label><input id="email" name="email" type="email" autocomplete="username" required autofocus value="<?= View::e($email ?? '') ?>"></div>
    <div class="full"><label for="password">Mot de passe</label><input id="password" name="password" type="password" data-password autocomplete="current-password" required></div>
    <div class="full"><label class="check"><input type="checkbox" data-show-password> Afficher le mot de passe</label></div>
    <div class="full"><button class="btn big" type="submit">Se connecter</button></div>
  </form>
  <p class="links"><a href="<?= View::url('/pro/mot-de-passe-oublie') ?>">Mot de passe oublié ?</a></p>
<?php elseif ($mode === 'forgot'): ?>
  <h1>Mot de passe oublié</h1>
  <p class="sub" style="margin-bottom:20px">Un code à 6 chiffres vous sera envoyé par email.</p>
  <form method="post" action="<?= View::url('/pro/mot-de-passe-oublie') ?>" class="form">
    <?= View::csrf() ?>
    <div class="full"><label for="email">Adresse email</label><input id="email" name="email" type="email" autocomplete="username" required autofocus></div>
    <div class="full"><button class="btn big" type="submit">Recevoir un code</button></div>
  </form>
  <p class="links"><a href="<?= View::url('/pro/connexion') ?>">← Retour à la connexion</a></p>
<?php else: ?>
  <h1>Nouveau mot de passe</h1>
  <p class="sub" style="margin-bottom:20px">Saisissez le code reçu par email et choisissez un nouveau mot de passe.</p>
  <form method="post" action="<?= View::url('/pro/reinitialiser') ?>" class="form">
    <?= View::csrf() ?>
    <div class="full"><label for="email">Adresse email</label><input id="email" name="email" type="email" required value="<?= View::e($email ?? '') ?>"></div>
    <div class="full"><label for="code">Code à 6 chiffres</label><input id="code" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required></div>
    <div class="full"><label for="password">Nouveau mot de passe</label><input id="password" name="password" type="password" data-password minlength="8" autocomplete="new-password" required><div class="hint">8 caractères minimum.</div></div>
    <div class="full"><label for="password2">Confirmer le mot de passe</label><input id="password2" name="password_confirmation" type="password" data-password minlength="8" autocomplete="new-password" required></div>
    <div class="full"><label class="check"><input type="checkbox" data-show-password> Afficher les mots de passe</label></div>
    <div class="full"><button class="btn big" type="submit">Changer le mot de passe</button></div>
  </form>
  <p class="links"><a href="<?= View::url('/pro/connexion') ?>">← Retour à la connexion</a></p>
<?php endif; ?>
</div>
