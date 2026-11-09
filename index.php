<?php
require __DIR__ . '/config.php';
requiere_login();
if (!puede('inicio')) ir(primer_modulo() ?? 'logout.php');

$pdo = db();
$por_estado = $pdo->query('SELECT estado, COUNT(*) n FROM items GROUP BY estado')->fetchAll(PDO::FETCH_KEY_PAIR);
$total = array_sum($por_estado);
$por_cat = $pdo->query("SELECT COALESCE(c.nombre,'Sin categoria') nombre, COUNT(*) n,
    SUM(i.estado IN ('asignado', 'instalado', 'prestado')) asignados FROM items i LEFT JOIN categorias c ON c.id = i.categoria_id
    WHERE i.estado NOT IN ('baja', 'cedido') GROUP BY nombre ORDER BY n DESC")->fetchAll();
$pendientes = $pdo->query("SELECT a.id, a.consecutivo, a.tipo, a.fecha, c.nombre FROM actas a
    LEFT JOIN colaboradores c ON c.id = a.colaborador_id WHERE a.estado = 'pendiente' ORDER BY a.id DESC LIMIT 10")->fetchAll();
$movs = $pdo->query('SELECT m.*, i.etiqueta, i.nombre item, u.nombre usuario FROM movimientos m
    LEFT JOIN items i ON i.id = m.item_id LEFT JOIN usuarios u ON u.id = m.usuario_id
    ORDER BY m.id DESC LIMIT 12')->fetchAll();
$colabs = (int)$pdo->query('SELECT COUNT(*) FROM colaboradores WHERE activo = 1')->fetchColumn();

$titulo = 'Vista general';
$menu = 'inicio';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera"><div><h1>Vista general</h1><small><?= $total ?> items registrados</small></div></div>

<div class="kpis">
  <a class="kpi" href="items.php"><small>Total activos</small><b><?= $total - ($por_estado['baja'] ?? 0) - ($por_estado['cedido'] ?? 0) ?></b></a>
  <a class="kpi" href="items.php?estado=asignado"><small>Asignados</small><b><?= ($por_estado['asignado'] ?? 0) + ($por_estado['instalado'] ?? 0) ?></b></a>
  <a class="kpi" href="items.php?estado=disponible"><small>Disponibles (libres)</small><b><?= $por_estado['disponible'] ?? 0 ?></b></a>
  <a class="kpi" href="items.php?estado=mantenimiento"><small>En mantenimiento</small><b><?= $por_estado['mantenimiento'] ?? 0 ?></b></a>
  <a class="kpi" href="actas.php?estado=pendiente"><small>Actas sin firmar</small><b><?= count($pendientes) ?></b></a>
  <a class="kpi" href="colaboradores.php"><small>Colaboradores activos</small><b><?= $colabs ?></b></a>
</div>

<div class="dos-col">
  <div>
    <div class="tarjeta">
      <h2>Por categoria</h2>
      <?php if (!$por_cat): ?><div class="vacio">Aun no hay items. <a href="importar.php">Importa el Excel</a> o crea uno nuevo.</div><?php else: ?>
      <table class="tabla"><thead><tr><th>Categoria</th><th>Total</th><th>Asignados</th><th></th></tr></thead><tbody>
      <?php foreach ($por_cat as $c): $pct = $c['n'] ? round($c['asignados'] * 100 / $c['n']) : 0; ?>
        <tr><td><?= e($c['nombre']) ?></td><td><?= $c['n'] ?></td><td><?= $c['asignados'] ?></td>
          <td style="width:40%"><div style="background:#222;border-radius:2px;height:6px"><div style="width:<?= $pct ?>%;background:var(--cat);height:6px;border-radius:2px"></div></div></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
    <div class="tarjeta">
      <h2>Ultimos movimientos</h2>
      <?php if (!$movs): ?><div class="vacio">Sin movimientos todavia</div><?php else: ?>
      <ul class="historial">
      <?php foreach ($movs as $m): ?>
        <li><time><?= fecha_hora($m['fecha']) ?></time><div><a href="item.php?id=<?= $m['item_id'] ?>" class="mono"><?= e($m['etiqueta']) ?></a> <?= e($m['detalle']) ?>
          <small><?= e($m['usuario']) ?></small></div></li>
      <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
  <div class="tarjeta">
    <h2>Pendientes de firma</h2>
    <?php if (!$pendientes): ?><div class="vacio">Todo firmado</div><?php endif; ?>
    <ul class="historial">
    <?php foreach ($pendientes as $p): ?>
      <li><div><a href="acta.php?id=<?= $p['id'] ?>"><b><?= e($p['consecutivo']) ?></b></a> · <?= $p['tipo'] === 'entrega' ? 'Entrega' : 'Devolucion' ?>
        <small><?= e($p['nombre']) ?> · <?= fecha_corta($p['fecha']) ?></small></div></li>
    <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
