<?php
// historial de actualizaciones de la pagina web: cuando se actualizo, cuanto tardo, cuanto estuvo en aviso y quien
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/pagina_web.php';
requiere_modulo('web');

$mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');
$tipo = $_GET['tipo'] ?? '';
$tipos = tipos_actualizacion();
if ($tipo !== '' && !isset($tipos[$tipo])) $tipo = '';

$error = '';
$lista = [];
$ultima = null;
$ahora = [];
if (!web_configurada()) {
    $error = 'Falta la conexión a la base de la página en el .env: WEB_DB_HOST, WEB_DB_NAME, WEB_DB_USER y WEB_DB_PASS.';
} else {
    try {
        // el mes en hora de Colombia, pasado a UTC para buscar
        $z = new DateTimeZone(date_default_timezone_get());
        $ini = new DateTime($mes . '-01 00:00:00', $z);
        $fin = (clone $ini)->modify('+1 month');
        $utc = new DateTimeZone('UTC');
        $lista = actualizaciones_web($ini->setTimezone($utc)->format('Y-m-d H:i:s'), $fin->setTimezone($utc)->format('Y-m-d H:i:s'));
        $ahora = estado_web_ahora();
        // la ultima que de verdad cambio la pagina (en cualquier mes)
        $ul = actualizaciones_web(gmdate('Y-m-d H:i:s', strtotime('-90 days')), gmdate('Y-m-d H:i:s', time() + 3600));
        foreach ($ul as $a) if (in_array($a['tipo'], ['cambios', 'completa', 'items'], true)) { $ultima = $a; break; }
    } catch (Throwable $t) {
        $m = $t->getMessage();
        $error = stripos($m, 'web_actualizaciones') !== false
            ? 'Falta correr WEB_01_historial_actualizaciones.sql en la base de la página (agrocosta_db).'
            : 'No se pudo conectar a la base de la página. Revisa WEB_DB_* en el .env.';
        error_log('pagina web: ' . $m);
    }
}

// excel del mes
if (!$error && ($_GET['formato'] ?? '') === 'xlsx') {
    require __DIR__ . '/inc/xlsx.php';
    $f = [['Fecha', 'Inicio', 'Fin', 'Resultado', 'Duración total (s)', 'Página en aviso (s)', 'Nuevas', 'Cambiadas', 'Eliminadas', 'Filas', 'Equipo', 'Usuario de Windows']];
    foreach (array_reverse($lista) as $a) {
        $r = $a['fila'];
        $f[] = [date('d/m/Y', strtotime($r['fin'])), date('g:i:s a', strtotime($r['inicio'])), date('g:i:s a', strtotime($r['fin'])), $tipos[$a['tipo']][0],
            $r['segundos'] !== null ? (int)$r['segundos'] : '', $a['aviso'] && $a['aviso']['segundos'] !== null ? (int)$a['aviso']['segundos'] : '',
            $r['nuevas'] ?? '', $r['cambiadas'] ?? '', $r['eliminadas'] ?? '', $r['filas'] ?? '', $r['equipo'] ?? '', $r['usuario'] ?? ''];
    }
    xlsx_enviar('Actualizaciones_pagina_web_' . $mes . '.xlsx', ['Actualizaciones' => ['anchos' => [11, 12, 12, 15, 12, 12, 9, 10, 10, 9, 16, 20], 'filas' => $f, 'negrita' => [0]]]);
    exit;
}

$conteo = array_fill_keys(array_keys($tipos), 0);
$aviso_total = 0;
$aviso_n = 0;
foreach ($lista as $a) {
    $conteo[$a['tipo']]++;
    if ($a['aviso'] && $a['aviso']['segundos'] !== null && $a['aviso']['resultado'] === 'ok') { $aviso_total += (int)$a['aviso']['segundos']; $aviso_n++; }
}
$ver = $tipo === '' ? $lista : array_values(array_filter($lista, fn($a) => $a['tipo'] === $tipo));

// meses para el filtro: los ultimos 24
$meses = [];
for ($d = new DateTime(date('Y-m-01')), $i = 0; $i < 24; $i++, $d->modify('-1 month')) $meses[] = $d->format('Y-m');

$titulo = 'Página web';
$menu = 'web';
require __DIR__ . '/inc/header.php';
$nom_mes = ['01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril', '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto', '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'];
$mes_txt = fn($m) => $nom_mes[substr($m, 5, 2)] . ' ' . substr($m, 0, 4);
?>
<div class="cabecera">
  <div><h1>Actualizaciones de la página web</h1><small>Cada vez que el actualizador sube el inventario a la tienda queda aquí: día, hora, cuánto tardó y cuánto estuvo la página en aviso</small></div>
  <?php if (!$error): ?><div class="acciones"><a class="btn btn-borde" href="?mes=<?= e($mes) ?>&amp;formato=xlsx">Descargar Excel</a></div><?php endif; ?>
</div>

<?php if ($error): ?>
  <div class="aviso aviso-error"><?= e($error) ?></div>
<?php else: ?>

<?php foreach ($ahora as $h): ?>
  <div class="aviso"><b><?= (int)$h['id'] === 1 ? 'La página está en aviso ahora mismo' : 'El actualizador está corriendo ahora' ?></b> · desde hace <?= duracion_txt(max(0, (int)$h['seg'])) ?><?= (int)$h['seg'] > 1800 ? ' (si nadie lo está usando, quedó prendida la bandera: revisa estado_inventario)' : '' ?></div>
<?php endforeach; ?>

<div class="kpis">
  <div class="kpi" style="border-left-color:var(--verde)"><small>Última actualización con cambios</small>
    <?php if ($ultima): $u = $ultima['fila']; ?>
      <b style="color:var(--verde);font-size:22px"><?= e(hace_dia_web($u['fin'])) ?></b>
      <span class="tenue"><?= fecha_hora($u['fin']) ?><?= $u['usuario'] ? ' · ' . e($u['usuario']) : '' ?></span>
    <?php else: ?><b style="font-size:22px">—</b><span class="tenue">Sin registro en los últimos 90 días</span><?php endif; ?></div>
  <div class="kpi"><small>Con cambios en <?= e($mes_txt($mes)) ?></small><b><?= $conteo['cambios'] + $conteo['completa'] + $conteo['items'] ?></b>
    <span class="tenue"><?= $conteo['sin_cambios'] ?> sin cambios · <?= $conteo['cancelada'] ?> cancelada<?= $conteo['cancelada'] === 1 ? '' : 's' ?></span></div>
  <div class="kpi"><small>Página en aviso (promedio)</small><b><?= $aviso_n ? duracion_txt((int)round($aviso_total / $aviso_n)) : '—' ?></b>
    <span class="tenue">Lo que el cliente ve el aviso de actualización</span></div>
</div>

<form class="filtros" method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
  <select name="mes" onchange="this.form.submit()"><?php foreach ($meses as $m): ?><option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= e(ucfirst($mes_txt($m))) ?></option><?php endforeach; ?></select>
  <select name="tipo" onchange="this.form.submit()"><option value="">Todas</option><?php foreach ($tipos as $k => [$t]): ?><option value="<?= $k ?>" <?= $k === $tipo ? 'selected' : '' ?>><?= e($t) ?> (<?= $conteo[$k] ?>)</option><?php endforeach; ?></select>
</form>

<div class="tabla-envoltura"><table class="tabla">
  <thead><tr><th>Fecha</th><th>Hora</th><th>Resultado</th><th style="text-align:right">Duración</th><th style="text-align:right">Página en aviso</th><th>Cambios</th><th>Quién</th></tr></thead><tbody>
  <?php if (!$ver): ?><tr><td colspan="7" class="vacio">No hay actualizaciones registradas en <?= e($mes_txt($mes)) ?>. Aparecen desde que se corrió el SQL del historial.</td></tr><?php endif; ?>
  <?php foreach ($ver as $a): $r = $a['fila']; $av = $a['aviso']; ?>
    <tr><td><?= date('d/m/Y', strtotime($r['fin'])) ?><small><?= e(hace_dia_web($r['fin'])) ?></small></td>
      <td class="mono"><?= date('g:i a', strtotime($r['inicio'])) ?> → <?= date('g:i a', strtotime($r['fin'])) ?></td>
      <td><span class="chip <?= $tipos[$a['tipo']][1] ?>"><?= e($tipos[$a['tipo']][0]) ?></span></td>
      <td class="mono" style="text-align:right"><?= duracion_txt($r['segundos']) ?></td>
      <td class="mono" style="text-align:right"><?= $av ? duracion_txt($av['segundos']) : '—' ?></td>
      <td class="mono"><?php if ($r['nuevas'] !== null || $r['cambiadas'] !== null || $r['eliminadas'] !== null): ?>
        <?= (int)$r['nuevas'] ?> nuevas · <?= (int)$r['cambiadas'] ?> cambiadas · <?= (int)$r['eliminadas'] ?> eliminadas
        <?php elseif ($a['tipo'] === 'completa'): ?>Todo el inventario<?= $r['filas'] ? ' (' . number_format((int)$r['filas'], 0, ',', '.') . ' filas)' : '' ?><?php else: ?>—<?php endif; ?></td>
      <td><?= $r['usuario'] ? e($r['usuario']) : '<span class="tenue">—</span>' ?><?= $r['equipo'] ? '<small class="tenue">' . e($r['equipo']) . '</small>' : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>

<p class="tenue" style="font-size:13px"><b>Subió cambios:</b> Sincronizar encontró diferencias y las aplicó. <b>Sin cambios:</b> comparó y la página ya estaba igual. <b>Cancelada:</b> se canceló o falló y la página no cambió. <b>Ítems puntuales:</b> botón “Actualizar ítems” (solo las referencias que se pidieron; el cliente no ve aviso). <b>Carga completa:</b> botón “Subir cambios” (borra y sube todo). <b>Página en aviso:</b> el rato en que el cliente vio el aviso de actualización.</p>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
