<?php
require __DIR__ . '/config.php';
requiere_modulo('colaboradores');

$q = trim($_GET['q'] ?? '');
$sede = $_GET['sede'] ?? '';
$est = $_GET['estado'] ?? '1';
$area = $_GET['area'] ?? '';

$where = ['1=1'];
$par = [];
if ($q !== '') { $where[] = '(c.nombre LIKE ? OR c.cedula LIKE ? OR c.email LIKE ? OR c.cargo LIKE ? OR c.area LIKE ?)'; array_push($par, ...array_fill(0, 5, "%$q%")); }
if ($sede !== '') { $where[] = 'c.sede_id = ?'; $par[] = $sede; }
if ($est !== '') { $where[] = 'c.activo = ?'; $par[] = (int)$est; }
if ($area !== '') {
    // area o area con grupo ej 3 o 3-RED
    [$aid, $agr] = array_pad(explode('-', $area, 2), 2, null);
    $where[] = 'c.area_id = ?'; $par[] = (int)$aid;
    if ($agr) { $where[] = 'c.grupo = ?'; $par[] = $agr; }
}

$st = db()->prepare('SELECT c.*, s.nombre sede,
    (SELECT COUNT(*) FROM items i WHERE i.colaborador_id = c.id) n_items,
    (SELECT COUNT(*) FROM actas a WHERE a.colaborador_id = c.id AND a.estado = \'pendiente\') n_pend
    FROM colaboradores c LEFT JOIN sedes s ON s.id = c.sede_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY c.activo DESC, c.nombre');
$st->execute($par);
$lista = $st->fetchAll();
// resumen del puesto de cada persona pc y extension
$puestos = [];
foreach (db()->query("SELECT i.colaborador_id, i.formato, i.extension, i.etiqueta, cat.nombre categoria FROM items i
    JOIN categorias cat ON cat.id = i.categoria_id WHERE i.colaborador_id IS NOT NULL AND i.estado <> 'baja'
    AND cat.nombre IN ('Portatil', 'Computador', 'Telefono IP')")->fetchAll() as $p) {
    $puestos[$p['colaborador_id']][] = $p['categoria'] === 'Telefono IP' ? 'Ext ' . $p['extension'] : tipo_pc($p);
}

$titulo = 'Colaboradores';
$menu = 'colaboradores';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1>Colaboradores</h1><small><?= count($lista) ?> personas</small></div>
  <div class="acciones"><?= boton_cambios(cambios_de_varios('colaboradores', array_column($lista, 'id'), 5), array_column($lista, 'nombre', 'id'), 'colaborador.php?id=') ?>
    <?php if (es_admin()): ?><a class="btn btn-primario" href="colaborador.php">+ Nueva ficha</a><?php endif; ?></div>
</div>
<form class="filtros">
  <input type="search" name="q" placeholder="Nombre, cedula, correo, cargo o area" value="<?= e($q) ?>">
  <select name="sede" onchange="this.form.submit()"><?= opciones(sedes(false), $sede, 'nombre', 'Todas las bodegas') ?></select>
  <select name="area" onchange="this.form.submit()"><option value="">Todas las areas</option>
    <?php foreach (areas(false) as $ar): ?>
      <option value="<?= $ar['id'] ?>" <?= $area === (string)$ar['id'] ? 'selected' : '' ?>><?= e($ar['nombre']) ?></option>
      <?php foreach (grupos_area($ar) as $g): $v = $ar['id'] . '-' . $g; ?>
        <option value="<?= e($v) ?>" <?= $area === $v ? 'selected' : '' ?>>&nbsp;&nbsp;<?= e($ar['nombre'] . ' ' . $g) ?></option>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </select>
  <select name="estado" onchange="this.form.submit()">
    <option value="1" <?= $est === '1' ? 'selected' : '' ?>>Activos</option>
    <option value="0" <?= $est === '0' ? 'selected' : '' ?>>Inactivos</option>
    <option value="" <?= $est === '' ? 'selected' : '' ?>>Todos</option>
  </select>
  <button class="btn btn-borde">Buscar</button>
</form>

<?php if (!$lista): ?><div class="tarjeta vacio">No hay colaboradores con esos filtros</div><?php endif; ?>
<div class="tarjetas">
<?php foreach ($lista as $c): ?>
  <div class="persona <?= $c['activo'] ? '' : 'inactiva' ?>">
    <div class="persona-cab">
      <span class="avatar"><?= e(iniciales($c['nombre'])) ?></span>
      <div><b><?= e($c['nombre']) ?></b><small style="display:block"><?= e($c['cargo'] ?: '—') ?></small></div>
      <span class="chip <?= $c['activo'] ? 'chip-disponible' : 'chip-baja' ?>"><?= $c['activo'] ? 'Activo' : 'INACTIVO' ?></span>
    </div>
    <small><?= e(implode(' · ', array_filter([$c['sede'], $c['area'], $c['cedula'] ? 'CC ' . $c['cedula'] : null]))) ?></small>
    <?php if ($c['email']): ?><small><?= e($c['email']) ?></small><?php endif; ?>
    <?php if (!empty($puestos[$c['id']])): ?><div class="mini-puesto"><?= e(implode(' · ', array_unique($puestos[$c['id']]))) ?></div><?php endif; ?>
    <div class="linea"><span>Items a su nombre</span><a href="items.php?colab=<?= $c['id'] ?>"><b><?= $c['n_items'] ?></b></a></div>
    <?php if ($c['n_pend']): ?><span class="chip chip-pendiente"><?= $c['n_pend'] ?> acta(s) sin firmar</span><?php endif; ?>
    <a class="btn btn-borde btn-sm btn-bloque" href="colaborador.php?id=<?= $c['id'] ?>">Ver ficha</a>
  </div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/inc/footer.php';
