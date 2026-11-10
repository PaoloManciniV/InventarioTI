<?php
// utilidades generales

function e($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function ir($url) { header('Location: ' . $url); exit; }

function flash($msg = null, $tipo = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $tipo]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// sesion y roles

function usuario() { return $_SESSION['usuario'] ?? null; }

function es_admin() { return (usuario()['rol'] ?? '') === 'admin'; }

function requiere_login() {
    if (!usuario()) ir('login.php');
    // en cada pagina revisa que siga activo y trae rol y permisos: si lo inactivan o le quitan un modulo, aplica de una
    $st = db()->prepare('SELECT activo, rol, permisos, gestiona_usuarios FROM usuarios WHERE id = ?');
    $st->execute([usuario()['id']]);
    $u = $st->fetch();
    if (!$u || !$u['activo']) { $_SESSION = []; session_destroy(); ir('login.php'); }
    $_SESSION['usuario']['rol'] = $u['rol'];
    $_SESSION['usuario']['permisos'] = $u['permisos'];
    $_SESSION['usuario']['gestiona'] = (int)$u['gestiona_usuarios'];
    $_SESSION['chequeo'] = time();
}

function requiere_admin() {
    requiere_login();
    if (!es_admin()) { http_response_code(403); exit('Solo administradores'); }
}

// solo quien tenga la marca en la base (la marca gestiona_usuarios) crea usuarios, cambia roles, permisos o claves de otros.
// la marca no se puede poner desde la pantalla, solo por sql
function gestiona_usuarios(): bool { return !empty(usuario()['gestiona']) && es_admin(); }

// permisos por modulo. clave => [nombre, solo admin]
function modulos() {
    return [
        'inicio' => ['Vista general', false],
        'items' => ['Inventario', false],
        'colaboradores' => ['Colaboradores', false],
        'actas' => ['Actas y firmas', false],
        'mant' => ['Mantenimientos', false],
        'red' => ['Red y extensiones', false],
        'impresoras' => ['Impresoras', false],
        'informe' => ['Informe anual', false],
        'herramientas' => ['Herramientas', false],
        'contactos' => ['Contactos de soporte y compras', false],
        'wifi' => ['Redes WiFi', true],
        'importar' => ['Importar Excel', true],
        'config' => ['Configuración', true],
    ];
}

// permisos vacio (NULL) = todos los modulos, como estaba antes
function lista_permisos($permisos) {
    if ($permisos === null) return array_keys(modulos());
    return array_values(array_filter(explode(',', (string)$permisos), fn($k) => isset(modulos()[$k])));
}

function puede(string $m): bool {
    $u = usuario();
    if (!$u) return false;
    $mods = modulos();
    if (!isset($mods[$m])) return false;
    if ($mods[$m][1] && ($u['rol'] ?? '') !== 'admin') return false;
    return in_array($m, lista_permisos(array_key_exists('permisos', $u) ? $u['permisos'] : null), true);
}

// primer modulo al que puede entrar (para mandarlo ahi si no tiene la vista general)
function primer_modulo(): ?string {
    $pag = ['inicio' => 'index.php', 'items' => 'items.php', 'colaboradores' => 'colaboradores.php', 'actas' => 'actas.php', 'mant' => 'mantenimientos.php',
        'red' => 'red.php', 'impresoras' => 'impresoras.php', 'informe' => 'informe.php', 'herramientas' => 'herramientas.php', 'contactos' => 'herramientas.php?ver=contactos',
        'wifi' => 'wifi.php', 'importar' => 'importar.php', 'config' => 'config_panel.php'];
    foreach ($pag as $m => $url) if (puede($m)) return $url;
    return null;
}

function requiere_modulo(string ...$mods) {
    requiere_login();
    foreach ($mods as $m) if (puede($m)) return;
    http_response_code(403);
    $ir = primer_modulo();
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sin permiso</title>'
        . '<body style="font-family:sans-serif;background:#111;color:#eee;display:grid;place-items:center;min-height:90vh;text-align:center">'
        . '<div><h2>No tienes permiso para este módulo</h2><p style="color:#aaa">Pídele a sistemas que te lo habilite.</p>'
        . ($ir ? '<p><a style="color:#f5c400" href="' . e($ir) . '">Ir a lo que sí puedo ver</a></p>' : '') . '<p><a style="color:#aaa" href="logout.php">Cerrar sesión</a></p></div></body>');
}

// historial de ediciones: que campo cambio, de que a que, quien y cuando
function campos_legibles() {
    return ['etiqueta' => 'Etiqueta', 'nombre' => 'Nombre', 'marca' => 'Marca', 'modelo' => 'Modelo', 'serial' => 'Serial', 'especificaciones' => 'Especificaciones',
        'notas' => 'Notas', 'condicion' => 'Condición', 'fecha_compra' => 'Fecha de compra', 'valor' => 'Valor', 'categoria_id' => 'Categoría', 'sede_id' => 'Bodega', 'ubicacion' => 'Ubicación',
        'ip' => 'IP', 'mac' => 'MAC', 'hostname' => 'Hostname', 'extension' => 'Extensión', 'consumible' => 'Tóner', 'mant_meses' => 'Mantenimiento cada (meses)',
        'formato' => 'Formato', 'proximo_mant' => 'Próximo mantenimiento', 'cantidad' => 'Cantidad', 'clave_cifrada' => 'PIN / clave', 'estado' => 'Estado', 'responsable' => 'Responsable',
        'cedula' => 'Cédula', 'cargo' => 'Cargo', 'area_id' => 'Área', 'area' => 'Área', 'grupo' => 'Grupo', 'email' => 'Correo', 'telefono' => 'Teléfono', 'extension_pc' => 'Extensión MicroSIP',
        'activo' => 'Activo', 'ssid' => 'Red (SSID)', 'banda' => 'Banda', 'seguridad' => 'Seguridad', 'aps' => 'Access points', 'rol' => 'Rol', 'permisos' => 'Módulos',
        'password_hash' => 'Clave', 'usuario' => 'Usuario de ingreso', 'prefijo' => 'Prefijo', 'grupos' => 'Grupos', 'colaborador_id' => 'Responsable', 'orden' => 'Orden en la lista', 'para_que' => 'Para qué sirve', 'url' => 'Link', 'version' => 'Versión'];
}

// texto para mostrar un valor guardado (ids a nombres, claves ocultas)
function valor_legible($campo, $v) {
    if ($v === null || $v === '') return '';
    if (in_array($campo, ['clave_cifrada', 'password_hash'], true)) return '••••';
    $tablas = ['categoria_id' => 'categorias', 'sede_id' => 'sedes', 'area_id' => 'areas', 'colaborador_id' => 'colaboradores'];
    if (isset($tablas[$campo])) {
        static $cache = [];
        $k = $campo . ':' . $v;
        if (!isset($cache[$k])) {
            $st = db()->prepare('SELECT nombre FROM ' . $tablas[$campo] . ' WHERE id = ?');
            $st->execute([$v]);
            $cache[$k] = $st->fetchColumn() ?: '#' . $v;
        }
        return $cache[$k];
    }
    if ($campo === 'activo') return $v ? 'Sí' : 'No';
    if ($campo === 'estado') return estados_item()[$v] ?? $v;
    if ($campo === 'formato') return formatos_pc()[$v] ?? $v;
    if ($campo === 'valor') return '$' . number_format((float)$v, 0, ',', '.');
    return (string)$v;
}

// compara antes y despues y guarda solo lo que cambio
function auditar($tabla, $id, $antes, $despues, $nota = null) {
    $u = usuario()['id'] ?? null;
    $ins = db()->prepare('INSERT INTO cambios (tabla, ref_id, campo, antes, despues, nota, usuario_id) VALUES (?,?,?,?,?,?,?)');
    if ($antes === null) { $ins->execute([$tabla, $id, '_creado', null, null, $nota ?: 'Creado', $u]); return 1; }
    $n = 0;
    foreach ($despues as $campo => $nuevo) {
        if (!array_key_exists($campo, $antes)) continue;
        $viejo = $antes[$campo];
        $a = $viejo === null ? '' : trim((string)$viejo);
        $b = $nuevo === null ? '' : trim((string)$nuevo);
        if (is_numeric($a) && is_numeric($b) && (float)$a === (float)$b) continue;
        if ($a === $b) continue;
        $secreto = in_array($campo, ['clave_cifrada', 'password_hash'], true);
        $ins->execute([$tabla, $id, $campo, $secreto ? null : mb_substr($a, 0, 1000), $secreto ? null : mb_substr($b, 0, 1000), $nota, $u]);
        $n++;
    }
    return $n;
}

function cambios_de($tabla, $id, $limite = 50) {
    return cambios_de_varios($tabla, [$id], $limite)[(int)$id] ?? [];
}

function cambios_de_varios($tabla, $ids, $limite = 50) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    if (!$ids) return [];
    $st = db()->prepare('SELECT x.*, u.nombre usuario FROM cambios x LEFT JOIN usuarios u ON u.id = x.usuario_id
        WHERE x.tabla = ? AND x.ref_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY x.id DESC LIMIT ' . (int)$limite * count($ids));
    $st->execute(array_merge([$tabla], $ids));
    $por = [];
    foreach ($st->fetchAll() as $c) if (count($por[$c['ref_id']] ?? []) < $limite) $por[$c['ref_id']][] = $c;
    return $por;
}

function linea_cambio($c) {
    $campos = campos_legibles();
    if ($c['campo'] === '_creado') return '<b>' . e($c['nota'] ?: 'Creado') . '</b>';
    $nombre = $campos[$c['campo']] ?? $c['campo'];
    if (in_array($c['campo'], ['clave_cifrada', 'password_hash'], true)) return '<b>' . e($nombre) . '</b> cambiada';
    $a = valor_legible($c['campo'], $c['antes']);
    $b = valor_legible($c['campo'], $c['despues']);
    return '<b>' . e($nombre) . '</b>: <span class="antes">' . ($a === '' ? '<i>vacío</i>' : e(mb_strimwidth($a, 0, 120, '…'))) . '</span> → '
        . '<span class="despues">' . ($b === '' ? '<i>vacío</i>' : e(mb_strimwidth($b, 0, 120, '…'))) . '</span>';
}

// enlace discreto "ver cambios" en la ficha; si no hay cambios no se muestra nada
function pintar_cambios($lista) {
    if (!$lista) return '';
    $h = '<details class="cambios"><summary>Ver cambios (' . count($lista) . ')</summary><ul>';
    foreach ($lista as $c) {
        $h .= '<li><time>' . fecha_hora($c['fecha']) . ' · ' . e($c['usuario'] ?: 'sistema') . '</time><div>' . linea_cambio($c) . '</div></li>';
    }
    return $h . '</ul></details>';
}

// boton "ver cambios" para la cabecera de una lista: abre un panel con lo ultimo editado
// $por_id viene de cambios_de_varios y $nombres es id => etiqueta para saber de que equipo es
function boton_cambios($por_id, $nombres, $enlace = 'item.php?id=') {
    $todos = [];
    foreach ($por_id as $id => $l) foreach ($l as $c) { $c['que'] = $nombres[$id] ?? '#' . $id; $todos[] = $c; }
    usort($todos, fn($a, $b) => $b['id'] <=> $a['id']);
    $todos = array_slice($todos, 0, 60);
    $h = '<details class="panel-cambios no-imprimir"><summary class="btn btn-borde">Ver cambios' . ($todos ? ' <span>' . count($todos) . '</span>' : '') . '</summary><div class="panel">';
    if (!$todos) return $h . '<p class="tenue" style="margin:0">Todavía no hay ediciones registradas aquí.</p></div></details>';
    $h .= '<ul>';
    foreach ($todos as $c) {
        $h .= '<li><time>' . fecha_hora($c['fecha']) . ' · ' . e($c['usuario'] ?: 'sistema') . '</time><div><a class="mono" href="' . e($enlace . $c['ref_id']) . '">'
            . e($c['que']) . '</a> · ' . linea_cambio($c) . '</div></li>';
    }
    return $h . '</ul><a class="tenue" style="font-size:12px" href="config_panel.php?tab=cambios">Ver todo el registro</a></div></details>';
}

// registro de cada intento de ingreso
function registrar_acceso($email, $ok, $nota = null) {
    try {
        db()->prepare('INSERT INTO accesos (email, ip, ok, nota, ua) VALUES (?, ?, ?, ?, ?)')
            ->execute([substr($email, 0, 150), $_SERVER['REMOTE_ADDR'] ?? null, $ok ? 1 : 0, $nota, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    } catch (Throwable $ex) { error_log('accesos: ' . $ex->getMessage()); }
}

function avisar_bloqueo($email, $ip) {
    $para = env('MAIL_ALERTAS', '');
    if (!$para || !correo_configurado()) return;
    enviar_correo($para, 'Sistemas', 'Alerta: intentos fallidos en el inventario TI',
        '<p>Se bloqueó por 15 minutos el ingreso de <b>' . e($email) . '</b> después de 5 claves incorrectas.</p><p>IP: ' . e($ip) . '<br>Hora: ' . date('d/m/Y g:i a') . '</p>'
        . '<p>Si no fuiste tú ni alguien de sistemas, revisa Configuración → Accesos.</p>');
}

// cuando un equipo vuelve a sistemas se guarda de quien era (persona o lugar)
function guardar_ultimo_dueno($item_id) {
    $st = db()->prepare('SELECT i.estado, i.ubicacion, c.nombre persona, p.nombre prestado FROM items i
        LEFT JOIN colaboradores c ON c.id = i.colaborador_id LEFT JOIN colaboradores p ON p.id = i.prestado_a WHERE i.id = ?');
    $st->execute([$item_id]);
    $r = $st->fetch();
    if (!$r) return;
    $quien = $r['persona'] ?: ($r['prestado'] ? $r['prestado'] : (in_array($r['estado'], ['asignado', 'instalado'], true) && trim((string)$r['ubicacion']) !== '' && strcasecmp(trim($r['ubicacion']), 'Sistemas') !== 0 ? $r['ubicacion'] : null));
    if ($quien) db()->prepare('UPDATE items SET ultimo_dueno = ? WHERE id = ?')->execute([mb_substr($quien, 0, 150), $item_id]);
}

// un equipo libre esta apagado y guardado: no tiene ip
function quitar_ip_si_libre($item_id) {
    $st = db()->prepare("SELECT id, ip FROM items WHERE id = ? AND estado = 'disponible' AND COALESCE(ip, '') <> ''");
    $st->execute([$item_id]);
    if ($r = $st->fetch()) {
        db()->prepare('UPDATE items SET ip = NULL WHERE id = ?')->execute([$item_id]);
        if (function_exists('usuario') && usuario()) auditar('items', $item_id, ['ip' => $r['ip']], ['ip' => null], 'Quedó disponible (apagado), se quita la IP');
    }
}

// usuario de ingreso: letras numeros punto guion; unico y distinto a otros correos
function usuario_invalido($login, $id) {
    if ($login === '') return null;
    if (!preg_match('/^[a-z0-9._-]{4,40}$/', $login)) return 'El usuario debe tener de 4 a 40 caracteres: letras, números, punto o guion, sin espacios';
    $st = db()->prepare('SELECT id FROM usuarios WHERE (LOWER(usuario) = ? OR LOWER(email) = ?) AND id <> ?');
    $st->execute([$login, $login, $id]);
    return $st->fetch() ? 'Ese usuario ya existe' : null;
}

// clave fuerte: 10 caracteres con letras y numeros
function clave_debil($clave) {
    if (strlen($clave) < 10) return 'La clave debe tener mínimo 10 caracteres';
    if (!preg_match('/[A-Za-z]/', $clave) || !preg_match('/\d/', $clave)) return 'La clave debe tener letras y números';
    return null;
}

function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_campo() { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }

function verificar_csrf() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Sesion vencida, recarga la pagina');
    }
}

// catalogos

function sedes($solo_activas = true) {
    return db()->query('SELECT * FROM sedes' . ($solo_activas ? ' WHERE activo = 1' : '') . ' ORDER BY nombre')->fetchAll();
}

function categorias($solo_activas = true) {
    return db()->query('SELECT * FROM categorias' . ($solo_activas ? ' WHERE activo = 1' : '') . ' ORDER BY ' . orden_categorias())->fetchAll();
}

// orden en que se muestran las categorias (filtros, listas): lo mas usado primero, el resto por nombre
function orden_categorias($col = 'nombre') {
    $orden = ['Computador', 'Portatil', 'Telefono IP', 'Impresora', 'Periferico', 'Tablet', 'Celular', 'Access point', 'Video beam'];
    $lista = implode(', ', array_map(fn($n) => db()->quote($n), array_reverse($orden)));
    return "FIELD($col, $lista) DESC, $col";
}

function opciones($filas, $sel, $campo = 'nombre', $vacio = null) {
    $h = $vacio !== null ? '<option value="">' . e($vacio) . '</option>' : '';
    foreach ($filas as $f) {
        $h .= '<option value="' . $f['id'] . '"' . ((string)$f['id'] === (string)$sel ? ' selected' : '') . '>' . e($f[$campo]) . '</option>';
    }
    return $h;
}

function estados_item() {
    return ['disponible' => 'Disponible (libre)', 'asignado' => 'Asignado', 'prestado' => 'Prestado', 'mantenimiento' => 'Mantenimiento', 'danado' => 'Dañado', 'extraviado' => 'Extraviado', 'cedido' => 'Cedido (a otra empresa)', 'baja' => 'De baja'];
}

// lo que se puede prestar; la infraestructura instalada nunca sale en una entrega
function categorias_prestables() { return ['Portatil', 'Periferico', 'Red', 'Video beam']; }
function categorias_fijas() { return ['Access point', 'Servidor', 'Impresora']; }

function chip_estado($estado) {
    // instalado en su sitio (impresoras, access points, telefonos de area) se ve como asignado
    if ($estado === 'instalado') return '<span class="chip chip-asignado" title="Asignado a su ubicación">Asignado</span>';
    if ($estado === 'disponible') return '<span class="chip chip-disponible">Disponible</span>';
    if ($estado === 'cedido') return '<span class="chip chip-cedido">Cedido</span>';
    $n = estados_item()[$estado] ?? $estado;
    return '<span class="chip chip-' . e($estado) . '">' . e($n) . '</span>';
}

// condicion fisica (mal estado, sin disco...): chip rojo pequeno al lado del estado
function chip_condicion($c) {
    $c = trim((string)$c);
    if ($c === '') return '';
    // nuevo en verde, usado en gris, cualquier falla en rojo
    $cl = preg_match('#^nuev#iu', $c) ? 'chip-cond-nuevo' : (preg_match('#^usad#iu', $c) ? 'chip-cond-usado' : 'chip-condicion');
    return ' <span class="chip ' . $cl . '" title="Condición física">' . e($c) . '</span>';
}

function fecha_corta($f) { return $f ? date('d/m/Y', strtotime($f)) : ''; }

function fecha_hora($f) { return $f ? date('d/m/Y g:i a', strtotime($f)) : ''; }

function iniciales($nombre) {
    $p = preg_split('/\s+/', trim($nombre));
    return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
}

// siguiente etiqueta libre segun prefijo de la categoria
function siguiente_etiqueta($categoria_id) {
    $st = db()->prepare('SELECT prefijo FROM categorias WHERE id = ?');
    $st->execute([$categoria_id]);
    $pref = $st->fetchColumn() ?: 'ITM';
    $st = db()->prepare("SELECT etiqueta FROM items WHERE etiqueta LIKE ? ");
    $st->execute([$pref . '-%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $et) {
        $n = (int)substr($et, strlen($pref) + 1);
        if ($n > $max) $max = $n;
    }
    return $pref . '-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}

function siguiente_consecutivo($tipo, $anio) {
    $pref = ($tipo === 'entrega' ? 'AE' : 'AD') . $anio . '-';
    $st = db()->prepare('SELECT consecutivo FROM actas WHERE consecutivo LIKE ? ORDER BY id DESC');
    $st->execute([$pref . '%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $n = (int)substr($c, strlen($pref));
        if ($n > $max) $max = $n;
    }
    return $pref . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}

function registrar_movimiento($item_id, $tipo, $detalle = null, $colaborador_id = null, $acta_id = null) {
    $st = db()->prepare('INSERT INTO movimientos (item_id, tipo, colaborador_id, acta_id, usuario_id, detalle) VALUES (?,?,?,?,?,?)');
    $st->execute([$item_id, $tipo, $colaborador_id, $acta_id, usuario()['id'] ?? null, $detalle]);
}

// actas

function cargar_acta($id) {
    $st = db()->prepare('SELECT a.*, c.nombre colaborador, c.cedula, c.cargo, c.area, c.email, c.telefono,
        s.nombre sede, u.nombre creado_por_nombre
        FROM actas a
        LEFT JOIN colaboradores c ON c.id = a.colaborador_id
        LEFT JOIN sedes s ON s.id = a.sede_id
        LEFT JOIN usuarios u ON u.id = a.creado_por
        WHERE a.id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) return null;
    $st = db()->prepare('SELECT * FROM acta_items WHERE acta_id = ? ORDER BY id');
    $st->execute([$id]);
    $a['items'] = $st->fetchAll();
    return $a;
}

// huella del contenido al momento de firmar para detectar cambios despues
function hash_acta($a) {
    $base = [
        $a['consecutivo'], $a['tipo'], $a['colaborador_id'], $a['fecha'], $a['observaciones'],
        array_map(fn($i) => [$i['etiqueta'], $i['descripcion'], $i['serial'], $i['cantidad'], $i['condicion']], $a['items']),
    ];
    return hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE));
}

// mueve los items del inventario segun el acta
// en un prestamo todo queda en el inventario, por minimo que sea: lo que se escribe a mano (adaptador, mouse, cable...)
// se crea como item de sistemas y asi queda prestado y se puede devolver
function crear_item_prestamo(string $desc, ?string $serial, int $cant, ?string $cond): array {
    $pdo = db();
    $cat = preg_match('#adaptador|cable|multipuerto|hub|switch|router|red|hdmi|ethernet|usb#iu', $desc) ? 'Red' : 'Periferico';
    $pref = $cat === 'Red' ? 'RED-' : 'PER-';
    $st = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(etiqueta, ?) AS UNSIGNED)), 0) FROM items WHERE etiqueta LIKE ?");
    $st->execute([strlen($pref) + 1, $pref . '%']);
    $n = (int)$st->fetchColumn();
    $chk = $pdo->prepare('SELECT 1 FROM items WHERE etiqueta = ?');
    do { $n++; $et = $pref . str_pad((string)$n, 3, '0', STR_PAD_LEFT); $chk->execute([$et]); } while ($chk->fetchColumn());
    $pdo->prepare("INSERT INTO items (etiqueta, nombre, categoria_id, serial, cantidad, estado, ubicacion, notas, creado)
        VALUES (?, ?, (SELECT id FROM categorias WHERE nombre = ? LIMIT 1), ?, ?, 'disponible', 'Sistemas', ?, NOW())")
        ->execute([$et, mb_substr($desc, 0, 150), $cat, $serial ?: null, max(1, $cant), 'Creado al prestarlo' . ($cond ? ' (' . $cond . ')' : '')]);
    $id = (int)$pdo->lastInsertId();
    registrar_movimiento($id, 'creado', 'Se creó en el inventario al prestarlo');
    return [$id, $et];
}

function aplicar_acta($acta_id) {
    $a = cargar_acta($acta_id);
    if (!$a || !$a['aplica_inventario']) return;
    foreach ($a['items'] as $i) {
        if (!$i['item_id']) continue;
        if ($a['tipo'] === 'entrega' && !empty($a['prestamo'])) {
            // en un prestamo el dueno sigue siendo sistemas, el colaborador solo lo tiene
            $st = db()->prepare("UPDATE items SET estado = 'prestado', colaborador_id = NULL, prestado_a = ?, sede_id = COALESCE(?, sede_id), actualizado = NOW() WHERE id = ?");
            $st->execute([$a['colaborador_id'], $a['sede_id'], $i['item_id']]);
            registrar_movimiento($i['item_id'], 'entrega', 'Acta ' . $a['consecutivo'] . ': Sistemas lo presta a ' . $a['colaborador'], $a['colaborador_id'], $acta_id);
        } elseif ($a['tipo'] === 'entrega') {
            $st = db()->prepare("UPDATE items SET estado = 'asignado', colaborador_id = ?, prestado_a = NULL, sede_id = COALESCE(?, sede_id), actualizado = NOW() WHERE id = ?");
            $st->execute([$a['colaborador_id'], $a['sede_id'], $i['item_id']]);
            registrar_movimiento($i['item_id'], 'entrega', 'Acta ' . $a['consecutivo'] . ' a ' . $a['colaborador'], $a['colaborador_id'], $acta_id);
        } else {
            guardar_ultimo_dueno($i['item_id']);
            // lo que se entrego nuevo vuelve usado
            $st = db()->prepare("UPDATE items SET estado = 'disponible', colaborador_id = NULL, prestado_a = NULL, actualizado = NOW(),
                condicion = IF(condicion LIKE 'Nuev%', 'Usado', condicion)
                WHERE id = ? AND (colaborador_id = ? OR prestado_a = ? OR colaborador_id IS NULL)");
            $st->execute([$i['item_id'], $a['colaborador_id'], $a['colaborador_id']]);
            quitar_ip_si_libre($i['item_id']);
            registrar_movimiento($i['item_id'], 'devolucion', 'Acta ' . $a['consecutivo'] . ' de ' . $a['colaborador'], $a['colaborador_id'], $acta_id);
        }
    }
}

function guardar_firma_png($dataurl, $consecutivo) {
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataurl, $m)) return null;
    $bin = base64_decode($m[1]);
    if (!$bin || strlen($bin) > 2000000 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $dir = DIR_UPLOADS . '/firmas';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $nombre = 'firmas/' . preg_replace('/[^A-Za-z0-9-]/', '', $consecutivo) . '_' . bin2hex(random_bytes(6)) . '.png';
    file_put_contents(DIR_UPLOADS . '/' . $nombre, $bin);
    return $nombre;
}

// guarda foto o pdf subido y devuelve la ruta relativa
function guardar_archivo_subido($campo, $carpeta) {
    if (empty($_FILES[$campo]) || $_FILES[$campo]['error'] !== UPLOAD_ERR_OK) return null;
    $f = $_FILES[$campo];
    if ($f['size'] > 15 * 1024 * 1024) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) return null;
    $dir = DIR_UPLOADS . '/' . $carpeta;
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $nombre = $carpeta . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    move_uploaded_file($f['tmp_name'], DIR_UPLOADS . '/' . $nombre);
    return $nombre;
}

function url_archivo($ruta) { return 'archivo.php?f=' . rawurlencode($ruta); }

// correo por resend o zeptomail segun el env
// el motivo del ultimo fallo queda en $GLOBALS['correo_error']
// phpmailer de empleados, el mismo que usan salas y camionetas
function ruta_phpmailer() {
    foreach ([dirname(__DIR__) . '/PHPMailer/src', dirname(__DIR__, 2) . '/empleados/PHPMailer/src'] as $r) {
        if (is_file($r . '/PHPMailer.php')) return $r;
    }
    return null;
}

// resend o zeptomail si tienen llave; si no, el smtp de zoho que ya usa empleados
function proveedor_correo() {
    $prov = env('MAIL_PROVIDER', '');
    if (in_array($prov, ['resend', 'zeptomail'], true) && env('MAIL_API_KEY', '') !== '') return $prov;
    if (env('MAIL_HOST', '') !== '' && env('MAIL_USER', '') !== '' && env('MAIL_PASS', '') !== '' && ruta_phpmailer()) return 'smtp';
    return 'none';
}

function correo_configurado() { return proveedor_correo() !== 'none'; }

function enviar_smtp($para, $nombre, $asunto, $html, $imagenes = []) {
    $r = ruta_phpmailer();
    require_once $r . '/Exception.php';
    require_once $r . '/PHPMailer.php';
    require_once $r . '/SMTP.php';
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host = env('MAIL_HOST');
        $m->SMTPAuth = true;
        $m->Username = env('MAIL_USER');
        $m->Password = env('MAIL_PASS');
        $puerto = (int)env('MAIL_PORT', 465);
        $seg = env('MAIL_SECURE', $puerto === 465 ? 'ssl' : 'tls');
        $m->SMTPSecure = $seg === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : ($seg === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        if ($seg === 'none') $m->SMTPAutoTLS = false;
        $m->Port = $puerto;
        $m->CharSet = 'UTF-8';
        $m->Timeout = 20;
        // zoho solo deja mandar como la cuenta que se autentica
        $m->setFrom(env('MAIL_USER'), env('MAIL_FROM_NAME', 'Sistemas Agro-Costa'));
        $m->addReplyTo(env('MAIL_REPLY', 'sistemas@agro-costa.com'), 'Sistemas Agro-Costa');
        foreach ((array)$para as $k => $p) $m->addAddress($p, $k === 0 ? $nombre : '');
        // imagenes dentro del correo como la firma
        foreach ($imagenes as $cid => $ruta) if (is_file($ruta)) $m->addEmbeddedImage($ruta, $cid);
        $m->isHTML(true);
        $m->Subject = $asunto;
        $m->Body = $html;
        $m->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</li>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $m->send();
        return true;
    } catch (Throwable $ex) {
        $GLOBALS['correo_error'] = 'smtp: ' . ($m->ErrorInfo ?: $ex->getMessage());
        error_log('correo a ' . $para . ' fallo: ' . $GLOBALS['correo_error']);
        return false;
    }
}

function enviar_correo($para, $nombre, $asunto, $html, $imagenes = []) {
    $GLOBALS['correo_error'] = '';
    // uno o varios correos separados por coma
    $lista = array_values(array_filter(array_map('trim', is_array($para) ? $para : explode(',', (string)$para))));
    foreach ($lista as $p) if (!filter_var($p, FILTER_VALIDATE_EMAIL)) { $GLOBALS['correo_error'] = 'el correo ' . $p . ' no es valido'; return false; }
    if (!$lista) { $GLOBALS['correo_error'] = 'no hay a quien enviar'; return false; }
    $prov = proveedor_correo();
    if ($prov === 'none') { $GLOBALS['correo_error'] = 'no se encontro el smtp (private/.env de empleados) ni PHPMailer en /empleados'; return false; }
    if ($prov === 'smtp') return enviar_smtp($lista, $nombre, $asunto, $html, $imagenes);
    $para = $lista[0];
    $from = env('MAIL_FROM', 'sistemas@agro-costa.com');
    $from_nombre = env('MAIL_FROM_NAME', 'Sistemas Agro-Costa');
    if ($prov === 'resend') {
        $url = 'https://api.resend.com/emails';
        $hdr = ['Authorization: Bearer ' . env('MAIL_API_KEY'), 'Content-Type: application/json'];
        $body = ['from' => "$from_nombre <$from>", 'to' => [$para], 'subject' => $asunto, 'html' => $html];
    } elseif ($prov === 'zeptomail') {
        $url = 'https://api.zeptomail.com/v1.1/email';
        $hdr = ['Authorization: ' . env('MAIL_API_KEY'), 'Content-Type: application/json', 'Accept: application/json'];
        $body = ['from' => ['address' => $from, 'name' => $from_nombre],
            'to' => [['email_address' => ['address' => $para, 'name' => $nombre]]],
            'subject' => $asunto, 'htmlbody' => $html];
    } else {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 20]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return true;
    // el proveedor dice por que no lo mando
    $j = json_decode((string)$resp, true);
    $msg = $j['error']['details'][0]['message'] ?? $j['error']['message'] ?? $j['message'] ?? ($cerr ?: substr(strip_tags((string)$resp), 0, 200));
    $GLOBALS['correo_error'] = 'respuesta ' . $code . ': ' . $msg;
    error_log('correo a ' . $para . ' fallo: ' . $GLOBALS['correo_error']);
    return false;
}

// correo para firmar un acta con el enlace
function correo_acta($a, $link) {
    $tipo = !empty($a['prestamo']) ? 'préstamo' : ($a['tipo'] === 'entrega' ? 'entrega' : 'devolución');
    $filas = '';
    foreach ($a['items'] as $i) $filas .= '<li>' . e(($i['etiqueta'] ? $i['etiqueta'] . ' · ' : '') . $i['descripcion']) . ($i['cantidad'] > 1 ? ' ×' . (int)$i['cantidad'] : '') . '</li>';
    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;border:1px solid #ddd">'
        . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">AGRO-COSTA · SISTEMAS</div>'
        . '<div style="padding:20px;color:#222"><p>Hola ' . e($a['colaborador']) . ',</p>'
        . '<p>Tienes un acta de <b>' . $tipo . '</b> pendiente por firmar (<b>' . e($a['consecutivo']) . '</b>):</p><ul>' . $filas . '</ul>'
        . '<p style="margin:24px 0"><a href="' . e($link) . '" style="background:#ffcd11;color:#000;padding:12px 22px;text-decoration:none;font-weight:bold">Revisar y firmar</a></p>'
        . '<p style="color:#666;font-size:12px">El enlace vence en 7 días. Si no esperabas este correo comunícate con el área de sistemas.<br>' . e($link) . '</p></div></div>';
}

// guarda la firma y aplica el acta al inventario
// devuelve mensaje de error o null si quedo firmada
function firmar_acta($acta_id, $dataurl, $nombre, $cedula, $modo) {
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT estado, consecutivo FROM actas WHERE id = ? FOR UPDATE');
    $st->execute([$acta_id]);
    $act = $st->fetch();
    if (!$act || $act['estado'] !== 'pendiente') { $pdo->rollBack(); return 'Esta acta ya no esta pendiente de firma'; }
    $nombre = trim($nombre);
    $cedula = preg_replace('/[^0-9A-Za-z]/', '', $cedula);
    if (mb_strlen($nombre) < 5 || strlen($cedula) < 5) { $pdo->rollBack(); return 'Escribe nombre completo y cedula'; }
    $png = guardar_firma_png($dataurl, $act['consecutivo']);
    if (!$png) { $pdo->rollBack(); return 'No se pudo leer la firma, intenta de nuevo'; }
    $a = cargar_acta($acta_id);
    $pdo->prepare("UPDATE actas SET estado = 'firmada', firma_archivo = ?, firma_nombre = ?, firma_cedula = ?, firmado_en = NOW(),
        firmado_modo = ?, firmado_ip = ?, firmado_ua = ?, hash_contenido = ?, token_expira = NULL WHERE id = ?")
        ->execute([$png, $nombre, $cedula, $modo, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), hash_acta($a), $acta_id]);
    // la cedula tambien queda en la ficha si no la tenia
    $pdo->prepare("UPDATE colaboradores SET cedula = ? WHERE id = ? AND (cedula IS NULL OR cedula = '')")->execute([$cedula, $a['colaborador_id']]);
    aplicar_acta($acta_id);
    $pdo->commit();
    notificar_firma($acta_id);
    return null;
}

// copia del acta firmada a sistemas; si falla no frena la firma
function notificar_firma($acta_id) {
    $GLOBALS['copia_firma'] = null;
    $a = cargar_acta($acta_id);
    if (!$a || $a['estado'] !== 'firmada') return;
    $para = array_filter(array_map('trim', explode(',', env('MAIL_COPIAS', ''))));
    // la persona que firmo primero, si tiene correo
    if ($a['email'] && filter_var($a['email'], FILTER_VALIDATE_EMAIL)) array_unshift($para, strtolower($a['email']));
    $para = array_values(array_unique($para));
    if (!$para) return;
    if (!correo_configurado()) { $GLOBALS['copia_firma'] = 'No se envió copia por correo: el correo no está configurado.'; return; }
    $tipo = !empty($a['prestamo']) ? 'Préstamo' : ($a['tipo'] === 'entrega' ? 'Entrega' : 'Devolución');
    $modo = ['pantalla' => 'en el celular de sistemas', 'link' => 'por enlace', 'papel' => 'en papel'][$a['firmado_modo']] ?? $a['firmado_modo'];
    $filas = '';
    foreach ($a['items'] as $n => $i) {
        $filas .= '<tr><td style="padding:6px;border-bottom:1px solid #eee">' . ($n + 1) . '</td><td style="padding:6px;border-bottom:1px solid #eee">' . e($i['etiqueta'] ?: '—')
            . '</td><td style="padding:6px;border-bottom:1px solid #eee">' . e($i['descripcion']) . '</td><td style="padding:6px;border-bottom:1px solid #eee">' . (int)$i['cantidad']
            . '</td><td style="padding:6px;border-bottom:1px solid #eee">' . e($i['condicion'] ?: '') . '</td></tr>';
    }
    $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;border:1px solid #ddd">'
        . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">AGRO-COSTA · ACTA FIRMADA</div>'
        . '<div style="padding:20px;color:#222">'
        . '<p style="margin-top:0">Esta es la copia del acta que quedó firmada.</p>'
        . '<p style="font-size:16px"><b>' . e($a['consecutivo']) . '</b> · ' . $tipo . '</p>'
        . '<p><b>' . e($a['colaborador']) . '</b> · CC ' . e($a['firma_cedula']) . '<br>' . e(trim(($a['cargo'] ?? '') . ' · ' . ($a['area'] ?? ''), ' ·')) . '</p>'
        . '<table style="border-collapse:collapse;width:100%;font-size:13px"><tr style="background:#f6f6f6"><th style="padding:6px;text-align:left">#</th><th style="padding:6px;text-align:left">Etiqueta</th>'
        . '<th style="padding:6px;text-align:left">Descripción</th><th style="padding:6px;text-align:left">Cant.</th><th style="padding:6px;text-align:left">Condición</th></tr>' . $filas . '</table>'
        . ($a['observaciones'] ? '<p><b>Observaciones:</b> ' . e($a['observaciones']) . '</p>' : '')
        . '<p style="margin-top:20px"><img src="cid:firma" alt="firma" style="max-width:300px;border-bottom:1px solid #999"><br>'
        . '<small>Firmó ' . e($a['firma_nombre']) . ' el ' . fecha_hora($a['firmado_en']) . ' ' . $modo . ($a['firmado_ip'] ? ' · IP ' . e($a['firmado_ip']) : '') . '</small></p>'
        . '<p><a href="' . e(APP_URL . '/acta.php?id=' . $a['id']) . '" style="background:#ffcd11;color:#000;padding:10px 18px;text-decoration:none;font-weight:bold">Ver acta</a></p>'
        . '<p style="color:#888;font-size:11px">Huella: ' . e($a['hash_contenido']) . '</p></div></div>';
    if (enviar_correo($para, $a['colaborador'], 'Acta firmada ' . $a['consecutivo'] . ' · ' . $tipo . ' · ' . $a['colaborador'], $html,
        ['firma' => DIR_UPLOADS . '/' . $a['firma_archivo']])) {
        $GLOBALS['copia_firma'] = 'Copia enviada a ' . implode(', ', $para);
    } else {
        $GLOBALS['copia_firma'] = 'No se envió la copia: ' . $GLOBALS['correo_error'];
        error_log('copia de firma ' . $a['consecutivo'] . ' no enviada: ' . $GLOBALS['correo_error']);
    }
}

// areas parametrizadas con grupos opcionales como RED y AD en comercial
function areas($solo_activas = true) {
    return db()->query('SELECT * FROM areas' . ($solo_activas ? ' WHERE activo = 1' : '') . ' ORDER BY nombre')->fetchAll();
}

function area_por_id($id) {
    $st = db()->prepare('SELECT * FROM areas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

function grupos_area($area) {
    return array_values(array_filter(array_map('trim', explode(',', (string)($area['grupos'] ?? '')))));
}


// cifrado de claves wifi y pines con APP_KEY del env
// formato v1 base64 de iv tag y texto cifrado
function llave_app() {
    $k = (string)env('APP_KEY', '');
    if (str_starts_with($k, 'base64:')) $k = base64_decode(substr($k, 7));
    return strlen($k) === 32 ? $k : null;
}

function cifrar($plano) {
    if ($plano === null || $plano === '') return null;
    $k = llave_app();
    if (!$k) throw new RuntimeException('Falta APP_KEY en el .env');
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plano, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function descifrar($valor) {
    if (!$valor) return null;
    // valores cargados por sql que aun no se han cifrado
    if (str_starts_with($valor, 'plano:')) return substr($valor, 6);
    if (!str_starts_with($valor, 'v1:') || !($k = llave_app())) return null;
    $b = base64_decode(substr($valor, 3));
    $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    return $p === false ? null : $p;
}

// cifra lo que llego en plano por la carga sql
function cifrar_pendientes() {
    if (!llave_app()) return;
    foreach (['items', 'wifi_redes'] as $t) {
        $st = db()->query("SELECT id, clave_cifrada FROM $t WHERE clave_cifrada LIKE 'plano:%'");
        foreach ($st->fetchAll() as $f) {
            db()->prepare("UPDATE $t SET clave_cifrada = ? WHERE id = ?")->execute([cifrar(substr($f['clave_cifrada'], 6)), $f['id']]);
        }
    }
}

function registrar_vista_clave($tipo, $ref_id) {
    db()->prepare('INSERT INTO claves_log (usuario_id, tipo, ref_id, ip) VALUES (?,?,?,?)')
        ->execute([usuario()['id'], $tipo, $ref_id, $_SERVER['REMOTE_ADDR'] ?? null]);
}

// campo de clave que se revela con el ojito pidiendo al servidor
function campo_secreto($tipo, $id, $tiene) {
    if (!$tiene) return '<span class="tenue">—</span>';
    return '<span class="secreto" data-tipo="' . e($tipo) . '" data-id="' . (int)$id . '"><span class="secreto-valor mono">••••••••</span>'
        . '<button type="button" class="ojito-mini" title="Ver">&#128065;</button></span>';
}

// mantenimientos

function siguiente_consecutivo_mant($anio) {
    $pref = 'MT' . $anio . '-';
    $st = db()->prepare('SELECT consecutivo FROM mantenimientos WHERE consecutivo LIKE ?');
    $st->execute([$pref . '%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) $max = max($max, (int)substr($c, strlen($pref)));
    return $pref . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}

// frecuencia del item o la de su categoria
function frecuencia_item($item) {
    return (int)($item['mant_meses'] ?: ($item['cat_mant_meses'] ?? 0));
}

// meses del ano en que toca segun ultimo mantenimiento o febrero por defecto
function meses_plan($item, $anio) {
    $f = frecuencia_item($item);
    if ($f <= 0) return [];
    // con fecha programada el plan arranca ahi; si no, desde el ultimo preventivo
    if (!empty($item['proximo_mant'])) {
        $base = date('Y-m', strtotime($item['proximo_mant']));
        $desde = 0;
    } elseif ($item['ultimo_mant']) {
        $base = date('Y-m', strtotime($item['ultimo_mant']));
        $desde = 1;
    } else {
        $base = date('Y-m');
        $desde = 0;
    }
    [$by, $bm] = array_map('intval', explode('-', $base));
    $meses = [];
    for ($m = 1; $m <= 12; $m++) {
        $dif = ($anio - $by) * 12 + ($m - $bm);
        if ($dif >= $desde && $dif % $f === 0) $meses[] = $m;
    }
    return $meses;
}

function proximo_mant($item) {
    $f = frecuencia_item($item);
    if ($f <= 0) return null;
    if (!empty($item['proximo_mant'])) return $item['proximo_mant'];
    if (!$item['ultimo_mant']) return date('Y-m-d');
    return date('Y-m-d', strtotime($item['ultimo_mant'] . " +$f months"));
}

// items que llevan mantenimiento con su frecuencia efectiva
function items_con_mant($filtro_cat = null) {
    $sql = "SELECT i.*, cat.nombre categoria, cat.mant_meses cat_mant_meses, c.nombre responsable, s.nombre sede
        FROM items i LEFT JOIN categorias cat ON cat.id = i.categoria_id
        LEFT JOIN colaboradores c ON c.id = i.colaborador_id LEFT JOIN sedes s ON s.id = i.sede_id
        WHERE i.estado NOT IN ('baja', 'extraviado', 'cedido') AND COALESCE(i.mant_meses, cat.mant_meses, 0) > 0";
    $par = [];
    if ($filtro_cat) { $sql .= ' AND i.categoria_id = ?'; $par[] = $filtro_cat; }
    $st = db()->prepare($sql . ' ORDER BY cat.nombre, i.etiqueta');
    $st->execute($par);
    return $st->fetchAll();
}

function mant_vencidos_total() {
    static $n = null;
    if ($n !== null) return $n;
    $n = 0;
    try {
        foreach (items_con_mant() as $i) if ($i['estado'] !== 'danado' && proximo_mant($i) <= date('Y-m-d')) $n++;
    } catch (Throwable $e) { $n = 0; }
    return $n;
}

// hoja de vida de equipos y colaboradores

// nombre corto del tipo de pc para mostrar en fichas
function tipo_pc($item) {
    $cat = mb_strtolower((string)($item['categoria'] ?? ''));
    if ($cat === 'portatil') return 'Portátil';
    if ($cat === 'computador') return ['all_in_one' => 'All in one', 'mini' => 'Mini PC'][$item['formato'] ?? ''] ?? 'PC de mesa';
    return $item['categoria'] ?? '';
}

function formatos_pc() {
    return ['' => 'De mesa (torre)', 'all_in_one' => 'All in one', 'mini' => 'Mini PC'];
}

// movimientos que ya se ven por su acta o mantenimiento y no se repiten
function mov_repetido($tipo) {
    return in_array($tipo, ['entrega', 'devolucion', 'historico', 'mantenimiento', 'consumible'], true);
}

function evento($fecha, $clase, $titulo, $detalle = '', $link = null, $chips = []) {
    return compact('fecha', 'clase', 'titulo', 'detalle', 'link', 'chips');
}

function chips_acta($a) {
    $c = [];
    if (!empty($a['prestamo'])) $c[] = ['Préstamo', 'pendiente'];
    if ($a['historica']) $c[] = ['Papel', 'gris'];
    if ($a['estado'] === 'pendiente') $c[] = ['Sin firmar', 'pendiente'];
    if ($a['estado'] === 'anulada') $c[] = ['Anulada', 'anulada'];
    return $c;
}

function linea_tiempo_item($id) {
    $ev = [];
    $st = db()->prepare("SELECT a.*, c.nombre persona, c.id persona_id, ai.descripcion, ai.condicion FROM acta_items ai
        JOIN actas a ON a.id = ai.acta_id LEFT JOIN colaboradores c ON c.id = a.colaborador_id
        WHERE ai.item_id = ? AND a.estado <> 'anulada'");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $a) {
        if ($a['tipo'] === 'devolucion') { $clase = 'devolucion'; $t = 'Devuelto por ' . $a['persona']; }
        elseif (!empty($a['prestamo'])) { $clase = 'prestamo'; $t = 'Préstamo de Sistemas a ' . $a['persona']; }
        else { $clase = 'entrega'; $t = 'Asignado a ' . $a['persona']; }
        $det = trim(($a['observaciones'] ?? '') . ($a['condicion'] ? ' · Condición: ' . $a['condicion'] : ''), ' ·');
        $ev[] = evento($a['fecha'], $clase, $t, $det, 'acta.php?id=' . $a['id'], array_merge([[$a['consecutivo'], 'gris']], chips_acta($a)));
    }
    $st = db()->prepare('SELECT m.*, c.nombre persona FROM mantenimientos m LEFT JOIN colaboradores c ON c.id = m.colaborador_id WHERE m.item_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $m) {
        $t = $m['tipo'] === 'correctivo' ? 'Reparación' : 'Mantenimiento preventivo';
        $det = str_replace("\n", ' · ', (string)$m['actividades']) . ($m['observaciones'] ? ' — ' . $m['observaciones'] : '');
        $ev[] = evento($m['fecha'], $m['tipo'] === 'correctivo' ? 'reparacion' : 'mantenimiento', $t . ($m['persona'] ? ' · ' . $m['persona'] : ''), $det,
            'mantenimiento.php?id=' . $m['id'], [[$m['consecutivo'], 'gris']]);
    }
    $st = db()->prepare('SELECT * FROM consumible_cambios WHERE item_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $cb) {
        $ev[] = evento($cb['fecha'], 'consumible', 'Cambio de ' . ($cb['consumible'] ?: 'tóner'),
            trim(($cb['contador'] ? 'Contador ' . number_format($cb['contador'], 0, ',', '.') : '') . ' ' . ($cb['notas'] ?? '')));
    }
    $st = db()->prepare('SELECT m.*, u.nombre usuario FROM movimientos m LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.item_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $m) {
        if (mov_repetido($m['tipo'])) continue;
        $clase = in_array($m['tipo'], ['danado', 'baja', 'extraviado'], true) ? 'alerta' : ($m['tipo'] === 'cambio_dueno' ? 'entrega' : ($m['tipo'] === 'reparacion' ? 'reparacion' : 'sistema'));
        $ev[] = evento(substr($m['fecha'], 0, 10), $clase, $m['detalle'], $m['usuario'] ? 'Por ' . $m['usuario'] : '');
    }
    usort($ev, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    return $ev;
}

// cuanto tiempo en palabras
function duracion_texto($desde, $hasta) {
    if (!$desde || !$hasta) return '';
    $a = new DateTime($desde);
    $b = new DateTime($hasta);
    $d = $a->diff($b);
    if ($d->days < 1) return 'el mismo día';
    if ($d->days < 45) return $d->days . ($d->days === 1 ? ' día' : ' días');
    $partes = [];
    if ($d->y) $partes[] = $d->y . ($d->y === 1 ? ' año' : ' años');
    if ($d->m) $partes[] = $d->m . ($d->m === 1 ? ' mes' : ' meses');
    return implode(' ', $partes) ?: $d->days . ' días';
}

// cada persona que tuvo el equipo con desde hasta y duracion
// una entrega dura hasta el siguiente movimiento del equipo
function tenencias_item($item) {
    $st = db()->prepare("SELECT DISTINCT a.id acta_id, a.consecutivo, a.fecha, a.tipo, a.prestamo, c.nombre, c.id, c.activo FROM acta_items ai
        JOIN actas a ON a.id = ai.acta_id LEFT JOIN colaboradores c ON c.id = a.colaborador_id
        WHERE ai.item_id = ? AND a.estado <> 'anulada' ORDER BY a.fecha, a.id");
    $st->execute([$item['id']]);
    $ev = $st->fetchAll();
    // cambios de responsable hechos a mano (sin acta) tambien abren y cierran periodos
    $st = db()->prepare("SELECT NULL acta_id, NULL consecutivo, DATE(m.fecha) fecha, IF(m.colaborador_id IS NULL, 'devolucion', 'entrega') tipo, 0 prestamo,
        COALESCE(c.nombre, 'Sistemas') nombre, c.id, COALESCE(c.activo, 1) activo, m.id mov_id FROM movimientos m LEFT JOIN colaboradores c ON c.id = m.colaborador_id
        WHERE m.item_id = ? AND m.tipo = 'cambio_dueno'");
    $st->execute([$item['id']]);
    if ($man = $st->fetchAll()) {
        $ev = array_merge($ev, $man);
        usort($ev, fn($a, $b) => [$a['fecha'], $a['acta_id'] === null ? 1 : 0, (int)($a['acta_id'] ?? $a['mov_id'])] <=> [$b['fecha'], $b['acta_id'] === null ? 1 : 0, (int)($b['acta_id'] ?? $b['mov_id'])]);
    }
    $hoy = date('Y-m-d');
    $lista = [];
    foreach ($ev as $n => $a) {
        if ($a['tipo'] !== 'entrega') continue;
        $prestamo = (int)$a['prestamo'];
        // cualquier movimiento cierra el periodo; en un prestamo el dueno es sistemas
        $sig = $ev[$n + 1] ?? null;
        if ($sig) $cierre = $sig['tipo'] === 'devolucion' ? 'devolución' : ($sig['prestamo'] ? 'volvió a Sistemas y se prestó a ' : ($prestamo ? 'volvió a Sistemas y pasó a ' : 'pasó a ')) . $sig['nombre'];
        else $cierre = $prestamo ? 'sin devolución registrada' : 'sin cierre registrado';
        $lista[] = ['nombre' => $a['nombre'], 'id' => $a['id'], 'activo' => $a['activo'], 'prestamo' => $prestamo,
            'desde' => $a['fecha'], 'hasta' => $sig ? $sig['fecha'] : null, 'actual' => false, 'acta_id' => $a['acta_id'], 'consecutivo' => $a['consecutivo'],
            'cierre' => $cierre];
    }
    // si la misma persona lo vuelve a recibir (reparacion, cambio de vidrio) es el mismo periodo
    $unidas = [];
    foreach ($lista as $t) {
        $u = count($unidas) - 1;
        if ($u >= 0 && (int)$unidas[$u]['prestamo'] === (int)$t['prestamo'] && (int)$unidas[$u]['id'] === (int)$t['id'] && $unidas[$u]['hasta'] === $t['desde']) {
            $unidas[$u]['hasta'] = $t['hasta'];
            $unidas[$u]['cierre'] = $t['cierre'];
            continue;
        }
        $unidas[] = $t;
    }
    $lista = $unidas;
    // el ultimo dueno abierto que coincide con el responsable de hoy sigue con el equipo
    $tiene = $item['estado'] === 'prestado' ? ($item['prestado_a'] ?? null) : ($item['estado'] === 'asignado' ? $item['colaborador_id'] : null);
    if ($tiene) {
        for ($k = count($lista) - 1; $k >= 0; $k--) {
            if ($lista[$k]['hasta'] !== null) continue;
            if ((int)$lista[$k]['id'] === (int)$tiene && (bool)$lista[$k]['prestamo'] === ($item['estado'] === 'prestado')) {
                $lista[$k]['hasta'] = $hoy;
                $lista[$k]['actual'] = true;
                $lista[$k]['cierre'] = 'lo tiene hoy';
            }
            break;
        }
    }
    // dueno actual que viene del excel sin acta
    $hay_actual = (bool)array_filter($lista, fn($t) => $t['actual']);
    if ($tiene && !$hay_actual) {
        $st = db()->prepare('SELECT nombre, activo FROM colaboradores WHERE id = ?');
        $st->execute([$tiene]);
        $c = $st->fetch();
        $lista[] = ['nombre' => $c['nombre'], 'id' => $tiene, 'activo' => $c['activo'], 'prestamo' => (int)($item['estado'] === 'prestado'), 'desde' => null,
            'hasta' => $hoy, 'actual' => true, 'acta_id' => null, 'consecutivo' => null, 'cierre' => 'lo tiene hoy (según el Excel, sin acta)'];
    }
    foreach ($lista as &$t) $t['dias'] = $t['desde'] && $t['hasta'] ? (new DateTime($t['desde']))->diff(new DateTime($t['hasta']))->days : null;
    unset($t);
    usort($lista, fn($a, $b) => [$b['actual'], (string)$b['desde']] <=> [$a['actual'], (string)$a['desde']]);
    return $lista;
}

function pintar_tenencias($lista) {
    if (!$lista) return '<div class="tenue">Nadie lo ha tenido según las actas</div>';
    $max = max(array_map(fn($t) => (int)$t['dias'], $lista)) ?: 1;
    $h = '<div class="tabla-envoltura" style="border:0"><table class="tabla tenencias"><thead><tr><th>Dueño / quién lo tuvo</th><th>Tipo</th><th>Desde</th><th>Hasta</th><th>Duración</th></tr></thead><tbody>';
    foreach ($lista as $t) {
        $pct = $t['dias'] !== null ? max(2, round($t['dias'] * 100 / $max)) : 0;
        $persona = '<a href="colaborador.php?id=' . (int)$t['id'] . '">' . e($t['nombre']) . '</a>' . ($t['activo'] ? '' : ' <span class="chip chip-gris">ya no está</span>');
        $h .= '<tr class="' . ($t['actual'] ? 'tenencia-actual' : '') . '"><td>'
            . ($t['prestamo'] ? '<b>Sistemas</b><small>prestado a ' . $persona . '</small>' : $persona) . '</td>'
            . '<td>' . ($t['prestamo'] ? '<span class="chip chip-pendiente">Préstamo</span>' : '<span class="chip chip-asignado">Asignado</span>') . '</td>'
            . '<td class="mono">' . ($t['desde'] ? ($t['acta_id'] ? '<a href="acta.php?id=' . $t['acta_id'] . '">' . fecha_corta($t['desde']) . '</a>' : fecha_corta($t['desde'])) : '¿?') . '</td>'
            . '<td class="mono">' . ($t['actual'] ? 'hoy' : ($t['hasta'] ? fecha_corta($t['hasta']) : '¿?')) . '<small>' . e($t['cierre']) . '</small></td>'
            . '<td style="min-width:150px">' . ($t['dias'] !== null ? '<b>' . e(duracion_texto($t['desde'], $t['hasta'])) . '</b><div class="barra-dur"><span style="width:' . $pct . '%"></span></div>' : '<span class="tenue">—</span>') . '</td></tr>';
    }
    return $h . '</tbody></table></div>';
}

// accesorios y elementos sin etiqueta que ha recibido una persona
function accesorios_colaborador($id) {
    $st = db()->prepare("SELECT a.id acta_id, a.fecha, a.tipo, a.prestamo, ai.descripcion, ai.cantidad FROM acta_items ai JOIN actas a ON a.id = ai.acta_id
        WHERE a.colaborador_id = ? AND ai.item_id IS NULL AND a.estado <> 'anulada' ORDER BY a.fecha DESC");
    $st->execute([$id]);
    $filas = $st->fetchAll();
    $grupos = ['Adaptador de red' => 'adaptador', 'Cargador' => 'cargador', 'Mouse' => 'mouse', 'Teclado' => 'teclado',
        'Multipuerto' => 'multipuerto', 'Cable' => 'cable', 'Portátil sin etiqueta' => 'portatil', 'Tablet sin etiqueta' => 'tablet', 'Celular' => 'celular'];
    $conteo = [];
    foreach ($filas as &$f) {
        $f['grupo'] = 'Otro';
        $d = mb_strtolower($f['descripcion']);
        foreach ($grupos as $g => $clave) if (str_contains($d, $clave)) { $f['grupo'] = $g; break; }
        $conteo[$f['grupo']] = ($conteo[$f['grupo']] ?? 0) + (int)$f['cantidad'];
    }
    arsort($conteo);
    return [$filas, $conteo];
}

function linea_tiempo_colaborador($id) {
    $ev = [];
    $st = db()->prepare("SELECT a.* FROM actas a WHERE a.colaborador_id = ? AND a.estado <> 'anulada'");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $a) {
        $st2 = db()->prepare('SELECT ai.*, i.etiqueta et_actual FROM acta_items ai LEFT JOIN items i ON i.id = ai.item_id WHERE ai.acta_id = ?');
        $st2->execute([$a['id']]);
        $partes = [];
        foreach ($st2->fetchAll() as $li) $partes[] = ($li['item_id'] ? $li['et_actual'] . ' ' : '') . $li['descripcion'];
        if ($a['tipo'] === 'devolucion') { $clase = 'devolucion'; $t = 'Devolvió'; }
        elseif (!empty($a['prestamo'])) { $clase = 'prestamo'; $t = 'Préstamo'; }
        else { $clase = 'entrega'; $t = 'Recibió'; }
        $ev[] = evento($a['fecha'], $clase, $t . ': ' . implode(', ', $partes), (string)$a['observaciones'], 'acta.php?id=' . $a['id'],
            array_merge([[$a['consecutivo'], 'gris']], chips_acta($a)));
    }
    $st = db()->prepare('SELECT m.*, i.etiqueta FROM mantenimientos m JOIN items i ON i.id = m.item_id WHERE m.colaborador_id = ?');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $m) {
        $t = ($m['tipo'] === 'correctivo' ? 'Reparación de ' : 'Mantenimiento de ') . $m['etiqueta'];
        $ev[] = evento($m['fecha'], $m['tipo'] === 'correctivo' ? 'reparacion' : 'mantenimiento', $t,
            str_replace("\n", ' · ', (string)$m['actividades']), 'mantenimiento.php?id=' . $m['id'], [[$m['consecutivo'], 'gris']]);
    }
    $st = db()->prepare("SELECT m.*, i.etiqueta FROM movimientos m JOIN items i ON i.id = m.item_id WHERE m.colaborador_id = ? AND m.tipo = 'importacion'");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $m) {
        $ev[] = evento(substr($m['fecha'], 0, 10), 'sistema', 'Tiene a su nombre ' . $m['etiqueta'], 'Según el inventario en Excel (carga inicial)', 'item.php?id=' . $m['item_id']);
    }
    usort($ev, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    return $ev;
}

function pintar_linea_tiempo($ev) {
    if (!$ev) return '<div class="tenue">Sin registros todavía</div>';
    $iconos = ['entrega' => '→', 'prestamo' => '⇄', 'devolucion' => '←', 'mantenimiento' => '✓', 'reparacion' => '⚒', 'consumible' => '▤', 'alerta' => '!', 'sistema' => '·'];
    $h = '<ol class="linea-tiempo">';
    $anio = null;
    foreach ($ev as $e) {
        $y = substr($e['fecha'], 0, 4);
        if ($y !== $anio) { $h .= '<li class="lt-anio">' . e($y) . '</li>'; $anio = $y; }
        $chips = '';
        foreach ($e['chips'] as [$txt, $cls]) $chips .= ' <span class="chip chip-' . e($cls) . '">' . e($txt) . '</span>';
        $titulo = $e['link'] ? '<a href="' . e($e['link']) . '">' . e($e['titulo']) . '</a>' : e($e['titulo']);
        $h .= '<li class="lt-' . e($e['clase']) . '"><span class="lt-ico">' . ($iconos[$e['clase']] ?? '·') . '</span>'
            . '<div><time>' . fecha_corta($e['fecha']) . '</time><b>' . $titulo . '</b>' . $chips
            . ($e['detalle'] !== '' ? '<small>' . e($e['detalle']) . '</small>' : '') . '</div></li>';
    }
    return $h . '</ol>';
}
