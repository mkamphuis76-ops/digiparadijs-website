<?php
/*
 * Digi Paradijs – statistiekenpagina, beveiligd met een wachtwoord.
 * Het wachtwoord staat niet in de code: bij het eerste bezoek stel je het zelf in.
 * Het wordt alleen versleuteld (als hash) opgeslagen, buiten de website.
 */
require dirname(__DIR__) . '/stats-lib.php';
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('dpstat');
session_start();

$cfg = dp_config();
$err = '';
$csrf = $_SESSION['csrf'] ?? ($_SESSION['csrf'] = bin2hex(random_bytes(16)));

// Inlogpogingen beperken: 5 per 15 minuten
$lockFile = dp_stats_dir() . '/login-' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $cfg['salt']) . '.json';
$tries = is_readable($lockFile) ? (json_decode((string) @file_get_contents($lockFile), true) ?: []) : [];
$tries = array_values(array_filter($tries, function ($t) { return $t > time() - 900; }));
$locked = count($tries) >= 5;

if (isset($_GET['uit'])) { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
    $pw = (string) ($_POST['pw'] ?? '');
    if (empty($cfg['pw'])) {
        // Eerste keer: wachtwoord instellen
        if (strlen($pw) < 8) { $err = 'Kies een wachtwoord van minimaal 8 tekens.'; }
        elseif ($pw !== (string) ($_POST['pw2'] ?? '')) { $err = 'De twee wachtwoorden zijn niet gelijk.'; }
        else { $cfg['pw'] = password_hash($pw, PASSWORD_DEFAULT); dp_save_config($cfg); session_regenerate_id(true); $_SESSION['ok'] = true; header('Location: ./'); exit; }
    } elseif ($locked) {
        $err = 'Te veel pogingen. Probeer het over een kwartier opnieuw.';
    } elseif (password_verify($pw, $cfg['pw'])) {
        @unlink($lockFile); session_regenerate_id(true); $_SESSION['ok'] = true; header('Location: ./'); exit;
    } else {
        $tries[] = time(); @file_put_contents($lockFile, json_encode($tries), LOCK_EX);
        $err = 'Dat wachtwoord klopt niet.';
    }
}

$loggedIn = !empty($_SESSION['ok']) && !empty($cfg['pw']);
function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function nl($n) { return number_format((float) $n, 0, ',', '.'); }

// ---------- Gegevens lezen ----------
$days = 30;
$rows = [];
if ($loggedIn) {
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    foreach (array_unique([date('Y-m', strtotime($from)), date('Y-m')]) as $m) {
        $f = dp_stats_dir() . '/' . $m . '.jsonl';
        if (!is_readable($f)) { continue; }
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if ($r && $r['d'] >= $from) { $rows[] = $r; }
        }
    }
}

$today = date('Y-m-d'); $wk = date('Y-m-d', strtotime('-6 days'));
$perDay = []; for ($i = $days - 1; $i >= 0; $i--) { $perDay[date('Y-m-d', strtotime("-$i days"))] = ['v' => [], 'p' => 0]; }
$uniq = ['today' => [], 'week' => [], 'month' => []]; $views = ['today' => 0, 'week' => 0, 'month' => 0];
$refs = []; $mobile = 0; $desktop = 0; $events = ['calc' => 0, 'offerte' => 0, 'aanvraag' => 0, 'cloud86' => 0, 'case' => 0, 'mail' => 0];
$cases = []; $hours = array_fill(0, 24, 0);
foreach ($rows as $r) {
    if ($r['t'] === 'view') {
        $views['month']++; $uniq['month'][$r['v']] = 1;
        if ($r['d'] >= $wk) { $views['week']++; $uniq['week'][$r['v']] = 1; }
        if ($r['d'] === $today) { $views['today']++; $uniq['today'][$r['v']] = 1; }
        if (isset($perDay[$r['d']])) { $perDay[$r['d']]['p']++; $perDay[$r['d']]['v'][$r['v']] = 1; }
        $refs[!empty($r['r']) ? $r['r'] : 'Direct of onbekend'] = ($refs[!empty($r['r']) ? $r['r'] : 'Direct of onbekend'] ?? 0) + 1;
        if (!empty($r['m'])) { $mobile++; } else { $desktop++; }
        $hours[(int) $r['h']]++;
    } elseif (isset($events[$r['t']])) {
        $events[$r['t']]++;
        if ($r['t'] === 'case' && !empty($r['n'])) { $cases[$r['n']] = ($cases[$r['n']] ?? 0) + 1; }
    }
}
arsort($refs); arsort($cases);
$maxDay = max(1, max(array_map(function ($d) { return count($d['v']); }, $perDay)));
$totalDev = max(1, $mobile + $desktop);
$conv = count($uniq['month']) ? round($events['aanvraag'] / count($uniq['month']) * 100, 1) : 0;
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Statistieken · Digi Paradijs</title>
<link rel="icon" href="../favicon.svg" type="image/svg+xml">
<style>
:root{--paper:#F7F5F0;--card:#fff;--ink:#102A2F;--mute:#55686A;--line:#DFE6E3;--petrol:#0E3B43;--sea:#14A3A0;--sea-ink:#0B7B78;--coral:#FF7A59;color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.wrap{max-width:1100px;margin:0 auto;padding:24px max(16px,env(safe-area-inset-left,0px)) 48px}
header{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:24px}
header img{height:34px;width:auto;display:block}
h1{font-size:1.6rem;margin:0 0 4px}
.sub{color:var(--mute);margin:0 0 24px}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}
@media (max-width:820px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px 20px;min-width:0}
.k{font-size:.85rem;color:var(--mute);font-weight:600}
.n{font-size:2rem;font-weight:800;line-height:1.1;margin-top:4px;font-variant-numeric:tabular-nums}
.n small{font-size:.9rem;color:var(--mute);font-weight:600}
.two{display:grid;grid-template-columns:1.4fr 1fr;gap:14px;margin-bottom:14px}
@media (max-width:820px){.two{grid-template-columns:1fr}}
h2{font-size:1.05rem;margin:0 0 14px}
.chart{display:flex;align-items:flex-end;gap:3px;height:170px;border-bottom:1px solid var(--line)}
.bar{flex:1;background:var(--sea);border-radius:4px 4px 0 0;min-height:2px;position:relative}
.bar.today{background:var(--coral)}
.axis{display:flex;justify-content:space-between;color:var(--mute);font-size:.8rem;margin-top:6px}
table{width:100%;border-collapse:collapse;font-size:.95rem}
td{padding:8px 0;border-bottom:1px solid var(--line)}
td:last-child{text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
tr:last-child td{border-bottom:0}
.split{display:flex;height:14px;border-radius:7px;overflow:hidden;margin:8px 0}
.split i{display:block;height:100%}
.legend{display:flex;justify-content:space-between;font-size:.9rem;color:var(--mute)}
.hours{display:flex;align-items:flex-end;gap:2px;height:70px}
.hours i{flex:1;background:#BFE3E1;border-radius:3px 3px 0 0;min-height:2px}
.btn{display:inline-block;background:var(--coral);color:var(--petrol);font-weight:800;border:0;border-radius:999px;padding:12px 22px;font-size:1rem;cursor:pointer;text-decoration:none}
.link{color:var(--sea-ink);font-weight:700}
.login{max-width:380px;margin:12vh auto 0;background:var(--card);border:1px solid var(--line);border-radius:22px;padding:28px;display:grid;gap:14px}
.login input{font:inherit;padding:12px 14px;border:2px solid var(--line);border-radius:12px;width:100%}
.login input:focus{border-color:var(--sea);outline:none}
.err{background:#FDECEA;color:#9B2C1F;border-radius:12px;padding:10px 12px;font-weight:600}
.empty{color:var(--mute)}
.note{color:var(--mute);font-size:.85rem;margin-top:18px}
:focus-visible{outline:3px solid var(--coral);outline-offset:2px}
</style>
</head>
<body>
<?php if (!$loggedIn): ?>
  <form class="login" method="post" autocomplete="on">
    <img src="../img/logo/digi-paradijs-horizontaal-kleur.svg" alt="Digi Paradijs" style="height:34px;width:auto">
    <?php if (empty($cfg['pw'])): ?>
      <h1>Wachtwoord instellen</h1>
      <p class="sub" style="margin:0">Dit is de eerste keer. Kies een wachtwoord voor je statistieken.</p>
      <label for="pw">Nieuw wachtwoord</label><input type="password" id="pw" name="pw" autocomplete="new-password" required minlength="8">
      <label for="pw2">Herhaal wachtwoord</label><input type="password" id="pw2" name="pw2" autocomplete="new-password" required minlength="8">
    <?php else: ?>
      <h1>Statistieken</h1>
      <label for="pw">Wachtwoord</label><input type="password" id="pw" name="pw" autocomplete="current-password" required autofocus>
    <?php endif; ?>
    <?php if ($err): ?><p class="err" role="alert"><?= e($err) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button class="btn" type="submit"><?= empty($cfg['pw']) ? 'Opslaan en inloggen' : 'Inloggen' ?></button>
  </form>
<?php else: ?>
<div class="wrap">
  <header><img src="../img/logo/digi-paradijs-horizontaal-kleur.svg" alt="Digi Paradijs"><a class="link" href="?uit=1">Uitloggen</a></header>
  <h1>Statistieken</h1>
  <p class="sub">Laatste 30 dagen · bijgewerkt <?= e(date('d-m-Y H:i')) ?></p>

  <div class="grid">
    <div class="card"><div class="k">Bezoekers vandaag</div><div class="n"><?= nl(count($uniq['today'])) ?> <small><?= nl($views['today']) ?> weergaven</small></div></div>
    <div class="card"><div class="k">Bezoekers 7 dagen</div><div class="n"><?= nl(count($uniq['week'])) ?> <small><?= nl($views['week']) ?> weergaven</small></div></div>
    <div class="card"><div class="k">Bezoekers 30 dagen</div><div class="n"><?= nl(count($uniq['month'])) ?> <small><?= nl($views['month']) ?> weergaven</small></div></div>
    <div class="card"><div class="k">Aanvragen 30 dagen</div><div class="n"><?= nl($events['aanvraag']) ?> <small><?= str_replace('.', ',', (string) $conv) ?>% van bezoekers</small></div></div>
  </div>

  <div class="two">
    <div class="card">
      <h2>Bezoekers per dag</h2>
      <div class="chart" role="img" aria-label="Bezoekers per dag, laatste 30 dagen">
        <?php foreach ($perDay as $d => $x): $c = count($x['v']); ?>
          <div class="bar<?= $d === $today ? ' today' : '' ?>" style="height:<?= round($c / $maxDay * 100, 1) ?>%" title="<?= e(date('d-m', strtotime($d))) ?>: <?= $c ?> bezoekers"></div>
        <?php endforeach; ?>
      </div>
      <div class="axis"><span><?= e(date('d-m', strtotime(array_key_first($perDay)))) ?></span><span>Vandaag (<?= $maxDay ?> max)</span></div>
    </div>
    <div class="card">
      <h2>Wat bezoekers doen</h2>
      <table>
        <tr><td>Prijscalculator gebruikt</td><td><?= nl($events['calc']) ?></td></tr>
        <tr><td>Op "Vraag deze offerte aan" geklikt</td><td><?= nl($events['offerte']) ?></td></tr>
        <tr><td>Aanvraag verstuurd</td><td><?= nl($events['aanvraag']) ?></td></tr>
        <tr><td>Portfolio bekeken (klik)</td><td><?= nl($events['case']) ?></td></tr>
        <tr><td>Naar Cloud86 geklikt</td><td><?= nl($events['cloud86']) ?></td></tr>
        <tr><td>E-mailadres gekopieerd</td><td><?= nl($events['mail']) ?></td></tr>
      </table>
    </div>
  </div>

  <div class="two">
    <div class="card">
      <h2>Waar bezoekers vandaan komen</h2>
      <?php if (!$refs): ?><p class="empty">Nog geen bezoekers.</p><?php else: ?>
      <table><?php foreach (array_slice($refs, 0, 8, true) as $k => $v): ?><tr><td><?= e($k) ?></td><td><?= nl($v) ?></td></tr><?php endforeach; ?></table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Apparaat</h2>
      <div class="split"><i style="width:<?= round($mobile / $totalDev * 100) ?>%;background:var(--sea)"></i><i style="width:<?= round($desktop / $totalDev * 100) ?>%;background:var(--petrol)"></i></div>
      <div class="legend"><span>Telefoon <?= round($mobile / $totalDev * 100) ?>%</span><span>Computer <?= round($desktop / $totalDev * 100) ?>%</span></div>
      <h2 style="margin-top:22px">Tijdstip van bezoek</h2>
      <?php $mh = max(1, max($hours)); ?>
      <div class="hours" role="img" aria-label="Bezoeken per uur van de dag"><?php foreach ($hours as $h): ?><i style="height:<?= round($h / $mh * 100) ?>%"></i><?php endforeach; ?></div>
      <div class="axis"><span>0:00</span><span>12:00</span><span>23:00</span></div>
      <?php if ($cases): ?><h2 style="margin-top:22px">Meest bekeken werk</h2><table><?php foreach ($cases as $k => $v): ?><tr><td><?= e($k) ?></td><td><?= nl($v) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
    </div>
  </div>
  <p class="note">Bezoekers worden per dag geteld, zonder cookies en zonder IP-adressen op te slaan. Bezoeken van zoekmachines en bots tellen niet mee.</p>
</div>
<?php endif; ?>
</body>
</html>
