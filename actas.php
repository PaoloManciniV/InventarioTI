<?php
require __DIR__ . '/config.php';
requiere_modulo('actas');

$q = trim($_GET['q'] ?? '');
$tipo = $_GET['tipo'] ?? '';
$estado = $_GET['estado'] ?? '';
$origen = $_GET['origen'] ?? '';
$anio = $_GET['anio'] ?? '';

$where = ['1=1'];
$par = [];
if ($q !== '') {
    $where[] = '(a.consecutivo LIKE ? OR c.nombre LIKE ? OR c.cedula LIKE ? OR EXISTS (SELECT 1 FROM acta_items ai WHERE ai.acta_id = a.id AND (ai.etiqueta LIKE ? OR ai.serial LIKE ? OR ai.descripcion LIKE ?)))';
    array_push($par, ...array_fill(0, 6, "%$q%"));
}
if ($tipo !== '') { $where[] = 'a.tipo = ?'; $par[] = $tipo; }
if ($estado !== '') { $where[] = 'a.estado = ?'; $par[] = $estado; }
if ($origen !== '') { $where[] = 'a.historica = ?'; $par[] = $origen === 'papel' ? 1 : 0; }
if ($anio !== '') { $where[] = 'YEAR(a.fecha) = ?'; $par[] = (int)$anio; }

$st = db()->prepare('SELECT a.*, c.nombre colaborador, (SELECT COUNT(*) FROM acta_items ai WHERE ai.acta_id = a.id) n
    FROM actas a LEFT JOIN colaboradores c ON c.id = a.colaborador_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY a.fecha DESC, a.id DESC LIMIT 500');
$st->execute($par);
$actas = $st->fetchAll();
$anios = db()->query('SELECT DISTINCT YEAR(fecha) FROM actas ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN);

$titulo = 'Actas';
$menu = 'actas';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1>Actas</h1><small><?= count($actas) ?> actas</small></div>
  <?php if (es_admin()): ?><div class="acciones">
    <a class="btn btn-borde" href="acta_nueva.php?historica=1">Subir acta en papel</a>
    <a class="btn btn-borde" href="acta_nueva.php?tipo=devolucion">Devolucion</a>
    <a class="btn btn-primario" href="acta_nueva.php?tipo=entrega">+ Entrega</a></div><?php endif; ?>
</div>
<form class="filtros">
  <input type="search" name="q" placeholder="Consecutivo, persona, cedula, etiqueta o serial" value="<?= e($q) ?>">
  <select name="tipo" onchange="this.form.submit()"><option value="">Entregas y devoluciones</option>
    <option value="entrega" <?= $tipo === 'entrega' ? 'selected' : '' ?>>Entregas</option><option value="devolucion" <?= $tipo === 'devolucion' ? 'selected' : '' ?>>Devoluciones</option></select>
  <select name="estado" onchange="this.form.submit()"><option value="">Todos los estados</option>
    <?php foreach (['pendiente' => 'Pendientes de firma', 'firmada' => 'Firmadas', 'anulada' => 'Anuladas'] as $k => $v): ?>
      <option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
  <select name="origen" onchange="this.form.submit()"><option value="">Digitales y papel</option>
    <option value="digital" <?= $origen === 'digital' ? 'selected' : '' ?>>Digitales</option><option value="papel" <?= $origen === 'papel' ? 'selected' : '' ?>>En papel</option></select>
  <select name="anio" onchange="this.form.submit()"><option value="">Todos los anos</option>
    <?php foreach ($anios as $y): ?><option <?= (string)$anio === (string)$y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select>
  <button class="btn btn-borde">Buscar</button>
</form>
<div class="tabla-envoltura"><table class="tabla">
  <thead><tr><th>Consecutivo</th><th>Fecha</th><th>Tipo</th><th>Colaborador</th><th>Items</th><th>Estado</th><th>Firma</th></tr></thead><tbody>
  <?php if (!$actas): ?><tr><td colspan="7" class="vacio">No hay actas</td></tr><?php endif; ?>
  <?php foreach ($actas as $a): ?>
    <tr onclick="location='acta.php?id=<?= $a['id'] ?>'" style="cursor:pointer">
      <td class="mono"><a href="acta.php?id=<?= $a['id'] ?>"><?= e($a['consecutivo']) ?></a></td>
      <td><?= fecha_corta($a['fecha']) ?></td>
      <td><?= $a['tipo'] === 'entrega' ? (!empty($a['prestamo']) ? 'Préstamo' : 'Entrega') : 'Devolucion' ?></td>
      <td><?= e($a['colaborador']) ?></td>
      <td><?= $a['n'] ?></td>
      <td><span class="chip chip-<?= e($a['estado']) ?>"><?= e(ucfirst($a['estado'])) ?></span></td>
      <td><?= ['pantalla' => 'En pantalla', 'link' => 'Por enlace', 'papel' => 'Papel'][$a['firmado_modo']] ?? '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php require __DIR__ . '/inc/footer.php';
