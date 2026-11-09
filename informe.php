<?php
// informe anual para contabilidad: cuantos equipos hay por tipo y en que estado
require __DIR__ . '/config.php';
requiere_modulo('informe');

$anio = (int)($_GET['anio'] ?? date('Y'));
if ($anio < 2020 || $anio > (int)date('Y')) $anio = (int)date('Y');

$items = db()->query("SELECT i.*, cat.nombre categoria, s.nombre bodega, c.nombre responsable, COALESCE(ar.nombre, c.area) area_resp,
    pr.nombre prestado_nombre
    FROM items i LEFT JOIN categorias cat ON cat.id = i.categoria_id LEFT JOIN sedes s ON s.id = i.sede_id
    LEFT JOIN colaboradores c ON c.id = i.colaborador_id LEFT JOIN areas ar ON ar.id = c.area_id
    LEFT JOIN colaboradores pr ON pr.id = i.prestado_a
    ORDER BY i.etiqueta")->fetchAll();

// bajas y extravios registrados en el ano
$st = db()->prepare("SELECT m.fecha, m.tipo, m.detalle, i.etiqueta, i.nombre, i.marca, i.modelo, i.serial, i.valor, cat.nombre categoria, i.formato
    FROM movimientos m JOIN items i ON i.id = m.item_id LEFT JOIN categorias cat ON cat.id = i.categoria_id
    WHERE m.tipo IN ('baja', 'extraviado') AND YEAR(m.fecha) = ? ORDER BY m.fecha");
$st->execute([$anio]);
$bajas = $st->fetchAll();

$orden = ['Portátil', 'PC de mesa', 'All in one', 'Mini PC', 'Tablet', 'Celular', 'Telefono IP', 'Impresora', 'Monitor', 'Servidor', 'Access point', 'Red', 'Video beam', 'Periferico'];
$nombres = ['Telefono IP' => 'Teléfono IP', 'Periferico' => 'Periféricos', 'Red' => 'Equipos de red'];
$tipo_de = fn($i) => in_array($i['categoria'], ['Computador', 'Portatil'], true) ? tipo_pc($i) : ($i['categoria'] ?: 'Sin categoría');

function situacion($i) {
    if (in_array($i['estado'], ['baja', 'cedido'], true)) return 'baja';
    // los servidores virtuales no son equipos fisicos
    if (in_array($i['categoria'], ['Servidor virtual', 'Extensión sin teléfono'], true)) return 'baja';
    if ($i['estado'] === 'extraviado') return 'extraviado';
    if (in_array($i['estado'], ['mantenimiento', 'danado'], true)) return 'reparacion';
    if (in_array($i['estado'], ['asignado', 'prestado', 'instalado'], true)) return 'uso';
    // disponible: si esta instalado en un puesto o es infraestructura se esta usando
    $ub = trim((string)$i['ubicacion']);
    if (in_array($i['categoria'], categorias_fijas(), true) || ($ub !== '' && strcasecmp($ub, 'Sistemas') !== 0)) return 'uso';
    return 'stock';
}

$res = [];
$por_area = [];
$valor_total = 0;
$compras = 0;
foreach ($items as &$i) {
    $i['tipo'] = $tipo_de($i);
    $i['situacion'] = situacion($i);
    if ($i['situacion'] === 'baja') continue;
    $t = $i['tipo'];
    $res[$t] = $res[$t] ?? ['uso' => 0, 'stock' => 0, 'reparacion' => 0, 'extraviado' => 0, 'total' => 0, 'valor' => 0];
    $res[$t][$i['situacion']]++;
    if ($i['situacion'] !== 'extraviado') $res[$t]['total']++;
    $res[$t]['valor'] += (float)$i['valor'];
    if ($i['situacion'] !== 'extraviado') $valor_total += (float)$i['valor'];
    if ($i['fecha_compra'] && (int)substr($i['fecha_compra'], 0, 4) === $anio) $compras++;
    $area = $i['estado'] === 'prestado' ? 'Sistemas (préstamo)' : ($i['area_resp'] ?: ($i['colaborador_id'] ? 'Sin área' : 'Sistemas / áreas comunes'));
    $por_area[$area][$t] = ($por_area[$area][$t] ?? 0) + 1;
}
unset($i);
uksort($res, function ($a, $b) use ($orden) {
    $x = array_search($a, $orden, true); $y = array_search($b, $orden, true);
    return [$x === false ? 99 : $x, $a] <=> [$y === false ? 99 : $y, $b];
});
ksort($por_area);
$tipos = array_keys($res);
$tot = ['uso' => 0, 'stock' => 0, 'reparacion' => 0, 'extraviado' => 0, 'total' => 0, 'valor' => 0];
foreach ($res as $r) foreach ($tot as $k => $_) $tot[$k] += $r[$k];
$nom = fn($t) => $nombres[$t] ?? $t;
$etiq_sit = ['uso' => 'En uso', 'stock' => 'Disponible en sistemas', 'reparacion' => 'En reparación / dañado', 'extraviado' => 'Extraviado', 'baja' => 'De baja'];
$corte = $anio === (int)date('Y') ? date('d/m/Y') : '31/12/' . $anio . ' (estado de hoy)';

// excel para contabilidad
if (($_GET['formato'] ?? '') === 'xlsx') {
    require __DIR__ . '/inc/xlsx.php';
    $resumen = [['INVENTARIO DE EQUIPOS TECNOLÓGICOS · AGRO-COSTA S.A.S.'], ['Año ' . $anio . ' · corte ' . $corte], [],
        ['Tipo de equipo', 'En uso', 'Disponible en sistemas', 'En reparación / dañado', 'Total equipos', 'Extraviados', 'Valor registrado']];
    foreach ($res as $t => $r) $resumen[] = [$nom($t), $r['uso'], $r['stock'], $r['reparacion'], $r['total'], $r['extraviado'], $r['valor'] ?: null];
    $resumen[] = ['TOTAL', $tot['uso'], $tot['stock'], $tot['reparacion'], $tot['total'], $tot['extraviado'], $tot['valor'] ?: null];
    $resumen[] = [];
    $resumen[] = ['Dados de baja en ' . $anio, count(array_filter($bajas, fn($b) => $b['tipo'] === 'baja'))];
    $resumen[] = ['Extraviados en ' . $anio, count(array_filter($bajas, fn($b) => $b['tipo'] === 'extraviado'))];
    $resumen[] = ['Comprados en ' . $anio . ' (con fecha de compra)', $compras];
    $resumen[] = [];
    $resumen[] = ['Total equipos no incluye extraviados ni dados de baja. Generado ' . date('d/m/Y g:i a') . ' por ' . usuario()['nombre'] . '.'];

    $area_f = [array_merge(['Área'], array_map($nom, $tipos), ['Total'])];
    foreach ($por_area as $a => $cnt) {
        $f = [$a];
        foreach ($tipos as $t) $f[] = $cnt[$t] ?? 0;
        $f[] = array_sum($cnt);
        $area_f[] = $f;
    }

    $det = [['Etiqueta', 'Tipo', 'Nombre', 'Marca', 'Modelo', 'Serial', 'Situación', 'Responsable', 'Área', 'Bodega', 'Ubicación', 'Fecha compra', 'Valor']];
    foreach ($items as $i) {
        if ($i['situacion'] === 'baja') continue;
        $det[] = [$i['etiqueta'], $nom($i['tipo']), $i['nombre'], $i['marca'], $i['modelo'], $i['serial'], $etiq_sit[$i['situacion']],
            $i['estado'] === 'prestado' ? 'Sistemas (prestado a ' . $i['prestado_nombre'] . ')' : $i['responsable'], $i['area_resp'], $i['bodega'], $i['ubicacion'],
            $i['fecha_compra'] ? fecha_corta($i['fecha_compra']) : null, $i['valor'] !== null ? (float)$i['valor'] : null];
    }

    $baj = [['Fecha', 'Movimiento', 'Etiqueta', 'Tipo', 'Nombre', 'Marca', 'Modelo', 'Serial', 'Valor', 'Detalle']];
    foreach ($bajas as $b) $baj[] = [fecha_corta($b['fecha']), $b['tipo'] === 'baja' ? 'Dado de baja' : 'Extraviado', $b['etiqueta'], $nom($tipo_de($b)), $b['nombre'],
        $b['marca'], $b['modelo'], $b['serial'], $b['valor'] !== null ? (float)$b['valor'] : null, $b['detalle']];

    xlsx_enviar('Inventario_TI_AgroCosta_' . $anio . '.xlsx', [
        'Resumen' => ['anchos' => [34, 10, 22, 22, 14, 12, 16], 'filas' => $resumen, 'negrita' => [0, 3, count($resumen) - 7]],
        'Por área' => ['anchos' => array_merge([30], array_fill(0, count($tipos) + 1, 12)), 'filas' => $area_f, 'negrita' => [0]],
        'Detalle' => ['anchos' => [12, 14, 30, 14, 34, 22, 22, 28, 20, 12, 24, 13, 14], 'filas' => $det, 'negrita' => [0]],
        'Bajas ' . $anio => ['anchos' => [12, 14, 12, 14, 30, 14, 30, 22, 14, 40], 'filas' => $baj, 'negrita' => [0]],
    ]);
}

$titulo = 'Informe anual';
$menu = 'informe';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1>Informe anual de equipos</h1><small>Para contabilidad · corte <?= e($corte) ?></small></div>
  <div class="acciones no-imprimir">
    <form><select name="anio" onchange="this.form.submit()"><?php for ($y = (int)date('Y'); $y >= 2022; $y--): ?><option <?= $y === $anio ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></form>
    <button class="btn btn-borde" onclick="window.print()">Imprimir / PDF</button>
    <a class="btn btn-primario" href="?anio=<?= $anio ?>&formato=xlsx">Descargar Excel</a></div>
</div>

<div class="kpis">
  <div class="kpi"><small>Total equipos</small><b><?= $tot['total'] ?></b></div>
  <div class="kpi"><small>En uso</small><b><?= $tot['uso'] ?></b></div>
  <div class="kpi"><small>Disponibles en sistemas</small><b><?= $tot['stock'] ?></b></div>
  <div class="kpi"><small>Bajas y extravíos <?= $anio ?></small><b><?= count($bajas) ?></b></div>
</div>

<div class="tarjeta">
  <h2>Por tipo de equipo</h2>
  <div class="tabla-envoltura"><table class="tabla">
    <thead><tr><th>Tipo</th><th>En uso</th><th>Disponible en sistemas</th><th>Reparación / dañado</th><th>Total</th><th>Extraviados</th><?php if ($tot['valor']): ?><th>Valor</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($res as $t => $r): ?>
      <tr><td><b><?= e($nom($t)) ?></b></td><td><?= $r['uso'] ?></td><td><?= $r['stock'] ?></td><td><?= $r['reparacion'] ?></td><td><b><?= $r['total'] ?></b></td>
        <td><?= $r['extraviado'] ?: '' ?></td><?php if ($tot['valor']): ?><td><?= $r['valor'] ? '$' . number_format($r['valor'], 0, ',', '.') : '' ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <tr style="border-top:2px solid var(--cat)"><td><b>TOTAL</b></td><td><b><?= $tot['uso'] ?></b></td><td><b><?= $tot['stock'] ?></b></td><td><b><?= $tot['reparacion'] ?></b></td>
      <td><b><?= $tot['total'] ?></b></td><td><b><?= $tot['extraviado'] ?: '' ?></b></td><?php if ($tot['valor']): ?><td><b>$<?= number_format($tot['valor'], 0, ',', '.') ?></b></td><?php endif; ?></tr>
    </tbody></table></div>
  <p class="tenue" style="margin-bottom:0">"En uso" incluye lo asignado a personas, lo prestado y lo instalado en un puesto o área (teléfonos, impresoras, access points). El total no incluye extraviados ni dados de baja.</p>
</div>

<div class="tarjeta">
  <h2>Por área</h2>
  <div class="tabla-envoltura"><table class="tabla">
    <thead><tr><th>Área</th><?php foreach ($tipos as $t): ?><th><?= e($nom($t)) ?></th><?php endforeach; ?><th>Total</th></tr></thead>
    <tbody>
    <?php foreach ($por_area as $a => $cnt): ?>
      <tr><td><?= e($a) ?></td><?php foreach ($tipos as $t): ?><td><?= $cnt[$t] ?? '' ?></td><?php endforeach; ?><td><b><?= array_sum($cnt) ?></b></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="tarjeta">
  <h2>Bajas y extravíos <?= $anio ?></h2>
  <?php if (!$bajas): ?><div class="tenue">Ninguno registrado en <?= $anio ?></div><?php else: ?>
  <div class="tabla-envoltura"><table class="tabla"><thead><tr><th>Fecha</th><th>Movimiento</th><th>Equipo</th><th>Serial</th><th>Detalle</th></tr></thead><tbody>
  <?php foreach ($bajas as $b): ?>
    <tr><td><?= fecha_corta($b['fecha']) ?></td><td><?= $b['tipo'] === 'baja' ? 'Dado de baja' : 'Extraviado' ?></td>
      <td class="mono"><?= e($b['etiqueta']) ?> <small><?= e(trim($b['nombre'] . ' ' . $b['marca'] . ' ' . $b['modelo'])) ?></small></td>
      <td class="mono"><?= e($b['serial']) ?></td><td><?= e($b['detalle']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php if (!$tot['valor']): ?><p class="tenue">Ningún equipo tiene valor ni fecha de compra todavía. Si contabilidad los necesita, se llenan en la ficha de cada equipo (Editar) y salen solos en el informe.</p><?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
