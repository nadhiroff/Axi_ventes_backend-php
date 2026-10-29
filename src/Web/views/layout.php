<?php
use Axi\Support\Users;
use Axi\Web\Live;
use Axi\Web\View;
use Axi\Web\WebAuth;

$path = '/' . trim((string) preg_replace('#^.*?/pro#', '/pro', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
$section = WebAuth::section($path);
$isAxi = $user !== null && $user['partner_type'] === 'axi';
$counts = $isAxi ? Live::counts() : ['applications' => 0, 'disputes' => 0, 'visits' => 0, 'proposals' => 0];
$manageCount = (int) $counts['applications'] + (int) $counts['disputes'];
$badgeFor = ['/pro/demandes' => (int) $counts['applications'], '/pro/commandes' => (int) $counts['disputes'], '/pro/commercants' => (int) $counts['visits'], '/pro/allees' => (int) $counts['proposals']];
$manageOn = in_array($section, array_column(WebAuth::MANAGE, 0), true);
$tabs = null;
foreach (WebAuth::TABS as $group) {
    if ($group[0][0] === $section && $user !== null) {
        $tabs = array_values(array_filter($group, static fn (array $t): bool => in_array($user['partner_type'], $t[2], true)));
    }
}
$initials = $user === null ? '' : mb_strtoupper(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1));
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= View::e(($title ?? 'Axi Pro') . ' · axi pro') ?></title>
<style>
:root{--brand:#C2410C;--brand-hover:#9A3412;--brand-soft:#FDEBDD;--brand-ink:#9A3412;--logo:#F97316;--ok:#166534;--ok-soft:#DCFCE7;--danger:#B91C1C;--danger-soft:#FEE2E2;
--ink:#1C1917;--muted:#6B6460;--bg:#F3F1EE;--surface:#FFFFFF;--surface-2:#FAF8F6;--border:#E7E2DC;--bar:#1C1A19;--bar-ink:#E7E5E4;--bar-on:#2E2B29;--shadow:0 1px 2px rgba(28,25,23,.05),0 8px 24px rgba(28,25,23,.06);--radius:18px}
@media (prefers-color-scheme:dark){:root{--brand:#EA580C;--brand-hover:#F97316;--brand-soft:#3B2417;--brand-ink:#FDBA74;--ok:#4ADE80;--ok-soft:#14301F;--danger:#F87171;--danger-soft:#3A1616;
--ink:#F5F5F4;--muted:#A8A29E;--bg:#141210;--surface:#1F1C19;--surface-2:#191715;--border:#36312C;--bar:#0C0B0A;--bar-on:#262320;--shadow:none}}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
body{margin:0;font:15px/1.55 Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{color:var(--brand)}h1{font-size:clamp(24px,3.2vw,34px);line-height:1.2;margin:0 0 6px;letter-spacing:-.01em}h2{font-size:19px;margin:0 0 14px}h3{font-size:16px;margin:0 0 8px}
.sub{color:var(--muted);margin:0 0 24px;max-width:760px;font-size:16px}.muted{color:var(--muted)}.small{font-size:13px}
.bar{background:var(--bar);color:var(--bar-ink);position:sticky;top:0;z-index:40}
.bar-in{max-width:1320px;margin:0 auto;display:flex;align-items:center;gap:16px;padding:0 clamp(16px,3vw,40px);min-height:68px;position:relative}
.logo{font-size:28px;font-weight:800;color:var(--logo);text-decoration:none;letter-spacing:-.02em;white-space:nowrap}.logo small{color:#fff;font-size:17px;font-weight:700;margin-left:6px}
.nav{display:flex;align-items:center;gap:4px;margin-left:auto}
.nav>a,.nav .drop>button{color:var(--bar-ink);text-decoration:none;padding:10px 14px;border-radius:12px;font:inherit;font-size:15px;background:none;border:0;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:6px}
.nav>a:hover,.nav .drop>button:hover{background:var(--bar-on)}.nav>a.on,.nav .drop>button.on{background:var(--bar-on);color:#fff;font-weight:700}
.drop{position:relative}.menu{display:none;position:absolute;right:0;top:calc(100% + 8px);background:var(--surface);color:var(--ink);border:1px solid var(--border);border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.18);min-width:250px;padding:6px;z-index:50}
.drop.open .menu{display:block}.menu a,.menu button{display:flex;justify-content:space-between;align-items:center;gap:10px;width:100%;padding:10px 12px;border-radius:10px;color:var(--ink);text-decoration:none;font:inherit;background:none;border:0;cursor:pointer;text-align:left}
.menu a:hover,.menu button:hover{background:var(--surface-2)}.menu a.on{background:var(--brand-soft);color:var(--brand-ink);font-weight:600}.menu hr{border:0;border-top:1px solid var(--border);margin:6px 0}
.menu .who{padding:10px 12px 8px;font-size:13px;color:var(--muted)}.menu .who strong{display:block;color:var(--ink);font-size:15px}
.avatar{width:36px;height:36px;border-radius:50%;background:var(--brand);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:14px}
.pill{display:inline-flex;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:var(--logo);color:#fff;font-size:12px;font-weight:700;align-items:center;justify-content:center}
.burger{display:none;margin-left:auto;background:none;border:0;color:#fff;padding:10px;border-radius:10px;cursor:pointer}.burger:hover{background:var(--bar-on)}
@media (max-width:1060px){.burger{display:inline-flex}.nav{display:none;position:absolute;left:0;right:0;top:100%;background:var(--bar);flex-direction:column;align-items:stretch;padding:8px 16px 16px;margin:0;max-height:calc(100vh - 68px);overflow:auto}
 .bar.open .nav{display:flex}.nav>a,.nav .drop>button{width:100%;justify-content:space-between}.menu{position:static;box-shadow:none;margin:4px 0 8px;min-width:0}}
main{max-width:1320px;margin:0 auto;padding:36px clamp(16px,3vw,40px) 64px}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin:-8px 0 24px;border-bottom:1px solid var(--border)}
.tabs a{padding:10px 14px;color:var(--muted);text-decoration:none;font-weight:600;border-bottom:2.5px solid transparent;margin-bottom:-1px}.tabs a.on{color:var(--ink);border-color:var(--brand)}.tabs a:hover{color:var(--ink)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:20px;box-shadow:var(--shadow)}
.grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:24px}
.kpi{margin:0;padding:24px 26px}.kpi .l{color:var(--muted);font-size:15px}.kpi .v{font-size:clamp(28px,3vw,36px);font-weight:800;letter-spacing:-.02em;margin:6px 0 2px;line-height:1.15}.kpi .s{color:var(--muted);font-size:14px}
a.kpi{text-decoration:none;color:inherit;transition:transform .12s,border-color .12s}a.kpi:hover{border-color:var(--brand);transform:translateY(-1px)}a.kpi.alert .v{color:var(--brand)}
.grid.three{grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr))}@media (min-width:1000px){.grid.three{grid-template-columns:repeat(3,1fr)}}
@media (max-width:700px){.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.kpi{padding:16px}.kpi .l{font-size:13px}.kpi .s{font-size:12px}.card{padding:18px}}
.card .card.empty{border:0;box-shadow:none;padding:12px 0;margin:0;background:none}
.split{display:grid;gap:24px;grid-template-columns:minmax(0,2fr) minmax(300px,1fr);align-items:start}
.row2{display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));align-items:start}
@media (max-width:900px){.split{grid-template-columns:1fr}}
.section-title{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:8px 0 14px}.section-title h2{margin:0}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:20px}
.card .table-wrap{border:0;box-shadow:none;border-radius:0;margin:0 -8px}
table{border-collapse:collapse;width:100%}th,td{padding:14px 16px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle}
th{font-size:14px;font-weight:600;color:var(--muted);white-space:nowrap}tr:last-child td{border-bottom:0}td.num,th.num{text-align:right;white-space:nowrap}
tbody tr:hover{background:var(--surface-2)}td a{font-weight:600;text-decoration:none}td a:hover{text-decoration:underline}
@media (max-width:700px){.table-wrap table,.table-wrap tbody,.table-wrap tr,.table-wrap td{display:block;width:100%}.table-wrap thead{display:none}
 .table-wrap tr{padding:12px 16px;border-bottom:1px solid var(--border)}.table-wrap tr:last-child{border-bottom:0}.table-wrap td{border:0;padding:3px 0;text-align:left!important}
 .table-wrap td[data-label]:not([data-label=""])::before{content:attr(data-label);display:block;font-size:12px;color:var(--muted);font-weight:600}}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1.5px solid var(--brand);background:var(--brand);color:#fff;border-radius:12px;padding:9px 16px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;min-height:42px;transition:background .12s,opacity .12s}
.btn:hover{background:var(--brand-hover);border-color:var(--brand-hover)}.btn.ghost{background:var(--surface);color:var(--ink);border-color:var(--border)}.btn.ghost:hover{background:var(--surface-2)}
.btn.ok{background:var(--ok);border-color:var(--ok)}.btn.danger{background:var(--surface);color:var(--danger);border-color:var(--danger)}.btn.danger:hover{background:var(--danger);color:#fff}
.btn.big{width:100%;min-height:54px;font-size:17px;border-radius:14px}.btn.sm{min-height:34px;padding:5px 12px;font-size:14px}
.btn[disabled]{opacity:.65;cursor:progress}.spin{width:16px;height:16px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .7s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
form.inline{display:inline}.actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:13px;font-weight:600;background:var(--surface-2);color:var(--muted);border:1px solid var(--border);white-space:nowrap}
.badge.ok{background:var(--ok-soft);color:var(--ok);border-color:transparent}.badge.warn{background:var(--brand-soft);color:var(--brand-ink);border-color:transparent}.badge.danger{background:var(--danger-soft);color:var(--danger);border-color:transparent}
label{display:block;font-weight:600;font-size:15px;margin:0 0 6px}
input,select,textarea{font:inherit;font-size:16px;color:var(--ink);background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:11px 14px;width:100%;min-height:48px;transition:border-color .12s,box-shadow .12s}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-soft)}
input[type=checkbox]{width:20px;height:20px;min-height:0;accent-color:var(--brand);margin:0;flex-shrink:0}input[type=file]{padding:9px}
.check{display:flex;gap:10px;align-items:center;font-weight:500;cursor:pointer;margin:0}
.form{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr))}.form .full{grid-column:1/-1}.hint{font-size:13px;color:var(--muted);margin-top:4px}
.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:20px}.filters>div{flex:1 1 180px}
.pager{display:flex;gap:10px;justify-content:flex-end;margin-top:4px}
img.photo{width:100%;max-height:360px;object-fit:cover;border-radius:14px;border:1px solid var(--border);background:var(--surface-2)}
.photos{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(170px,1fr))}.photos figure{margin:0}.photos figcaption{font-size:13px;color:var(--muted);margin-top:6px}
.photos a img{width:100%;height:170px;object-fit:cover;border-radius:12px;border:1px solid var(--border);display:block}
dl{display:grid;grid-template-columns:minmax(110px,auto) 1fr;gap:10px 18px;margin:0}dt{color:var(--muted)}dd{margin:0;font-weight:500}
.note{background:var(--surface-2);border:1px dashed var(--border);border-radius:12px;padding:12px 14px;color:var(--muted);font-size:14px;margin:0 0 16px}
.empty{padding:28px;text-align:center;color:var(--muted)}
.auth{min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:radial-gradient(1200px 500px at 50% -10%,var(--brand-soft),transparent),var(--bg)}
.auth-box{width:100%;max-width:440px}.auth .logo{display:block;text-align:center;font-size:42px;color:var(--brand);margin-bottom:2px}.auth .logo small{color:var(--ink);font-size:22px}
.auth .tag{text-align:center;color:var(--muted);margin:0 0 24px}.auth .card{padding:30px}.auth h1{font-size:24px}
.auth .links{text-align:center;margin-top:18px}.auth .links a{font-weight:600;text-decoration:none}
#toasts{position:fixed;right:16px;top:84px;z-index:90;display:flex;flex-direction:column;gap:10px;max-width:min(420px,calc(100vw - 32px))}
.toast{display:flex;gap:12px;align-items:flex-start;background:var(--surface);color:var(--ink);border:1px solid var(--border);border-left:5px solid var(--ok);border-radius:14px;padding:14px 16px;box-shadow:0 12px 32px rgba(0,0,0,.16);animation:in .2s ease-out}
.toast.err{border-left-color:var(--danger)}.toast.info{border-left-color:var(--brand)}.toast .ic{font-size:18px;line-height:1.3}.toast .x{margin-left:auto;background:none;border:0;color:var(--muted);font-size:22px;cursor:pointer;line-height:1;padding:0 2px}
.toast.out{opacity:0;transform:translateY(-6px);transition:all .25s}
.toast{position:relative;overflow:hidden;cursor:pointer}.toast::after{content:"";position:absolute;left:0;bottom:0;height:3px;width:100%;background:currentColor;opacity:.25;transform-origin:left;animation:countdown 4s linear forwards}
@keyframes countdown{to{transform:scaleX(0)}}
@keyframes in{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.modal{position:fixed;inset:0;z-index:100;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(12,10,9,.55);backdrop-filter:blur(2px)}
.modal.open{display:flex}.dialog{background:var(--surface);color:var(--ink);border-radius:20px;max-width:440px;width:100%;padding:26px;box-shadow:0 24px 64px rgba(0,0,0,.3);animation:in .18s ease-out}
.dialog .icon{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--brand-soft);color:var(--brand);font-size:24px;font-weight:800;margin-bottom:14px}
.dialog.danger .icon{background:var(--danger-soft);color:var(--danger)}.dialog h3{font-size:20px;margin:0 0 8px}.dialog p{color:var(--muted);margin:0 0 22px}
.dialog .actions{justify-content:flex-end}.dialog.danger .btn.go{background:var(--danger);border-color:var(--danger);color:#fff}
#live{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:80;display:none}#live.show{display:flex}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body data-pulse="<?= $user === null ? '' : View::url('/pro/pulse') ?>" data-version="<?= $user === null ? '' : View::e(Live::version()) ?>">
<?php if ($user === null): ?>
<div class="auth"><div class="auth-box">
  <a class="logo" href="<?= View::url('/pro') ?>">axi<small>pro</small></a>
  <p class="tag">L'espace des partenaires d'Axi</p>
  <?= $content ?>
</div></div>
<?php else: ?>
<header class="bar" id="bar">
  <div class="bar-in">
    <a class="logo" href="<?= View::url('/pro') ?>">axi<small>pro</small></a>
    <button class="burger" type="button" aria-label="Ouvrir le menu" aria-expanded="false" data-toggle="bar">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <nav class="nav" aria-label="Menu principal">
      <?php foreach (WebAuth::MENUS as [$href, $label, $types]): if (!in_array($user['partner_type'], $types, true)) { continue; } ?>
        <a href="<?= View::url($href) ?>" class="<?= $section === $href ? 'on' : '' ?>"><?= View::e($label) ?></a>
      <?php endforeach; ?>
      <?php if ($isAxi): ?>
      <div class="drop">
        <button type="button" class="<?= $manageOn ? 'on' : '' ?>" data-drop aria-haspopup="true">Gestion<?php if ($manageCount > 0): ?> <span class="pill"><?= $manageCount ?></span><?php endif; ?> ▾</button>
        <div class="menu" role="menu">
          <?php foreach (WebAuth::MANAGE as [$href, $label]): $n = $badgeFor[$href] ?? 0; ?>
            <a role="menuitem" href="<?= View::url($href) ?>" class="<?= $section === $href ? 'on' : '' ?>"><span><?= View::e($label) ?></span><?php if ($n > 0): ?><span class="pill"><?= $n ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="drop">
        <button type="button" data-drop aria-haspopup="true" aria-label="Mon compte"><span class="avatar"><?= View::e($initials) ?></span></button>
        <div class="menu" role="menu">
          <div class="who"><strong><?= View::e($user['name']) ?></strong><?= View::e(Users::PARTNER_TYPES[$user['partner_type']] . ' · ' . $user['org_name']) ?></div>
          <hr>
          <a role="menuitem" href="<?= View::url('/pro/mon-compte') ?>">Mon compte</a>
          <form method="post" action="<?= View::url('/pro/deconnexion') ?>" data-confirm="Vous allez quitter l'espace partenaire. Il faudra saisir à nouveau votre mot de passe." data-confirm-title="Se déconnecter ?" data-confirm-ok="Se déconnecter">
            <?= View::csrf() ?><button type="submit" role="menuitem">Se déconnecter</button>
          </form>
        </div>
      </div>
    </nav>
  </div>
</header>
<main id="main">
  <?php if ($tabs !== null && count($tabs) > 1): ?>
  <nav class="tabs" aria-label="Sous-rubriques">
    <?php foreach ($tabs as [$href, $label]): ?><a href="<?= View::url($href) ?>" class="<?= ($path === $href || str_starts_with($path, $href . '/')) ? 'on' : '' ?>"><?= View::e($label) ?></a><?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <?= $content ?>
</main>
<div id="live"><button class="btn" type="button" data-live-reload>Nouvelles données · Actualiser</button></div>
<?php endif; ?>

<div id="toasts" aria-live="polite"><?php if ($flash): ?><div class="toast <?= View::e($flash['type']) ?>" data-auto><span class="ic"><?= $flash['type'] === 'err' ? '⚠️' : '✅' ?></span><div><?= View::e($flash['message']) ?></div><button class="x" type="button" aria-label="Fermer">×</button></div><?php endif; ?></div>

<div class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="dialog" id="dialog">
    <div class="icon" id="modal-icon">?</div>
    <h3 id="modal-title"></h3>
    <p id="modal-text"></p>
    <div class="actions"><button class="btn ghost" type="button" data-modal="no">Annuler</button><button class="btn go" type="button" data-modal="yes">Confirmer</button></div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* Notifications (au lieu des alertes du navigateur) */
  function close(t) { t.classList.add('out'); setTimeout(function () { t.remove(); }, 260); }
  /* Chaque notification disparaît seule après 4 secondes (barre de temps en bas), ou dès qu'on la touche */
  function autoClose(t) { setTimeout(function () { close(t); }, 4000); }
  function toast(message, type) {
    var t = document.createElement('div');
    t.className = 'toast ' + (type || 'ok');
    t.innerHTML = '<span class="ic">' + (type === 'err' ? '⚠️' : type === 'info' ? 'ℹ️' : '✅') + '</span><div></div><button class="x" type="button" aria-label="Fermer">×</button>';
    t.children[1].textContent = message;
    $('#toasts').appendChild(t);
    autoClose(t);
  }
  $$('.toast[data-auto]').forEach(autoClose);

  /* Fenêtre de confirmation (au lieu de confirm()) */
  var modal = $('#modal'), answer = null, lastFocus = null;
  function ask(title, text, okLabel, danger) {
    return new Promise(function (resolve) {
      $('#modal-title').textContent = title;
      $('#modal-text').textContent = text;
      $('[data-modal=yes]').textContent = okLabel || 'Confirmer';
      $('#dialog').className = 'dialog' + (danger ? ' danger' : '');
      $('#modal-icon').textContent = danger ? '!' : '?';
      lastFocus = document.activeElement;
      modal.classList.add('open');
      $('[data-modal=yes]').focus();
      answer = function (ok) { modal.classList.remove('open'); answer = null; if (lastFocus && lastFocus.focus) lastFocus.focus(); resolve(ok); };
    });
  }
  modal.addEventListener('click', function (e) {
    if (!answer) return;
    if (e.target === modal || e.target.dataset.modal === 'no') answer(false);
    else if (e.target.dataset.modal === 'yes') answer(true);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && answer) answer(false); });

  /* Formulaires : confirmation si demandée, bouton bloqué pendant l'envoi (jamais deux envois) */
  document.addEventListener('submit', function (e) {
    var f = e.target, btn = e.submitter;
    if (f.dataset.confirm && f.dataset.confirmed !== '1') {
      e.preventDefault();
      ask(f.dataset.confirmTitle || 'Êtes-vous sûr ?', f.dataset.confirm, f.dataset.confirmOk, f.dataset.tone === 'danger').then(function (ok) {
        if (!ok) return;
        f.dataset.confirmed = '1';
        if (f.requestSubmit) f.requestSubmit(btn || undefined); else f.submit();
      });
      return;
    }
    f.dataset.confirmed = '';
    if (f.method.toLowerCase() === 'post') {
      dirty = false;
      $$('button[type=submit]', f).forEach(function (b) { b.disabled = true; if (!btn || b === btn) b.insertAdjacentHTML('afterbegin', '<span class="spin"></span>'); });
    }
  });
  window.addEventListener('pageshow', function () { $$('button[disabled]').forEach(function (b) { b.disabled = false; var s = $('.spin', b); if (s) s.remove(); }); });

  /* Menus déroulants, menu mobile, fermeture des notifications */
  document.addEventListener('click', function (e) {
    var toastEl = e.target.closest('.toast');
    if (toastEl) close(toastEl);
    var t = e.target.closest('[data-drop]');
    $$('.drop.open').forEach(function (d) { if (!t || d !== t.parentNode) d.classList.remove('open'); });
    if (t) t.parentNode.classList.toggle('open');
    var b = e.target.closest('[data-toggle=bar]');
    if (b) { var open = $('#bar').classList.toggle('open'); b.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    if (e.target.closest('[data-live-reload]')) refresh(true);
  });

  /* « Afficher le mot de passe » */
  document.addEventListener('change', function (e) {
    if (!e.target.matches('[data-show-password]')) return;
    $$('input[data-password]', e.target.form || document).forEach(function (i) { i.type = e.target.checked ? 'text' : 'password'; });
  });

  /* Mise à jour en direct : la page se met à jour seule quand les données changent */
  var pulse = document.body.dataset.pulse, version = document.body.dataset.version, dirty = false, waiting = false;
  document.addEventListener('input', function (e) { if (e.target.form && e.target.form.method.toLowerCase() === 'post') dirty = true; });
  function busy() {
    var a = document.activeElement;
    return dirty || !!answer || $$('.drop.open').length > 0 || $('#bar').classList.contains('open') || (a && /INPUT|TEXTAREA|SELECT/.test(a.tagName));
  }
  function refresh(manual) {
    fetch(location.href, { credentials: 'same-origin' }).then(function (r) {
      if (r.redirected && /connexion/.test(r.url)) { location.href = r.url; return null; }
      return r.ok ? r.text() : null;
    }).then(function (html) {
      if (!html) return;
      var doc = new DOMParser().parseFromString(html, 'text/html'), main = doc.getElementById('main'), nav = doc.querySelector('.nav');
      if (!main) return;
      var y = window.scrollY;
      $('#main').replaceWith(main);
      if (nav) $('.nav').innerHTML = nav.innerHTML;
      window.scrollTo(0, y);
      version = doc.body.dataset.version || version;
      dirty = false; waiting = false; $('#live').classList.remove('show');
      if (!manual) toast('Page mise à jour : de nouvelles informations sont arrivées.', 'info');
    }).catch(function () {});
  }
  if (pulse) {
    setInterval(function () {
      if (document.hidden) return;
      fetch(pulse, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
        if (r.status === 401) { location.reload(); return null; }
        return r.ok ? r.json() : null;
      }).then(function (j) {
        if (!j || j.v === version) return;
        version = j.v;
        if (busy()) {
          if (!waiting) {
            waiting = true;
            $('#live').classList.add('show');
            setTimeout(function () { $('#live').classList.remove('show'); waiting = false; }, 15000);
          }
          return;
        }
        refresh(false);
      }).catch(function () {});
    }, 8000);
  }
})();
</script>
</body>
</html>
