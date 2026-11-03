<?php
/*
 * Digi Paradijs – aanvraagformulier
 * Ontvangt het formulier van de website en mailt de aanvraag naar info@digiparadijs.nl.
 * Spambeveiliging: verborgen veld, minimale invultijd, linklimiet en maximaal 5 aanvragen per uur per IP-adres.
 */

date_default_timezone_set('Europe/Amsterdam');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$TO        = 'info@digiparadijs.nl';      // hier komen de aanvragen binnen
$FROM      = 'info@digiparadijs.nl';      // afzender: moet een adres op je eigen domein zijn
$FROM_NAME = 'Website Digi Paradijs';
$MAX_PER_HOUR = 5;

function reply($ok, $error = null, $code = 200) {
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

function field($key, $max = 2000) {
    $v = isset($_POST[$key]) ? (string) $_POST[$key] : '';
    $v = str_replace("\0", '', $v);
    $v = trim($v);
    if (function_exists('mb_substr')) { return mb_substr($v, 0, $max, 'UTF-8'); }
    return substr($v, 0, $max);
}

function oneline($v) { return trim(preg_replace('/[\r\n\t]+/', ' ', $v)); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { reply(false, 'method', 405); }

// 1. Verborgen veld: mensen zien het niet, spambots vullen het wel in.
if (field('website') !== '') { reply(true); }

// 2. Minimale invultijd: een mens doet langer dan 4 seconden over het formulier.
$started = (int) field('t', 20);
$nowMs   = (int) round(microtime(true) * 1000);
if ($started <= 0 || ($nowMs - $started) < 4000 || ($nowMs - $started) > 86400000) { reply(false, 'invalid', 400); }

// 3. Velden controleren
$naam      = oneline(field('naam', 120));
$email     = oneline(field('email', 160));
$org       = oneline(field('org', 160));
$tel       = oneline(field('tel', 40));
$wat       = oneline(field('wat', 80));
$pakket    = oneline(field('pakket', 60));
$bericht   = field('bericht', 3000);
$berekening = oneline(field('berekening', 1000));

if ($naam === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { reply(false, 'invalid', 400); }
if (preg_match('/[<>]/', $naam)) { reply(false, 'invalid', 400); }
if (preg_match_all('/https?:\/\/|www\./i', $bericht) > 3) { reply(false, 'invalid', 400); }

// 4. Maximaal aantal aanvragen per uur per IP-adres
$ip = $_SERVER['REMOTE_ADDR'] ?? 'onbekend';
$dir = __DIR__ . '/.aanvragen-limiet';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); }
$file = $dir . '/' . hash('sha256', $ip) . '.json';
$hits = [];
if (is_readable($file)) { $hits = json_decode((string) @file_get_contents($file), true) ?: []; }
$hits = array_values(array_filter($hits, function ($t) { return $t > time() - 3600; }));
if (count($hits) >= $MAX_PER_HOUR) { reply(false, 'rate', 429); }

// 5. E-mail opstellen en versturen
$subject = 'Nieuwe aanvraag via de website' . ($org !== '' ? ' – ' . $org : ' – ' . $naam);
$lines = [
    'Nieuwe aanvraag via digiparadijs.nl',
    '',
    'Naam:        ' . $naam,
    'Organisatie: ' . ($org !== '' ? $org : '-'),
    'E-mail:      ' . $email,
    'Telefoon:    ' . ($tel !== '' ? $tel : '-'),
    'Zoekt:       ' . ($wat !== '' ? $wat : '-'),
    'Pakket:      ' . ($pakket !== '' ? $pakket : '-'),
    '',
    'Berekening uit de prijscalculator:',
    $berekening !== '' ? $berekening : '(niet meegestuurd)',
    '',
    'Bericht:',
    $bericht !== '' ? $bericht : '(geen bericht)',
    '',
    '---',
    'Verstuurd op ' . date('d-m-Y H:i'),
];
$body = implode("\r\n", $lines);

$encSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n") : '=?UTF-8?B?' . base64_encode($subject) . '?=';
$encFrom    = '=?UTF-8?B?' . base64_encode($FROM_NAME) . '?=';
$headers  = 'From: ' . $encFrom . ' <' . $FROM . ">\r\n";
$headers .= 'Reply-To: ' . $email . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";

$sent = @mail($TO, $encSubject, $body, $headers, '-f' . $FROM);
if (!$sent) { reply(false, 'mail', 500); }

$hits[] = time();
@file_put_contents($file, json_encode($hits), LOCK_EX);
reply(true);
