<?php
// lo corre el cron de Hestia cada hora:  php /ruta/a/inventarioti/tareas_cron.php
// 1) avisa una vez por correo las tareas que se vencieron sin cumplirse
// 2) cada mañana (lunes a sabado, desde TAREAS_HORA_RESUMEN, por defecto 7 am) le manda a cada uno su resumen de pendientes
// desde el navegador no hace nada
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/tareas.php';

if (!correo_configurado()) { echo "Correo no configurado\n"; exit(1); }

// 1) vencidas
$n = 0;
foreach (db()->query('SELECT id FROM tareas WHERE hecha = 0 AND vence IS NOT NULL AND aviso_vencida IS NULL AND vence < NOW() + INTERVAL 1 DAY')->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $t = tarea($id);
    if (!$t || !tarea_vencida($t)) continue;
    db()->prepare('UPDATE tareas SET aviso_vencida = NOW() WHERE id = ?')->execute([$id]);
    if (correo_tarea($id, 'vencida', involucrados_tarea($t))) $n++;
}
echo "Vencidas avisadas: $n\n";

// 2) resumen de la mañana
$hora = (int)env('TAREAS_HORA_RESUMEN', 7);
$hoy = date('Y-m-d');
if ((int)date('G') < $hora || date('w') === '0') exit;
$st = db()->prepare("SELECT valor FROM ajustes WHERE clave = 'tareas_resumen'");
$st->execute();
if ($st->fetchColumn() === $hoy) exit;
db()->prepare("INSERT INTO ajustes (clave, valor) VALUES ('tareas_resumen', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$hoy]);

$enviados = 0;
foreach (miembros_tareas() as $uid => $m) {
    if (empty($m['email'])) continue;
    $grupos = ['Vencidas' => [], 'Hoy' => [], 'Mañana' => [], 'Sin fecha' => []];
    foreach (pendientes_de($uid) as $t) {
        if (!$t['vence']) $grupos['Sin fecha'][] = $t;
        elseif (tarea_vencida($t)) $grupos['Vencidas'][] = $t;
        elseif (date('Y-m-d', strtotime($t['vence'])) === $hoy) $grupos['Hoy'][] = $t;
        elseif (date('Y-m-d', strtotime($t['vence'])) === date('Y-m-d', strtotime('+1 day'))) $grupos['Mañana'][] = $t;
    }
    if (!$grupos['Vencidas'] && !$grupos['Hoy'] && !$grupos['Mañana'] && !$grupos['Sin fecha']) continue;
    $cuerpo = '';
    $colores = ['Vencidas' => '#b71c1c', 'Hoy' => '#1565c0', 'Mañana' => '#555', 'Sin fecha' => '#777'];
    foreach ($grupos as $g => $lista) {
        if (!$lista) continue;
        $cuerpo .= '<div style="margin:16px 0 6px;font-weight:bold;color:' . $colores[$g] . '">' . $g . ' (' . count($lista) . ')</div>';
        foreach ($lista as $t) {
            $de = $t['privada'] ? 'Tuya' : ($t['para_id'] === null ? 'Del grupo' : ((int)$t['creado_por'] === $uid ? 'Tuya' : 'De ' . $t['creador']));
            $cuerpo .= '<div style="padding:7px 0;border-bottom:1px solid #eee;font-size:14px">☐ ' . e($t['titulo'])
                . ($t['prioridad'] === 'alta' ? ' <b style="color:#b71c1c">· Alta</b>' : '')
                . '<div style="color:#777;font-size:12px">' . e($de) . ($t['vence'] ? ' · ' . e(vence_texto($t)) : '') . '</div></div>';
        }
    }
    $total = count($grupos['Vencidas']) + count($grupos['Hoy']);
    $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #ddd">'
        . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">AGRO-COSTA · TAREAS DE SISTEMAS</div>'
        . '<div style="padding:20px;color:#222"><p style="margin-top:0">Buenos días, <b>' . e(explode(' ', $m['nombre'])[0]) . '</b>. Esto es lo que tienes pendiente:</p>'
        . $cuerpo
        . '<p style="margin-top:18px"><a href="' . e(APP_URL . '/tareas.php') . '" style="background:#ffcd11;color:#000;padding:9px 16px;text-decoration:none;font-weight:bold;display:inline-block">Abrir tareas</a></p></div></div>';
    if (enviar_correo([$m['email']], $m['nombre'], '☀️ Tus tareas de hoy' . ($total ? ' · ' . $total . ' para hoy o vencidas' : ''), $html)) $enviados++;
}
echo "Resumenes enviados: $enviados\n";
