<?php
// entrega fotos firmas y escaneos solo a usuarios con sesion
// o a quien tenga el token del acta para ver su propia firma
require __DIR__ . '/config.php';

$f = $_GET['f'] ?? '';
if (!preg_match('#^(firmas|actas|items|reparaciones)/[A-Za-z0-9_.-]+$#', $f) || strpos($f, '..') !== false) { http_response_code(404); exit; }

if (!usuario()) {
    $tok = $_GET['t'] ?? '';
    $st = db()->prepare('SELECT COUNT(*) FROM actas WHERE token = ? AND firma_archivo = ?');
    $st->execute([$tok, $f]);
    if (!$tok || !$st->fetchColumn()) { http_response_code(403); exit; }
}

$ruta = DIR_UPLOADS . '/' . $f;
if (!is_file($ruta)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($ruta);
