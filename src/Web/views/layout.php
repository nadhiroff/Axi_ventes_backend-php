<?php
use Axi\Support\Users;
use Axi\Web\Html;
use Axi\Web\Live;
use Axi\Web\View;
use Axi\Web\WebAuth;

$path = '/' . trim((string) preg_replace('#^.*?/pro#', '/pro', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
$section = WebAuth::section($path);
$isAxi = $user !== null && $user['partner_type'] === 'axi';
$counts = $isAxi ? Live::counts() : ['applications' => 0, 'disputes' => 0, 'visits' => 0, 'proposals' => 0, 'reports' => 0, 'credits' => 0, 'campaigns' => 0];
$badgeFor = ['/pro/campagnes' => (int) $counts['campaigns'], '/pro/credits' => (int) $counts['credits'], '/pro/demandes' => (int) $counts['applications'], '/pro/commandes' => (int) $counts['disputes'], '/pro/commercants' => (int) $counts['visits'], '/pro/allees' => (int) $counts['proposals'], '/pro/signalements' => (int) $counts['reports']];
$navIcons = ['/pro' => 'home', '/pro/prix' => 'trend', '/pro/campagnes' => 'megaphone', '/pro/recensement' => 'map', '/pro/credits' => 'wallet', '/pro/demandes' => 'inbox', '/pro/commercants' => 'store', '/pro/clients' => 'users', '/pro/signalements' => 'flag', '/pro/commandes' => 'package', '/pro/paiements' => 'card', '/pro/allees' => 'grid', '/pro/partenaires' => 'handshake', '/pro/reglages' => 'settings', '/pro/journal' => 'list'];
$tabs = null;
foreach (WebAuth::TABS as $group) {
    if ($group[0][0] === $section && $user !== null) {
        $tabs = array_values(array_filter($group, static fn (array $t): bool => in_array($user['partner_type'], $t[2], true)));
    }
}
// Sous-page (/pro/rubrique/…) : bouton Retour vers sa rubrique, vers son libellé du menu quand il existe
$segments = explode('/', trim($path, '/'));
$backHref = $user !== null && count($segments) >= 3 ? '/pro/' . $segments[1] : null;
$backLabel = 'Retour';
foreach ([...WebAuth::MENUS, ...WebAuth::MANAGE] as $entry) {
    if ($entry[0] === $backHref) {
        $backLabel = (string) preg_replace('/ et .*$/', '', $entry[1]);
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
<!-- Logo de l'onglet : SVG intégré à la page (aucun fichier à charger, carré orange et « a » blanc) -->
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23C2410C'/%3E%3Ccircle cx='29' cy='34' r='12' fill='none' stroke='%23fff' stroke-width='8'/%3E%3Crect x='37' y='20' width='8' height='26' rx='2' fill='%23fff'/%3E%3C/svg%3E">
<style>
:root{--brand:#C2410C;--brand-hover:#9A3412;--brand-soft:#FDEBDD;--brand-ink:#9A3412;--logo:#FB923C;--gold:#D97706;--gold-soft:#FEF3C7;--ok:#166534;--ok-soft:#DCFCE7;--info:#1D4ED8;--info-soft:#DBEAFE;--plum:#7C3AED;--plum-soft:#EDE9FE;--danger:#B91C1C;--danger-soft:#FEE2E2;
--ink:#1F1410;--muted:#6F625A;--bg:#F8F3EC;--surface:#FFFFFF;--surface-2:#FBF7F1;--border:#EDE3D8;--side:#26160F;--side-2:#3A2115;--side-ink:#E9DDD3;--side-on:#C2410C;--shadow:0 1px 2px rgba(60,30,10,.05),0 10px 28px rgba(60,30,10,.07);--radius:20px;
--hero:linear-gradient(120deg,#9A3412 0%,#C2410C 52%,#EA580C 100%);--wax:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120' viewBox='0 0 120 120' fill='none' stroke='%23fff' stroke-width='2'%3E%3Cpath d='M60 6 114 60 60 114 6 60Z'/%3E%3Cpath d='M60 26 94 60 60 94 26 60Z'/%3E%3Ccircle cx='60' cy='60' r='12'/%3E%3Ccircle cx='60' cy='60' r='3' fill='%23fff'/%3E%3Ccircle cx='0' cy='0' r='16'/%3E%3Ccircle cx='120' cy='0' r='16'/%3E%3Ccircle cx='0' cy='120' r='16'/%3E%3Ccircle cx='120' cy='120' r='16'/%3E%3C/svg%3E")}
@media (prefers-color-scheme:dark){:root{--brand:#EA580C;--brand-hover:#F97316;--brand-soft:#3B2417;--brand-ink:#FDBA74;--gold:#FBBF24;--gold-soft:#3A2C10;--ok:#4ADE80;--ok-soft:#14301F;--info:#93C5FD;--info-soft:#172A4A;--plum:#C4B5FD;--plum-soft:#2C2147;--danger:#F87171;--danger-soft:#3A1616;
--ink:#F7EFE8;--muted:#B5A79C;--bg:#1C1410;--surface:#281E18;--surface-2:#221914;--border:#3D2F26;--side:#150D09;--side-2:#2A1A12;--shadow:none}}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
body{margin:0;font:15px/1.55 Inter,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
a{color:var(--brand)}h1{font-size:clamp(25px,3.2vw,34px);line-height:1.18;margin:0 0 6px;letter-spacing:-.02em;font-weight:800}h2{font-size:19px;margin:0 0 14px}h3{font-size:16px;margin:0 0 8px}
.sub{color:var(--muted);margin:0 0 24px;max-width:760px;font-size:16px}.muted{color:var(--muted)}.small{font-size:13px}
.shell{display:flex;min-height:100vh}.page{flex:1;min-width:0;display:flex;flex-direction:column}
.side{width:268px;flex-shrink:0;position:sticky;top:0;height:100vh;display:flex;flex-direction:column;background:var(--side);background-image:linear-gradient(180deg,var(--side) 60%,var(--side-2));color:var(--side-ink);z-index:40}
.logo{display:inline-block;font-size:30px;font-weight:800;color:var(--logo);text-decoration:none;letter-spacing:-.03em;white-space:nowrap;line-height:1}.logo small{color:#fff;font-size:16px;font-weight:600;margin-left:6px;letter-spacing:0}
.side-head{padding:26px 24px 18px}.side-nav{flex:1;overflow-y:auto;padding:4px 14px 14px;scrollbar-width:none}.side-nav::-webkit-scrollbar{display:none}
.side-label{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#A08C7E;margin:20px 12px 8px}
.side-nav a{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:12px;color:var(--side-ink);text-decoration:none;font-size:15px;font-weight:500;margin-bottom:2px;transition:background .15s,color .15s}
.side-nav a span:first-of-type{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.side-nav a svg{flex-shrink:0;opacity:.8}.side-nav a:hover{background:rgba(255,255,255,.07);color:#fff}
.side-nav a.on{background:var(--side-on);color:#fff;font-weight:600;box-shadow:0 6px 18px rgba(194,65,12,.4)}.side-nav a.on svg{opacity:1}
.side-user{border-top:1px solid rgba(255,255,255,.09);padding:14px;display:flex;align-items:center;gap:10px}.side-who{flex:1;min-width:0;text-decoration:none;color:inherit;display:flex;align-items:center;gap:10px;border-radius:12px;padding:4px}.side-who:hover{background:rgba(255,255,255,.07)}
.side-who strong{display:block;color:#fff;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.side-who small{display:block;color:#A08C7E;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.side-who>span:last-child{min-width:0}
.side-out{background:none;border:0;color:#A08C7E;cursor:pointer;width:40px;height:40px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center}.side-out:hover{background:rgba(255,255,255,.07);color:#fff}
.avatar{width:38px;height:38px;border-radius:50%;background:var(--brand);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0}
.pill{display:inline-flex;min-width:22px;height:22px;padding:0 7px;border-radius:999px;background:var(--logo);color:#26160F;font-size:12px;font-weight:800;align-items:center;justify-content:center}.side-nav a.on .pill{background:#fff;color:var(--brand)}
.top{display:none;align-items:center;justify-content:space-between;background:var(--side);padding:12px 16px;position:sticky;top:0;z-index:30}.burger{background:none;border:0;color:#fff;padding:8px;border-radius:10px;cursor:pointer;display:inline-flex}.scrim{display:none}
@media (max-width:1060px){.top{display:flex}.side{position:fixed;left:0;top:0;bottom:0;height:auto;transform:translateX(-100%);transition:transform .22s ease-out}.side.open{transform:none;box-shadow:0 0 48px rgba(0,0,0,.4)}
 .side.open~.scrim{display:block;position:fixed;inset:0;z-index:35;background:rgba(20,10,5,.5);border:0}}
main{width:100%;max-width:1320px;margin:0 auto;padding:32px clamp(16px,3vw,40px) 64px}
.hero{position:relative;overflow:hidden;background:var(--hero);color:#fff;border-radius:26px;padding:clamp(24px,3.4vw,38px) clamp(22px,3.4vw,42px);margin-bottom:24px;box-shadow:0 18px 40px rgba(194,65,12,.26)}
.hero::after{content:"";position:absolute;inset:0 0 0 45%;background-image:var(--wax);background-size:120px;opacity:.13;pointer-events:none;-webkit-mask-image:linear-gradient(90deg,transparent,#000 60%);mask-image:linear-gradient(90deg,transparent,#000 60%)}
.hero>*{position:relative;z-index:1}.hero-eyebrow{font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#FED7AA;margin:0 0 8px}.hero h1{color:#fff;font-size:clamp(26px,3.6vw,38px);margin:0 0 8px}.hero .sub{color:#FFEDD5;margin:0;max-width:620px}
.hero-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}.hero-actions a{display:inline-flex;align-items:center;gap:8px;background:#fff;color:#9A3412;text-decoration:none;font-weight:700;padding:10px 16px;border-radius:12px;min-height:44px}.hero-actions a.alt{background:rgba(255,255,255,.16);color:#fff}.hero-actions a:hover{transform:translateY(-1px)}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 24px}
.tabs a{padding:9px 16px;color:var(--muted);text-decoration:none;font-weight:600;border-radius:999px;background:var(--surface);border:1px solid var(--border)}.tabs a.on{color:#fff;background:var(--brand);border-color:var(--brand)}.tabs a:hover:not(.on){color:var(--ink);border-color:var(--brand)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;margin-bottom:20px;box-shadow:var(--shadow)}
.grid{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:24px}.grid.three>.kpi:nth-child(2),.grid.three>.kpi:nth-child(6){--c:var(--danger);--cs:var(--danger-soft)}
.kpi{margin:0;padding:22px;position:relative;overflow:hidden;--c:var(--brand);--cs:var(--brand-soft);transition:transform .15s,box-shadow .15s,border-color .15s}.grid>.kpi:nth-child(4n+2){--c:var(--ok);--cs:var(--ok-soft)}.grid>.kpi:nth-child(4n+3){--c:var(--gold);--cs:var(--gold-soft)}.grid>.kpi:nth-child(4n){--c:var(--plum);--cs:var(--plum-soft)}
.kpi .ic{width:44px;height:44px;border-radius:14px;background:var(--cs);color:var(--c);display:flex;align-items:center;justify-content:center;margin-bottom:14px}.kpi .l{color:var(--muted);font-size:14px;font-weight:600}.kpi .v{font-size:clamp(22px,2.3vw,32px);font-weight:800;letter-spacing:-.02em;margin:2px 0;line-height:1.2;white-space:nowrap}.kpi .s{color:var(--muted);font-size:13.5px}
a.kpi{text-decoration:none;color:inherit}a.kpi:hover{border-color:var(--c);transform:translateY(-2px)}a.kpi.alert{border-color:var(--c)}a.kpi.alert .v{color:var(--c)}a.kpi.alert::before{content:"";position:absolute;right:16px;top:16px;width:10px;height:10px;border-radius:50%;background:var(--c);box-shadow:0 0 0 4px var(--cs)}
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
th{font-size:12.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);white-space:nowrap;background:var(--surface-2)}tr:last-child td{border-bottom:0}td.num,th.num{text-align:right;white-space:nowrap}
tbody tr:hover{background:var(--surface-2)}td a{font-weight:600;text-decoration:none}td a:hover{text-decoration:underline}
@media (max-width:700px){.table-wrap table,.table-wrap tbody,.table-wrap tr,.table-wrap td{display:block;width:100%}.table-wrap thead{display:none}
 .table-wrap tr{padding:12px 16px;border-bottom:1px solid var(--border)}.table-wrap tr:last-child{border-bottom:0}.table-wrap td{border:0;padding:3px 0;text-align:left!important}
 .table-wrap td[data-label]:not([data-label=""])::before{content:attr(data-label);display:block;font-size:12px;color:var(--muted);font-weight:600}}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1.5px solid var(--brand);background:var(--brand);color:#fff;border-radius:12px;padding:9px 18px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;min-height:44px;transition:background .12s,opacity .12s,transform .12s}.btn:active{transform:translateY(1px)}a:focus-visible,button:focus-visible{outline:3px solid var(--logo);outline-offset:2px}
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
.form{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));align-items:end}.form .full{grid-column:1/-1}.form>div:has(input[name=title]){grid-column:1/-1}.form:not(:has(option[value=featured_stall]:checked))>div:has(select[name=target_stall_id]){display:none}details.new-campaign summary{cursor:pointer;font-size:19px;font-weight:700}details.new-campaign[open] summary{margin-bottom:12px}.hint{font-size:13px;color:var(--muted);margin-top:4px}
.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:20px}.filters>div{flex:1 1 180px}
.pager{display:flex;gap:10px;justify-content:flex-end;margin-top:4px}
img.photo{width:100%;max-height:360px;object-fit:cover;border-radius:14px;border:1px solid var(--border);background:var(--surface-2)}
.photos{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(170px,1fr))}.photos figure{margin:0}.photos figcaption{font-size:13px;color:var(--muted);margin-top:6px}
.photos a img{width:100%;height:170px;object-fit:cover;border-radius:12px;border:1px solid var(--border);display:block}
dl{display:grid;grid-template-columns:minmax(110px,auto) 1fr;gap:10px 18px;margin:0}dt{color:var(--muted)}dd{margin:0;font-weight:500}
.credits{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));align-items:start;margin-bottom:20px}
.credit{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;box-shadow:var(--shadow)}.credit header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.credit h3{font-size:17px;margin:0}.credit h3 a{text-decoration:none}.credit header p{margin:2px 0 0}
.credit-tags{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.credit-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 18px;margin:16px 0 0}.credit-facts dt{font-size:12.5px;font-weight:600}.credit-facts dd{font-weight:700}
.sheet-wrap{z-index:95}.sheet{position:relative;background:var(--bg);border-radius:22px;width:100%;max-width:860px;max-height:calc(100vh - 48px);overflow-y:auto;padding:26px;box-shadow:0 24px 64px rgba(0,0,0,.35);animation:in .18s ease-out;scrollbar-width:none}.sheet::-webkit-scrollbar{display:none}.sheet .row2{gap:16px}.sheet .card{margin-bottom:14px;padding:18px}.sheet h1{font-size:24px;padding-right:44px}.sheet .sub{margin-bottom:14px}.sheet-x{position:sticky;top:0;float:right;z-index:2;width:40px;height:40px;border-radius:50%;border:1px solid var(--border);background:var(--surface);color:var(--ink);display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
#loadbar{position:fixed;left:0;top:0;height:3px;width:0;background:var(--logo);z-index:120;opacity:0}body.loading #loadbar{opacity:1;width:80%;transition:width 1.2s ease-out}
.page-back{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:6px 14px 6px 10px;margin-bottom:16px;border-radius:999px;background:var(--surface);border:1px solid var(--border);color:var(--ink);font-weight:600;text-decoration:none;transition:border-color .12s}.page-back:hover{border-color:var(--brand)}.sheet .page-back{display:none}.ad-preview{display:block;max-width:360px;margin:0 0 16px}.ad-preview img,.ad-preview video{display:block;width:100%;max-height:220px;object-fit:cover;border-radius:14px;border:1px solid var(--border);background:#000}.ad-thumb{width:48px;height:48px;object-fit:cover;border-radius:10px;float:left;margin:2px 10px 0 0}
.credit-progress{margin-top:14px}.credit-bar{height:10px;border-radius:999px;background:var(--surface-2);border:1px solid var(--border);overflow:hidden;margin-bottom:6px}.credit-bar span{display:block;height:100%;background:var(--ok);border-radius:999px}.credit-note{background:var(--surface-2);border-radius:10px;padding:8px 12px;margin:14px 0 0;font-size:14px;color:var(--muted)}.credit-form{border-top:1px solid var(--border);margin-top:16px;padding-top:16px}.credit-form .form{gap:12px;grid-template-columns:repeat(2,minmax(0,1fr))}.credit-form .form>div:has(input[name=note]){grid-column:1/-1}.credit-form label{font-size:13.5px}
details.note summary{cursor:pointer;font-weight:600;color:var(--ink)}details.note p{margin:10px 0 0}
.note{background:var(--surface-2);border:1px dashed var(--border);border-radius:12px;padding:12px 14px;color:var(--muted);font-size:14px;margin:0 0 16px}
.empty{padding:28px;text-align:center;color:var(--muted)}
.auth{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);background:var(--bg)}
.auth-side{position:relative;overflow:hidden;background:var(--hero);color:#fff;padding:clamp(32px,5vw,64px);display:flex;flex-direction:column;justify-content:space-between;gap:32px}
.auth-side::after{content:"";position:absolute;inset:0;background-image:var(--wax);background-size:140px;opacity:.1;pointer-events:none}.auth-side>*{position:relative;z-index:1}
.auth-side .logo{font-size:40px;color:#fff}.auth-side .logo small{font-size:20px;color:#FFEDD5}.auth-side h2{font-size:clamp(32px,4.2vw,52px);line-height:1.08;letter-spacing:-.02em;margin:0 0 14px}.auth-side p{font-size:18px;color:#FFEDD5;margin:0;max-width:460px}
.auth-who{display:grid;gap:10px;margin:0;padding:0;list-style:none}.auth-who li{display:flex;gap:12px;align-items:center;background:rgba(255,255,255,.12);border-radius:14px;padding:12px 14px;font-weight:600}.auth-who li svg{flex-shrink:0}.auth-who small{display:block;font-weight:400;color:#FFEDD5;font-size:13.5px}
.auth-main{display:grid;place-items:center;padding:32px 20px}.auth-box{width:100%;max-width:420px}.auth .card{padding:0;background:none;border:0;box-shadow:none}.auth h1{font-size:30px}.auth .links{text-align:center;margin-top:18px}.auth .links a{font-weight:600;text-decoration:none}
@media (max-width:900px){.auth{grid-template-columns:1fr;grid-template-rows:auto 1fr}.auth-side{padding:24px 20px;gap:14px}.auth-side h2{font-size:26px;margin:0 0 6px}.auth-side p{font-size:15px}.auth-who{display:none}}
#toasts{position:fixed;right:16px;top:20px;z-index:90;display:flex;flex-direction:column;gap:10px;max-width:min(420px,calc(100vw - 32px))}
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
.popup>summary{list-style:none}.popup>summary::-webkit-details-marker{display:none}
.popup-wrap{position:fixed;inset:0;z-index:98;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(12,10,9,.55)}
.popup-box{background:var(--surface);color:var(--ink);text-align:left;border-radius:20px;padding:24px;width:100%;max-width:460px;max-height:calc(100vh - 32px);overflow-y:auto}.popup-box h3{font-size:20px;margin:0 0 6px}.popup-box p{margin:0 0 16px}.popup-box .actions{justify-content:flex-end}
#live{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:80;display:none}#live.show{display:flex}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body data-pulse="<?= $user === null ? '' : View::url('/pro/pulse') ?>" data-version="<?= $user === null ? '' : View::e(Live::version()) ?>">
<div id="loadbar"></div>
<?php if ($user === null): ?>
<div class="auth">
  <aside class="auth-side">
    <a class="logo" href="<?= View::url('/pro') ?>">axi<small>pro</small></a>
    <div>
      <h2>Le marché qui vient à vous.</h2>
      <p>L'espace de celles et ceux qui font vivre les marchés en ligne.</p>
    </div>
    <ul class="auth-who">
      <li><?= Html::icon('shield', 22) ?><span>Équipe Axi<small>Commerçants, litiges et versements</small></span></li>
      <li><?= Html::icon('map', 22) ?><span>Gestion des marchés<small>Recensement et suivi des étals</small></span></li>
      <li><?= Html::icon('megaphone', 22) ?><span>Annonceurs<small>Campagnes auprès des commerçants et des clients</small></span></li>
      <li><?= Html::icon('wallet', 22) ?><span>Institutions de financement<small>Dossiers partagés avec l'accord du commerçant</small></span></li>
    </ul>
  </aside>
  <div class="auth-main"><div class="auth-box"><?= $content ?></div></div>
</div>
<?php else: ?>
<div class="shell">
<aside class="side" id="bar">
  <div class="side-head"><a class="logo" href="<?= View::url('/pro') ?>">axi<small>pro</small></a></div>
  <nav class="side-nav" aria-label="Menu principal">
    <?php foreach (WebAuth::MENUS as [$href, $label, $types]): if (!in_array($user['partner_type'], $types, true)) { continue; } ?>
      <a href="<?= View::url($href) ?>" class="<?= $section === $href ? 'on' : '' ?>"<?= $section === $href ? ' aria-current="page"' : '' ?>><?= Html::icon($navIcons[$href] ?? 'grid') ?><span><?= View::e($label) ?></span><?php if (($badgeFor[$href] ?? 0) > 0): ?><span class="pill"><?= $badgeFor[$href] ?></span><?php endif; ?></a>
    <?php endforeach; ?>
    <?php if ($isAxi): ?>
      <div class="side-label">Gestion</div>
      <?php foreach (WebAuth::MANAGE as [$href, $label]): $n = $badgeFor[$href] ?? 0; ?>
        <a href="<?= View::url($href) ?>" class="<?= $section === $href ? 'on' : '' ?>"<?= $section === $href ? ' aria-current="page"' : '' ?>><?= Html::icon($navIcons[$href] ?? 'grid') ?><span><?= View::e($label) ?></span><?php if ($n > 0): ?><span class="pill"><?= $n ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
  </nav>
  <div class="side-user">
    <a class="side-who" href="<?= View::url('/pro/mon-compte') ?>" aria-label="Mon compte"><span class="avatar"><?= View::e($initials) ?></span><span><strong><?= View::e($user['name']) ?></strong><small><?= View::e(Users::PARTNER_TYPES[$user['partner_type']]) ?></small></span></a>
    <form method="post" action="<?= View::url('/pro/deconnexion') ?>" data-confirm="Vous allez quitter l'espace partenaire. Il faudra saisir à nouveau votre mot de passe." data-confirm-title="Se déconnecter ?" data-confirm-ok="Se déconnecter">
      <?= View::csrf() ?><button class="side-out" type="submit" aria-label="Se déconnecter" title="Se déconnecter"><?= Html::icon('logout') ?></button>
    </form>
  </div>
</aside>
<button class="scrim" type="button" data-toggle="bar" aria-label="Fermer le menu"></button>
<div class="page">
<header class="top">
  <a class="logo" href="<?= View::url('/pro') ?>">axi<small>pro</small></a>
  <button class="burger" type="button" aria-label="Ouvrir le menu" aria-expanded="false" data-toggle="bar"><?= Html::icon('menu', 24) ?></button>
</header>
<main id="main">
  <?php if ($backHref !== null): ?>
  <a class="page-back" href="<?= View::url($backHref) ?>"><?= Html::icon('back', 18) ?><span><?= View::e($backLabel) ?></span></a>
  <?php endif; ?>
  <?php if ($tabs !== null && count($tabs) > 1): ?>
  <nav class="tabs" aria-label="Sous-rubriques">
    <?php foreach ($tabs as [$href, $label]): ?><a href="<?= View::url($href) ?>" class="<?= ($path === $href || str_starts_with($path, $href . '/')) ? 'on' : '' ?>"><?= View::e($label) ?></a><?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <?= $content ?>
</main>
</div>
</div>
<div class="modal sheet-wrap" id="sheet" role="dialog" aria-modal="true" aria-label="Fiche"><div class="sheet"><button class="sheet-x" type="button" aria-label="Fermer"><?= Html::icon('close', 20) ?></button><div id="sheet-body"></div></div></div>
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
  /* Filtres sans bouton : la liste se recharge dès qu'un filtre change (petite attente pendant la saisie), le curseur reste dans le champ */
  var filterTimer = null;
  function applyFilters(f, field) {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(function () {
      try { sessionStorage.setItem('axi-filter-field', field.name); } catch (err) {}
      f.submit();
    }, field.tagName === 'SELECT' ? 0 : 500);
  }
  ['input', 'change'].forEach(function (type) {
    document.addEventListener(type, function (e) {
      var f = e.target.form;
      if (!f || !f.hasAttribute('data-auto-filter')) return;
      if (type === 'input' && (e.target.tagName === 'SELECT' || e.target.type === 'date')) return;
      applyFilters(f, e.target);
    });
  });
  document.addEventListener('keydown', function (e) {
    var f = e.target.form;
    if (e.key === 'Enter' && f && f.hasAttribute('data-auto-filter')) { e.preventDefault(); clearTimeout(filterTimer); f.submit(); }
  });
  try {
    var kept = sessionStorage.getItem('axi-filter-field');
    sessionStorage.removeItem('axi-filter-field');
    var keptField = kept && $('[data-auto-filter] [name="' + kept + '"]');
    if (keptField) { keptField.focus(); if (keptField.setSelectionRange && keptField.type === 'search') keptField.setSelectionRange(keptField.value.length, keptField.value.length); }
  } catch (err) {}

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
    return dirty || !!answer || $$('.drop.open, details.popup[open]').length > 0 || $('#bar').classList.contains('open') || (a && /INPUT|TEXTAREA|SELECT/.test(a.tagName));
  }
  /* Le menu garde sa position : son contenu change (rubrique active, compteurs), jamais son défilement */
  function swapNav(nav) {
    var side = $('.side-nav');
    if (!nav || !side) return;
    var top = side.scrollTop;
    side.innerHTML = nav.innerHTML;
    side.scrollTop = top;
  }

  /* Changer de rubrique sans recharger la page : seul le contenu de droite est remplacé.
     Barre de chargement en haut ; seul le dernier clic compte ; serveur trop lent : vraie navigation. */
  var navigation = null;
  function go(url, push) {
    if (navigation) navigation.abort();
    var current = navigation = new AbortController(), tooSlow = setTimeout(function () { location.href = url; }, 12000);
    document.body.classList.add('loading');
    function finished() { clearTimeout(tooSlow); if (current === navigation) { navigation = null; document.body.classList.remove('loading'); } }
    fetch(url, { credentials: 'same-origin', signal: current.signal }).then(function (r) {
      if (r.redirected && /connexion/.test(r.url)) { location.href = r.url; return null; }
      if (!r.ok) { location.href = url; return null; }
      return r.text();
    }).then(function (html) {
      if (!html) return;
      var doc = new DOMParser().parseFromString(html, 'text/html'), main = doc.getElementById('main');
      if (!main) { location.href = url; return; }
      $('#main').replaceWith(main);
      swapNav(doc.querySelector('.side-nav'));
      document.title = doc.title;
      version = doc.body.dataset.version || version;
      $$('.toast', doc).forEach(function (t) { $('#toasts').appendChild(t); autoClose(t); });
      if (push) history.pushState({ axi: true }, '', url);
      $('#bar').classList.remove('open');
      window.scrollTo(0, 0);
      finished();
    }).catch(function (err) {
      finished();
      if (err.name !== 'AbortError') location.href = url;
    });
  }

  /* Fiche ouverte dans une petite fenêtre, par-dessus la liste */
  var sheet = $('#sheet');
  function closeSheet() { if (sheet) { sheet.classList.remove('open'); $('#sheet-body').innerHTML = ''; } }
  function openSheet(url) {
    document.body.classList.add('loading');
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : null; }).then(function (html) {
      document.body.classList.remove('loading');
      var main = html && new DOMParser().parseFromString(html, 'text/html').getElementById('main');
      if (!main) { location.href = url; return; }
      $('#sheet-body').innerHTML = main.innerHTML;
      sheet.classList.add('open');
      $('.sheet-x').focus();
    }).catch(function () { location.href = url; });
  }
  document.addEventListener('click', function (e) {
    // Tout lien vers une fiche utilisateur (Utilisateurs, Signalements…) s'ouvre en petite fenêtre
    var a = e.target.closest('a[href]');
    if (a && /\/pro\/clients\/\d+$/.test(a.pathname) && !a.closest('.sheet') && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0) { e.preventDefault(); openSheet(a.href); return; }
    if (e.target.closest('.sheet-x') || e.target === sheet) closeSheet();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !answer) closeSheet(); });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('.side-nav a, .tabs a, .side-head a, a.page-back');
    if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    go(a.href, true);
  });
  window.addEventListener('popstate', function () { go(location.href, false); });

  /* Après l'envoi d'un formulaire (vraie recharge), le menu revient là où il était */
  (function () {
    var side = $('.side-nav');
    if (!side) return;
    try {
      var saved = sessionStorage.getItem('axi-side-top');
      if (saved) side.scrollTop = +saved;
      window.addEventListener('pagehide', function () { sessionStorage.setItem('axi-side-top', String(side.scrollTop)); });
    } catch (err) {}
  })();

  function refresh(manual) {
    var page = location.href;
    if (navigation) return;
    fetch(page, { credentials: 'same-origin' }).then(function (r) {
      if (r.redirected && /connexion/.test(r.url)) { location.href = r.url; return null; }
      return r.ok ? r.text() : null;
    }).then(function (html) {
      if (!html || page !== location.href || navigation) return;
      var doc = new DOMParser().parseFromString(html, 'text/html'), main = doc.getElementById('main'), nav = doc.querySelector('.side-nav');
      if (!main) return;
      var y = window.scrollY, changed = $('#main').innerHTML !== main.innerHTML;
      $('#main').replaceWith(main);
      swapNav(nav);
      window.scrollTo(0, y);
      version = doc.body.dataset.version || version;
      dirty = false; waiting = false; $('#live').classList.remove('show');
      /* Alerte seulement si ce qui est affiché a vraiment changé */
      if (!manual && changed) toast('Page mise à jour : de nouvelles informations sont arrivées.', 'info');
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
