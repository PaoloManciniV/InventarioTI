<?php
// descarga de archivos de herramientas, solo con sesion
require __DIR__ . '/config.php';
requiere_modulo('herramientas');

$f = basename((string)($_GET['f'] ?? ''));
if ($f === '' || !preg_match('#^[A-Za-z0-9_.() -]+$#', $f)) { http_response_code(404); exit('No existe'); }
$ruta = DIR_UPLOADS . '/descargas/' . $f;
if (!is_file($ruta)) { http_response_code(404); exit('No existe'); }
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $f) . '"');
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($ruta);
