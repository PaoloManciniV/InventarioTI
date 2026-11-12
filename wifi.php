<?php
// redes wifi con claves cifradas solo para administradores
require __DIR__ . '/config.php';
requiere_admin();
requiere_modulo('wifi');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['accion'] ?? '') === 'activo') {
        $st = db()->prepare('SELECT activo FROM wifi_redes WHERE id = ?');
        $st->execute([$id]);
        $act = (int)$st->fetchColumn();
        db()->prepare('UPDATE wifi_redes SET activo = 1 - activo WHERE id = ?')->execute([$id]);
        auditar('wifi_redes', $id, ['activo' => $act], ['activo' => 1 - $act], $act ? 'Red ocultada' : 'Red visible');
        ir('wifi.php');
    }
    $d = [];
    foreach (['ssid', 'banda', 'seguridad', 'aps', 'notas'] as $k) $d[$k] = trim($_POST[$k] ?? '') ?: null;
    $clave = $_POST['clave'] ?? '';
    if (!$d['ssid']) $error = 'El nombre de la red es obligatorio';
    if (!$error && $clave !== '') {
        try { $d['clave_cifrada'] = cifrar($clave); } catch (Throwable $ex) { $error = 'Falta APP_KEY en el .env, no se puede guardar la clave'; }
    }
    if (!$error) {
        $cols = array_keys($d);
        if ($id) {
            $st = db()->prepare('SELECT * FROM wifi_redes WHERE id = ?');
            $st->execute([$id]);
            $antes = $st->fetch();
            db()->prepare('UPDATE wifi_redes SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute(array_merge(array_values($d), [$id]));
            // la clave cifrada cambia aunque sea igual, se marca solo si escribieron una
            if ($antes) auditar('wifi_redes', $id, $antes, $clave !== '' ? $d : array_diff_key($d, ['clave_cifrada' => 1]));
        } else {
            db()->prepare('INSERT INTO wifi_redes (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($d));
            auditar('wifi_redes', (int)db()->lastInsertId(), null, null, 'Red creada');
        }
        flash('Red guardada');
        ir('wifi.php');
    }
}

$redes = db()->query('SELECT * FROM wifi_redes ORDER BY activo DESC, ssid')->fetchAll();
$hist = cambios_de_varios('wifi_redes', array_column($redes, 'id'), 15);
$ed = (int)($_GET['ed'] ?? 0);
$edit = $ed ? current(array_filter($redes, fn($r) => (int)$r['id'] === $ed)) : null;
$aps = db()->query("SELECT i.*, (SELECT nombre FROM categorias WHERE id = i.categoria_id) categoria FROM items i
    JOIN categorias c ON c.id = i.categoria_id WHERE c.nombre = 'Access point' AND i.estado <> 'baja' ORDER BY i.etiqueta")->fetchAll();
$log = db()->query('SELECT l.*, u.nombre FROM claves_log l LEFT JOIN usuarios u ON u.id = l.usuario_id ORDER BY l.id DESC LIMIT 15')->fetchAll();

$titulo = 'Redes WiFi';
$menu = 'wifi';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera"><div><h1>Redes WiFi</h1><small>Solo administradores · las claves se guardan cifradas y cada vez que alguien las ve queda registrado</small></div>
  <div class="acciones"><?= boton_cambios($hist, array_column($redes, 'ssid', 'id'), 'wifi.php?ed=') ?></div></div>
<?php if (!llave_app()): ?><div class="aviso aviso-error">Falta APP_KEY en el .env. Sin eso no se pueden guardar ni leer claves.</div><?php endif; ?>
<?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>

<div class="tabla-envoltura" style="margin-bottom:16px"><table class="tabla">
  <thead><tr><th>Red (SSID)</th><th>Clave</th><th>Banda</th><th>Seguridad</th><th>APs que la emiten</th><th></th></tr></thead><tbody>
  <?php if (!$redes): ?><tr><td colspan="6" class="vacio">No hay redes registradas</td></tr><?php endif; ?>
  <?php foreach ($redes as $r): ?>
    <tr style="<?= $r['activo'] ? '' : 'opacity:.5' ?>"><td><b><?= e($r['ssid']) ?></b><?php if ($r['notas']): ?><small><?= e($r['notas']) ?></small><?php endif; ?></td>
      <td><?= campo_secreto('wifi', $r['id'], (bool)$r['clave_cifrada']) ?></td>
      <td><?= e($r['banda'] ?: '—') ?></td><td><?= e($r['seguridad'] ?: '—') ?></td><td><?= e($r['aps'] ?: '—') ?></td>
      <td style="text-align:right;white-space:nowrap"><a class="btn btn-borde btn-sm" href="?ed=<?= $r['id'] ?>">Editar</a>
        <form method="post" style="display:inline"><?= csrf_campo() ?><input type="hidden" name="accion" value="activo"><input type="hidden" name="id" value="<?= $r['id'] ?>">
          <button class="btn btn-borde btn-sm"><?= $r['activo'] ? 'Ocultar' : 'Mostrar' ?></button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<?php if ($edit): ?><div class="modal-fondo" data-cerrar="wifi.php"><?php endif; ?>
<form method="post" class="tarjeta<?= $edit ? ' modal-caja' : '' ?>" id="form"<?= $edit ? ' role="dialog" aria-modal="true"' : '' ?>><?= csrf_campo() ?><input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
  <?php if ($edit): ?><a class="modal-x" href="wifi.php" aria-label="Cerrar">×</a>
  <?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?><?php endif; ?>
  <h2><?= $edit ? 'Editar ' . e($edit['ssid']) : 'Nueva red' ?></h2>
  <div class="rejilla">
    <div class="campo"><label>SSID</label><input name="ssid" required value="<?= e($edit['ssid'] ?? '') ?>"></div>
    <div class="campo"><label>Clave<?= $edit ? ' (vacio = no cambia)' : '' ?></label><input type="password" name="clave" autocomplete="new-password"></div>
    <div class="campo"><label>Banda</label><input name="banda" value="<?= e($edit['banda'] ?? '') ?>" placeholder="2.4 + 5 GHz"></div>
    <div class="campo"><label>Seguridad</label><input name="seguridad" value="<?= e($edit['seguridad'] ?? '') ?>" placeholder="WPA2"></div>
    <div class="campo ancho"><label>APs que la emiten</label><input name="aps" value="<?= e($edit['aps'] ?? '') ?>"></div>
    <div class="campo ancho"><label>Notas</label><input name="notas" value="<?= e($edit['notas'] ?? '') ?>"></div>
  </div>
  <p><button class="btn btn-primario">Guardar</button><?php if ($edit): ?> <a class="btn btn-borde" href="wifi.php">Cancelar</a><?php endif; ?></p>
</form>
<?php if ($edit): ?></div><?php endif; ?>

<div class="dos-col">
  <div class="tabla-envoltura"><table class="tabla">
    <thead><tr><th colspan="4">Access points</th></tr></thead><tbody>
    <?php foreach ($aps as $a): ?>
      <tr><td class="mono"><a href="item.php?id=<?= $a['id'] ?>"><?= e($a['etiqueta']) ?></a></td><td><?= e(trim($a['marca'] . ' ' . $a['modelo'])) ?></td>
        <td class="mono"><?= e($a['ip'] ?: '—') ?></td><td><?= e($a['ubicacion'] ?: '—') ?> <?= chip_estado($a['estado']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$aps): ?><tr><td class="vacio">Sin access points</td></tr><?php endif; ?>
    </tbody></table></div>
  <div class="tarjeta"><h2>Ultimas consultas de claves</h2>
    <ul class="historial"><?php foreach ($log as $l): ?><li><time><?= fecha_hora($l['fecha']) ?></time><div><?= e($l['nombre']) ?> · <?= e($l['tipo']) ?> #<?= $l['ref_id'] ?></div></li><?php endforeach; ?></ul>
    <?php if (!$log): ?><div class="tenue">Nadie ha consultado claves</div><?php endif; ?></div>
</div>
<?php require __DIR__ . '/inc/footer.php';
