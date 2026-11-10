<?php
require __DIR__ . '/config.php';
requiere_modulo('colaboradores');

$id = (int)($_GET['id'] ?? 0);
$c = null;
if ($id) {
    $st = db()->prepare('SELECT c.*, s.nombre sede, u.nombre inactivado_por_nombre FROM colaboradores c
        LEFT JOIN sedes s ON s.id = c.sede_id LEFT JOIN usuarios u ON u.id = c.inactivado_por WHERE c.id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) { http_response_code(404); exit('No existe'); }
}
$editar = (!$c || isset($_GET['editar'])) && es_admin();
if (!$c && !es_admin()) { http_response_code(403); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin()) {
    verificar_csrf();
    if (($_POST['accion'] ?? '') === 'activo' && $c) {
        $nuevo = $c['activo'] ? 0 : 1;
        db()->prepare('UPDATE colaboradores SET activo = ?, inactivado_en = IF(? = 0, NOW(), NULL), inactivado_por = IF(? = 0, ?, NULL) WHERE id = ?')
            ->execute([$nuevo, $nuevo, $nuevo, usuario()['id'], $id]);
        auditar('colaboradores', $id, ['activo' => $c['activo']], ['activo' => $nuevo], $nuevo ? 'Ficha activada' : 'Ficha inactivada');
        flash($nuevo ? 'Ficha activada' : 'Ficha inactivada');
        ir('colaborador.php?id=' . $id);
    }
    $d = [];
    foreach (['nombre', 'cedula', 'cargo', 'email', 'telefono', 'extension_pc', 'sede_id', 'area_id', 'grupo'] as $k) {
        $v = trim($_POST[$k] ?? '');
        $d[$k] = $v === '' ? null : $v;
    }
    if ($d['email']) $d['email'] = strtolower($d['email']);
    if (!$d['nombre']) $error = 'El nombre es obligatorio';
    // el area se escoge de la lista y el grupo solo si el area lo pide
    $d['area'] = null;
    if (!$error && $d['area_id']) {
        $ar = area_por_id($d['area_id']);
        $grupos = $ar ? grupos_area($ar) : [];
        if (!$grupos) $d['grupo'] = null;
        elseif (!in_array($d['grupo'], $grupos, true)) $error = 'Escoge el grupo de ' . $ar['nombre'] . ' (' . implode(' o ', $grupos) . ')';
        if ($ar) $d['area'] = $ar['nombre'] . ($d['grupo'] ? ' ' . $d['grupo'] : '');
    } else {
        $d['grupo'] = null;
    }
    if (!$error && $d['cedula']) {
        $st = db()->prepare('SELECT nombre FROM colaboradores WHERE cedula = ? AND id <> ?');
        $st->execute([$d['cedula'], $id]);
        if ($o = $st->fetchColumn()) $error = 'Esa cedula ya es de ' . $o;
    }
    if (!$error) {
        $cols = array_keys($d);
        if ($c) {
            db()->prepare('UPDATE colaboradores SET ' . implode(', ', array_map(fn($x) => "$x = ?", $cols)) . ' WHERE id = ?')
                ->execute(array_merge(array_values($d), [$id]));
            auditar('colaboradores', $id, $c, $d);
        } else {
            db()->prepare('INSERT INTO colaboradores (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($d));
            $id = (int)db()->lastInsertId();
            auditar('colaboradores', $id, null, null, 'Ficha creada');
        }
        flash('Ficha guardada');
        ir('colaborador.php?id=' . $id);
    }
    $c = array_merge($c ?? [], $d);
}

$items = [];
if ($id) {
    $st = db()->prepare("SELECT i.*, cat.nombre categoria FROM items i LEFT JOIN categorias cat ON cat.id = i.categoria_id WHERE (i.colaborador_id = ? OR (i.prestado_a = ? AND i.estado = 'prestado')) AND i.estado <> 'baja'
        ORDER BY FIELD(cat.nombre, 'Portatil', 'Computador', 'Telefono IP', 'Celular', 'Tablet') = 0, FIELD(cat.nombre, 'Portatil', 'Computador', 'Telefono IP', 'Celular', 'Tablet'), i.etiqueta");
    $st->execute([$id, $id]);
    $items = $st->fetchAll();
}

$titulo = $c['nombre'] ?? 'Nueva ficha';
$menu = 'colaboradores';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><a href="colaboradores.php" class="tenue">&larr; Colaboradores</a><h1><?= e($c['nombre'] ?? 'Nueva ficha') ?></h1>
    <?php if ($c && !$c['activo']): ?><small>Inactivo<?= $c['inactivado_en'] ? ' desde ' . fecha_corta($c['inactivado_en']) : '' ?><?= $c['inactivado_por_nombre'] ? ' por ' . e($c['inactivado_por_nombre']) : ' · ya no trabaja en la empresa' ?></small><?php endif; ?></div>
  <?php if (!$editar && es_admin()): ?>
  <div class="acciones">
    <?= boton_cambios([$id => cambios_de('colaboradores', $id)], [$id => $c['nombre']], 'colaborador.php?id=') ?>
    <?php if ($c['activo']): ?><a class="btn btn-primario" href="acta_nueva.php?tipo=entrega&colab=<?= $id ?>">Entregar</a><?php endif; ?>
    <?php if ($items): ?><a class="btn btn-borde" href="acta_nueva.php?tipo=devolucion&colab=<?= $id ?>&todos=1">Recoger todo (retiro)</a><?php endif; ?>
    <a class="btn btn-borde" href="acta_nueva.php?historica=1&colab=<?= $id ?>">Subir acta en papel</a>
    <a class="btn btn-borde" href="colaborador.php?id=<?= $id ?>&editar=1">Editar</a>
  </div>
  <?php endif; ?>
</div>
<?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($editar): ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <div class="rejilla">
    <div class="campo"><label>Nombre completo *</label><input name="nombre" required value="<?= e($c['nombre'] ?? '') ?>"></div>
    <div class="campo"><label>Cedula</label><input name="cedula" inputmode="numeric" value="<?= e($c['cedula'] ?? '') ?>"></div>
    <div class="campo"><label>Cargo</label><input name="cargo" value="<?= e($c['cargo'] ?? '') ?>"></div>
    <div class="campo"><label>Area</label>
      <select name="area_id" id="area_id">
        <option value="" data-grupos="">Sin area</option>
        <?php foreach (areas() as $ar): ?>
          <option value="<?= $ar['id'] ?>" data-grupos="<?= e($ar['grupos']) ?>" <?= (string)$ar['id'] === (string)($c['area_id'] ?? '') ? 'selected' : '' ?>><?= e($ar['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($c['area']) && empty($c['area_id'])): ?><small>Antes: <?= e($c['area']) ?></small><?php endif; ?></div>
    <div class="campo" id="campo-grupo" style="display:none"><label>Grupo *</label><select name="grupo" id="grupo"></select></div>
    <div class="campo"><label>Correo</label><input type="email" name="email" value="<?= e($c['email'] ?? '') ?>"></div>
    <div class="campo"><label>Celular</label><input name="telefono" value="<?= e($c['telefono'] ?? '') ?>"></div>
    <div class="campo"><label>Extensión en el PC (MicroSIP)</label><input name="extension_pc" inputmode="numeric" maxlength="10" value="<?= e($c['extension_pc'] ?? '') ?>"><small>Solo si no tiene teléfono físico y llama desde el computador</small></div>
    <div class="campo"><label>Bodega</label><select name="sede_id"><?= opciones(sedes(), $c['sede_id'] ?? '', 'nombre', 'Sin bodega') ?></select></div>
  </div>
  <p><button class="btn btn-primario">Guardar</button> <a class="btn btn-borde" href="<?= $id ? 'colaborador.php?id=' . $id : 'colaboradores.php' ?>">Cancelar</a></p>
</form>
<script>
// muestra el selector de grupo solo cuando el area tiene grupos
(function () {
  var area = document.getElementById('area_id'), campo = document.getElementById('campo-grupo'), grupo = document.getElementById('grupo');
  var actual = <?= json_encode($c['grupo'] ?? '') ?>;
  function pintar() {
    var g = (area.options[area.selectedIndex].dataset.grupos || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
    grupo.innerHTML = '<option value="">Escoger...</option>';
    g.forEach(function (x) {
      var o = document.createElement('option');
      o.value = o.textContent = x;
      if (x === actual) o.selected = true;
      grupo.appendChild(o);
    });
    campo.style.display = g.length ? '' : 'none';
    grupo.required = g.length > 0;
  }
  area.addEventListener('change', function () { actual = ''; pintar(); });
  pintar();
})();
</script>
<?php else: ?>
<div class="dos-col">
  <div>
    <div class="tarjeta">
      <h2>Puesto de trabajo</h2>
      <?php if (!$items): ?><div class="tenue">No tiene nada a su nombre</div><?php endif; ?>
      <div class="puesto">
      <?php foreach ($items as $i):
        $tipo = in_array($i['categoria'], ['Portatil', 'Computador'], true) ? tipo_pc($i) : ($i['categoria'] === 'Telefono IP' ? 'Teléfono' : ($i['categoria'] ?: 'Otro'));
        $sub = $i['categoria'] === 'Telefono IP' ? 'Ext. ' . ($i['extension'] ?: '—') : trim($i['marca'] . ' ' . $i['modelo']); ?>
        <a href="item.php?id=<?= $i['id'] ?>"><small><?= e($tipo) ?></small><b><?= e($i['categoria'] === 'Telefono IP' ? $sub : ($sub ?: $i['nombre'])) ?></b>
          <span class="mono"><?= e($i['etiqueta']) ?><?= $i['ip'] ? ' · ' . e($i['ip']) : '' ?></span><?= $i['estado'] !== 'asignado' ? ' ' . chip_estado($i['estado']) : '' ?></a>
      <?php endforeach; ?>
      </div>
    </div>
    <?php [$acc, $acc_conteo] = accesorios_colaborador($id); if ($acc): ?>
    <div class="tarjeta">
      <h2>Accesorios y elementos sin etiqueta</h2>
      <div class="pildoras" style="margin-top:0"><?php foreach ($acc_conteo as $g => $n): ?><span class="pildora"><?= e($g) ?><b><?= $n ?></b></span><?php endforeach; ?></div>
      <ul class="historial">
      <?php foreach ($acc as $f): ?>
        <li><time><?= fecha_corta($f['fecha']) ?></time><div><a href="acta.php?id=<?= $f['acta_id'] ?>"><?= e($f['descripcion']) ?></a><?= $f['cantidad'] > 1 ? ' ×' . (int)$f['cantidad'] : '' ?>
          <?= $f['tipo'] === 'devolucion' ? '<span class="chip chip-disponible">devolvió</span>' : ($f['prestamo'] ? '<span class="chip chip-pendiente">préstamo</span>' : '') ?></div></li>
      <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <div class="tarjeta">
      <h2>Hoja de vida</h2>
      <p class="tenue" style="margin:-6px 0 8px;font-size:12.5px">Qué se le asignó y cuándo, préstamos, devoluciones, cambios de equipo y reparaciones.</p>
      <?= pintar_linea_tiempo(linea_tiempo_colaborador($id)) ?>
    </div>
  </div>
  <div>
    <div class="tarjeta"><div class="ficha">
      <div><small>Cedula</small><span><?= e($c['cedula'] ?: '—') ?></span></div>
      <div><small>Cargo</small><span><?= e($c['cargo'] ?: '—') ?></span></div>
      <div><small>Area</small><span><?= e($c['area'] ?: '—') ?></span></div>
      <div><small>Bodega</small><span><?= e($c['sede'] ?: '—') ?></span></div>
      <div><small>Correo</small><span><?= e($c['email'] ?: '—') ?></span></div>
      <div><small>Celular</small><span><?= e($c['telefono'] ?: '—') ?></span></div>
      <?php if (!empty($c['extension_pc'])): ?><div><small>Extensión</small><span><?= e($c['extension_pc']) ?> <span class="chip chip-gris">MicroSIP</span></span></div><?php endif; ?>
    </div></div>
    <?php if (es_admin()): ?>
    <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="activo">
      <?php if ($c['activo']): ?>
        <button class="btn btn-borde btn-bloque" data-confirmar="<?= $items ? 'Todavia tiene ' . count($items) . ' items a su nombre. Inactivar de todas formas?' : 'Inactivar esta ficha?' ?>">Inactivar ficha</button>
      <?php else: ?>
        <button class="btn btn-borde btn-bloque">Activar ficha</button>
      <?php endif; ?>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
