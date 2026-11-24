<?php
/*
 * HS Advies & Begeleiding – contactformulier (voorbeeldversie)
 * Mailt het bericht naar $TO. Zolang $TO leeg is, valt het formulier terug op het e-mailprogramma van de bezoeker.
 * Spambeveiliging: verborgen veld, minimale invultijd, linklimiet en maximaal 5 berichten per uur per IP-adres.
 */
date_default_timezone_set('Europe/Amsterdam');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$TO        = '';                          // e-mailadres van HS Advies & Begeleiding invullen
$FROM      = 'info@digiparadijs.nl';      // afzender: een adres op het domein waar de site draait
$FROM_NAME = 'Website HS Advies & Begeleiding';
$MAX_PER_HOUR = 5;

function reply($ok, $error = null, $code = 200) { http_response_code($code); echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]); exit; }
function field($key, $max = 2000) { $v = trim(str_replace("\0", '', (string)($_POST[$key] ?? ''))); return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max); }
function oneline($v) { return trim(preg_replace('/[\r\n\t]+/', ' ', $v)); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { reply(false, 'method', 405); }

// Alleen vanuit de afgeschermde voorbeeldversie (zelfde sessie als index.php)
session_name('hsvoorbeeld');
session_set_cookie_params(['lifetime' => 0, 'path' => dirname($_SERVER['SCRIPT_NAME']) . '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (empty($_SESSION['ok'])) { reply(false, 'auth', 403); }
session_write_close();

if (field('website') !== '') { reply(true); }
$started = (int) field('t', 20); $nowMs = (int) round(microtime(true) * 1000);
if ($started <= 0 || ($nowMs - $started) < 4000 || ($nowMs - $started) > 86400000) { reply(false, 'invalid', 400); }

$naam  = oneline(field('naam', 120));
$org   = oneline(field('organisatie', 160));
$email = oneline(field('email', 160));
$tel   = oneline(field('telefoon', 40));
$vraag = field('vraag', 4000);
if ($naam === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $vraag === '') { reply(false, 'invalid', 400); }
if (preg_match('/[<>]/', $naam) || preg_match_all('/https?:\/\/|www\./i', $vraag) > 3) { reply(false, 'invalid', 400); }

if ($TO === '' || !filter_var($TO, FILTER_VALIDATE_EMAIL)) { reply(false, 'config', 503); }

$dir = __DIR__ . '/.berichten-limiet';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
$saltFile = $dir . '/.sleutel';
$salt = is_readable($saltFile) ? trim((string) @file_get_contents($saltFile)) : '';
if (strlen($salt) < 32) { $salt = bin2hex(random_bytes(32)); @file_put_contents($saltFile, $salt, LOCK_EX); @chmod($saltFile, 0600); }
foreach ((array) glob($dir . '/*.json') as $old) { if (is_file($old) && filemtime($old) < time() - 3600) { @unlink($old); } }
$file = $dir . '/' . hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '-', $salt) . '.json';
$hits = is_readable($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
$hits = array_values(array_filter($hits, function ($t) { return $t > time() - 3600; }));
if (count($hits) >= $MAX_PER_HOUR) { reply(false, 'rate', 429); }

$subject = 'Kennismaking via de website – ' . ($org !== '' ? $org : $naam);
$body = implode("\r\n", [
    'Nieuw bericht via de website van HS Advies & Begeleiding', '',
    'Naam:        ' . $naam,
    'Organisatie: ' . ($org !== '' ? $org : '-'),
    'E-mail:      ' . $email,
    'Telefoon:    ' . ($tel !== '' ? $tel : '-'), '',
    'Bericht:', $vraag, '', '---', 'Verstuurd op ' . date('d-m-Y H:i'),
]);
$encSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n") : '=?UTF-8?B?' . base64_encode($subject) . '?=';
$headers  = 'From: =?UTF-8?B?' . base64_encode($FROM_NAME) . '?= <' . $FROM . ">\r\n";
$headers .= 'Reply-To: ' . $email . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
if (!@mail($TO, $encSubject, $body, $headers, '-f' . $FROM)) { reply(false, 'mail', 500); }
$hits[] = time(); @file_put_contents($file, json_encode($hits), LOCK_EX);
reply(true);
