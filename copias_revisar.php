<?php
// aviso cuando pasan mas de BACKUP_DIAS dias sin copia de seguridad
// lo corre el cron de Hestia una vez al dia:  php /ruta/a/inventarioti/copias_revisar.php
// desde el navegador no hace nada
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/copias.php';

$dias = dias_copias();
$ultima = ultima_copia();
if ($ultima && strtotime($ultima['fecha']) >= time() - $dias * 86400) { echo "Al dia: ultima copia " . $ultima['fecha'] . "\n"; exit; }

// un solo aviso por dia aunque el cron corra varias veces
$hoy = date('Y-m-d');
$st = db()->prepare("SELECT valor FROM ajustes WHERE clave = 'copias_ultimo_aviso'");
$st->execute();
if ($st->fetchColumn() === $hoy) { echo "Ya se aviso hoy\n"; exit; }

$para = correos_copias();
if (!$para || !correo_configurado()) { echo "Sin destinatarios (MAIL_RESPALDOS o MAIL_INVENTARIO) o sin correo configurado\n"; exit(1); }

$txt = $ultima
    ? 'La última copia de seguridad fue <b>' . hace_dias($ultima['fecha']) . '</b> (' . fecha_hora($ultima['fecha']) . ', la hizo ' . e($ultima['persona']) . '). Se espera una cada ' . $dias . ' días.'
    : 'Todavía no se ha registrado ninguna copia de seguridad en la plataforma.';
$html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;border:1px solid #ddd">'
    . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">AGRO-COSTA · COPIA DE SEGURIDAD</div>'
    . '<div style="padding:20px;color:#222"><div style="padding:12px 14px;margin-bottom:14px;background:#ffebee;color:#b71c1c;font-size:15px"><b>La copia de seguridad está atrasada</b></div>'
    . '<p>' . $txt . '</p><p>Hay que conectar el disco externo, hacer la copia y darle doble clic a <b>REPORTAR_COPIA.bat</b>.</p>'
    . '<p><a href="' . e(APP_URL . '/copias.php') . '" style="color:#000">Ver copias en la plataforma →</a></p></div></div>';
$ok = enviar_correo($para, '', '⚠️ Copia de seguridad atrasada' . ($ultima ? ' · ' . hace_dias($ultima['fecha']) : ''), $html);
if ($ok) db()->prepare("INSERT INTO ajustes (clave, valor) VALUES ('copias_ultimo_aviso', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$hoy]);
echo $ok ? "Aviso enviado\n" : "No se pudo enviar: " . ($GLOBALS['correo_error'] ?? '') . "\n";
