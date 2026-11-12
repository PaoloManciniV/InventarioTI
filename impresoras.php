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
        flash('Cambio de tóner registrado');
    } else flash('Pon la fecha del cambio', 'error');
    // si vino desde la ficha del equipo, vuelve a la ficha
    ir(($_POST['volver'] ?? '') === 'item' ? 'item.php?id=' . $toner_id : 'impresoras.php#imp-' . $toner_id);
}

// reparaciones y revisiones por fuera: a quien se mando, que tenia, cotizacion y cuanto costo
function pesos($v) { return $v === null || $v === '' ? '—' : '$' . number_format((float)$v, 0, ',', '.'); }
function valor_pesos($v) { $v = preg_replace('/[^\d]/', '', (string)$v); return $v === '' ? null : (int)$v; }
$reparar_id = (int)($_GET['reparar'] ?? 0);
$regreso_id = (int)($_GET['regreso'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin() && in_array($_POST['accion'] ?? '', ['reparar', 'regreso'], true)) {
    verificar_csrf();
    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
    if ($_POST['accion'] === 'reparar') {
        $st = db()->prepare("SELECT * FROM items WHERE id = ? AND estado NOT IN ('baja')");
        $st->execute([$reparar_id]);
        $it = $st->fetch();
        $prov = trim($_POST['proveedor'] ?? '');
        $falla = trim($_POST['falla'] ?? '');
        if (!$it || $prov === '' || $falla === '') { flash('Falta el proveedor o qué tiene la impresora', 'error'); ir('impresoras.php?reparar=' . $reparar_id); }
        $arch = guardar_archivo_subido('cotizacion', 'reparaciones');
        if (!empty($_FILES['cotizacion']['name']) && !$arch) { flash('La cotización debe ser PDF o imagen de hasta 15 MB', 'error'); ir('impresoras.php?reparar=' . $reparar_id); }
        $tipo = ($_POST['tipo'] ?? '') === 'revision' ? 'revision' : 'reparacion';
        db()->prepare("INSERT INTO reparaciones (item_id, tipo, fecha_envio, proveedor, falla, cotizacion_valor, cotizacion_archivo, estado, estado_antes, creado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'enviada', ?, ?)")
            ->execute([$reparar_id, $tipo, $fecha, $prov, $falla, valor_pesos($_POST['valor'] ?? ''), $arch, $it['estado'], usuario()['id']]);
        $rid = (int)db()->lastInsertId();
        db()->prepare("UPDATE items SET estado = 'mantenimiento', actualizado = NOW() WHERE id = ?")->execute([$reparar_id]);
        $txt = ($tipo === 'revision' ? 'Enviada a revisión' : 'Enviada a reparar') . ' a ' . $prov . ': ' . $falla . ($_POST['valor'] ?? '' ? ' · cotización ' . pesos(valor_pesos($_POST['valor'])) : '');
        db()->prepare('INSERT INTO movimientos (item_id, tipo, usuario_id, detalle, fecha) VALUES (?, ?, ?, ?, ?)')->execute([$reparar_id, 'reparacion', usuario()['id'], $txt, $fecha . ' ' . date('H:i:s')]);
        auditar('items', $reparar_id, ['estado' => $it['estado']], ['estado' => 'mantenimiento'], $txt);
        flash($it['etiqueta'] . ' quedó en reparación con ' . $prov);
        ir('impresoras.php#reparaciones');
    }
    // regreso: reparada vuelve a como estaba, sin arreglo queda danada
    $st = db()->prepare("SELECT r.*, i.etiqueta FROM reparaciones r JOIN items i ON i.id = r.item_id WHERE r.id = ? AND r.estado = 'enviada'");
    $st->execute([$regreso_id]);
    $r = $st->fetch();
    if (!$r) ir('impresoras.php#reparaciones');
    $res = ($_POST['resultado'] ?? '') === 'sin_arreglo' ? 'sin_arreglo' : 'reparada';
    $fact = guardar_archivo_subido('factura', 'reparaciones');
    $costo = valor_pesos($_POST['costo'] ?? '');
    db()->prepare('UPDATE reparaciones SET estado = ?, fecha_regreso = ?, trabajo = ?, costo_final = ?, factura_archivo = COALESCE(?, factura_archivo) WHERE id = ?')
        ->execute([$res, $fecha, trim($_POST['trabajo'] ?? '') ?: null, $costo, $fact, $regreso_id]);
    $vuelve = $res === 'sin_arreglo' ? 'danado' : (in_array($r['estado_antes'], ['mantenimiento', 'danado', '', null], true) ? 'instalado' : $r['estado_antes']);
    db()->prepare("UPDATE items SET estado = ?, actualizado = NOW() WHERE id = ? AND estado = 'mantenimiento'")->execute([$vuelve, $r['item_id']]);
    $txt = ($res === 'sin_arreglo' ? 'Volvió de ' . $r['proveedor'] . ' sin arreglo' : 'Volvió reparada de ' . $r['proveedor']) . (trim($_POST['trabajo'] ?? '') ? ': ' . trim($_POST['trabajo']) : '') . ($costo !== null ? ' · costó ' . pesos($costo) : '');
    db()->prepare('INSERT INTO movimientos (item_id, tipo, usuario_id, detalle, fecha) VALUES (?, ?, ?, ?, ?)')->execute([$r['item_id'], $res === 'sin_arreglo' ? 'danado' : 'reparacion', usuario()['id'], $txt, $fecha . ' ' . date('H:i:s')]);
    auditar('items', $r['item_id'], ['estado' => 'mantenimiento'], ['estado' => $vuelve], $txt);
    flash($r['etiqueta'] . ': ' . ($res === 'sin_arreglo' ? 'quedó como dañada' : 'de vuelta y funcionando'));
    ir('impresoras.php#reparaciones');
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
$sel_rep = null;
foreach ($imps as $i) { if ((int)$i['id'] === $toner_id) $sel = $i; if ((int)$i['id'] === $reparar_id) $sel_rep = $i; }
$reps = db()->query("SELECT r.*, i.etiqueta, i.marca, i.modelo, i.ubicacion FROM reparaciones r JOIN items i ON i.id = r.item_id
    ORDER BY r.estado = 'enviada' DESC, r.fecha_envio DESC, r.id DESC LIMIT 60")->fetchAll();
$en_rep = [];
foreach ($reps as $r) if ($r['estado'] === 'enviada') $en_rep[$r['item_id']] = $r;
$sel_reg = null;
foreach ($reps as $r) if ((int)$r['id'] === $regreso_id && $r['estado'] === 'enviada') $sel_reg = $r;
$gasto_anio = db()->query("SELECT COALESCE(SUM(COALESCE(costo_final, 0)), 0) FROM reparaciones WHERE YEAR(COALESCE(fecha_regreso, fecha_envio)) = YEAR(CURDATE())")->fetchColumn();
$proveedores = db()->query("SELECT DISTINCT empresa FROM contactos WHERE grupo LIKE '%mpresora%' UNION SELECT DISTINCT proveedor FROM reparaciones ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);

$titulo = 'Impresoras';
$menu = 'impresoras';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1>Impresoras</h1><small><?= count($imps) ?> equipos</small></div>
  <div class="acciones"><?= boton_cambios($hist, array_column($imps, 'etiqueta', 'id')) ?><?php if (es_admin()): ?><a class="btn btn-primario" href="item.php">+ Nueva impresora</a><?php endif; ?></div>
</div>

<?php $cerrar = ($sel && ($_GET['volver'] ?? '') === 'item') ? 'item.php?id=' . $sel['id'] : 'impresoras.php'; ?>
<?php if ($sel && es_admin()): ?>
<div class="modal-fondo" data-cerrar="<?= e($cerrar) ?>">
<form method="post" action="impresoras.php?toner=<?= $sel['id'] ?>" class="tarjeta modal-caja" role="dialog" aria-modal="true"><input type="hidden" name="volver" value="<?= e($_GET['volver'] ?? '') ?>">
  <?= csrf_campo() ?>
  <a class="modal-x" href="<?= e($cerrar) ?>" aria-label="Cerrar">×</a>
  <h2>Cambio de toner · <?= e($sel['etiqueta'] . ' ' . $sel['modelo']) ?></h2>
  <div class="rejilla">
    <div class="campo"><label>Fecha</label><input type="date" name="fecha" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="campo"><label>Toner</label><input name="consumible" value="<?= e($sel['consumible']) ?>"></div>
    <div class="campo"><label>Contador de paginas</label><input name="contador" inputmode="numeric"></div>
    <div class="campo"><label>Notas</label><input name="notas"></div>
  </div>
  <p><button class="btn btn-primario">Guardar</button> <a class="btn btn-borde" href="<?= e($cerrar) ?>">Cancelar</a></p>
</form>
</div>
<?php endif; ?>

<?php if ($sel_rep && es_admin()): ?>
<div class="modal-fondo" data-cerrar="impresoras.php">
<form method="post" action="impresoras.php?reparar=<?= $sel_rep['id'] ?>" class="tarjeta modal-caja" role="dialog" aria-modal="true" enctype="multipart/form-data">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="reparar">
  <a class="modal-x" href="<?= e($cerrar) ?>" aria-label="Cerrar">×</a>
  <h2>Mandar a reparar · <?= e($sel_rep['etiqueta'] . ' ' . $sel_rep['modelo']) ?></h2>
  <div class="rejilla">
    <div class="campo"><label>Fecha en que sale</label><input type="date" name="fecha" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="campo"><label>Tipo</label><select name="tipo"><option value="reparacion">Reparación</option><option value="revision">Revisión / diagnóstico</option></select></div>
    <div class="campo"><label>Se manda a</label><input name="proveedor" required list="provs" placeholder="Datecsa, Copytel del Caribe…"><datalist id="provs"><?php foreach ($proveedores as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></div>
    <div class="campo"><label>Valor de la cotización</label><input name="valor" inputmode="numeric" placeholder="$ 350.000"></div>
    <div class="campo" style="grid-column:1/-1"><label>¿Qué tiene?</label><textarea name="falla" rows="2" required placeholder="Atasca el papel, raya las hojas, no prende…"></textarea></div>
    <div class="campo" style="grid-column:1/-1"><label>Cotización (PDF o foto, opcional)</label><input type="file" name="cotizacion" accept="application/pdf,image/*"></div>
  </div>
  <p style="margin-bottom:0"><button class="btn btn-primario">Mandar a reparar</button> <a class="btn btn-borde" href="<?= e($cerrar) ?>">Cancelar</a></p>
  <p class="tenue" style="margin-bottom:0">La impresora queda en “Mantenimiento” hasta que registres el regreso.</p>
</form>
</div>
<?php endif; ?>

<?php if ($sel_reg && es_admin()): ?>
<div class="modal-fondo" data-cerrar="impresoras.php">
<form method="post" action="impresoras.php?regreso=<?= $sel_reg['id'] ?>" class="tarjeta modal-caja" role="dialog" aria-modal="true" enctype="multipart/form-data">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="regreso">
  <a class="modal-x" href="<?= e($cerrar) ?>" aria-label="Cerrar">×</a>
  <h2>Regreso de <?= e($sel_reg['etiqueta']) ?> · <?= e($sel_reg['proveedor']) ?></h2>
  <p class="tenue" style="margin-top:-6px">Salió el <?= fecha_corta($sel_reg['fecha_envio']) ?>: <?= e($sel_reg['falla']) ?><?= $sel_reg['cotizacion_valor'] !== null ? ' · cotización ' . pesos($sel_reg['cotizacion_valor']) : '' ?></p>
  <div class="rejilla">
    <div class="campo"><label>Fecha en que volvió</label><input type="date" name="fecha" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="campo"><label>Resultado</label><select name="resultado"><option value="reparada">Reparada, funcionando</option><option value="sin_arreglo">No tiene arreglo (queda dañada)</option></select></div>
    <div class="campo"><label>Cuánto costó al final</label><input name="costo" inputmode="numeric" value="<?= $sel_reg['cotizacion_valor'] !== null ? number_format((float)$sel_reg['cotizacion_valor'], 0, ',', '.') : '' ?>"></div>
    <div class="campo" style="grid-column:1/-1"><label>¿Qué le hicieron?</label><textarea name="trabajo" rows="2" placeholder="Cambio de fusor, limpieza, rodillos…"></textarea></div>
    <div class="campo" style="grid-column:1/-1"><label>Factura (PDF o foto, opcional)</label><input type="file" name="factura" accept="application/pdf,image/*"></div>
  </div>
  <p style="margin-bottom:0"><button class="btn btn-primario">Guardar regreso</button> <a class="btn btn-borde" href="<?= e($cerrar) ?>">Cancelar</a></p>
</form>
</div>
<?php endif; ?>

<div class="dos-col">
  <div class="tabla-envoltura"><table class="tabla">
    <thead><tr><th>Equipo</th><th>Ubicacion</th><th>IP</th><th>Toner</th><th>Ultimo cambio</th><th></th></tr></thead><tbody>
    <?php if (!$imps): ?><tr><td colspan="6" class="vacio">No hay impresoras</td></tr><?php endif; ?>
    <?php foreach ($imps as $i): ?>
      <tr><td><a class="mono" href="item.php?id=<?= $i['id'] ?>"><?= e($i['etiqueta']) ?></a><small><?= e(trim($i['marca'] . ' ' . $i['modelo'])) ?></small></td>
        <td><?= e($i['ubicacion'] ?: ($i['sede'] ?: '—')) ?><?= isset($en_rep[$i['id']]) ? '<small><span class="chip chip-mantenimiento">En reparación · ' . e($en_rep[$i['id']]['proveedor']) . '</span></small>' : '' ?></td><td class="mono"><?= e($i['ip'] ?: '—') ?></td>
        <td class="mono"><?= e($i['consumible'] ?: '—') ?></td>
        <td><?= fecha_corta($i['ultimo_cambio']) ?: '—' ?><?= $i['cambios_anio'] ? '<small>' . $i['cambios_anio'] . ' en 12 meses</small>' : '' ?></td>
        <td style="white-space:nowrap"><?php if (es_admin()): ?><a class="btn btn-borde btn-sm" href="item.php?id=<?= $i['id'] ?>&editar=1&volver=impresoras.php" title="Editar ubicación, IP, tóner, modelo...">✎ Editar</a>
          <a class="btn btn-borde btn-sm" href="?toner=<?= $i['id'] ?>">Cambio de toner</a>
          <?php if (isset($en_rep[$i['id']])): ?><a class="btn btn-primario btn-sm" href="?regreso=<?= $en_rep[$i['id']]['id'] ?>">Registrar regreso</a>
          <?php else: ?><a class="btn btn-borde btn-sm" href="?reparar=<?= $i['id'] ?>">Mandar a reparar</a><?php endif; ?><?php endif; ?></td></tr>
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

<div class="tarjeta" id="reparaciones" style="margin-top:16px">
  <div class="cabecera" style="margin-bottom:6px"><h2 style="margin:0">Reparaciones y revisiones</h2>
    <small class="tenue">Gastado en <?= date('Y') ?>: <b style="color:var(--texto)"><?= pesos($gasto_anio) ?></b></small></div>
  <?php if (!$reps): ?><div class="vacio">Todavía no se ha mandado ninguna a reparar. Usa “Mandar a reparar” en la impresora.</div><?php else: ?>
  <div class="tabla-envoltura" style="border:0"><table class="tabla">
    <thead><tr><th>Impresora</th><th>Salió</th><th>Se mandó a</th><th>Qué tenía</th><th>Cotización</th><th>Volvió</th><th>Costó</th><th></th></tr></thead><tbody>
    <?php foreach ($reps as $r): ?>
      <tr><td><a class="mono" href="item.php?id=<?= $r['item_id'] ?>"><?= e($r['etiqueta']) ?></a><small><?= e(trim($r['marca'] . ' ' . $r['modelo'])) ?></small></td>
        <td><?= fecha_corta($r['fecha_envio']) ?><small><?= $r['tipo'] === 'revision' ? 'Revisión' : 'Reparación' ?></small></td>
        <td><?= e($r['proveedor']) ?></td>
        <td><?= e($r['falla']) ?><?= $r['trabajo'] ? '<small>Le hicieron: ' . e($r['trabajo']) . '</small>' : '' ?></td>
        <td><?= pesos($r['cotizacion_valor']) ?><?= $r['cotizacion_archivo'] ? '<small><a href="' . e(url_archivo($r['cotizacion_archivo'])) . '" target="_blank">Ver cotización</a></small>' : '' ?></td>
        <td><?= $r['estado'] === 'enviada' ? '<span class="chip chip-mantenimiento">En reparación</span>' : fecha_corta($r['fecha_regreso']) . '<small>' . ($r['estado'] === 'sin_arreglo' ? '<span class="chip chip-danado">Sin arreglo</span>' : 'Reparada') . '</small>' ?></td>
        <td><b><?= pesos($r['costo_final']) ?></b><?= $r['factura_archivo'] ? '<small><a href="' . e(url_archivo($r['factura_archivo'])) . '" target="_blank">Ver factura</a></small>' : '' ?></td>
        <td><?php if ($r['estado'] === 'enviada' && es_admin()): ?><a class="btn btn-borde btn-sm" href="?regreso=<?= $r['id'] ?>">Registrar regreso</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/inc/footer.php';
