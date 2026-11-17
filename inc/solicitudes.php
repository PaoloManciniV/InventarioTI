<?php
// solicitudes a administración (formato SGSST-AC-F55 "Requerimiento de materiales/suministros")
// flujo: el auxiliar radica -> Paolo o Carlos (quienes administran usuarios) aprueban o rechazan ->
// al aprobar se libera el PDF, que se imprime UNA sola vez -> se entrega a administración -> en compra -> recibida

function categorias_solicitud() {
    return [
        'Cables', 'Adaptadores y conectores', 'Almacenamiento (discos, memorias USB)', 'Repuestos de equipo (RAM, fuente, batería)',
        'Periféricos (mouse, teclado, diadema, monitor)', 'Tóner e insumos de impresión', 'Redes (switch, AP, patch cord)',
        'Telefonía', 'Reparación o servicio técnico', 'Licencias o software', 'Herramientas', 'Equipo nuevo', 'Otro',
    ];
}

function tipos_compra() { return ['herramientas' => 'Herramientas', 'repuestos' => 'Repuestos', 'consumibles' => 'Consumibles', 'otros' => 'Otros']; }

function unidades_medida() { return ['Unidad', 'Par', 'Caja', 'Paquete', 'Rollo', 'Metro', 'Kit', 'Servicio']; }

function estados_solicitud() {
    return [
        'por_aprobar' => ['Por aprobar', 'chip-pendiente'],
        'aprobada'    => ['Aprobada · por imprimir y entregar', 'chip-asignado'],
        'pendiente'   => ['Entregada a administración', 'chip-prestado'],
        'en_compra'   => ['En compra', 'chip-prestado'],
        'recibida'    => ['Recibida', 'chip-disponible'],
        'rechazada'   => ['Rechazada', 'chip-danado'],
        'cancelada'   => ['Cancelada', 'chip-gris'],
    ];
}

// estados en que sigue abierta (para contadores)
function estados_abiertos() { return ['por_aprobar', 'aprobada', 'pendiente', 'en_compra']; }

function codigo_solicitud($s) { return 'SOL-' . str_pad((string)(int)$s['id'], 4, '0', STR_PAD_LEFT); }

function token_solicitud() { return strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)); }

// quienes aprueban: los que administran usuarios (Paolo y Carlos)
function aprobadores_solicitud() {
    return db()->query('SELECT id, nombre, email FROM usuarios WHERE activo = 1 AND gestiona_usuarios = 1 ORDER BY nombre')->fetchAll();
}

function sql_solicitudes() {
    return 'SELECT s.*, u.nombre solicitante, u.email solicitante_email, ap.nombre aprobador, im.nombre impresor,
        c.nombre colaborador, i.etiqueta item_etiqueta, i.nombre item_nombre,
        (SELECT GROUP_CONCAT(CONCAT(l.cantidad, \' × \', l.descripcion) ORDER BY l.id SEPARATOR \' · \') FROM solicitudes_lineas l WHERE l.solicitud_id = s.id) resumen,
        (SELECT COALESCE(SUM(l.cantidad), 0) FROM solicitudes_lineas l WHERE l.solicitud_id = s.id) unidades,
        (SELECT SUM(l.costo_estimado * l.cantidad) FROM solicitudes_lineas l WHERE l.solicitud_id = s.id) estimado
        FROM solicitudes s LEFT JOIN usuarios u ON u.id = s.solicitado_por LEFT JOIN usuarios ap ON ap.id = s.aprobado_por
        LEFT JOIN usuarios im ON im.id = s.impreso_por LEFT JOIN colaboradores c ON c.id = s.colaborador_id LEFT JOIN items i ON i.id = s.item_id';
}

function solicitud($id) {
    $st = db()->prepare(sql_solicitudes() . ' WHERE s.id = ?');
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

function lineas_solicitud($id) {
    $st = db()->prepare('SELECT * FROM solicitudes_lineas WHERE solicitud_id = ? ORDER BY id');
    $st->execute([(int)$id]);
    return $st->fetchAll();
}

function eventos_solicitud($id) {
    $st = db()->prepare('SELECT e.*, u.nombre FROM solicitudes_eventos e LEFT JOIN usuarios u ON u.id = e.usuario_id WHERE e.solicitud_id = ? ORDER BY e.creado, e.id');
    $st->execute([(int)$id]);
    return $st->fetchAll();
}

function evento_solicitud($id, $texto) {
    db()->prepare('INSERT INTO solicitudes_eventos (solicitud_id, usuario_id, texto) VALUES (?,?,?)')
        ->execute([(int)$id, usuario()['id'] ?? null, mb_substr($texto, 0, 4000)]);
}

// dias que lleva abierta (o que tardo, si ya se cerro), desde que se radico
function dias_solicitud($s) {
    $fin = in_array($s['estado'], estados_abiertos(), true) ? time() : strtotime($s['fecha_recibida'] ?: ($s['fecha_estado'] ?: $s['fecha']));
    return max(0, (int)floor(($fin - strtotime($s['fecha'])) / 86400));
}

function correos_solicitudes() {
    $l = env('MAIL_SOLICITUDES', '') ?: env('MAIL_INVENTARIO', '');
    return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$l)))));
}

function pesos_sol($v) { return $v === null || $v === '' ? '' : '$' . number_format((float)$v, 0, ',', '.'); }

// correo despues de responderle al usuario
function avisar_solicitud($id, $evento, $extra = '') {
    if (!isset($GLOBALS['avisos_sol'])) {
        $GLOBALS['avisos_sol'] = [];
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) { @session_write_close(); fastcgi_finish_request(); }
            foreach ($GLOBALS['avisos_sol'] as $a) {
                try { correo_solicitud(...$a); } catch (Throwable $t) { error_log('aviso solicitud: ' . $t->getMessage()); }
            }
        });
    }
    $GLOBALS['avisos_sol'][] = [$id, $evento, $extra, usuario()['nombre'] ?? 'Sistemas'];
}

function correo_solicitud($id, $evento, $extra = '', $quien = 'Sistemas') {
    if (!correo_configurado()) return false;
    $s = solicitud($id);
    if (!$s) return false;
    $aprob = array_column(aprobadores_solicitud(), 'email');
    // a quien le llega cada aviso
    $para = match ($evento) {
        'radicada' => $aprob,
        'aprobada', 'rechazada' => array_merge([$s['solicitante_email']], $aprob),
        default => array_merge(correos_solicitudes(), [$s['solicitante_email']]),
    };
    $para = array_values(array_unique(array_filter($para)));
    if (!$para) return false;
    $est = estados_solicitud();
    $titulos = [
        'radicada'  => ['Solicitud por aprobar', '#1565c0', '<b>' . e($quien) . '</b> radicó una solicitud a administración y necesita tu aprobación'],
        'aprobada'  => ['Solicitud aprobada', '#1b5e20', '<b>' . e($quien) . '</b> aprobó la solicitud. Ya se puede imprimir el formato (una sola vez) y entregarlo a administración'],
        'rechazada' => ['Solicitud rechazada', '#b71c1c', '<b>' . e($quien) . '</b> rechazó la solicitud' . ($extra !== '' ? '. Motivo: <i>' . e($extra) . '</i>' : '')],
        'estado'    => ['La solicitud cambió a: ' . ($est[$s['estado']][0] ?? $s['estado']), '#8a6d00', '<b>' . e($quien) . '</b> la marcó como <b>' . e($est[$s['estado']][0] ?? $s['estado']) . '</b>' . ($extra !== '' ? ': ' . e($extra) : '')],
    ];
    [$tit, $color, $frase] = $titulos[$evento] ?? $titulos['estado'];
    $td = 'padding:6px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top';
    $filas = '';
    foreach (lineas_solicitud($id) as $l) {
        $filas .= '<tr><td style="' . $td . ';text-align:right;width:40px"><b>' . (int)$l['cantidad'] . '</b></td><td style="' . $td . '">' . e($l['unidad'] ?? '') . '</td><td style="' . $td . '"><b>' . e($l['descripcion']) . '</b>'
            . ($l['especificaciones'] ? '<div style="color:#666;font-size:12px">' . e($l['especificaciones']) . '</div>' : '') . '</td><td style="' . $td . ';text-align:right">' . pesos_sol($l['costo_estimado']) . '</td></tr>';
    }
    $datos = [['Tipo', e(tipos_compra()[$s['tipo_compra']] ?? $s['tipo_compra'])], ['Radicó', e($s['solicitante']) . ' · ' . fecha_corta($s['fecha'])]];
    if ($s['trabajo_realizado']) $datos[] = ['Trabajo', e($s['trabajo_realizado'])];
    if ($s['aprobador']) $datos[] = ['Aprobó', e($s['aprobador']) . ($s['aprobado_en'] ? ' · ' . fecha_hora($s['aprobado_en']) : '')];
    if ($s['urgencia'] === 'urgente') $datos[] = ['Urgencia', '<b style="color:#b71c1c">Urgente</b>'];
    if ($s['colaborador']) $datos[] = ['Para', e($s['colaborador'])];
    if ($s['item_etiqueta']) $datos[] = ['Equipo', e($s['item_etiqueta'] . ' · ' . $s['item_nombre'])];
    if ($s['estimado']) $datos[] = ['Estimado', pesos_sol($s['estimado'])];
    if ($s['costo_total'] !== null) $datos[] = ['Costo real', pesos_sol($s['costo_total'])];
    $tabla = '';
    foreach ($datos as [$k, $v]) $tabla .= '<tr><td style="' . $td . ';color:#777;width:90px">' . $k . '</td><td style="' . $td . '">' . $v . '</td></tr>';
    $boton = $evento === 'radicada' ? 'Revisar y aprobar' : 'Ver solicitud';
    $html = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;border:1px solid #ddd">'
        . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">AGRO-COSTA · SOLICITUDES A ADMINISTRACIÓN</div>'
        . '<div style="padding:20px;color:#222">'
        . '<div style="border-left:4px solid ' . $color . ';padding:2px 0 2px 12px;margin-bottom:14px;color:' . $color . ';font-weight:bold">' . e($tit) . ' · ' . codigo_solicitud($s) . '</div>'
        . '<p style="margin:0 0 12px">' . $frase . ':</p>'
        . '<table style="border-collapse:collapse;width:100%;margin-bottom:14px"><tr style="background:#f6f6f6"><th style="' . $td . ';text-align:right">Cant.</th><th style="' . $td . ';text-align:left">U. medida</th><th style="' . $td . ';text-align:left">Artículo</th><th style="' . $td . ';text-align:right">Costo est.</th></tr>' . $filas . '</table>'
        . '<table style="border-collapse:collapse;width:100%">' . $tabla . '</table>'
        . '<p style="margin-top:18px"><a href="' . e(APP_URL . '/solicitudes.php?ver=' . $s['id']) . '" style="background:#ffcd11;color:#000;padding:9px 16px;text-decoration:none;font-weight:bold;display:inline-block">' . $boton . '</a></p>'
        . '</div></div>';
    $pre = ['radicada' => '📝', 'aprobada' => '✅', 'rechazada' => '❌'][$evento] ?? (['recibida' => '📦', 'en_compra' => '🛒', 'cancelada' => '↩️', 'pendiente' => '🧾'][$s['estado']] ?? '🧾');
    $txt = ['radicada' => 'Por aprobar', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada'][$evento] ?? ($est[$s['estado']][0] ?? '');
    return enviar_correo($para, '', $pre . ' ' . codigo_solicitud($s) . ' · ' . $txt . ': ' . mb_strimwidth((string)$s['resumen'], 0, 70, '…'), $html);
}

// lunes: las entregadas a administracion que llevan muchos dias sin recibirse (SOLICITUDES_DIAS_ALERTA, por defecto 8)
function aviso_solicitudes_atrasadas() {
    $dias = max(1, (int)env('SOLICITUDES_DIAS_ALERTA', 8));
    $para = array_values(array_unique(array_filter(array_merge(correos_solicitudes(), array_column(aprobadores_solicitud(), 'email')))));
    if (!$para || !correo_configurado()) return 0;
    $st = db()->prepare(sql_solicitudes() . " WHERE s.estado IN ('por_aprobar','aprobada','pendiente','en_compra') AND s.fecha <= CURDATE() - INTERVAL ? DAY AND (s.aviso_atraso IS NULL OR s.aviso_atraso < CURDATE()) ORDER BY s.fecha");
    $st->execute([$dias]);
    $lista = $st->fetchAll();
    if (!$lista) return 0;
    $est = estados_solicitud();
    $filas = '';
    $td = 'padding:7px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top';
    foreach ($lista as $s) {
        $filas .= '<tr><td style="' . $td . '"><a href="' . e(APP_URL . '/solicitudes.php?ver=' . $s['id']) . '" style="color:#000;font-weight:bold">' . codigo_solicitud($s) . '</a><div style="color:#777;font-size:12px">' . fecha_corta($s['fecha']) . '</div></td>'
            . '<td style="' . $td . '">' . e($s['resumen']) . '<div style="color:#777;font-size:12px">' . e($s['solicitante']) . ' · ' . e($est[$s['estado']][0]) . '</div></td>'
            . '<td style="' . $td . ';text-align:right;color:#b71c1c;font-weight:bold;white-space:nowrap">' . dias_solicitud($s) . ' días</td></tr>';
    }
    $html = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;border:1px solid #ddd">'
        . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">AGRO-COSTA · SOLICITUDES A ADMINISTRACIÓN</div>'
        . '<div style="padding:20px;color:#222"><p style="margin-top:0">Estas solicitudes llevan más de <b>' . $dias . ' días</b> abiertas:</p>'
        . '<table style="border-collapse:collapse;width:100%">' . $filas . '</table>'
        . '<p style="margin-top:18px"><a href="' . e(APP_URL . '/solicitudes.php') . '" style="background:#ffcd11;color:#000;padding:9px 16px;text-decoration:none;font-weight:bold;display:inline-block">Ver solicitudes abiertas</a></p></div></div>';
    if (!enviar_correo($para, '', '⏳ ' . count($lista) . ' solicitud(es) a administración abiertas hace más de ' . $dias . ' días', $html)) return 0;
    $ids = array_column($lista, 'id');
    db()->prepare('UPDATE solicitudes SET aviso_atraso = CURDATE() WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
    return count($lista);
}
