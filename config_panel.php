<?php
// sedes categorias y usuarios del sistema
require __DIR__ . '/config.php';
requiere_admin();
requiere_modulo('config');

$tab = $_GET['tab'] ?? 'usuarios';
// la pestana de usuarios (y su historial) solo la ven quienes gestionan usuarios; los demas ni saben que existe
if (!gestiona_usuarios() && $tab === 'usuarios') $tab = 'sedes';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $a = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($a === 'probar_correo') {
        $para = usuario()['email'];
        $ok = enviar_correo($para, usuario()['nombre'], 'Prueba de correo inventario TI',
            '<p>Si te llegó este correo, el envío de actas por correo funciona.</p>');
        flash($ok ? 'Correo de prueba enviado a ' . $para : 'No se envió: ' . $GLOBALS['correo_error'], $ok ? 'ok' : 'error');
        ir('config_panel.php?tab=usuarios');
    }

    if (in_array($a, ['usuario', 'usuario_activo', 'probar_correo'], true) && !gestiona_usuarios()) {
        http_response_code(403);
        exit('No disponible');
    }

    // foto antes y despues para el historial de cambios
    $tabla_aud = ['sede' => 'sedes', 'sede_activo' => 'sedes', 'categoria' => 'categorias', 'categoria_activo' => 'categorias',
        'area' => 'areas', 'area_activo' => 'areas', 'usuario' => 'usuarios', 'usuario_activo' => 'usuarios'][$a] ?? null;
    $foto = function ($t, $i) use ($pdo) { $st = $pdo->prepare("SELECT * FROM $t WHERE id = ?"); $st->execute([$i]); $r = $st->fetch() ?: null;
        if ($r) unset($r['ultimo_ingreso'], $r['creado']); return $r; };
    $antes_aud = $tabla_aud && $id ? $foto($tabla_aud, $id) : null;

    if ($a === 'sede') {
        $n = trim($_POST['nombre'] ?? '');
        if ($n !== '') {
            if ($id) $pdo->prepare('UPDATE sedes SET nombre = ? WHERE id = ?')->execute([$n, $id]);
            else $pdo->prepare('INSERT INTO sedes (nombre) VALUES (?)')->execute([$n]);
        }
        flash('Bodega guardada');
    } elseif ($a === 'sede_activo') {
        $pdo->prepare('UPDATE sedes SET activo = 1 - activo WHERE id = ?')->execute([$id]);
    } elseif ($a === 'categoria') {
        $n = trim($_POST['nombre'] ?? '');
        $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['prefijo'] ?? ''));
        if ($n !== '' && $p !== '') {
            $mm = (int)($_POST['mant_meses'] ?? 0) ?: null;
            if ($id) $pdo->prepare('UPDATE categorias SET nombre = ?, prefijo = ?, mant_meses = ? WHERE id = ?')->execute([$n, $p, $mm, $id]);
            else $pdo->prepare('INSERT INTO categorias (nombre, prefijo, mant_meses) VALUES (?, ?, ?)')->execute([$n, $p, $mm]);
            flash('Categoria guardada');
        } else {
            flash('Nombre y prefijo son obligatorios', 'error');
        }
    } elseif ($a === 'area') {
        $n = trim($_POST['nombre'] ?? '');
        $g = implode(',', array_unique(array_filter(array_map(fn($x) => strtoupper(trim($x)), explode(',', $_POST['grupos'] ?? '')))));
        if ($n !== '') {
            if ($id) {
                $pdo->prepare('UPDATE areas SET nombre = ?, grupos = ? WHERE id = ?')->execute([$n, $g ?: null, $id]);
                // refresca el texto que se ve en fichas y actas
                $pdo->prepare("UPDATE colaboradores SET area = CONCAT(?, IF(grupo IS NULL, '', CONCAT(' ', grupo))) WHERE area_id = ?")->execute([$n, $id]);
            } else {
                $pdo->prepare('INSERT INTO areas (nombre, grupos) VALUES (?, ?)')->execute([$n, $g ?: null]);
            }
            flash('Area guardada');
        }
    } elseif ($a === 'area_activo') {
        $pdo->prepare('UPDATE areas SET activo = 1 - activo WHERE id = ?')->execute([$id]);
    } elseif ($a === 'categoria_activo') {
        $pdo->prepare('UPDATE categorias SET activo = 1 - activo WHERE id = ?')->execute([$id]);
    } elseif ($a === 'usuario') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $rol = ($_POST['rol'] ?? '') === 'admin' ? 'admin' : 'consulta';
        $clave = $_POST['clave'] ?? '';
        $login = strtolower(trim($_POST['usuario'] ?? ''));
        // modulos marcados; todos marcados = NULL (tambien recibe los modulos nuevos que se agreguen despues)
        $mods = array_values(array_intersect(array_keys(modulos()), (array)($_POST['mods'] ?? [])));
        if ($id === (int)usuario()['id'] && !in_array('config', $mods, true)) $mods[] = 'config';
        $permisos = count($mods) === count(modulos()) ? null : implode(',', $mods);
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
        $st->execute([$email, $id]);
        if (!$nombre || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Nombre y correo valido son obligatorios', 'error');
        } elseif ($st->fetch()) {
            flash('Ese correo ya tiene usuario', 'error');
        } elseif ($err = usuario_invalido($login, $id)) {
            flash($err, 'error');
        } elseif ((!$id || $clave !== '') && ($debil = clave_debil($clave))) {
            flash($debil, 'error');
        } elseif (!$mods) {
            flash('Marca al menos un módulo (para quitarle todo el acceso usa Inactivar)', 'error');
        } elseif ($id === (int)usuario()['id'] && $rol !== 'admin') {
            flash('No te puedes quitar el rol de admin a ti mismo', 'error');
        } else {
            if ($id) {
                $pdo->prepare('UPDATE usuarios SET nombre = ?, email = ?, usuario = ?, rol = ?, permisos = ? WHERE id = ?')->execute([$nombre, $email, $login ?: null, $rol, $permisos, $id]);
                if ($clave !== '') $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $id]);
            } else {
                $pdo->prepare('INSERT INTO usuarios (nombre, email, usuario, password_hash, rol, permisos) VALUES (?,?,?,?,?,?)')
                    ->execute([$nombre, $email, $login ?: null, password_hash($clave, PASSWORD_DEFAULT), $rol, $permisos]);
            }
            flash('Usuario guardado');
        }
    } elseif ($a === 'usuario_activo' && $id !== (int)usuario()['id']) {
        $pdo->prepare('UPDATE usuarios SET activo = 1 - activo WHERE id = ?')->execute([$id]);
    }
    if ($tabla_aud && $id && $antes_aud) {
        auditar($tabla_aud, $id, $antes_aud, $foto($tabla_aud, $id));
    } elseif ($tabla_aud && !$id && ($nuevo = (int)$pdo->lastInsertId())) {
        auditar($tabla_aud, $nuevo, null, null, 'Creado');
    }
    ir('config_panel.php?tab=' . $tab);
}

$ed = (int)($_GET['ed'] ?? 0);
$titulo = 'Configuracion';
$menu = 'config';
require __DIR__ . '/inc/header.php';
?>
<?php $tab_tabla = ['usuarios' => ['usuarios', 'nombre'], 'sedes' => ['sedes', 'nombre'], 'areas' => ['areas', 'nombre'], 'categorias' => ['categorias', 'nombre']][$tab] ?? null;
  if ($tab_tabla) { $nom_t = $pdo->query('SELECT id, ' . $tab_tabla[1] . ' FROM ' . $tab_tabla[0])->fetchAll(PDO::FETCH_KEY_PAIR); } ?>
<div class="cabecera"><h1>Configuracion</h1>
  <?php if ($tab_tabla): ?><div class="acciones"><?= boton_cambios(cambios_de_varios($tab_tabla[0], array_keys($nom_t), 10), $nom_t, 'config_panel.php?tab=' . $tab . '&ed=') ?></div><?php endif; ?></div>
<div class="pildoras">
  <?php foreach ((gestiona_usuarios() ? ['usuarios' => 'Usuarios y claves'] : []) + ['sedes' => 'Bodegas', 'areas' => 'Areas', 'categorias' => 'Categorias', 'cambios' => 'Registro de cambios'] as $k => $v): ?>
    <a class="pildora <?= $tab === $k ? 'activa' : '' ?>" href="?tab=<?= $k ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'usuarios'):
  $lista = $pdo->query('SELECT * FROM usuarios ORDER BY activo DESC, nombre')->fetchAll();
  $edit = $ed ? current(array_filter($lista, fn($u) => (int)$u['id'] === $ed)) : null; ?>
  <?php if ($edit): ?><div class="modal-fondo" data-cerrar="?tab=usuarios"><?php endif; ?>
  <form method="post" class="tarjeta<?= $edit ? ' modal-caja' : '' ?>"<?= $edit ? ' role="dialog" aria-modal="true"' : '' ?>><?= csrf_campo() ?><input type="hidden" name="accion" value="usuario"><input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
    <?php if ($edit): ?><a class="modal-x" href="?tab=usuarios" aria-label="Cerrar">×</a><?php endif; ?>
    <h2><?= $edit ? 'Editar usuario' : 'Nuevo usuario' ?></h2>
    <div class="rejilla">
      <div class="campo"><label>Nombre</label><input name="nombre" required value="<?= e($edit['nombre'] ?? '') ?>"></div>
      <div class="campo"><label>Correo</label><input type="email" name="email" required value="<?= e($edit['email'] ?? '') ?>"></div>
      <div class="campo"><label>Usuario para entrar (opcional)</label><input name="usuario" autocomplete="off" autocapitalize="off" value="<?= e($edit['usuario'] ?? '') ?>" placeholder="ej. nombre.inventario"><small>Puede entrar con este usuario o con el correo</small></div>
      <div class="campo"><label>Rol</label><select name="rol">
        <option value="consulta">Consulta (solo ve)</option>
        <option value="admin" <?= ($edit['rol'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrador (IT)</option></select></div>
      <div class="campo"><label><?= $edit ? 'Nueva clave (vacio = no cambia)' : 'Clave' ?></label><input type="password" name="clave" minlength="10" autocomplete="new-password" <?= $edit ? '' : 'required' ?>><small>Mínimo 10 caracteres, con letras y números</small></div>
    </div>
    <?php $tiene = lista_permisos($edit ? $edit['permisos'] : null); ?>
    <div class="campo" style="margin-top:14px"><label>Módulos que puede ver</label>
      <div class="mods-permisos">
      <?php foreach (modulos() as $k => [$nom, $solo_admin]): ?>
        <label><input type="checkbox" name="mods[]" value="<?= $k ?>" <?= in_array($k, $tiene, true) ? 'checked' : '' ?> <?= ($edit && (int)$edit['id'] === (int)usuario()['id'] && $k === 'config') ? 'disabled checked' : '' ?>> <?= e($nom) ?><?= $solo_admin ? ' <small class="tenue">(solo admin)</small>' : '' ?></label>
      <?php endforeach; ?>
      </div>
      <small>Consulta solo ve; Administrador además edita. Los marcados “solo admin” no aplican a Consulta.</small></div>
    <p><button class="btn btn-primario">Guardar</button><?php if ($edit): ?> <a class="btn btn-borde" href="?tab=usuarios">Cancelar</a><?php endif; ?></p>
  </form>
  <?php if ($edit): ?></div><?php endif; ?>
  <form method="post" class="tarjeta filtros" style="align-items:center"><?= csrf_campo() ?><input type="hidden" name="accion" value="probar_correo">
    <div style="flex:1"><b>Correo de actas</b><br><small class="tenue"><?php $pc = proveedor_correo(); ?><?= $pc === 'smtp' ? 'SMTP ' . e(env('MAIL_HOST')) . ' (el mismo de empleados) · sale desde ' . e(env('MAIL_USER')) : ($pc !== 'none' ? 'Proveedor ' . e($pc) . ' · remitente ' . e(env('MAIL_FROM', '')) : 'No encontré la configuración de correo de empleados (private/.env)') ?></small></div>
    <button class="btn btn-borde btn-sm">Enviarme un correo de prueba</button></form>
  <div class="tabla-envoltura"><table class="tabla"><thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Módulos</th><th>Ultimo ingreso</th><th></th></tr></thead><tbody>
  <?php foreach ($lista as $u): ?>
    <tr style="<?= $u['activo'] ? '' : 'opacity:.5' ?>"><td><?= e($u['nombre']) ?><?= $u['activo'] ? '' : ' <span class="chip chip-baja">INACTIVO</span>' ?><?= $u['gestiona_usuarios'] ? ' <span class="chip chip-gris" title="Puede crear usuarios y dar permisos">permisos</span>' : '' ?></td><td><?= e($u['email']) ?></td>
      <td><?= $u['rol'] === 'admin' ? 'Administrador' : 'Consulta' ?></td>
      <td><small><?php $tp = lista_permisos($u['permisos']); $falta = array_diff(array_keys(modulos()), $tp);
        echo $u['permisos'] === null ? 'Todos' : 'Personalizado'; ?></small></td><td><?= fecha_hora($u['ultimo_ingreso']) ?: '—' ?></td>
      <td style="text-align:right"><?php if (gestiona_usuarios()): ?><a class="btn btn-borde btn-sm" href="?tab=usuarios&ed=<?= $u['id'] ?>">Editar</a><?php endif; ?>
        <?php if (gestiona_usuarios() && (int)$u['id'] !== (int)usuario()['id']): ?>
        <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="usuario_activo"><input type="hidden" name="id" value="<?= $u['id'] ?>">
          <button class="btn btn-borde btn-sm"><?= $u['activo'] ? 'Inactivar' : 'Activar' ?></button></form>
        <?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

  <?php $acc = $pdo->query('SELECT * FROM accesos ORDER BY id DESC LIMIT 40')->fetchAll();
    $fallos = (int)$pdo->query('SELECT COUNT(*) FROM accesos WHERE ok = 0 AND fecha > NOW() - INTERVAL 1 DAY')->fetchColumn(); ?>
  <div class="tarjeta" style="margin-top:16px">
    <h2>Accesos recientes</h2>
    <p class="tenue" style="margin-top:-6px">Intentos fallidos en las últimas 24 horas: <b style="color:<?= $fallos ? 'var(--rojo)' : 'var(--verde)' ?>"><?= $fallos ?></b> · tras 5 fallos seguidos el correo queda bloqueado 15 minutos y te llega aviso</p>
    <div class="tabla-envoltura"><table class="tabla"><thead><tr><th>Fecha</th><th>Correo</th><th>IP</th><th>Resultado</th></tr></thead><tbody>
    <?php foreach ($acc as $x): ?>
      <tr><td class="mono"><?= fecha_hora($x['fecha']) ?></td><td><?= e($x['email']) ?></td><td class="mono"><?= e($x['ip']) ?></td>
        <td><?= $x['ok'] ? '<span class="chip chip-disponible">Entró</span>' : '<span class="chip chip-danado">' . e($x['nota'] ?: 'Clave incorrecta') . '</span>' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$acc): ?><tr><td colspan="4" class="vacio">Sin registros todavía</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

<?php elseif ($tab === 'cambios'):
  $q = trim($_GET['q'] ?? '');
  $sql = "SELECT x.*, u.nombre usuario,
      CASE x.tabla WHEN 'items' THEN (SELECT etiqueta FROM items WHERE id = x.ref_id) WHEN 'colaboradores' THEN (SELECT nombre FROM colaboradores WHERE id = x.ref_id)
        WHEN 'wifi_redes' THEN (SELECT ssid FROM wifi_redes WHERE id = x.ref_id) WHEN 'usuarios' THEN (SELECT nombre FROM usuarios WHERE id = x.ref_id)
        WHEN 'sedes' THEN (SELECT nombre FROM sedes WHERE id = x.ref_id) WHEN 'areas' THEN (SELECT nombre FROM areas WHERE id = x.ref_id)
        WHEN 'categorias' THEN (SELECT nombre FROM categorias WHERE id = x.ref_id) WHEN 'herramientas' THEN (SELECT nombre FROM herramientas WHERE id = x.ref_id) END que
    FROM cambios x LEFT JOIN usuarios u ON u.id = x.usuario_id";
  if (!gestiona_usuarios()) $sql .= " WHERE x.tabla NOT IN ('usuarios')";
  $par = [];
  if ($q !== '') { $sql .= ' HAVING que LIKE ? OR usuario LIKE ? OR x.antes LIKE ? OR x.despues LIKE ?'; $par = array_fill(0, 4, "%$q%"); }
  $st = $pdo->prepare($sql . ' ORDER BY x.id DESC LIMIT 300');
  $st->execute($par);
  $lista = $st->fetchAll();
  $enlace = ['items' => 'item.php?id=', 'colaboradores' => 'colaborador.php?id=', 'wifi_redes' => 'wifi.php?ed=', 'usuarios' => 'config_panel.php?tab=usuarios&ed=',
    'sedes' => 'config_panel.php?tab=sedes&ed=', 'areas' => 'config_panel.php?tab=areas&ed=', 'categorias' => 'config_panel.php?tab=categorias&ed=', 'herramientas' => 'herramientas.php?ed='];
  $tipo_t = ['items' => 'Equipo', 'colaboradores' => 'Colaborador', 'wifi_redes' => 'Red WiFi', 'usuarios' => 'Usuario', 'sedes' => 'Bodega', 'areas' => 'Área', 'categorias' => 'Categoría', 'herramientas' => 'Herramienta']; ?>
  <form class="filtros"><input type="hidden" name="tab" value="cambios"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Equipo, persona, usuario o valor"><button class="btn btn-borde">Buscar</button></form>
  <div class="tabla-envoltura"><table class="tabla"><thead><tr><th>Fecha</th><th>Quién</th><th>Qué</th><th>Cambio</th></tr></thead><tbody>
  <?php foreach ($lista as $x): ?>
    <tr><td class="mono"><?= fecha_hora($x['fecha']) ?></td><td><?= e($x['usuario'] ?: 'sistema') ?></td>
      <td><small><?= e($tipo_t[$x['tabla']] ?? $x['tabla']) ?></small><a href="<?= e(($enlace[$x['tabla']] ?? '#') . $x['ref_id']) ?>"><?= e($x['que'] ?: '#' . $x['ref_id']) ?></a></td>
      <td class="cambio-linea"><?= linea_cambio($x) ?><?= $x['nota'] && $x['campo'] !== '_creado' ? ' <small>' . e($x['nota']) . '</small>' : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$lista): ?><tr><td colspan="4" class="vacio">Sin cambios registrados todavía</td></tr><?php endif; ?>
  </tbody></table></div>

<?php elseif ($tab === 'sedes'):
  $lista = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM items i WHERE i.sede_id = s.id) n FROM sedes s ORDER BY activo DESC, nombre')->fetchAll();
  $edit = $ed ? current(array_filter($lista, fn($s) => (int)$s['id'] === $ed)) : null; ?>
  <form method="post" class="tarjeta filtros" style="align-items:center"><?= csrf_campo() ?><input type="hidden" name="accion" value="sede"><input type="hidden" name="id" value="0">
    <input name="nombre" placeholder="Nombre de la bodega (ej. Bodega XM)" required style="flex:1">
    <button class="btn btn-primario">+ Agregar</button>
  </form>
  <?php if ($edit): ?><div class="modal-fondo" data-cerrar="?tab=sedes">
  <form method="post" class="tarjeta modal-caja" role="dialog" aria-modal="true"><?= csrf_campo() ?><input type="hidden" name="accion" value="sede"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
    <a class="modal-x" href="?tab=sedes" aria-label="Cerrar">×</a>
    <h2>Editar bodega · <?= e($edit['nombre']) ?></h2>
    <div class="rejilla">
      <div class="campo" style="grid-column:1/-1"><label>Nombre</label><input name="nombre" required value="<?= e($edit['nombre']) ?>"></div>
    </div>
    <p style="margin-bottom:0"><button class="btn btn-primario">Guardar</button> <a class="btn btn-borde" href="?tab=sedes">Cancelar</a></p>
  </form></div><?php endif; ?>
  <div class="tabla-envoltura"><table class="tabla"><thead><tr><th>Bodega</th><th>Items</th><th></th></tr></thead><tbody>
  <?php foreach ($lista as $s): ?>
    <tr style="<?= $s['activo'] ? '' : 'opacity:.5' ?>"><td><?= e($s['nombre']) ?></td><td><?= $s['n'] ?></td>
      <td style="text-align:right"><a class="btn btn-borde btn-sm" href="?tab=sedes&ed=<?= $s['id'] ?>">Editar</a>
        <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="sede_activo"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="btn btn-borde btn-sm"><?= $s['activo'] ? 'Ocultar' : 'Mostrar' ?></button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<?php elseif ($tab === 'areas'):
  $lista = $pdo->query('SELECT a.*, (SELECT COUNT(*) FROM colaboradores c WHERE c.area_id = a.id AND c.activo = 1) n FROM areas a ORDER BY activo DESC, nombre')->fetchAll();
  $edit = $ed ? current(array_filter($lista, fn($s) => (int)$s['id'] === $ed)) : null; ?>
  <form method="post" class="tarjeta filtros" style="align-items:center"><?= csrf_campo() ?><input type="hidden" name="accion" value="area"><input type="hidden" name="id" value="0">
    <input name="nombre" placeholder="Nombre del area" required style="flex:1">
    <input name="grupos" placeholder="Grupos separados por coma (opcional) ej RED, AD" style="flex:1">
    <button class="btn btn-primario">+ Agregar</button>
  </form>
  <?php if ($edit): ?><div class="modal-fondo" data-cerrar="?tab=areas">
  <form method="post" class="tarjeta modal-caja" role="dialog" aria-modal="true"><?= csrf_campo() ?><input type="hidden" name="accion" value="area"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
    <a class="modal-x" href="?tab=areas" aria-label="Cerrar">×</a>
    <h2>Editar área · <?= e($edit['nombre']) ?></h2>
    <div class="rejilla">
      <div class="campo"><label>Nombre</label><input name="nombre" required value="<?= e($edit['nombre']) ?>"></div>
      <div class="campo"><label>Grupos (separados por coma, opcional)</label><input name="grupos" value="<?= e($edit['grupos'] ?? '') ?>" placeholder="RED, AD"></div>
    </div>
    <p style="margin-bottom:0"><button class="btn btn-primario">Guardar</button> <a class="btn btn-borde" href="?tab=areas">Cancelar</a></p>
  </form></div><?php endif; ?>
  <div class="tabla-envoltura"><table class="tabla"><thead><tr><th>Area</th><th>Grupos</th><th>Personas</th><th></th></tr></thead><tbody>
  <?php foreach ($lista as $ar): ?>
    <tr style="<?= $ar['activo'] ? '' : 'opacity:.5' ?>"><td><?= e($ar['nombre']) ?></td><td><?= e($ar['grupos'] ? str_replace(',', ' · ', $ar['grupos']) : '—') ?></td><td><?= $ar['n'] ?></td>
      <td style="text-align:right"><a class="btn btn-borde btn-sm" href="?tab=areas&ed=<?= $ar['id'] ?>">Editar</a>
        <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="area_activo"><input type="hidden" name="id" value="<?= $ar['id'] ?>">
          <button class="btn btn-borde btn-sm"><?= $ar['activo'] ? 'Ocultar' : 'Mostrar' ?></button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<?php else:
  $lista = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.categoria_id = c.id) n FROM categorias c ORDER BY activo DESC, nombre')->fetchAll();
  $edit = $ed ? current(array_filter($lista, fn($s) => (int)$s['id'] === $ed)) : null; ?>
  <form method="post" class="tarjeta filtros" style="align-items:center"><?= csrf_campo() ?><input type="hidden" name="accion" value="categoria"><input type="hidden" name="id" value="0">
    <input name="nombre" placeholder="Nombre de la categoria" required style="flex:1">
    <input name="prefijo" placeholder="Prefijo etiqueta (PC)" required maxlength="10" style="width:170px">
    <input type="number" min="0" name="mant_meses" placeholder="Mantenimiento cada (meses)" style="width:230px">
    <button class="btn btn-primario">+ Agregar</button>
  </form>
  <?php if ($edit): ?><div class="modal-fondo" data-cerrar="?tab=categorias">
  <form method="post" class="tarjeta modal-caja" role="dialog" aria-modal="true"><?= csrf_campo() ?><input type="hidden" name="accion" value="categoria"><input type="hidden" name="id" value="<?= $edit['id'] ?>">
    <a class="modal-x" href="?tab=categorias" aria-label="Cerrar">×</a>
    <h2>Editar categoría · <?= e($edit['nombre']) ?></h2>
    <div class="rejilla">
      <div class="campo" style="grid-column:1/-1"><label>Nombre</label><input name="nombre" required value="<?= e($edit['nombre']) ?>"></div>
      <div class="campo"><label>Prefijo de la etiqueta</label><input name="prefijo" required maxlength="10" value="<?= e($edit['prefijo']) ?>"></div>
      <div class="campo"><label>Mantenimiento cada (meses)</label><input type="number" min="0" name="mant_meses" value="<?= e($edit['mant_meses'] ?? '') ?>"></div>
    </div>
    <p style="margin-bottom:0"><button class="btn btn-primario">Guardar</button> <a class="btn btn-borde" href="?tab=categorias">Cancelar</a></p>
  </form></div><?php endif; ?>
  <div class="tabla-envoltura"><table class="tabla"><thead><tr><th>Categoria</th><th>Prefijo</th><th>Mantenimiento</th><th>Items</th><th></th></tr></thead><tbody>
  <?php foreach ($lista as $c): ?>
    <tr style="<?= $c['activo'] ? '' : 'opacity:.5' ?>"><td><?= e($c['nombre']) ?></td><td class="mono"><?= e($c['prefijo']) ?>-0001</td><td><?= $c['mant_meses'] ? 'Cada ' . $c['mant_meses'] . ' meses' : '—' ?></td><td><?= $c['n'] ?></td>
      <td style="text-align:right"><a class="btn btn-borde btn-sm" href="?tab=categorias&ed=<?= $c['id'] ?>">Editar</a>
        <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="categoria_activo"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <button class="btn btn-borde btn-sm"><?= $c['activo'] ? 'Ocultar' : 'Mostrar' ?></button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
