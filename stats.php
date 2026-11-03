<?php
/*
 * Digi Paradijs – ontvangt paginabezoeken en klikken van de website.
 * Slaat geen IP-adressen op en gebruikt geen cookies.
 */
require __DIR__ . '/stats-lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }

$allowed = ['view', 'calc', 'offerte', 'cloud86', 'case', 'mail'];
$type = dp_clean($_POST['t'] ?? 'view', 20);
if (!in_array($type, $allowed, true)) { http_response_code(400); exit; }

$extra = [];
if ($type === 'view') {
    $ref = (string) ($_POST['r'] ?? '');
    $host = $ref !== '' ? strtolower((string) parse_url($ref, PHP_URL_HOST)) : '';
    $self = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && $host !== $self && $host !== 'www.' . $self && 'www.' . $host !== $self) {
        $extra['r'] = dp_clean(preg_replace('/^www\./', '', $host), 80);
    }
    $w = (int) ($_POST['w'] ?? 0);
    $extra['m'] = $w > 0 && $w < 768 ? 1 : 0;
    $extra['p'] = dp_clean($_POST['p'] ?? '/', 80);
} elseif (isset($_POST['n'])) {
    $extra['n'] = dp_clean($_POST['n'], 60);
}

dp_log($type, $extra);
http_response_code(204);
