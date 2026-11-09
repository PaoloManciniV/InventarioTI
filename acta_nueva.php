<?php
// crea acta de entrega o devolucion
// con historica=1 sirve para pasar las actas en papel desde 2022
require __DIR__ . '/config.php';
requiere_admin();
requiere_modulo('actas');

$historica = !empty($_REQUEST['historica']);
$tipo = ($_REQUEST['tipo'] ?? 'entrega') === 'devolucion' ? 'devolucion' : 'entrega';
$colab_id = (int)($_REQUEST['colab'] ?? 0);
$pre = array_filter(array_map('intval', explode(',', $_GET['items'] ?? '')));
$error = '';

// si viene un item asignado y no hay colaborador se toma el responsable
if (!$colab_id && $pre && $tipo === 'devolucion') {
    $colab_id = (int)db()->query('SELECT colaborador_id FROM items WHERE id = ' . (int)$pre[0])->fetchColumn();
}

$colab = null;
if ($colab_id) {
    $st = db()->prepare('SELECT * FROM colaboradores WHERE id = ?');
    $st->execute([$colab_id]);
    $colab = $st->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $sel = array_map('intval', $_POST['item'] ?? []);
    $cond = $_POST['condicion'] ?? [];
    $libres = [];
    foreach ($_POST['libre_desc'] ?? [] as $k => $desc) {
        $desc = trim($desc);
        if ($desc === '') continue;
        $libres[] = ['descripcion' => $desc, 'serial' => trim($_POST['libre_serial'][$k] ?? ''),
            'cantidad' => max(1, (int)($_POST['libre_cant'][$k] ?? 1)), 'condicion' => trim($_POST['libre_cond'][$k] ?? '')];
    }
    $fecha = $historica ? ($_POST['fecha'] ?? '') : date('Y-m-d');

    if (!$colab) $error = 'Selecciona el colaborador';
    elseif (!$sel && !$libres) $error = 'Agrega al menos un item';
    elseif ($sel && !$historica && $tipo === 'entrega') {
        // que nadie lo haya tomado mientras tanto y que en prestamo solo vaya lo prestable
        $st = db()->prepare('SELECT i.etiqueta, i.estado, i.colaborador_id, i.prestado_a, c.nombre categoria FROM items i LEFT JOIN categorias c ON c.id = i.categoria_id
            WHERE i.id IN (' . implode(',', array_fill(0, count($sel), '?')) . ')');
        $st->execute($sel);
        foreach ($st->fetchAll() as $r) {
            if ($r['estado'] !== 'disponible' || $r['colaborador_id'] || $r['prestado_a']) { $error = $r['etiqueta'] . ' ya no está disponible'; break; }
            if (!empty($_POST['prestamo']) && !in_array($r['categoria'], categorias_prestables(), true)) { $error = $r['etiqueta'] . ' no se presta (' . $r['categoria'] . ')'; break; }
        }
    }
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $error = 'Fecha invalida';
    elseif ($historica && empty($_FILES['fotos']['name'][0])) $error = 'Sube la foto o pdf del acta en papel';

    if (!$error) {
        $pdo = db();
        $pdo->beginTransaction();
        $consec = siguiente_consecutivo($tipo, substr($fecha, 0, 4));
        $sede_id = (int)($_POST['sede_id'] ?? 0) ?: ($colab['sede_id'] ?: null);
        $aplica = $historica ? (!empty($_POST['aplica']) ? 1 : 0) : 1;
        $prestamo = $tipo === 'entrega' && !empty($_POST['prestamo']) ? 1 : 0;
        $pdo->prepare('INSERT INTO actas (consecutivo, tipo, prestamo, colaborador_id, sede_id, fecha, observaciones, estado, historica, aplica_inventario, creado_por,
            firmado_modo, firmado_en, firma_nombre, firma_cedula) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$consec, $tipo, $prestamo, $colab['id'], $sede_id, $fecha, trim($_POST['observaciones'] ?? '') ?: null,
                $historica ? 'firmada' : 'pendiente', $historica ? 1 : 0, $aplica, usuario()['id'],
                $historica ? 'papel' : null, $historica ? $fecha . ' 00:00:00' : null,
                $historica ? $colab['nombre'] : null, $historica ? $colab['cedula'] : null]);
        $acta_id = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO acta_items (acta_id, item_id, etiqueta, descripcion, serial, cantidad, condicion) VALUES (?,?,?,?,?,?,?)');
        if ($sel) {
            $st = $pdo->prepare('SELECT * FROM items WHERE id IN (' . implode(',', array_fill(0, count($sel), '?')) . ')');
            $st->execute($sel);
            foreach ($st->fetchAll() as $i) {
                $desc = trim($i['nombre'] . ' ' . $i['marca'] . ' ' . $i['modelo']);
                $ins->execute([$acta_id, $i['id'], $i['etiqueta'], $desc, $i['serial'], $i['cantidad'], trim($cond[$i['id']] ?? '') ?: null]);
            }
        }
        foreach ($libres as $l) {
            // en prestamo lo escrito a mano tambien entra al inventario, para poder saber que esta prestado y recibirlo de vuelta
            [$nid, $net] = ($prestamo && $aplica) ? crear_item_prestamo($l['descripcion'], $l['serial'], $l['cantidad'], $l['condicion']) : [null, null];
            $ins->execute([$acta_id, $nid, $net, $l['descripcion'], $l['serial'] ?: null, $l['cantidad'], $l['condicion'] ?: null]);
        }

        if ($historica) {
            $n = count($_FILES['fotos']['name']);
            $insf = $pdo->prepare('INSERT INTO acta_archivos (acta_id, archivo) VALUES (?, ?)');
            for ($k = 0; $k < $n; $k++) {
                $_FILES['_una'] = ['name' => $_FILES['fotos']['name'][$k], 'tmp_name' => $_FILES['fotos']['tmp_name'][$k],
                    'error' => $_FILES['fotos']['error'][$k], 'size' => $_FILES['fotos']['size'][$k]];
                if ($ruta = guardar_archivo_subido('_una', 'actas')) $insf->execute([$acta_id, $ruta]);
            }
            $a = cargar_acta($acta_id);
            $pdo->prepare('UPDATE actas SET hash_contenido = ? WHERE id = ?')->execute([hash_acta($a), $acta_id]);
            aplicar_acta($acta_id);
            if (!$aplica) {
                // queda en el historial del item aunque no mueva el estado actual
                foreach ($a['items'] as $i) if ($i['item_id']) registrar_movimiento($i['item_id'], 'historico',
                    'Acta en papel ' . $consec . ' (' . fecha_corta($fecha) . ') ' . ($tipo === 'entrega' ? 'entrega a ' : 'devolucion de ') . $colab['nombre'], $colab['id'], $acta_id);
            }
        }
        $pdo->commit();
        flash($historica ? 'Acta en papel registrada' : 'Acta creada, falta la firma');
        ir($historica ? 'acta.php?id=' . $acta_id : 'firma.php?id=' . $acta_id);
    }
}

// candidatos
if ($tipo === 'entrega') {
    // solo lo libre: disponible, sin dueno y guardado en sistemas o sin ubicacion; nada instalado en un puesto
    $fijas = implode(',', array_map(fn($c) => db()->quote($c), categorias_fijas()));
    $sql = "SELECT i.*, c.nombre categoria, col.nombre responsable FROM items i LEFT JOIN categorias c ON c.id = i.categoria_id
        LEFT JOIN colaboradores col ON col.id = i.colaborador_id WHERE "
        . ($historica ? "i.estado <> 'baja'" : "i.estado = 'disponible' AND i.colaborador_id IS NULL AND i.prestado_a IS NULL
            AND (COALESCE(TRIM(i.ubicacion), '') = '' OR i.ubicacion = 'Sistemas') AND COALESCE(c.nombre, '') NOT IN ($fijas)") . ' ORDER BY i.etiqueta';
    $candidatos = db()->query($sql)->fetchAll();
} else {
    $candidatos = [];
    if ($colab) {
        $sql = "SELECT i.*, c.nombre categoria, col.nombre responsable FROM items i LEFT JOIN categorias c ON c.id = i.categoria_id
            LEFT JOIN colaboradores col ON col.id = i.colaborador_id WHERE " . ($historica ? "i.estado <> 'baja'" : "(i.colaborador_id = ? OR (i.prestado_a = ? AND i.estado = 'prestado'))") . ' ORDER BY i.colaborador_id = ? DESC, i.etiqueta';
        $st = db()->prepare($sql);
        $st->execute($historica ? [$colab['id']] : [$colab['id'], $colab['id'], $colab['id']]);
        $candidatos = $st->fetchAll();
    }
}
$marcados = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_map('intval', $_POST['item'] ?? []) : $pre;
if (!empty($_GET['todos'])) $marcados = array_column($candidatos, 'id');

$colabs = db()->query('SELECT id, nombre, cedula FROM colaboradores WHERE activo = 1 OR id = ' . (int)$colab_id . ' ORDER BY nombre')->fetchAll();

$titulo = $historica ? 'Acta en papel' : ($tipo === 'entrega' ? 'Nueva entrega' : 'Nueva devolucion');
$menu = 'actas';
require __DIR__ . '/inc/header.php';
$qs = fn($c) => '?' . http_build_query(array_merge(['tipo' => $tipo, 'colab' => $colab_id ?: null, 'historica' => $historica ? 1 : null, 'items' => $_GET['items'] ?? null], $c));
?>
<div class="cabecera">
  <div><a href="actas.php" class="tenue">&larr; Actas</a><h1><?= e($titulo) ?></h1>
    <small><?= $historica ? 'Registra un acta vieja con la foto del papel firmado' : 'Se crea el acta y se firma con el dedo en el celular o se envia el link' ?></small></div>
  <div class="pildoras" style="margin:0">
    <a class="pildora <?= $tipo === 'entrega' ? 'activa' : '' ?>" href="<?= e($qs(['tipo' => 'entrega'])) ?>">Entrega</a>
    <a class="pildora <?= $tipo === 'devolucion' ? 'activa' : '' ?>" href="<?= e($qs(['tipo' => 'devolucion'])) ?>">Devolucion</a>
    <a class="pildora <?= $historica ? 'activa' : '' ?>" href="<?= e($qs(['historica' => $historica ? null : 1])) ?>">En papel</a>
  </div>
</div>
<?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <?= csrf_campo() ?>
  <input type="hidden" name="tipo" value="<?= $tipo ?>">
  <?php if ($historica): ?><input type="hidden" name="historica" value="1"><?php endif; ?>
  <div class="tarjeta">
    <div class="rejilla">
      <div class="campo"><label><?= $tipo === 'entrega' ? 'Recibe' : 'Devuelve' ?> *</label>
        <select name="colab" required data-buscar onchange="location='<?= e($qs(['colab' => null])) ?>&colab=' + this.value">
          <option value="">Seleccionar...</option>
          <?php foreach ($colabs as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $colab_id ? 'selected' : '' ?>><?= e($c['nombre']) ?><?= $c['cedula'] ? ' · ' . e($c['cedula']) : '' ?></option><?php endforeach; ?>
        </select>
        <small><a href="colaborador.php" target="_blank">+ Nueva ficha</a> (luego recarga)</small></div>
      <div class="campo"><label>Bodega</label><select name="sede_id"><?= opciones(sedes(), $colab['sede_id'] ?? '', 'nombre', 'La del colaborador') ?></select></div>
      <?php if ($historica): ?>
        <div class="campo"><label>Fecha del acta en papel *</label><input type="date" name="fecha" required max="<?= date('Y-m-d') ?>" value="<?= e($_POST['fecha'] ?? '') ?>"></div>
        <div class="campo"><label>Foto(s) o pdf del papel *</label><input type="file" name="fotos[]" accept="image/*,application/pdf" multiple required></div>
        <div class="campo ancho"><label style="display:flex;gap:8px;align-items:center;color:var(--texto)"><input type="checkbox" name="aplica" value="1" <?= !empty($_POST['aplica']) ? 'checked' : '' ?>>
          Esta acta refleja como estan las cosas hoy (mueve el estado y responsable de los items)</label>
          <small>Dejalo sin marcar si despues hubo otra entrega o devolucion. Igual queda en el historial del item.</small></div>
      <?php endif; ?>
      <?php if ($tipo === 'entrega'): ?>
      <div class="campo ancho"><label style="display:flex;gap:8px;align-items:center;color:var(--texto);text-transform:none;font-family:inherit;font-size:13.5px">
        <input type="checkbox" name="prestamo" value="1" <?= !empty($_POST['prestamo']) ? 'checked' : '' ?>> Es un préstamo (el dueño sigue siendo Sistemas, el colaborador solo lo tiene prestado)</label></div>
      <?php endif; ?>
      <div class="campo ancho"><label>Observaciones</label><textarea name="observaciones" placeholder="Ej. se entrega con cargador y maletin"><?= e($_POST['observaciones'] ?? '') ?></textarea></div>
    </div>
  </div>

  <div class="tarjeta">
    <div class="cabecera" style="margin-bottom:8px"><h2 style="margin:0">Items del inventario</h2>
      <input type="search" id="filtro-items" placeholder="Filtrar por etiqueta, serial, nombre..." style="min-width:260px"></div>
    <?php if ($tipo === 'devolucion' && !$colab): ?><div class="tenue">Selecciona primero quien devuelve</div>
    <?php elseif (!$candidatos): ?><div class="tenue"><?= $tipo === 'entrega' ? 'No hay items disponibles.' : 'No tiene items a su nombre.' ?> Puedes agregar elementos abajo.</div>
    <?php elseif ($tipo === 'entrega' && !$historica): ?><p class="tenue" id="aviso-prestamo" style="margin-top:-4px;display:none">En préstamo solo salen portátiles, periféricos, red y video beam disponibles. Lo que agregues a mano (adaptador, mouse, cable…) se crea solo en el inventario como RED-xxx o PER-xxx y queda prestado.</p>
    <?php endif; ?>
    <?php if ($candidatos && !($tipo === 'devolucion' && !$colab)): ?>
    <div class="tabla-envoltura" style="max-height:420px;overflow:auto">
    <table class="tabla" id="tabla-items"><thead><tr><th></th><th>Etiqueta</th><th>Item</th><th>Serial</th><th><?= $historica ? 'Hoy' : 'Categoria' ?></th><th>Condicion</th></tr></thead><tbody>
    <?php foreach ($candidatos as $i): $m = in_array((int)$i['id'], $marcados, true); ?>
      <tr data-prestable="<?= in_array($i['categoria'], categorias_prestables(), true) ? 1 : 0 ?>" data-texto="<?= e(mb_strtolower($i['etiqueta'] . ' ' . $i['nombre'] . ' ' . $i['serial'] . ' ' . $i['marca'] . ' ' . $i['modelo'])) ?>" <?= $m ? 'style="background:var(--acento-suave)"' : '' ?>>
        <td><input type="checkbox" name="item[]" value="<?= $i['id'] ?>" <?= $m ? 'checked' : '' ?>></td>
        <td class="mono"><?= e($i['etiqueta']) ?></td>
        <td><b><?= e($i['nombre']) ?></b><small><?= e(trim($i['marca'] . ' ' . $i['modelo'])) ?></small></td>
        <td class="mono"><?= e($i['serial']) ?></td>
        <td><?= $historica ? ($i['responsable'] ? e($i['responsable']) : chip_estado($i['estado'])) : e($i['categoria']) ?></td>
        <td><input name="condicion[<?= $i['id'] ?>]" placeholder="Buen estado" style="width:150px" value="<?= e($_POST['condicion'][$i['id']] ?? '') ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>

  <div class="tarjeta">
    <h2>Otros elementos sin etiqueta</h2>
    <p class="tenue" style="margin-top:-6px">Dotacion, llaves, accesorios o cosas que solo van en el acta y no se controlan en el inventario</p>
    <div id="libres"></div>
    <button type="button" class="btn btn-borde btn-sm" id="agregar-libre">+ Agregar elemento</button>
    <?php if ($tipo === 'entrega'): ?><span id="rapidos" style="margin-left:8px">
      <?php foreach (['Adaptador de red', 'Mouse', 'Teclado', 'Cargador'] as $r): ?><button type="button" class="btn btn-borde btn-sm" data-libre="<?= e($r) ?>">+ <?= e($r) ?></button> <?php endforeach; ?>
    </span><?php endif; ?>
  </div>

  <p><button class="btn btn-primario"><?= $historica ? 'Guardar acta en papel' : 'Crear acta y pasar a firma' ?></button>
    <a class="btn btn-borde" href="actas.php">Cancelar</a></p>
</form>

<template id="fila-libre">
  <div class="filtros" style="margin-bottom:8px">
    <input name="libre_desc[]" placeholder="Descripcion" style="flex:2;min-width:200px">
    <input name="libre_serial[]" placeholder="Serial / ref" style="flex:1;min-width:120px">
    <input name="libre_cant[]" type="number" min="1" value="1" style="width:80px" title="Cantidad">
    <input name="libre_cond[]" placeholder="Condicion" style="flex:1;min-width:120px">
    <button type="button" class="btn btn-borde btn-sm" onclick="this.parentNode.remove()">&times;</button>
  </div>
</template>
<script>
(function () {
  var f = document.getElementById('filtro-items');
  var pr = document.querySelector('input[name=prestamo]');
  var aviso = document.getElementById('aviso-prestamo');
  function filtrar() {
    var t = f ? f.value.toLowerCase().trim() : '';
    var soloPrest = pr && pr.checked;
    if (aviso) aviso.style.display = soloPrest ? '' : 'none';
    document.querySelectorAll('#tabla-items tbody tr').forEach(function (tr) {
      var cb = tr.querySelector('input[type=checkbox]');
      // en prestamo lo que no se presta se oculta y se desmarca
      if (soloPrest && tr.dataset.prestable !== '1') { cb.checked = false; tr.style.background = ''; tr.style.display = 'none'; return; }
      tr.style.display = !t || tr.dataset.texto.indexOf(t) >= 0 || cb.checked ? '' : 'none';
    });
  }
  if (f) f.addEventListener('input', filtrar);
  if (pr) pr.addEventListener('change', filtrar);
  filtrar();
  document.querySelectorAll('#tabla-items input[type=checkbox]').forEach(function (cb) {
    cb.addEventListener('change', function () { cb.closest('tr').style.background = cb.checked ? 'var(--acento-suave)' : ''; });
  });
  var tpl = document.getElementById('fila-libre'), cont = document.getElementById('libres');
  document.getElementById('agregar-libre').addEventListener('click', function () {
    cont.appendChild(tpl.content.cloneNode(true));
    cont.lastElementChild.querySelector('input').focus();
  });
  document.querySelectorAll('[data-libre]').forEach(function (b) {
    b.addEventListener('click', function () {
      // si ya hay uno disponible en el inventario (ej. mouse de un combo separado) se marca ese, asi se descuenta
      var palabra = b.dataset.libre.toLowerCase().split(' ')[0];
      var fila = Array.prototype.find.call(document.querySelectorAll('#tabla-items tbody tr'), function (tr) {
        var cb = tr.querySelector('input[type=checkbox]');
        return cb && !cb.checked && tr.style.display !== 'none' && (' ' + tr.dataset.texto).indexOf(' ' + palabra) !== -1;
      });
      if (fila) { fila.querySelector('input[type=checkbox]').checked = true; fila.style.background = 'rgba(245,196,0,.15)'; fila.scrollIntoView({ block: 'nearest' }); return; }
      cont.appendChild(tpl.content.cloneNode(true));
      cont.lastElementChild.querySelector('input').value = b.dataset.libre;
      cont.lastElementChild.querySelectorAll('input')[1].focus();
    });
  });
})();
</script>
<?php require __DIR__ . '/inc/footer.php';
