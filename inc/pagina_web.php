<?php
// historial de actualizaciones de la pagina web (e-commerce)
// el actualizador (Interfaz_DB) prende y apaga las banderas de estado_inventario en la base de la pagina;
// un disparador en esa base guarda cada vuelta en web_actualizaciones (WEB_01_historial_actualizaciones.sql).
// Aqui solo se lee: conexion aparte con WEB_DB_* del .env

function web_configurada() { return env('WEB_DB_NAME', '') !== '' && env('WEB_DB_USER', '') !== ''; }

function db_web() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . env('WEB_DB_HOST', 'localhost') . ';port=' . (int)env('WEB_DB_PORT', 3306) . ';dbname=' . env('WEB_DB_NAME') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, env('WEB_DB_USER'), env('WEB_DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
    ]);
    return $pdo;
}

// las horas vienen en UTC: se pasan a la hora de Colombia
function hora_web($utc) {
    if (!$utc) return null;
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $d->format('Y-m-d H:i:s');
}

function hace_dia_web($f) {
    $d = (int)((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($f)))) / 86400);
    return $d <= 0 ? 'hoy' : ($d === 1 ? 'ayer' : 'hace ' . $d . ' días');
}

function duracion_txt($s) {
    if ($s === null) return '—';
    $s = (int)$s;
    if ($s < 60) return $s . ' s';
    if ($s < 3600) return intdiv($s, 60) . ' min' . ($s % 60 ? ' ' . ($s % 60) . ' s' : '');
    return intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
}

// arma las actualizaciones: cada vuelta de "Sincronizar" (proceso 2) con el rato en que la pagina estuvo en aviso
// (proceso 1 dentro de ese rato); un proceso 1 suelto es el boton viejo "Subir cambios" (carga completa)
function actualizaciones_web($desde, $hasta) {
    $st = db_web()->prepare('SELECT * FROM web_actualizaciones WHERE proceso IN (1, 2, 3) AND fin_utc >= ? AND fin_utc < ? ORDER BY fin_utc, id');
    $st->execute([$desde, $hasta]);
    $filas = $st->fetchAll();
    $sync = [];
    $sueltas = [];
    $items = [];
    foreach ($filas as $f) {
        $f['inicio'] = hora_web($f['inicio_utc'] ?: $f['fin_utc']);
        $f['fin'] = hora_web($f['fin_utc']);
        if ((int)$f['proceso'] === 2) { $f['aviso'] = null; $sync[] = $f; }
        elseif ((int)$f['proceso'] === 3) $items[] = $f;
        else $sueltas[] = $f;
    }
    $res = [];
    foreach ($sueltas as $p1) {
        $dueno = null;
        foreach ($sync as $k => $s2) {
            if ($p1['fin_utc'] >= ($s2['inicio_utc'] ?: $s2['fin_utc']) && $p1['fin_utc'] <= $s2['fin_utc']) { $dueno = $k; break; }
        }
        if ($dueno !== null) $sync[$dueno]['aviso'] = $p1;
        else $res[] = ['tipo' => 'completa', 'fila' => $p1, 'aviso' => $p1];
    }
    foreach ($sync as $s2) {
        $tipo = $s2['resultado'] !== 'ok' ? 'cancelada' : ($s2['aviso'] ? 'cambios' : 'sin_cambios');
        $res[] = ['tipo' => $tipo, 'fila' => $s2, 'aviso' => $s2['aviso']];
    }
    // proceso 3: boton "Actualizar items" (cambios puntuales; el cliente no ve aviso)
    foreach ($items as $p3) $res[] = ['tipo' => $p3['resultado'] === 'ok' ? 'items' : 'cancelada', 'fila' => $p3, 'aviso' => null];
    usort($res, fn($a, $b) => strcmp($b['fila']['fin_utc'], $a['fila']['fin_utc']) ?: $b['fila']['id'] <=> $a['fila']['id']);
    return $res;
}

function tipos_actualizacion() {
    return [
        'cambios' => ['Subió cambios', 'chip-disponible'],
        'sin_cambios' => ['Sin cambios', 'chip-baja'],
        'cancelada' => ['Cancelada', 'chip-danado'],
        'items' => ['Ítems puntuales', 'chip-prestado'],
        'completa' => ['Carga completa', 'chip-asignado'],
    ];
}

// estado de ahora: si el actualizador esta corriendo (la diferencia se calcula en el servidor de la pagina)
function estado_web_ahora() {
    $st = db_web()->query('SELECT id, cargando, TIMESTAMPDIFF(SECOND, inicio_carga, NOW()) seg FROM estado_inventario WHERE id IN (1, 2) AND cargando = 1');
    return $st->fetchAll();
}

// ---------------------------------------------------------------- aviso por correo
// a quien: MAIL_WEB del .env (si no esta, los mismos de las copias: MAIL_RESPALDOS o MAIL_INVENTARIO) + los que tengan marcado "Página web" en Configuración > Avisos
function correos_web() {
    $l = env('MAIL_WEB', '') ?: (env('MAIL_RESPALDOS', '') ?: env('MAIL_INVENTARIO', ''));
    $fijos = array_values(array_filter(array_map('trim', preg_split('/[,;]/', (string)$l))));
    return array_values(array_unique(array_merge($fijos, correos_aviso('web_actualizada'))));
}

function correo_web(array $a) {
    $r = $a['fila'];
    $av = $a['aviso'];
    $tipos = tipos_actualizacion();
    $bien = $a['tipo'] !== 'cancelada';
    $td = 'padding:7px 10px;border-bottom:1px solid #eee';
    $fila = function ($k, $v) use ($td) { return '<tr><td style="' . $td . ';color:#666">' . $k . '</td><td style="' . $td . '"><b>' . $v . '</b></td></tr>'; };
    $cambios = $r['nuevas'] !== null || $r['cambiadas'] !== null || $r['eliminadas'] !== null
        ? (int)$r['nuevas'] . ' nuevas · ' . (int)$r['cambiadas'] . ' cambiadas · ' . (int)$r['eliminadas'] . ' eliminadas'
        : ($a['tipo'] === 'completa' ? 'Todo el inventario' : '—');
    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;border:1px solid #ddd">'
        . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">AGRO-COSTA · PÁGINA WEB</div>'
        . '<div style="padding:20px;color:#222">'
        . '<div style="padding:12px 14px;margin-bottom:14px;font-size:15px;background:' . ($bien ? '#e8f5e9;color:#1b5e20' : '#ffebee;color:#b71c1c') . '"><b>'
        . ($bien ? 'Se actualizó el inventario de la página' : 'La actualización se canceló o falló: la página no cambió') . '</b></div>'
        . '<table style="border-collapse:collapse;width:100%;font-size:14px">'
        . $fila('Tipo', e($tipos[$a['tipo']][0]))
        . $fila('Día', date('d/m/Y', strtotime($r['fin'])))
        . $fila('Hora', date('g:i a', strtotime($r['inicio'])) . ' → ' . date('g:i a', strtotime($r['fin'])))
        . $fila('Duración', duracion_txt($r['segundos']))
        . ($av ? $fila('Página en aviso', duracion_txt($av['segundos'])) : '')
        . $fila('Cambios', $cambios)
        . $fila('Quién', $r['usuario'] ? e($r['usuario']) . ($r['equipo'] ? ' · ' . e($r['equipo']) : '') : '—')
        . '</table>'
        . '<p style="margin-top:16px"><a href="' . e(rtrim(env('APP_URL', ''), '/')) . '/pagina_web.php" style="color:#000">Ver el historial →</a></p></div></div>';
    $asunto = ($bien ? '🌐 Página web actualizada' : '⚠️ Actualización de la página cancelada') . ' · ' . date('d/m g:i a', strtotime($r['fin'])) . ($r['usuario'] ? ' · ' . $r['usuario'] : '');
    $para = correos_web();
    return $para ? enviar_correo($para, '', $asunto, $html) : false;
}

// avisa las actualizaciones nuevas (la primera vez, las de las ultimas 6 horas)
// espera 2 minutos despues de terminar para que el actualizador alcance a dejar quien y cuantas
function avisar_web_nuevas() {
    $st = db()->prepare("SELECT valor FROM ajustes WHERE clave = 'web_ultimo_aviso'");
    $st->execute();
    $desde = $st->fetchColumn();
    $guardar = function ($v) { db()->prepare("INSERT INTO ajustes (clave, valor) VALUES ('web_ultimo_aviso', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$v]); };
    $hasta = gmdate('Y-m-d H:i:s', time() - 120);
    if (!$desde) $desde = gmdate('Y-m-d H:i:s', time() - 6 * 3600); // la primera vez: lo de las ultimas 6 horas
    // si el actualizador sigue corriendo, se espera a la proxima vuelta
    foreach (estado_web_ahora() as $h) if ((int)$h['id'] === 2) return 0;
    $n = 0;
    $lista = array_reverse(actualizaciones_web(gmdate('Y-m-d H:i:s', strtotime($desde . ' UTC') - 6 * 3600), $hasta));
    foreach ($lista as $a) {
        if ($a['fila']['fin_utc'] <= $desde || $a['tipo'] === 'sin_cambios') continue;
        // si el correo falla se intenta otra vez en la proxima vuelta
        if (!correo_web($a)) { $guardar(gmdate('Y-m-d H:i:s', strtotime($a['fila']['fin_utc'] . ' UTC') - 1)); return $n; }
        $n++;
    }
    $guardar($hasta);
    return $n;
}
