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
