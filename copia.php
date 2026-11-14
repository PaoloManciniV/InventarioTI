<?php
// aqui reporta el script del disco externo (reportar_copia.ps1) cuando se hace la copia de seguridad.
// No es una pagina para abrir: pide la clave del script en la cabecera X-Clave
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/copias.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder($cod, $datos) { http_response_code($cod); echo json_encode($datos, JSON_UNESCAPED_UNICODE); exit; }

$clave = clave_copias();
$dada = (string)($_SERVER['HTTP_X_CLAVE'] ?? '');
if ($clave === '' || strlen($clave) < 20 || !hash_equals($clave, $dada)) {
    usleep(500000);
    responder(403, ['ok' => false, 'error' => 'No autorizado']);
}

// lista para el menu "quien hizo la copia"
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['personas'])) {
    responder(200, ['ok' => true, 'personas' => personas_copias(), 'carpetas' => carpetas_esperadas()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'error' => 'Solo POST']);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$st = db()->prepare('SELECT COUNT(*) FROM copias_seguridad WHERE ip = ? AND creado > NOW() - INTERVAL 1 HOUR');
$st->execute([$ip]);
if ($st->fetchColumn() >= 20) responder(429, ['ok' => false, 'error' => 'Demasiados reportes en una hora']);

$cuerpo = file_get_contents('php://input', false, null, 0, 300000);
if (substr($cuerpo, 0, 3) === "\xEF\xBB\xBF") $cuerpo = substr($cuerpo, 3);
$d = json_decode($cuerpo, true);
if (!is_array($d)) responder(400, ['ok' => false, 'error' => 'Datos inválidos']);

$txt = fn($v, $max) => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$v)), 0, $max);
$persona = $txt($d['persona'] ?? '', 120);
if ($persona === '') responder(400, ['ok' => false, 'error' => 'Falta quién hizo la copia']);

$carpetas = [];
foreach (array_slice(is_array($d['carpetas'] ?? null) ? $d['carpetas'] : [], 0, 50) as $c) {
    if (!is_array($c)) continue;
    $nombre = $txt($c['nombre'] ?? '', 120);
    if ($nombre === '') continue;
    $ult = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($c['ultimo'] ?? '')) ? $c['ultimo'] : null;
    $carpetas[] = ['nombre' => $nombre, 'archivos' => max(0, (int)($c['archivos'] ?? 0)), 'mb' => max(0, round((float)($c['mb'] ?? 0), 2)), 'ultimo' => $ult];
}
if (!$carpetas) responder(400, ['ok' => false, 'error' => 'No llegó ninguna carpeta']);

[$carpetas, $alertas] = revisar_carpetas($carpetas);
$disco = is_array($d['disco'] ?? null) ? ['etiqueta' => $txt($d['disco']['etiqueta'] ?? '', 60), 'total_gb' => (int)($d['disco']['total_gb'] ?? 0), 'libre_gb' => (int)($d['disco']['libre_gb'] ?? 0)] : [];
$estado = $alertas ? 'revisar' : 'ok';

// usuario de la plataforma con ese nombre, si existe
$st = db()->prepare('SELECT id FROM usuarios WHERE nombre = ? LIMIT 1');
$st->execute([$persona]);
$uid = $st->fetchColumn() ?: null;

db()->prepare("INSERT INTO copias_seguridad (fecha, persona, usuario_id, origen, pc, usuario_windows, ip, disco, carpetas, total_mb, estado, alertas, nota)
    VALUES (NOW(), ?, ?, 'script', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([$persona, $uid, $txt($d['pc'] ?? '', 80) ?: null, $txt($d['usuario_windows'] ?? '', 80) ?: null, $ip,
        json_encode($disco, JSON_UNESCAPED_UNICODE), json_encode($carpetas, JSON_UNESCAPED_UNICODE),
        round(array_sum(array_column($carpetas, 'mb')), 2), $estado, $alertas ? implode("\n", $alertas) : null, $txt($d['nota'] ?? '', 1000) ?: null]);
$id = (int)db()->lastInsertId();

$correo = false;
try { $correo = correo_copia($id); } catch (Throwable $t) { error_log('correo copia: ' . $t->getMessage()); }

responder(200, ['ok' => true, 'id' => $id, 'estado' => $estado, 'alertas' => $alertas, 'correo' => (bool)$correo]);
