<?php
/*
 * Digi Paradijs – gedeelde functies voor de bezoekersstatistieken.
 * Privacyvriendelijk: geen cookies, geen IP-adressen opgeslagen.
 * Bezoekers worden per dag geteld met een versleutelde code die elke dag verandert.
 */

date_default_timezone_set('Europe/Amsterdam');

function dp_stats_dir() {
    $outside = dirname(__DIR__) . '/.dp-stats';
    $inside  = __DIR__ . '/.dp-stats';
    if (is_dir($outside) || is_writable(dirname(__DIR__))) { $dir = $outside; } else { $dir = $inside; }
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function dp_config() {
    $file = dp_stats_dir() . '/config.json';
    $cfg = is_readable($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
    if (empty($cfg['salt'])) {
        $cfg['salt'] = bin2hex(random_bytes(16));
        @file_put_contents($file, json_encode($cfg), LOCK_EX);
        @chmod($file, 0600);
    }
    return $cfg;
}

function dp_save_config($cfg) {
    $file = dp_stats_dir() . '/config.json';
    @file_put_contents($file, json_encode($cfg), LOCK_EX);
    @chmod($file, 0600);
}

function dp_is_bot($ua) {
    return $ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|headless|lighthouse|curl|wget|python|go-http/i', $ua);
}

function dp_visitor_id() {
    $cfg = dp_config();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return substr(hash('sha256', $cfg['salt'] . '|' . date('Y-m-d') . '|' . $ip . '|' . $ua), 0, 16);
}

function dp_clean($v, $max = 120) {
    $v = preg_replace('/[^\p{L}\p{N}\/\-_.:#?\' ]/u', '', (string) $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

/* Legt een regel vast: type is 'view' of de naam van een gebeurtenis */
function dp_log($type, $extra = []) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (dp_is_bot($ua)) { return; }
    $row = array_merge([
        'd' => date('Y-m-d'),
        'h' => (int) date('G'),
        't' => dp_clean($type, 30),
        'v' => dp_visitor_id(),
    ], $extra);
    $file = dp_stats_dir() . '/' . date('Y-m') . '.jsonl';
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
