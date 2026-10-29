<?php
use Axi\Web\View;
use Axi\Web\WebAuth;
use Axi\Support\Users;

$path = '/' . trim((string) preg_replace('#^.*?/pro#', '/pro', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e(($title ?? 'Axi Pro') . ' · axi pro') ?></title>
<style>
:root{--brand:#C2410C;--brand-dark:#9A3412;--brand-soft:#FDEBDD;--trust:#166534;--trust-soft:#DCFCE7;--ink:#1C1917;--muted:#6B6460;--bg:#F3F1EE;--surface:#fff;--border:#E7E2DC;--danger:#B91C1C;--danger-soft:#FEE2E2}
@media (prefers-color-scheme:dark){:root{--brand:#EA580C;--brand-dark:#FB923C;--brand-soft:#3B2417;--trust:#4ADE80;--trust-soft:#14301F;--ink:#F5F5F4;--muted:#A8A29E;--bg:#141210;--surface:#1F1C19;--border:#36312C;--danger:#F87171;--danger-soft:#3A1616}}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{color:var(--brand)}h1{font-size:24px;margin:0 0 4px}h2{font-size:18px;margin:24px 0 10px}.sub{color:var(--muted);margin:0 0 20px}
.app{display:flex;min-height:100vh}.side{width:250px;flex-shrink:0;background:var(--surface);border-right:1px solid var(--border);padding:20px 14px;position:sticky;top:0;height:100vh;overflow:auto}
.logo{font-size:28px;font-weight:800;color:var(--brand);text-decoration:none;display:block;margin:0 8px 2px}.logo small{font-size:14px;color:var(--muted);font-weight:600}
.who{margin:6px 8px 16px;font-size:13px;color:var(--muted)}.nav a{display:block;padding:9px 10px;border-radius:10px;color:var(--ink);text-decoration:none;margin:1px 0}
.nav a:hover{background:var(--bg)}.nav a.on{background:var(--brand-soft);color:var(--brand-dark);font-weight:600}
main{flex:1;min-width:0;padding:28px clamp(16px,3vw,40px);max-width:1280px}
.menu-toggle{display:none}
@media (max-width:900px){.app{display:block}.side{position:static;width:auto;height:auto;border-right:0;border-bottom:1px solid var(--border);padding:12px 16px}
 .side .nav,.side .who,.side form{display:none}.side.open .nav,.side.open .who,.side.open form{display:block}
 .menu-toggle{display:inline-flex;float:right;margin-top:2px}main{padding:20px 16px}}
.card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:18px;margin-bottom:16px}
.grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr))}.kpi .v{font-size:26px;font-weight:800}.kpi .l{color:var(--muted);font-size:13px}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--border);border-radius:14px;background:var(--surface)}
table{border-collapse:collapse;width:100%;min-width:640px}th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);background:var(--bg)}tr:last-child td{border-bottom:0}td.num,th.num{text-align:right;white-space:nowrap}
.btn{display:inline-flex;align-items:center;gap:6px;border:1.5px solid var(--brand);background:var(--brand);color:#fff;border-radius:10px;padding:8px 14px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;min-height:40px}
.btn.ghost{background:var(--surface);color:var(--ink);border-color:var(--border)}.btn.ok{background:var(--trust);border-color:var(--trust)}.btn.danger{background:var(--danger);border-color:var(--danger)}
.btn:hover{filter:brightness(.95)}form.inline{display:inline}.actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;background:var(--bg);color:var(--muted);white-space:nowrap}
.badge.ok{background:var(--trust-soft);color:var(--trust)}.badge.warn{background:var(--brand-soft);color:var(--brand-dark)}.badge.danger{background:var(--danger-soft);color:var(--danger)}
label{display:block;font-weight:600;font-size:13px;margin:0 0 4px}input,select,textarea{font:inherit;color:var(--ink);background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:9px 11px;width:100%;min-height:42px}
.form{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}.form .full{grid-column:1/-1}
.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:14px}.filters>div{flex:1 1 160px}
.flash{padding:12px 16px;border-radius:12px;margin-bottom:16px;font-weight:600}.flash.ok{background:var(--trust-soft);color:var(--trust)}.flash.err{background:var(--danger-soft);color:var(--danger)}
.pager{display:flex;gap:10px;justify-content:flex-end;margin-top:12px}.muted{color:var(--muted)}img.photo{max-width:100%;border-radius:12px;border:1px solid var(--border)}
.row2{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:0}dt{color:var(--muted)}dd{margin:0}
.login{max-width:420px;margin:8vh auto;padding:0 16px}.login .card{padding:26px}
</style>
</head>
<body>
<?php if ($user === null): ?>
<div class="login">
  <a class="logo" href="<?= View::url('/pro') ?>">axi <small>pro</small></a>
  <p class="sub" style="margin:4px 8px 18px">Espace web des partenaires</p>
  <?php if ($flash): ?><div class="flash <?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div><?php endif; ?>
  <?= $content ?>
</div>
<?php else: ?>
<div class="app">
  <aside class="side" id="side">
    <button type="button" class="btn ghost menu-toggle" onclick="document.getElementById('side').classList.toggle('open')" aria-label="Menu">Menu</button>
    <a class="logo" href="<?= View::url('/pro') ?>">axi <small>pro</small></a>
    <div class="who"><?= View::e($user['name']) ?><br><?= View::e(Users::PARTNER_TYPES[$user['partner_type']]) ?> · <?= View::e($user['org_name']) ?></div>
    <nav class="nav">
      <?php foreach (WebAuth::MENUS as [$href, $label, $types]): if (!in_array($user['partner_type'], $types, true)) { continue; } ?>
        <a href="<?= View::url($href) ?>" class="<?= ($href === '/pro' ? $path === '/pro' : str_starts_with($path, $href)) ? 'on' : '' ?>"><?= View::e($label) ?></a>
      <?php endforeach; ?>
      <a href="<?= View::url('/pro/mon-compte') ?>" class="<?= str_starts_with($path, '/pro/mon-compte') ? 'on' : '' ?>">Mon compte</a>
    </nav>
    <div style="margin:14px 8px 0"><?= View::action('/pro/deconnexion', 'Se déconnecter', [], 'Êtes-vous sûr de vouloir vous déconnecter ?', 'btn ghost') ?></div>
  </aside>
  <main>
    <?php if ($flash): ?><div class="flash <?= View::e($flash['type']) ?>"><?= View::e($flash['message']) ?></div><?php endif; ?>
    <?= $content ?>
  </main>
</div>
<?php endif; ?>
</body>
</html>
