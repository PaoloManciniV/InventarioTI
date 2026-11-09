<?php
// impresoras toner y cambios de consumible
require __DIR__ . '/config.php';
requiere_modulo('impresoras');

$toner_id = (int)($_GET['toner'] ?? 0);
if ($toner_id && $_SERVER['REQUEST_METHOD'] === 'POST' && es_admin()) {
    verificar_csrf();
    $fecha = $_POST['fecha'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $cont = preg_replace('/\D/', '', $_POST['contador'] ?? '');
        db()->prepare('INSERT INTO consumible_cambios (item_id, fecha, consumible, contador, notas, usuario_id) VALUES (?,?,?,?,?,?)')
            ->execute([$toner_id, $fecha, trim($_POST['consumible'] ?? '') ?: null, $cont === '' ? null : (int)$cont,
                trim($_POST['notas'] ?? '') ?: null, usuario()['id']]);
        registrar_movimiento($toner_id, 'consumible', 'Cambio de ' . trim($_POST['consumible'] ?? 'toner'));
        flash('Cambio registrado');
    }
    ir('impresoras.php');
}

$cat_imp = db()->query("SELECT id FROM categorias WHERE nombre = 'Impresora'")->fetchColumn();
$st = db()->prepare("SELECT i.*, s.nombre sede,
    (SELECT MAX(fecha) FROM consumible_cambios cc WHERE cc.item_id = i.id) ultimo_cambio,
    (SELECT COUNT(*) FROM consumible_cambios cc WHERE cc.item_id = i.id AND cc.fecha >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)) cambios_anio
    FROM items i LEFT JOIN sedes s ON s.id = i.sede_id
    WHERE (i.categoria_id = ? OR (i.consumible IS NOT NULL AND i.consumible <> '')) AND i.estado <> 'baja' ORDER BY i.orden IS NULL, i.orden, i.ubicacion, i.etiqueta");
$st->execute([$cat_imp]);
$imps = $st->fetchAll();
$hist = cambios_de_varios('items', array_column($imps, 'id'), 10);

$por_toner = [];
foreach ($imps as $i) if ($i['consumible']) $por_toner[$i['consumible']][] = $i['etiqueta'];
ksort($por_toner);

$sel = null;
foreach ($imps as $i) if ((int)$i['id'] === $toner_id) $sel = $i;

$titulo = 'Impresoras';
$menu = 'impresoras';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1>Impresoras</h1><small><?= count($imps) ?> equipos</small></div>
  <div class="acciones"><?= boton_cambios($hist, array_column($imps, 'etiqueta', 'id')) ?><?php if (es_admin()): ?><a class="btn btn-primario" href="item.php">+ Nueva impresora</a><?php endif; ?></div>
</div>

<?php if ($sel && es_admin()): ?>
<form method="post" action="impresoras.php?toner=<?= $sel['id'] ?>" class="tarjeta">
  <?= csrf_campo() ?>
  <h2>Cambio de toner · <?= e($sel['etiqueta'] . ' ' . $sel['modelo']) ?></h2>
  <div class="rejilla">
    <div class="campo"><label>Fecha</label><input type="date" name="fecha" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="campo"><label>Toner</label><input name="consumible" value="<?= e($sel['consumible']) ?>"></div>
    <div class="campo"><label>Contador de paginas</label><input name="contador" inputmode="numeric"></div>
    <div class="campo"><label>Notas</label><input name="notas"></div>
  </div>
  <p><button class="btn btn-primario">Guardar</button> <a class="btn btn-borde" href="impresoras.php">Cancelar</a></p>
</form>
<?php endif; ?>

<div class="dos-col">
  <div class="tabla-envoltura"><table class="tabla">
    <thead><tr><th>Equipo</th><th>Ubicacion</th><th>IP</th><th>Toner</th><th>Ultimo cambio</th><th></th></tr></thead><tbody>
    <?php if (!$imps): ?><tr><td colspan="6" class="vacio">No hay impresoras</td></tr><?php endif; ?>
    <?php foreach ($imps as $i): ?>
      <tr><td><a class="mono" href="item.php?id=<?= $i['id'] ?>"><?= e($i['etiqueta']) ?></a><small><?= e(trim($i['marca'] . ' ' . $i['modelo'])) ?></small></td>
        <td><?= e($i['ubicacion'] ?: ($i['sede'] ?: '—')) ?></td><td class="mono"><?= e($i['ip'] ?: '—') ?></td>
        <td class="mono"><?= e($i['consumible'] ?: '—') ?></td>
        <td><?= fecha_corta($i['ultimo_cambio']) ?: '—' ?><?= $i['cambios_anio'] ? '<small>' . $i['cambios_anio'] . ' en 12 meses</small>' : '' ?></td>
        <td style="white-space:nowrap"><?php if (es_admin()): ?><a class="btn btn-borde btn-sm" href="item.php?id=<?= $i['id'] ?>&editar=1&volver=impresoras.php" title="Editar ubicación, IP, tóner, modelo...">✎ Editar</a>
          <a class="btn btn-borde btn-sm" href="?toner=<?= $i['id'] ?>">Cambio de toner</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <div class="tarjeta">
    <h2>Toner por modelo</h2>
    <p class="tenue" style="margin-top:-6px">Para saber que pedir en compras</p>
    <?php foreach ($por_toner as $t => $eqs): ?>
      <div class="linea"><span class="mono"><b><?= e($t) ?></b></span><span><?= count($eqs) ?> impresora<?= count($eqs) > 1 ? 's' : '' ?></span></div>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
