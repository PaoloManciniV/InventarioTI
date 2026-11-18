<?php
// copias de seguridad al disco externo: el script del disco reporta (copia.php),
// aqui se compara con la copia anterior y se manda el correo

// clave que lleva el script. Si no hay BACKUP_TOKEN en el .env sale de APP_KEY,
// asi funciona sin configurar nada; para cambiarla basta poner BACKUP_TOKEN
function clave_copias() {
    $t = trim((string)env('BACKUP_TOKEN', ''));
    if ($t !== '') return $t;
    $k = (string)env('APP_KEY', '');
    return $k === '' ? '' : substr(hash_hmac('sha256', 'copias-de-seguridad', $k), 0, 40);
}

function dias_copias() { return max(1, (int)env('BACKUP_DIAS', 1)); }

// ultimo dia en que se puede hacer la siguiente copia: la fecha de la ultima + BACKUP_DIAS (los domingos no cuentan).
// Ej.: copia el 18 con BACKUP_DIAS=1 -> a mas tardar el 19; si el 20 no hay copia, sale el aviso
function copia_limite($fecha) {
    $t = strtotime(date('Y-m-d', strtotime($fecha)));
    for ($i = 0; $i < dias_copias();) { $t = strtotime('+1 day', $t); if (date('w', $t) !== '0') $i++; }
    return date('Y-m-d', $t);
}

function copia_atrasada($ultima = null) {
    $ultima = $ultima ?? ultima_copia();
    return !$ultima || date('Y-m-d') > copia_limite($ultima['fecha']);
}

function correos_copias() {
    $l = env('MAIL_RESPALDOS', '') ?: env('MAIL_INVENTARIO', '');
    return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$l)))));
}

// las carpetas que debe tener el disco, si se fijan en el .env: BACKUP_CARPETAS=Traverse,Inventario,...
// si no se fijan, el script toma todas las de la raiz del disco
function carpetas_esperadas() {
    return array_values(array_filter(array_map('trim', explode(',', (string)env('BACKUP_CARPETAS', '')))));
}

// carpeta de las copias diarias (adentro se crea una por dia); acepta comodin para el año
function base_copias() { $b = trim((string)env('BACKUP_BASE', 'Copias diarias *')); return $b === '-' ? '' : $b; }

function personas_copias() {
    return db()->query("SELECT nombre FROM usuarios WHERE activo = 1 AND rol = 'admin' ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
}

// la ultima copia que cuenta: un reporte que dice "no hay carpeta de hoy" no es una copia
function ultima_copia() {
    return db()->query("SELECT * FROM copias_seguridad WHERE alertas IS NULL OR alertas NOT LIKE '%no hay carpeta de hoy%' ORDER BY fecha DESC, id DESC LIMIT 1")->fetch() ?: null;
}

function mb_legible($mb) {
    if ($mb === null || $mb === '') return '—';
    $mb = (float)$mb;
    return $mb >= 1024 ? number_format($mb / 1024, 1, ',', '.') . ' GB' : number_format($mb, $mb < 10 ? 1 : 0, ',', '.') . ' MB';
}

function hace_dias($fecha) {
    $d = (int)floor((time() - strtotime($fecha)) / 86400);
    return $d <= 0 ? 'hoy' : ($d === 1 ? 'ayer' : 'hace ' . $d . ' días');
}

// compara cada carpeta con la ultima copia que trajo carpetas: falta, vacia, igual a la anterior, mucho mas pequeña
// $diaria: el reporte es de la carpeta del dia (copias diarias); solo se compara con otro reporte igual,
// no con uno que reviso la raiz del disco
function revisar_carpetas(array $carpetas, $diaria = false) {
    $prev = null;
    foreach (db()->query("SELECT carpetas, disco FROM copias_seguridad WHERE origen = 'script' AND carpetas IS NOT NULL ORDER BY fecha DESC, id DESC LIMIT 30") as $r) {
        $era = !empty((json_decode((string)$r['disco'], true) ?: [])['dia']);
        if ($era === (bool)$diaria) { $prev = $r['carpetas']; break; }
    }
    $antes = [];
    foreach (json_decode((string)$prev, true) ?: [] as $c) $antes[mb_strtolower($c['nombre'])] = $c;
    $alertas = [];
    $vistas = [];
    foreach ($carpetas as &$c) {
        $k = mb_strtolower($c['nombre']);
        $vistas[$k] = 1;
        $a = $antes[$k] ?? null;
        $c['estado'] = 'ok';
        $c['nota'] = $a ? '' : 'Nueva (no estaba en la copia anterior)';
        if ($c['archivos'] === 0) {
            $c['estado'] = 'mal'; $c['nota'] = 'Está vacía';
        } elseif ($a && (int)$a['archivos'] === $c['archivos'] && abs((float)$a['mb'] - $c['mb']) < 0.01 && ($a['ultimo'] ?? '') === $c['ultimo']) {
            $c['estado'] = 'mal'; $c['nota'] = 'Igual a la copia anterior: no se copió nada nuevo';
        } elseif ($a && (float)$a['mb'] > 1 && $c['mb'] < (float)$a['mb'] * 0.8) {
            $c['estado'] = 'mal'; $c['nota'] = 'Pesa mucho menos que la copia anterior (' . mb_legible($a['mb']) . ')';
        }
        if ($c['estado'] === 'mal') $alertas[] = $c['nombre'] . ': ' . $c['nota'];
    }
    unset($c);
    // las fijadas en el .env que no llegaron cuentan como faltantes aunque sea la primera copia
    foreach (carpetas_esperadas() as $n) {
        $k = mb_strtolower($n);
        if (!isset($vistas[$k]) && !isset($antes[$k])) $antes[$k] = ['nombre' => $n];
    }
    foreach ($antes as $k => $a) {
        if (isset($vistas[$k])) continue;
        $carpetas[] = ['nombre' => $a['nombre'], 'archivos' => 0, 'mb' => 0, 'ultimo' => null, 'estado' => 'mal', 'nota' => 'No está en el disco (sí estaba en la copia anterior)'];
        $alertas[] = $a['nombre'] . ': no está en el disco';
    }
    return [$carpetas, $alertas];
}

// el reporte dice que en el disco no esta la carpeta de hoy: no es una copia hecha
function copia_sin_hoy($c) { return strpos((string)($c['alertas'] ?? ''), 'no hay carpeta de hoy') !== false; }

// alertas que no son de una carpeta en particular (ej. "no hay carpeta de hoy")
function alertas_generales($c) {
    $nombres = array_map('mb_strtolower', array_column(json_decode((string)($c['carpetas'] ?? ''), true) ?: [], 'nombre'));
    return array_values(array_filter(explode("\n", (string)($c['alertas'] ?? '')), function ($a) use ($nombres) {
        return trim($a) !== '' && !in_array(mb_strtolower(trim(explode(':', $a)[0])), $nombres, true);
    }));
}

function correo_copia($id) {
    $para = array_values(array_unique(array_merge(correos_copias(), correos_aviso('copias_reporte'))));
    if (!$para || !correo_configurado()) return false;
    $st = db()->prepare('SELECT * FROM copias_seguridad WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) return false;
    $ok = $c['estado'] === 'ok';
    $sin_hoy = copia_sin_hoy($c);
    $disco = json_decode((string)$c['disco'], true) ?: [];
    $generales = alertas_generales($c);
    $td = 'padding:6px 8px;border-bottom:1px solid #eee;font-size:13px;vertical-align:top';
    $filas = '';
    foreach (json_decode((string)$c['carpetas'], true) ?: [] as $f) {
        $bien = ($f['estado'] ?? 'ok') === 'ok';
        $filas .= '<tr><td style="' . $td . '">' . ($bien ? '✅' : '❌') . ' <b>' . e($f['nombre']) . '</b>' . (!empty($f['nota']) && !$bien ? '<div style="color:' . ($bien ? '#666' : '#c62828') . ';font-size:12px">' . e($f['nota']) . '</div>' : '') . '</td>'
            . '<td style="' . $td . ';text-align:right">' . number_format((int)$f['archivos'], 0, ',', '.') . '</td>'
            . '<td style="' . $td . ';text-align:right">' . mb_legible($f['mb']) . '</td>'
            . '<td style="' . $td . '">' . ($f['ultimo'] ? fecha_hora($f['ultimo']) : '—') . '</td></tr>';
    }
    $tabla = $filas ? '<table style="border-collapse:collapse;width:100%"><tr style="background:#f6f6f6"><th style="' . $td . ';text-align:left">' . (!empty($disco['dia']) ? 'Copia' : 'Carpeta') . '</th><th style="' . $td . ';text-align:right">Archivos</th><th style="' . $td . ';text-align:right">Tamaño</th><th style="' . $td . ';text-align:left">Archivo más reciente</th></tr>' . $filas . '</table>' : '';
    $info = [];
    if (!empty($disco['dia'])) $info[] = 'Carpeta del día ' . $disco['dia'];
    if (!empty($disco['etiqueta'])) $info[] = 'Disco ' . $disco['etiqueta'];
    if (!empty($disco['libre_gb']) && !empty($disco['total_gb'])) $info[] = 'libres ' . $disco['libre_gb'] . ' de ' . $disco['total_gb'] . ' GB';
    if ($c['pc']) $info[] = 'PC ' . $c['pc'] . ($c['usuario_windows'] ? ' (usuario ' . $c['usuario_windows'] . ')' : '');
    $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;border:1px solid #ddd">'
        . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">AGRO-COSTA · COPIA DE SEGURIDAD</div>'
        . '<div style="padding:20px;color:#222">'
        . '<div style="padding:12px 14px;margin-bottom:14px;font-size:15px;background:' . ($ok ? '#e8f5e9;color:#1b5e20' : '#ffebee;color:#b71c1c') . '"><b>' . ($ok ? 'Copia hecha, todo en orden' : ($sin_hoy ? 'No hay copia de hoy' : 'Copia hecha, pero hay que revisar')) . '</b>'
        . ($generales ? '<div style="font-size:13px;margin-top:4px">' . implode('<br>', array_map('e', $generales)) . '</div>' : '') . '</div>'
        . '<p style="margin:0 0 4px"><b>' . e($c['persona']) . '</b> ' . ($sin_hoy ? 'revisó el disco' : 'hizo la copia') . ' el ' . fecha_hora($c['fecha']) . ($c['origen'] === 'manual' ? ' (registrada a mano en la plataforma)' : '') . '.' . ($sin_hoy ? ' Lo que se ve abajo es la última copia que hay en el disco.' : '') . '</p>'
        . ($info ? '<p style="margin:0 0 14px;color:#666;font-size:12px">' . e(implode(' · ', $info)) . '</p>' : '')
        . $tabla
        . ($c['nota'] ? '<p style="margin-top:12px"><b>Nota:</b> ' . nl2br(e($c['nota'])) . '</p>' : '')
        . ($c['captura'] ? '<p style="font-size:13px">Trae captura de pantalla: se ve en la plataforma.</p>' : '')
        . '<p style="margin-top:16px"><a href="' . e(APP_URL . '/copias.php?id=' . $c['id']) . '" style="color:#000">Ver en la plataforma →</a></p>'
        . '</div></div>';
    return enviar_correo($para, '', ($ok ? '✅' : '⚠️') . ($sin_hoy ? ' Sin copia de hoy ' : ' Copia de seguridad ') . date('d/m', strtotime($c['fecha'])) . ' · ' . $c['persona'] . ($ok || $sin_hoy ? '' : ' · revisar'), $html);
}

// el script del disco con la direccion y la clave ya puestas
function script_copias_ps1() {
    $ps = file_get_contents(__DIR__ . '/reportar_copia.ps1');
    $ps = str_replace(['__URL__', '__CLAVE__'], [APP_URL . '/copia.php', clave_copias()], $ps);
    return "\xEF\xBB\xBF" . str_replace(["\r\n", "\n"], ["\n", "\r\n"], $ps);
}
