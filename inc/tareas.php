<?php
// tareas del equipo de sistemas: propias (solo las ve quien las crea), asignadas a alguien y grupales.
// Avisos por correo: al crear, al cumplir, al cambiar la fecha de entrega, al vencerse y un resumen cada mañana

// el equipo = administradores activos que tienen el modulo Tareas
function miembros_tareas() {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    foreach (db()->query("SELECT id, nombre, email, permisos FROM usuarios WHERE activo = 1 AND rol = 'admin' ORDER BY nombre") as $u) {
        if (in_array('tareas', lista_permisos($u['permisos']), true)) $m[(int)$u['id']] = $u;
    }
    return $m;
}

// lo que se pide en cada consulta de tareas: quien, para quien, categoria, persona y equipo relacionados, y cuantas notas tiene
function sql_tareas() {
    return 'SELECT t.*, c.nombre creador, p.nombre para, h.nombre hecha_nombre, tt.categoria,
        col.nombre colaborador, i.etiqueta item_etiqueta, i.nombre item_nombre,
        (SELECT COUNT(*) FROM tareas_notas n WHERE n.tarea_id = t.id AND n.tipo <> \'evento\') notas
        FROM tareas t
        LEFT JOIN usuarios c ON c.id = t.creado_por LEFT JOIN usuarios p ON p.id = t.para_id LEFT JOIN usuarios h ON h.id = t.hecha_por
        LEFT JOIN tareas_tipos tt ON tt.id = t.tipo_id LEFT JOIN colaboradores col ON col.id = t.colaborador_id LEFT JOIN items i ON i.id = t.item_id';
}

function tarea($id) {
    $st = db()->prepare(sql_tareas() . ' WHERE t.id = ?');
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

// lista para escoger, agrupada por categoria
function tipos_tarea($solo_activos = true) {
    $g = [];
    foreach (db()->query('SELECT * FROM tareas_tipos' . ($solo_activos ? ' WHERE activo = 1' : '') . ' ORDER BY orden, id') as $t) $g[$t['categoria']][] = $t;
    return $g;
}

// notas del caso (bitacora). tipo: nota (la escribe alguien), transferencia, evento (lo pone el sistema)
function nota_tarea($tarea_id, $texto, $tipo = 'evento', $uid = null) {
    db()->prepare('INSERT INTO tareas_notas (tarea_id, usuario_id, tipo, texto) VALUES (?,?,?,?)')
        ->execute([(int)$tarea_id, $uid ?? (usuario()['id'] ?? null), $tipo, mb_substr($texto, 0, 4000)]);
}

function notas_tarea($tarea_id) {
    $st = db()->prepare('SELECT n.*, u.nombre FROM tareas_notas n LEFT JOIN usuarios u ON u.id = n.usuario_id WHERE n.tarea_id = ? ORDER BY n.creado, n.id');
    $st->execute([(int)$tarea_id]);
    return $st->fetchAll();
}

// quien puede trabajarla: marcar hecha, mover la fecha, escribir notas, transferir
function trabaja_tarea($t, $uid) {
    if ($t['privada']) return (int)$t['creado_por'] === (int)$uid;
    return (int)$t['creado_por'] === (int)$uid || (int)$t['para_id'] === (int)$uid || $t['para_id'] === null;
}

// quien la puede ver
function ve_tarea($t, $uid) {
    return !$t['privada'] || (int)$t['creado_por'] === (int)$uid;
}

// quienes estan metidos en la tarea (a quien se le avisa)
function involucrados_tarea($t) {
    if ($t['privada']) return [(int)$t['creado_por']];
    if ($t['para_id'] === null) return array_keys(miembros_tareas());
    return array_values(array_unique([(int)$t['creado_por'], (int)$t['para_id']]));
}

function tipo_tarea($t) {
    if ($t['privada']) return 'propia';
    return $t['para_id'] === null ? 'grupal' : 'asignada';
}

// "Hoy 3:00 pm", "Mañana", "lun 17/11", con la hora solo si se puso
function vence_texto($t) {
    if (!$t['vence']) return '';
    $ts = strtotime($t['vence']);
    $hoy = strtotime('today');
    $dia = strtotime(date('Y-m-d', $ts));
    $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $txt = $dia === $hoy ? 'Hoy' : ($dia === $hoy + 86400 ? 'Mañana' : ($dia === $hoy - 86400 ? 'Ayer' : $dias[(int)date('w', $ts)] . ' ' . date('d/m', $ts) . (date('Y', $ts) !== date('Y') ? '/' . date('Y', $ts) : '')));
    return $txt . ($t['con_hora'] ? ' ' . date('g:i a', $ts) : '');
}

// vencida: con hora, si ya paso la hora; sin hora, si ya paso el dia
function tarea_vencida($t) {
    if ($t['hecha'] || !$t['vence']) return false;
    $ts = strtotime($t['vence']);
    return $t['con_hora'] ? $ts < time() : date('Y-m-d', $ts) < date('Y-m-d');
}

function hace_texto($fecha) {
    $s = time() - strtotime($fecha);
    if ($s < 3600) return 'hace ' . max(1, (int)round($s / 60)) . ' min';
    if ($s < 86400) return 'hace ' . (int)round($s / 3600) . ' h';
    $d = (int)floor($s / 86400);
    return $d === 1 ? 'hace 1 día' : 'hace ' . $d . ' días';
}

// los correos salen despues de responderle al usuario, para que la pagina no se demore
function avisar_tarea($tarea_id, $evento, $destinos, $extra = '') {
    $destinos = array_values(array_unique(array_filter(array_map('intval', $destinos))));
    if (!$destinos) return;
    if (!isset($GLOBALS['avisos_tareas'])) {
        $GLOBALS['avisos_tareas'] = [];
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) { @session_write_close(); fastcgi_finish_request(); }
            foreach ($GLOBALS['avisos_tareas'] as $a) {
                try { correo_tarea(...$a); } catch (Throwable $t) { error_log('aviso tarea: ' . $t->getMessage()); }
            }
        });
    }
    $GLOBALS['avisos_tareas'][] = [$tarea_id, $evento, $destinos, $extra, usuario()['nombre'] ?? 'Sistemas'];
}

function correo_tarea($tarea_id, $evento, $destinos, $extra = '', $quien = 'Sistemas') {
    if (!correo_configurado()) return false;
    $t = tarea($tarea_id);
    if (!$t) return false;
    $m = miembros_tareas();
    $para = [];
    foreach ($destinos as $id) if (!empty($m[$id]['email'])) $para[] = $m[$id]['email'];
    if (!$para) return false;
    $eventos = [
        'nueva'      => ['Tarea nueva', '#1565c0', e($quien) . ($t['para_id'] === null ? ' le puso una tarea a <b>todo el grupo</b>' : ' te puso una tarea')],
        'hecha'      => ['Tarea cumplida', '#1b5e20', '<b>' . e($t['hecha_nombre'] ?: $quien) . '</b> marcó como hecha esta tarea'],
        'fecha'      => ['Cambió la fecha de entrega', '#8a6d00', e($quien) . ' cambió la fecha de entrega' . ($extra !== '' ? ' (antes: <b>' . e($extra) . '</b>)' : '')],
        'vencida'    => ['Tarea vencida sin cumplir', '#b71c1c', 'Esta tarea ya pasó su fecha de entrega y no se ha marcado como hecha'],
        'recordar'   => ['Recordatorio', '#8a6d00', e($quien) . ' te recuerda esta tarea pendiente'],
        'reabierta'  => ['Tarea reabierta', '#8a6d00', e($quien) . ' volvió a abrir esta tarea'],
        'transferida'=> ['Tarea transferida', '#6a1b9a', e($quien) . ' transfirió esta tarea a <b>' . e($t['para'] ?? '') . '</b>' . ($extra !== '' ? '. Motivo: <i>' . e($extra) . '</i>' : '')],
        'tomada'     => ['Alguien tomó la tarea', '#1565c0', '<b>' . e($quien) . '</b> tomó esta tarea del grupo y queda a su cargo'],
    ];
    [$tit, $color, $frase] = $eventos[$evento] ?? $eventos['nueva'];
    $filas = [];
    if ($t['categoria']) $filas[] = ['Categoría', e($t['categoria'])];
    if ($t['colaborador']) $filas[] = ['Persona', e($t['colaborador'])];
    if ($t['item_etiqueta']) $filas[] = ['Equipo', e($t['item_etiqueta'] . ' · ' . $t['item_nombre'])];
    $filas[] = ['De', e($t['creador'])];
    $filas[] = ['Para', $t['privada'] ? 'Solo para ' . e($t['creador']) : ($t['para_id'] === null ? 'Todo el grupo de sistemas' : e($t['para']))];
    if ($t['vence']) $filas[] = ['Entrega', '<b' . (tarea_vencida($t) ? ' style="color:#b71c1c"' : '') . '>' . e(vence_texto($t)) . '</b> · ' . fecha_corta($t['vence'])];
    if ($t['prioridad'] === 'alta') $filas[] = ['Prioridad', '<b style="color:#b71c1c">Alta</b>'];
    if ($t['hecha']) $filas[] = ['Hecha', e($t['hecha_nombre']) . ' · ' . fecha_hora($t['hecha_en'])];
    $td = 'padding:6px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top';
    $tabla = '';
    foreach ($filas as [$k, $v]) $tabla .= '<tr><td style="' . $td . ';color:#777;width:90px">' . $k . '</td><td style="' . $td . '">' . $v . '</td></tr>';
    // las ultimas notas del caso, para que quien la recibe vea hasta donde se llego
    $ultimas = array_slice(array_filter(notas_tarea($t['id']), fn($n) => $n['tipo'] !== 'evento'), -3);
    if ($ultimas && in_array($evento, ['transferida', 'tomada', 'vencida', 'recordar', 'hecha'], true)) {
        $tabla .= '<tr><td colspan="2" style="padding:12px 0 4px;font-weight:bold;font-size:13px">Últimas notas del caso</td></tr>';
        foreach ($ultimas as $n) $tabla .= '<tr><td style="' . $td . ';color:#777;font-size:12px">' . e(explode(' ', (string)$n['nombre'])[0]) . '<br>' . date('d/m g:i a', strtotime($n['creado'])) . '</td><td style="' . $td . '">' . nl2br(e($n['texto'])) . '</td></tr>';
    }
    $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #ddd">'
        . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">AGRO-COSTA · TAREAS DE SISTEMAS</div>'
        . '<div style="padding:20px;color:#222">'
        . '<div style="border-left:4px solid ' . $color . ';padding:2px 0 2px 12px;margin-bottom:14px;color:' . $color . ';font-weight:bold">' . $tit . '</div>'
        . '<p style="margin:0 0 10px">' . $frase . ':</p>'
        . '<div style="font-size:17px;font-weight:bold;margin:0 0 6px;' . ($t['hecha'] ? 'text-decoration:line-through;color:#777' : '') . '">' . e($t['titulo']) . '</div>'
        . ($t['detalle'] ? '<div style="color:#444;font-size:14px;margin-bottom:12px">' . nl2br(e($t['detalle'])) . '</div>' : '')
        . '<table style="border-collapse:collapse;width:100%">' . $tabla . '</table>'
        . '<p style="margin-top:18px"><a href="' . e(APP_URL . '/tareas.php#t' . $t['id']) . '" style="background:#ffcd11;color:#000;padding:9px 16px;text-decoration:none;font-weight:bold;display:inline-block">Abrir tareas</a></p>'
        . '</div></div>';
    $prefijo = ['nueva' => '📝', 'hecha' => '✅', 'fecha' => '🕒', 'vencida' => '⚠️', 'recordar' => '🔔', 'reabierta' => '↩️', 'transferida' => '🔁', 'tomada' => '🙋'][$evento] ?? '📝';
    return enviar_correo($para, '', $prefijo . ' ' . $tit . ': ' . mb_substr($t['titulo'], 0, 80), $html);
}

// tareas que le tocan a una persona: las suyas, las asignadas a ella y las del grupo
function pendientes_de($uid) {
    $st = db()->prepare(sql_tareas() . '
        WHERE t.hecha = 0 AND (t.para_id = ? OR t.para_id IS NULL OR (t.privada = 1 AND t.creado_por = ?))
        ORDER BY t.vence IS NULL, t.vence, t.prioridad = \'alta\' DESC, t.id');
    $st->execute([$uid, $uid]);
    return $st->fetchAll();
}

// para el numerito del menu: vencidas + las de hoy
function tareas_urgentes($uid) {
    try {
        $n = 0;
        foreach (pendientes_de($uid) as $t) {
            if ($t['vence'] && (tarea_vencida($t) || date('Y-m-d', strtotime($t['vence'])) === date('Y-m-d'))) $n++;
        }
        return $n;
    } catch (Throwable $e) { return 0; }
}
