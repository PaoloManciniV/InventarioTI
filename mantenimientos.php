<?php
// cronograma anual de mantenimiento preventivo formato agr-f-074
require __DIR__ . '/config.php';
requiere_modulo('mant');

$anio = (int)($_GET['anio'] ?? date('Y'));
$cat = $_GET['cat'] ?? '';
$ver = $_GET['ver'] ?? '';

$items = items_con_mant($cat ?: null);

// mantenimientos del ano por item y mes
$st = db()->prepare('SELECT id, item_id, MONTH(fecha) mes, tipo, estado FROM mantenimientos WHERE YEAR(fecha) = ?');
$st->execute([$anio]);
$hechos = [];
foreach ($st->fetchAll() as $m) $hechos[$m['item_id']][(int)$m['mes']][] = $m;

$hoy = date('Y-m-d');
$mes_actual = (int)date('n');
$filas = [];
$tot = ['vencidos' => 0, 'mes' => 0, 'hechos' => 0];
foreach ($items as $i) {
    $plan = meses_plan($i, $anio);
    $prox = proximo_mant($i);
    $vencido = $i['estado'] !== 'danado' && $prox <= $hoy;
    if ($vencido) $tot['vencidos']++;
    // los dañados no se cuentan: no se les va a hacer el preventivo
    if ($i['estado'] !== 'danado' && $anio === (int)date('Y') && in_array($mes_actual, $plan, true) && empty($hechos[$i['id']][$mes_actual])) $tot['mes']++;
    $tot['hechos'] += count(array_merge(...array_values($hechos[$i['id']] ?? [[]])));
    if ($ver === 'vencidos' && !$vencido) continue;
    $filas[] = ['i' => $i, 'plan' => $plan, 'prox' => $prox, 'vencido' => $vencido];
}

$cats = db()->query('SELECT * FROM categorias WHERE COALESCE(mant_meses, 0) > 0 OR id IN (SELECT categoria_id FROM items WHERE mant_meses > 0) ORDER BY nombre')->fetchAll();
$meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

$titulo = 'Mantenimientos';
$menu = 'mant';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1>Cronograma de mantenimiento <?= $anio ?></h1><small>AGR-F-074 · P programado · R realizado · V vencido</small></div>
  <div class="acciones"><button class="btn btn-borde" onclick="window.print()">Imprimir</button></div>
</div>

<div class="kpis">
  <a class="kpi" href="?anio=<?= $anio ?>&ver=vencidos"><small>Vencidos hoy</small><b style="color:var(--rojo)"><?= $tot['vencidos'] ?></b></a>
  <div class="kpi"><small>Programados este mes</small><b><?= $tot['mes'] ?></b></div>
  <div class="kpi"><small>Realizados en <?= $anio ?></small><b><?= $tot['hechos'] ?></b></div>
  <div class="kpi"><small>Equipos en el plan</small><b><?= count($items) ?></b></div>
</div>

<form class="filtros no-imprimir">
  <select name="anio" onchange="this.form.submit()"><?php for ($y = (int)date('Y') + 1; $y >= 2021; $y--): ?><option <?= $y === $anio ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
  <select name="cat" onchange="this.form.submit()"><?= opciones($cats, $cat, 'nombre', 'Todas las categorias') ?></select>
  <select name="ver" onchange="this.form.submit()"><option value="">Todos</option><option value="vencidos" <?= $ver === 'vencidos' ? 'selected' : '' ?>>Solo vencidos</option></select>
</form>

<div class="tabla-envoltura"><table class="tabla crono">
  <thead><tr><th>Equipo</th><th>Responsable / ubicacion</th><th>Cada</th>
    <?php foreach ($meses as $n => $mm): ?><th class="<?= $anio === (int)date('Y') && $n + 1 === $mes_actual ? 'mes-actual' : '' ?>"><?= $mm ?></th><?php endforeach; ?>
    <th>Proximo</th><?php if (es_admin()): ?><th class="no-imprimir"></th><?php endif; ?></tr></thead>
  <tbody>
  <?php if (!$filas): ?><tr><td colspan="17" class="vacio">Nada para mostrar</td></tr><?php endif; ?>
  <?php foreach ($filas as $f): $i = $f['i']; ?>
    <tr>
      <td class="mono"><a href="item.php?id=<?= $i['id'] ?>"><?= e($i['etiqueta']) ?></a><small><?= e($i['categoria']) ?></small></td>
      <td><?= e($i['responsable'] ?: ($i['ubicacion'] ?: '—')) ?><?= $i['estado'] === 'danado' ? ' <span class="chip chip-danado">Dañado</span>' : '' ?></td>
      <td><?= frecuencia_item($i) ?>m</td>
      <?php for ($m = 1; $m <= 12; $m++):
        $h = $hechos[$i['id']][$m] ?? [];
        $pasado = $anio < (int)date('Y') || ($anio === (int)date('Y') && $m < $mes_actual);
        $cls = $anio === (int)date('Y') && $m === $mes_actual ? 'mes-actual' : ''; ?>
        <td class="<?= $cls ?>">
          <?php if ($h): ?><a class="r" href="mantenimiento.php?id=<?= $h[0]['id'] ?>" title="<?= e(ucfirst($h[0]['tipo'])) ?>">R</a>
          <?php elseif (in_array($m, $f['plan'], true)): ?><span class="<?= $pasado && $f['vencido'] ? 'v' : 'p' ?>"><?= $pasado && $f['vencido'] ? 'V' : 'P' ?></span>
          <?php endif; ?>
        </td>
      <?php endfor; ?>
      <td><?= $f['vencido'] ? '<span class="chip chip-vencido">Vencido</span>' : fecha_corta($f['prox']) ?></td>
      <?php if (es_admin()): ?><td class="no-imprimir"><a class="btn btn-borde btn-sm" href="mantenimiento.php?item=<?= $i['id'] ?>">Registrar</a></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="tenue">Solo llevan mantenimiento las categorias con frecuencia (Configuracion > Categorias). Si el equipo tiene fecha programada se usa esa; al registrar el preventivo vuelve a contar cada N meses desde ahi. Los dañados no cuentan como vencidos.</p>
<?php require __DIR__ . '/inc/footer.php';
