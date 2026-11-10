<?php
require __DIR__ . '/config.php';
requiere_modulo('items');

$q = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? '';
$cat = $_GET['cat'] ?? '';
$sede = $_GET['sede'] ?? '';
$colab = $_GET['colab'] ?? '';

$where = ['1=1'];
$par = [];
if ($q !== '') {
    $where[] = '(i.etiqueta LIKE ? OR i.nombre LIKE ? OR i.serial LIKE ? OR i.marca LIKE ? OR i.modelo LIKE ? OR c.nombre LIKE ? OR c.cedula LIKE ? OR i.ip LIKE ? OR i.ubicacion LIKE ? OR i.extension LIKE ?)';
    array_push($par, ...array_fill(0, 10, "%$q%"));
}
if ($estado === 'asignado') { $where[] = "i.estado IN ('asignado', 'instalado')"; }
elseif ($estado !== '') { $where[] = 'i.estado = ?'; $par[] = $estado; }
else { $where[] = "i.estado <> 'baja'"; }
if ($cat !== '') { $where[] = 'i.categoria_id = ?'; $par[] = $cat; }
if ($sede !== '') { $where[] = 'i.sede_id = ?'; $par[] = $sede; }
if ($colab !== '') { $where[] = '(i.colaborador_id = ? OR i.prestado_a = ?)'; $par[] = $colab; $par[] = $colab; }

$sql = 'SELECT i.*, cat.nombre categoria, s.nombre sede, IF(i.estado = \'prestado\', CONCAT(\'Sistemas (prestado a \', COALESCE(pr.nombre, \'—\'), \')\'), IF(i.estado IN (\'asignado\', \'instalado\') AND i.colaborador_id IS NULL, COALESCE(NULLIF(i.ubicacion, \'\'), \'Su ubicación\'), IF(i.estado = \'cedido\', CONCAT(\'Cedido a \', COALESCE(NULLIF(i.ubicacion, \'\'), \'otra empresa\')), c.nombre))) responsable
    FROM items i
    LEFT JOIN categorias cat ON cat.id = i.categoria_id
    LEFT JOIN sedes s ON s.id = i.sede_id
    LEFT JOIN colaboradores c ON c.id = i.colaborador_id LEFT JOIN colaboradores pr ON pr.id = i.prestado_a
    WHERE ' . implode(' AND ', $where) . ' ORDER BY i.etiqueta';
$st = db()->prepare($sql);
$st->execute($par);
$items = $st->fetchAll();

// exportar lo filtrado a csv para abrir en excel
if (isset($_GET['exportar'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventario_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['etiqueta', 'nombre', 'categoria', 'marca', 'modelo', 'serial', 'sede', 'ubicacion', 'ip', 'extension', 'estado', 'responsable', 'cantidad', 'especificaciones', 'valor', 'fecha_compra', 'notas'], ';');
    foreach ($items as $i) {
        fputcsv($out, [$i['etiqueta'], $i['nombre'], $i['categoria'], $i['marca'], $i['modelo'], $i['serial'], $i['sede'], $i['ubicacion'], $i['ip'], $i['extension'],
            $i['estado'], $i['responsable'], $i['cantidad'], $i['especificaciones'], $i['valor'], $i['fecha_compra'], $i['notas']], ';');
    }
    exit;
}

$conteo_cat = db()->query("SELECT categoria_id, COUNT(*) n FROM items WHERE estado <> 'baja' GROUP BY categoria_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$cats = categorias(false);
$colab_nombre = '';
if ($colab !== '') {
    $s2 = db()->prepare('SELECT nombre FROM colaboradores WHERE id = ?');
    $s2->execute([$colab]);
    $colab_nombre = $s2->fetchColumn();
}

function url_filtro($cambios) {
    $p = array_merge($_GET, $cambios);
    return 'items.php?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}

$titulo = 'Inventario';
$menu = 'items';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1>Inventario</h1><small><?= count($items) ?> items<?= $colab_nombre ? ' a nombre de ' . e($colab_nombre) : '' ?></small></div>
  <div class="acciones">
    <?= boton_cambios(cambios_de_varios('items', array_column($items, 'id'), 5), array_column($items, 'etiqueta', 'id')) ?>
    <a class="btn btn-borde" href="<?= e(url_filtro(['exportar' => 1])) ?>">Exportar a Excel</a>
    <?php if (es_admin()): ?><a class="btn btn-primario" href="item.php">+ Nuevo item</a><?php endif; ?>
  </div>
</div>

<div class="pildoras">
  <a class="pildora <?= $cat === '' ? 'activa' : '' ?>" href="<?= e(url_filtro(['cat' => ''])) ?>">Todas<b><?= array_sum($conteo_cat) ?></b></a>
  <?php foreach ($cats as $c): if (empty($conteo_cat[$c['id']])) continue; ?>
    <a class="pildora <?= (string)$cat === (string)$c['id'] ? 'activa' : '' ?>" href="<?= e(url_filtro(['cat' => $c['id']])) ?>"><?= e($c['nombre']) ?><b><?= $conteo_cat[$c['id']] ?></b></a>
  <?php endforeach; ?>
</div>

<form class="filtros">
  <input type="search" name="q" placeholder="Etiqueta, serial, nombre, marca, modelo o persona" value="<?= e($q) ?>">
  <select name="estado" onchange="this.form.submit()">
    <option value="">Todos los estados (sin bajas)</option>
    <?php foreach (estados_item() as $k => $v): ?><option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
  </select>
  <select name="cat" onchange="this.form.submit()"><?= opciones($cats, $cat, 'nombre', 'Todas las categorias') ?></select>
  <select name="sede" onchange="this.form.submit()"><?= opciones(sedes(false), $sede, 'nombre', 'Todas las bodegas') ?></select>
  <?php if ($colab !== ''): ?><input type="hidden" name="colab" value="<?= e($colab) ?>"><?php endif; ?>
  <button class="btn btn-borde">Buscar</button>
  <?php if ($q || $estado || $cat || $sede || $colab): ?><a class="btn btn-borde" href="items.php">Limpiar</a><?php endif; ?>
</form>

<div class="tabla-envoltura">
<table class="tabla">
  <thead><tr><th>Etiqueta</th><th>Item</th><th>Serial / IP</th><th>Estado</th><th>Responsable</th><th>Categoria</th><th>Bodega</th></tr></thead>
  <tbody>
  <?php if (!$items): ?><tr><td colspan="7" class="vacio">No hay items con esos filtros</td></tr><?php endif; ?>
  <?php foreach ($items as $i): ?>
    <?php // disponibles: color por tipo para distinguir de un vistazo
      $tono = $i['estado'] === 'disponible' ? (['Telefono IP' => 'tel', 'Computador' => 'pc', 'Portatil' => 'port', 'Access point' => 'ap'][$i['categoria']] ?? '') : ''; ?>
    <tr onclick="location='item.php?id=<?= $i['id'] ?>'" style="cursor:pointer"<?= $tono ? ' class="disp-' . $tono . '"' : '' ?>>
      <td class="mono"><a href="item.php?id=<?= $i['id'] ?>"><?= e($i['etiqueta']) ?></a></td>
      <td><b><?= e($i['nombre']) ?></b><small><?= e(trim($i['marca'] . ' ' . $i['modelo'])) ?><?= $i['cantidad'] > 1 ? ' · x' . $i['cantidad'] : '' ?></small></td>
      <td class="mono"><?= e($i['serial'] ?: '—') ?><?= $i['ip'] ? '<small>' . e($i['ip']) . '</small>' : '' ?></td>
      <td><?= chip_estado($i['estado']) ?><?= chip_condicion($i['condicion'] ?? null, $i['id']) ?></td>
      <?php $libre = in_array($i['estado'], ['disponible', 'danado'], true); ?>
      <td><?= $i['responsable'] ? e($i['responsable']) : ($libre ? 'Sistemas' : ($i['ubicacion'] ? '<span class="tenue">' . e($i['ubicacion']) . '</span>' : '<span class="tenue">—</span>')) ?>
        <?php if (in_array($i['estado'], ['disponible', 'danado'], true) && !empty($i['ultimo_dueno'])): ?><small class="tenue">antes: <?= e($i['ultimo_dueno']) ?></small><?php endif; ?></td>
      <td><span class="chip <?= $tono ? 'chip-tipo-' . $tono : 'chip-gris' ?>"><?= e($i['categoria'] ?: 'Sin categoria') ?></span></td>
      <td><?= e($i['sede'] ?: '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php require __DIR__ . '/inc/footer.php';
