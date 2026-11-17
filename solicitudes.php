<?php
// solicitudes a administración: registro, estado, historial, resumen (que se pide y cuantas veces) y Excel
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/solicitudes.php';
requiere_modulo('solicitudes');

$yo = (int)usuario()['id'];
$est = estados_solicitud();
$cats = categorias_solicitud();

function ir_sol($msg = null, $tipo = 'ok', $ver = 0) {
    if ($msg) flash($msg, $tipo);
    ir('solicitudes.php' . ($ver ? '?ver=' . (int)$ver : ''));
}

// lineas del formulario: categoria[], descripcion[], cantidad[]
function leer_lineas($cats) {
    $out = [];
    foreach ((array)($_POST['descripcion'] ?? []) as $i => $d) {
        $d = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$d)), 0, 200);
        if ($d === '') continue;
        $c = (string)($_POST['categoria'][$i] ?? 'Otro');
        $out[] = [in_array($c, $cats, true) ? $c : 'Otro', $d, max(1, min(9999, (int)($_POST['cantidad'][$i] ?? 1)))];
    }
    return $out;
}

function guardar_lineas($id, $lineas) {
    db()->prepare('DELETE FROM solicitudes_lineas WHERE solicitud_id = ?')->execute([$id]);
    $ins = db()->prepare('INSERT INTO solicitudes_lineas (solicitud_id, categoria, descripcion, cantidad) VALUES (?,?,?,?)');
    foreach ($lineas as [$c, $d, $n]) $ins->execute([$id, $c, $d, $n]);
}

function puede_editar_sol($s) {
    global $yo;
    return gestiona_usuarios() || ((int)$s['solicitado_por'] === $yo && $s['estado'] === 'pendiente');
}

// ---------- acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $acc = $_POST['accion'] ?? '';
    $s = !empty($_POST['id']) ? solicitud($_POST['id']) : null;
    if ($acc !== 'crear' && !$s) ir_sol('Esa solicitud no existe', 'error');

    if ($acc === 'crear' || $acc === 'editar') {
        if ($acc === 'editar' && !puede_editar_sol($s)) ir_sol('Ya no se puede editar: cámbiale el estado o agrégale una nota', 'error', $s['id']);
        $lineas = leer_lineas($cats);
        if (!$lineas) ir_sol('Escribe al menos una cosa que se pidió', 'error', $s['id'] ?? 0);
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
        $col = (int)($_POST['colaborador_id'] ?? 0) ?: null;
        $item = null;
        if (($et = trim($_POST['item'] ?? '')) !== '') {
            $st = db()->prepare('SELECT id FROM items WHERE etiqueta = ?');
            $st->execute([preg_replace('/\s.*$/', '', $et)]);
            $item = $st->fetchColumn() ?: null;
            if (!$item) ir_sol('No encontré el equipo “' . $et . '”', 'error', $s['id'] ?? 0);
        }
        $datos = [$fecha, trim($_POST['motivo'] ?? '') ?: null, ($_POST['urgencia'] ?? '') === 'urgente' ? 'urgente' : 'normal', $col, $item];
        if ($acc === 'crear') {
            db()->prepare('INSERT INTO solicitudes (fecha, motivo, urgencia, colaborador_id, item_id, solicitado_por) VALUES (?,?,?,?,?,?)')->execute([...$datos, $yo]);
            $id = (int)db()->lastInsertId();
        } else {
            $id = (int)$s['id'];
            db()->prepare('UPDATE solicitudes SET fecha = ?, motivo = ?, urgencia = ?, colaborador_id = ?, item_id = ?, actualizado = NOW() WHERE id = ?')->execute([...$datos, $id]);
        }
        guardar_lineas($id, $lineas);
        if ($a = guardar_archivo_subido('formato_archivo', 'solicitudes')) db()->prepare('UPDATE solicitudes SET formato_archivo = ? WHERE id = ?')->execute([$a, $id]);
        evento_solicitud($id, $acc === 'crear' ? 'Registró la solicitud' : 'Editó la solicitud');
        if ($acc === 'crear') avisar_solicitud($id, 'nueva');
        ir_sol($acc === 'crear' ? 'Solicitud registrada' . (correos_solicitudes() ? ' y avisada por correo' : '') : 'Solicitud guardada', 'ok', $id);
    }

    if ($acc === 'estado') {
        $nuevo = $_POST['estado'] ?? '';
        if (!isset($est[$nuevo])) ir_sol('Escoge el estado', 'error', $s['id']);
        $nota = trim($_POST['nota'] ?? '');
        $costo = trim(str_replace(['$', '.', ' '], '', $_POST['costo'] ?? ''));
        $costo = $costo === '' ? $s['costo_total'] : (float)str_replace(',', '.', $costo);
        $frec = $nuevo === 'recibida' ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha_recibida'] ?? '') ? $_POST['fecha_recibida'] : date('Y-m-d')) : null;
        db()->prepare('UPDATE solicitudes SET estado = ?, fecha_estado = NOW(), fecha_recibida = ?, costo_total = ?, actualizado = NOW() WHERE id = ?')
            ->execute([$nuevo, $frec, $costo, $s['id']]);
        if ($a = guardar_archivo_subido('factura_archivo', 'solicitudes')) db()->prepare('UPDATE solicitudes SET factura_archivo = ? WHERE id = ?')->execute([$a, $s['id']]);
        $txt = 'Cambió el estado a: ' . $est[$nuevo][0] . ($frec ? ' (el ' . fecha_corta($frec) . ')' : '') . ($costo !== null && $costo != $s['costo_total'] ? ' · costo ' . pesos_sol($costo) : '') . ($nota !== '' ? "\n" . $nota : '');
        evento_solicitud($s['id'], $txt);
        if ($nuevo !== $s['estado']) avisar_solicitud($s['id'], 'estado', $nota);
        ir_sol('Estado actualizado', 'ok', $s['id']);
    }

    if ($acc === 'nota') {
        $nota = trim($_POST['nota'] ?? '');
        $a = guardar_archivo_subido('formato_archivo', 'solicitudes');
        if ($a) db()->prepare('UPDATE solicitudes SET formato_archivo = ? WHERE id = ?')->execute([$a, $s['id']]);
        if ($nota === '' && !$a) ir_sol('Escribe la nota', 'error', $s['id']);
        evento_solicitud($s['id'], $nota !== '' ? $nota : 'Adjuntó la foto del formato');
        ir_sol('Guardado', 'ok', $s['id']);
    }

    if ($acc === 'borrar') {
        if (!puede_editar_sol($s)) ir_sol('No la puedes borrar', 'error', $s['id']);
        foreach (['solicitudes_lineas', 'solicitudes_eventos'] as $t) db()->prepare("DELETE FROM $t WHERE solicitud_id = ?")->execute([$s['id']]);
        db()->prepare('DELETE FROM solicitudes WHERE id = ?')->execute([$s['id']]);
        ir_sol('Solicitud borrada');
    }
    ir_sol();
}

$anio = (int)($_GET['anio'] ?? date('Y'));
$anios = db()->query('SELECT DISTINCT YEAR(fecha) FROM solicitudes ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN) ?: [date('Y')];
if (!in_array((string)date('Y'), array_map('strval', $anios), true)) array_unshift($anios, date('Y'));

// ---------- Excel del año ----------
if (($_GET['formato'] ?? '') === 'xlsx') {
    require __DIR__ . '/inc/xlsx.php';
    $st = db()->prepare(sql_solicitudes() . ' WHERE YEAR(s.fecha) = ? ORDER BY s.fecha, s.id');
    $st->execute([$anio]);
    $sols = $st->fetchAll();
    $f1 = [['Código', 'Fecha', 'Solicitó', 'Qué se pidió', 'Unidades', 'Para', 'Equipo', 'Motivo', 'Urgencia', 'Estado', 'Recibida', 'Días', 'Costo']];
    foreach ($sols as $s) $f1[] = [codigo_solicitud($s), fecha_corta($s['fecha']), $s['solicitante'], $s['resumen'], (int)$s['unidades'], $s['colaborador'], $s['item_etiqueta'], $s['motivo'], $s['urgencia'] === 'urgente' ? 'Urgente' : '', $est[$s['estado']][0], fecha_corta($s['fecha_recibida']), dias_solicitud($s), $s['costo_total'] !== null ? (float)$s['costo_total'] : ''];
    $st = db()->prepare('SELECT l.categoria, l.descripcion, COUNT(DISTINCT l.solicitud_id) veces, SUM(l.cantidad) unidades, MAX(s.fecha) ultima FROM solicitudes_lineas l JOIN solicitudes s ON s.id = l.solicitud_id WHERE YEAR(s.fecha) = ? AND s.estado <> \'cancelada\' GROUP BY l.categoria, l.descripcion ORDER BY veces DESC, unidades DESC');
    $st->execute([$anio]);
    $f2 = [['Categoría', 'Qué se pidió', 'Veces', 'Unidades', 'Última vez']];
    foreach ($st as $r) $f2[] = [$r['categoria'], $r['descripcion'], (int)$r['veces'], (int)$r['unidades'], fecha_corta($r['ultima'])];
    xlsx_enviar('Solicitudes_administracion_' . $anio . '.xlsx', [
        'Solicitudes' => ['anchos' => [11, 11, 22, 50, 9, 22, 12, 40, 10, 22, 11, 7, 12], 'filas' => $f1, 'negrita' => [0]],
        'Lo que se pide' => ['anchos' => [30, 45, 8, 10, 12], 'filas' => $f2, 'negrita' => [0]],
    ]);
    exit;
}

// ---------- formato para imprimir ----------
if (!empty($_GET['imprimir']) && ($s = solicitud($_GET['imprimir']))) {
    $lin = lineas_solicitud($s['id']); ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= codigo_solicitud($s) ?> · Solicitud</title>
<style>
  body { font-family: Arial, sans-serif; color: #111; background: #fff; margin: 0; padding: 28px; font-size: 13px; }
  .hoja { max-width: 760px; margin: auto; }
  .cab { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #ffcd11; padding-bottom: 10px; margin-bottom: 16px; }
  h1 { font-size: 18px; margin: 0; } .cod { font-family: monospace; font-size: 16px; font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; } th, td { border: 1px solid #999; padding: 7px 8px; text-align: left; vertical-align: top; }
  th { background: #f2f2f2; font-size: 12px; } .datos td:first-child { width: 150px; background: #fafafa; font-weight: bold; }
  .firmas { display: flex; gap: 30px; margin-top: 60px; } .firmas div { flex: 1; border-top: 1px solid #000; padding-top: 6px; text-align: center; font-size: 12px; }
  .boton { margin: 0 0 18px; } @media print { .boton { display: none; } body { padding: 0; } }
</style></head><body><div class="hoja">
  <p class="boton"><button onclick="window.print()">Imprimir</button></p>
  <div class="cab"><div><h1>Solicitud de sistemas a administración</h1><div>Agro-Costa · Área de sistemas</div></div><div class="cod"><?= codigo_solicitud($s) ?></div></div>
  <table class="datos">
    <tr><td>Fecha</td><td><?= fecha_corta($s['fecha']) ?></td></tr>
    <tr><td>Solicitado por</td><td><?= e($s['solicitante']) ?></td></tr>
    <?php if ($s['colaborador']): ?><tr><td>Para</td><td><?= e($s['colaborador']) ?></td></tr><?php endif; ?>
    <?php if ($s['item_etiqueta']): ?><tr><td>Equipo</td><td><?= e($s['item_etiqueta'] . ' · ' . $s['item_nombre']) ?></td></tr><?php endif; ?>
    <tr><td>Urgencia</td><td><?= $s['urgencia'] === 'urgente' ? 'URGENTE' : 'Normal' ?></td></tr>
  </table>
  <table><tr><th style="width:70px">Cantidad</th><th>Descripción</th><th style="width:200px">Categoría</th></tr>
    <?php foreach ($lin as $l): ?><tr><td style="text-align:center"><b><?= (int)$l['cantidad'] ?></b></td><td><?= e($l['descripcion']) ?></td><td><?= e($l['categoria']) ?></td></tr><?php endforeach; ?>
  </table>
  <table class="datos"><tr><td>Motivo / justificación</td><td style="height:60px"><?= nl2br(e($s['motivo'])) ?></td></tr></table>
  <div class="firmas"><div>Solicita (sistemas)<br><?= e($s['solicitante']) ?></div><div>Recibe (administración)</div><div>Entrega / fecha</div></div>
</div></body></html>
<?php exit; }

// ---------- datos de la vista ----------
$vista = ($_GET['vista'] ?? '') === 'resumen' ? 'resumen' : 'lista';
$filtro = $_GET['estado'] ?? 'abiertas';
$q = trim($_GET['q'] ?? '');
$colabs = db()->query('SELECT id, nombre FROM colaboradores WHERE activo = 1 ORDER BY nombre')->fetchAll();
$equipos = db()->query("SELECT etiqueta, nombre FROM items WHERE etiqueta IS NOT NULL AND etiqueta <> '' ORDER BY etiqueta LIMIT 3000")->fetchAll();
$descs = db()->query('SELECT descripcion, MAX(categoria) categoria, COUNT(*) n FROM solicitudes_lineas GROUP BY descripcion ORDER BY n DESC, descripcion LIMIT 500')->fetchAll();
$abiertas = (int)db()->query("SELECT COUNT(*) FROM solicitudes WHERE estado IN ('pendiente','en_compra')")->fetchColumn();

$ver = !empty($_GET['ver']) ? solicitud($_GET['ver']) : null;
$editar = !empty($_GET['editar']) ? solicitud($_GET['editar']) : null;
if ($editar && !puede_editar_sol($editar)) $editar = null;
$nueva = !empty($_GET['nueva']);

function form_lineas($cats, $lineas) {
    if (!$lineas) $lineas = [['categoria' => '', 'descripcion' => '', 'cantidad' => 1]];
    $h = '<div class="sol-lineas" data-lineas>';
    foreach ($lineas as $l) {
        $h .= '<div class="sol-linea"><input type="number" name="cantidad[]" min="1" max="9999" value="' . (int)$l['cantidad'] . '" title="Cantidad">'
            . '<input name="descripcion[]" list="lista-descs" maxlength="200" placeholder="Qué se pide, ej. Cable HDMI 3 m" value="' . e($l['descripcion']) . '" data-desc>'
            . '<select name="categoria[]" title="Categoría"><option value="">Categoría…</option>';
        foreach ($cats as $c) $h .= '<option' . ($l['categoria'] === $c ? ' selected' : '') . '>' . e($c) . '</option>';
        $h .= '</select><button type="button" class="btn btn-borde btn-sm" data-quitar-linea title="Quitar">×</button></div>';
    }
    return $h . '</div><button type="button" class="btn btn-borde btn-sm" data-agregar-linea>+ Otra cosa</button>';
}

function form_solicitud($s, $cats, $colabs, $lineas) {
    $h = '<div class="rejilla">'
        . '<div class="campo"><label>Fecha en que se entregó el formato</label><input type="date" name="fecha" required max="' . date('Y-m-d') . '" value="' . e($s['fecha'] ?? date('Y-m-d')) . '"></div>'
        . '<div class="campo"><label>Urgencia</label><select name="urgencia"><option value="normal">Normal</option><option value="urgente"' . (($s['urgencia'] ?? '') === 'urgente' ? ' selected' : '') . '>Urgente</option></select></div>'
        . '<div class="campo" style="grid-column:1/-1"><label>Qué se pide</label>' . form_lineas($cats, $lineas) . '</div>'
        . '<div class="campo"><label>Para quién (opcional)</label><select name="colaborador_id"><option value="">—</option>';
    foreach ($colabs as $c) $h .= '<option value="' . $c['id'] . '"' . ((int)($s['colaborador_id'] ?? 0) === (int)$c['id'] ? ' selected' : '') . '>' . e($c['nombre']) . '</option>';
    $h .= '</select></div><div class="campo"><label>Equipo (opcional)</label><input name="item" list="lista-equipos" value="' . e($s['item_etiqueta'] ?? '') . '" placeholder="Etiqueta, ej. AGR-0004"></div>'
        . '<div class="campo" style="grid-column:1/-1"><label>Motivo</label><textarea name="motivo" rows="2" placeholder="Por qué se necesita: se dañó, equipo nuevo, reposición…">' . e($s['motivo'] ?? '') . '</textarea></div>'
        . '<div class="campo" style="grid-column:1/-1"><label>Foto del formato en papel (opcional)</label><input type="file" name="formato_archivo" accept="image/*,application/pdf" capture="environment"></div>'
        . '</div>';
    return $h;
}

$titulo = 'Solicitudes';
$menu = 'solicitudes';
require __DIR__ . '/inc/header.php';
?>
<datalist id="lista-equipos"><?php foreach ($equipos as $q2): ?><option value="<?= e($q2['etiqueta']) ?>"><?= e($q2['nombre']) ?></option><?php endforeach; ?></datalist>
<datalist id="lista-descs"><?php foreach ($descs as $d): ?><option value="<?= e($d['descripcion']) ?>" data-cat="<?= e($d['categoria']) ?>"><?= e($d['categoria']) ?> · pedido <?= (int)$d['n'] ?> vez/veces</option><?php endforeach; ?></datalist>

<div class="cabecera">
  <div><h1>Solicitudes a administración</h1><small>Lo que sistemas le pide a la administradora con el formato en papel: cables, adaptadores, discos, reparaciones…</small></div>
  <div class="acciones"><a class="btn btn-primario" href="?nueva=1">+ Registrar solicitud</a></div>
</div>

<div class="pestanas-herr" style="margin:4px 0 14px">
  <a href="solicitudes.php" class="<?= $vista === 'lista' ? 'activa' : '' ?>">Solicitudes<?= $abiertas ? '<small>' . $abiertas . ' abiertas</small>' : '' ?></a>
  <a href="?vista=resumen" class="<?= $vista === 'resumen' ? 'activa' : '' ?>">Resumen: qué se pide y cuánto</a>
</div>

<?php if ($vista === 'lista'):
  $where = []; $args = [];
  if ($filtro === 'abiertas') $where[] = "s.estado IN ('pendiente','en_compra')";
  elseif (isset($est[$filtro])) { $where[] = 's.estado = ?'; $args[] = $filtro; }
  if ($filtro !== 'abiertas' && empty($_GET['todo'])) { $where[] = 'YEAR(s.fecha) = ?'; $args[] = $anio; }
  if ($q !== '') { $where[] = '(EXISTS (SELECT 1 FROM solicitudes_lineas l WHERE l.solicitud_id = s.id AND (l.descripcion LIKE ? OR l.categoria LIKE ?)) OR s.motivo LIKE ? OR c.nombre LIKE ? OR i.etiqueta LIKE ?)'; array_push($args, ...array_fill(0, 5, '%' . $q . '%')); }
  $st = db()->prepare(sql_solicitudes() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.fecha DESC, s.id DESC LIMIT 500');
  $st->execute($args);
  $lista = $st->fetchAll(); ?>
  <form class="filtros" method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <select name="estado" onchange="this.form.submit()">
      <option value="abiertas"<?= $filtro === 'abiertas' ? ' selected' : '' ?>>Abiertas (sin recibir)</option>
      <option value="todas"<?= $filtro === 'todas' ? ' selected' : '' ?>>Todas</option>
      <?php foreach ($est as $k => [$n]): ?><option value="<?= $k ?>"<?= $filtro === $k ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
    </select>
    <?php if ($filtro !== 'abiertas'): ?><select name="anio" onchange="this.form.submit()"><?php foreach ($anios as $a): ?><option<?= (int)$a === $anio ? ' selected' : '' ?>><?= (int)$a ?></option><?php endforeach; ?></select><?php endif; ?>
    <input name="q" value="<?= e($q) ?>" placeholder="Buscar: cable, disco, persona, equipo…" style="flex:1 1 240px">
    <button class="btn btn-borde">Buscar</button>
  </form>
  <div class="tabla-envoltura"><table class="tabla">
    <thead><tr><th>Solicitud</th><th>Qué se pidió</th><th>Para</th><th>Estado</th><th style="text-align:right">Días</th></tr></thead><tbody>
    <?php if (!$lista): ?><tr><td colspan="5" class="vacio"><?= $filtro === 'abiertas' ? 'No hay solicitudes abiertas.' : 'No hay solicitudes con ese filtro.' ?></td></tr><?php endif; ?>
    <?php foreach ($lista as $s): $d = dias_solicitud($s); $abierta = in_array($s['estado'], ['pendiente', 'en_compra'], true); ?>
      <tr><td><a href="?ver=<?= $s['id'] ?>" class="mono"><b><?= codigo_solicitud($s) ?></b></a><?= $s['urgencia'] === 'urgente' ? ' <span class="chip chip-danado">Urgente</span>' : '' ?><small><?= fecha_corta($s['fecha']) ?> · <?= e(explode(' ', (string)$s['solicitante'])[0]) ?></small></td>
        <td><?= e($s['resumen']) ?><?= $s['motivo'] ? '<small>' . e(mb_strimwidth($s['motivo'], 0, 90, '…')) . '</small>' : '' ?></td>
        <td><?= e($s['colaborador'] ?: '—') ?><?= $s['item_etiqueta'] ? '<small class="mono">' . e($s['item_etiqueta']) . '</small>' : '' ?></td>
        <td><span class="chip <?= $est[$s['estado']][1] ?>"><?= e($est[$s['estado']][0]) ?></span><?= $s['costo_total'] !== null ? '<small>' . pesos_sol($s['costo_total']) . '</small>' : '' ?></td>
        <td class="mono" style="text-align:right;<?= $abierta && $d >= (int)env('SOLICITUDES_DIAS_ALERTA', 8) ? 'color:var(--rojo);font-weight:700' : '' ?>"><?= $d ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>

<?php else:
  // ---------- resumen del año ----------
  $base = "FROM solicitudes s WHERE YEAR(s.fecha) = ? AND s.estado <> 'cancelada'";
  $one = function ($sql) use ($anio) { $st = db()->prepare($sql); $st->execute([$anio]); return $st->fetchColumn(); };
  $all = function ($sql) use ($anio) { $st = db()->prepare($sql); $st->execute([$anio]); return $st->fetchAll(); };
  $n_sol = (int)$one("SELECT COUNT(*) $base");
  $n_uni = (int)$one("SELECT COALESCE(SUM(l.cantidad),0) FROM solicitudes_lineas l JOIN solicitudes s ON s.id = l.solicitud_id WHERE YEAR(s.fecha) = ? AND s.estado <> 'cancelada'");
  $gasto = (float)$one("SELECT COALESCE(SUM(costo_total),0) $base AND s.estado = 'recibida'");
  $prom = $one("SELECT AVG(DATEDIFF(fecha_recibida, fecha)) $base AND s.estado = 'recibida' AND fecha_recibida IS NOT NULL");
  $rech = (int)$one("SELECT COUNT(*) $base AND s.estado = 'rechazada'");
  $mes = array_fill(1, 12, 0);
  foreach ($all("SELECT MONTH(fecha) m, COUNT(*) n $base GROUP BY m") as $r) $mes[(int)$r['m']] = (int)$r['n'];
  $max_mes = max(1, max($mes));
  $por_cat = $all("SELECT l.categoria, COUNT(DISTINCT l.solicitud_id) veces, SUM(l.cantidad) unidades FROM solicitudes_lineas l JOIN solicitudes s ON s.id = l.solicitud_id WHERE YEAR(s.fecha) = ? AND s.estado <> 'cancelada' GROUP BY l.categoria ORDER BY veces DESC");
  $max_cat = max(1, (int)max(array_column($por_cat, 'veces') ?: [1]));
  $top = $all("SELECT l.descripcion, MAX(l.categoria) categoria, COUNT(DISTINCT l.solicitud_id) veces, SUM(l.cantidad) unidades, MAX(s.fecha) ultima FROM solicitudes_lineas l JOIN solicitudes s ON s.id = l.solicitud_id WHERE YEAR(s.fecha) = ? AND s.estado <> 'cancelada' GROUP BY l.descripcion ORDER BY veces DESC, unidades DESC LIMIT 20");
  $quien = $all("SELECT u.nombre, COUNT(*) n FROM solicitudes s JOIN usuarios u ON u.id = s.solicitado_por WHERE YEAR(s.fecha) = ? AND s.estado <> 'cancelada' GROUP BY u.nombre ORDER BY n DESC");
  $para = $all("SELECT c.nombre, COUNT(*) n FROM solicitudes s JOIN colaboradores c ON c.id = s.colaborador_id WHERE YEAR(s.fecha) = ? AND s.estado <> 'cancelada' GROUP BY c.nombre ORDER BY n DESC LIMIT 10");
  $meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']; ?>
  <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:12px"><input type="hidden" name="vista" value="resumen">
    <select name="anio" onchange="this.form.submit()"><?php foreach ($anios as $a): ?><option<?= (int)$a === $anio ? ' selected' : '' ?>><?= (int)$a ?></option><?php endforeach; ?></select>
    <a class="btn btn-borde" href="?anio=<?= $anio ?>&amp;formato=xlsx">Descargar Excel <?= $anio ?></a>
  </form>
  <div class="kpis">
    <div class="kpi"><small>Solicitudes</small><b><?= $n_sol ?></b><span class="tenue"><?= $rech ? $rech . ' rechazada' . ($rech > 1 ? 's' : '') : 'en ' . $anio ?></span></div>
    <div class="kpi"><small>Unidades pedidas</small><b><?= $n_uni ?></b></div>
    <div class="kpi"><small>Gasto registrado</small><b style="font-size:22px"><?= pesos_sol($gasto) ?: '$0' ?></b><span class="tenue">de las recibidas con costo</span></div>
    <div class="kpi"><small>Tardan en llegar</small><b><?= $prom !== null ? round((float)$prom, 1) : '—' ?></b><span class="tenue">días en promedio</span></div>
    <div class="kpi" style="<?= $abiertas ? 'border-left-color:var(--rojo)' : '' ?>"><small>Abiertas ahora</small><b style="<?= $abiertas ? 'color:var(--rojo)' : '' ?>"><?= $abiertas ?></b></div>
  </div>
  <div class="dos-col">
    <div>
      <div class="tarjeta"><h2>Lo que más se pide · <?= $anio ?></h2>
        <?php if (!$top): ?><p class="tenue">Todavía no hay solicitudes en <?= $anio ?>.</p><?php else: ?>
        <div class="tabla-envoltura" style="border:0"><table class="tabla"><thead><tr><th>Qué</th><th style="text-align:right">Veces</th><th style="text-align:right">Unidades</th><th>Última</th></tr></thead><tbody>
          <?php foreach ($top as $r): ?><tr><td><a href="?estado=todas&amp;anio=<?= $anio ?>&amp;q=<?= urlencode($r['descripcion']) ?>"><?= e($r['descripcion']) ?></a><small><?= e($r['categoria']) ?></small></td><td class="mono" style="text-align:right"><b><?= (int)$r['veces'] ?></b></td><td class="mono" style="text-align:right"><?= (int)$r['unidades'] ?></td><td><?= fecha_corta($r['ultima']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </div>
      <div class="tarjeta"><h2>Por mes</h2>
        <div class="barras"><?php foreach ($mes as $m => $n): ?><div class="barra-fila"><span><?= $meses[$m] ?></span><div class="barra-pista"><div class="barra-llena" style="width:<?= round($n / $max_mes * 100) ?>%"></div></div><b><?= $n ?: '' ?></b></div><?php endforeach; ?></div>
      </div>
    </div>
    <div>
      <div class="tarjeta"><h2>Por categoría</h2>
        <?php if (!$por_cat): ?><p class="tenue">—</p><?php endif; ?>
        <div class="barras"><?php foreach ($por_cat as $r): ?><div class="barra-fila barra-ancha"><span title="<?= e($r['categoria']) ?>"><?= e($r['categoria']) ?></span><div class="barra-pista"><div class="barra-llena" style="width:<?= round($r['veces'] / $max_cat * 100) ?>%"></div></div><b><?= (int)$r['veces'] ?></b></div><?php endforeach; ?></div>
        <p class="tenue" style="font-size:12px;margin-bottom:0">Veces que aparece en una solicitud.</p>
      </div>
      <div class="tarjeta"><h2>Quién pide</h2>
        <?php foreach ($quien as $r): ?><div class="fila-dato"><span><?= e($r['nombre']) ?></span><b class="mono"><?= (int)$r['n'] ?></b></div><?php endforeach; ?>
        <?php if (!$quien): ?><p class="tenue">—</p><?php endif; ?>
      </div>
      <?php if ($para): ?><div class="tarjeta"><h2>Para quién se pide más</h2>
        <?php foreach ($para as $r): ?><div class="fila-dato"><span><?= e($r['nombre']) ?></span><b class="mono"><?= (int)$r['n'] ?></b></div><?php endforeach; ?>
      </div><?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($nueva || $editar): $s0 = $editar ?: []; ?>
<div class="modal-fondo" data-cerrar="<?= $editar ? '?ver=' . $editar['id'] : 'solicitudes.php' ?>">
<form method="post" class="tarjeta modal-caja" role="dialog" aria-modal="true" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="<?= $editar ? 'editar' : 'crear' ?>"><?php if ($editar): ?><input type="hidden" name="id" value="<?= $editar['id'] ?>"><?php endif; ?>
  <a class="modal-x" href="<?= $editar ? '?ver=' . $editar['id'] : 'solicitudes.php' ?>" aria-label="Cerrar">×</a>
  <h2><?= $editar ? 'Editar ' . codigo_solicitud($editar) : 'Registrar solicitud' ?></h2>
  <?php if (!$editar): ?><p class="tenue" style="margin-top:-6px">Lo mismo que va en el formato en papel. Al guardar llega el aviso por correo.</p><?php endif; ?>
  <?= form_solicitud($s0, $cats, $colabs, $editar ? lineas_solicitud($editar['id']) : []) ?>
  <p style="margin-bottom:0;display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primario"><?= $editar ? 'Guardar' : 'Registrar y avisar' ?></button> <a class="btn btn-borde" href="<?= $editar ? '?ver=' . $editar['id'] : 'solicitudes.php' ?>">Cancelar</a>
  <?php if ($editar): ?><button class="btn btn-borde" style="margin-left:auto;color:var(--rojo)" name="accion" value="borrar" formnovalidate onclick="return confirm('¿Borrar esta solicitud?')">Borrar</button><?php endif; ?></p>
</form>
</div>

<?php elseif ($ver): $lin = lineas_solicitud($ver['id']); $evs = eventos_solicitud($ver['id']); $d = dias_solicitud($ver); ?>
<div class="modal-fondo" data-cerrar="solicitudes.php">
<div class="tarjeta modal-caja caso" role="dialog" aria-modal="true">
  <a class="modal-x" href="solicitudes.php" aria-label="Cerrar">×</a>
  <div class="tenue mono" style="font-size:12px"><?= fecha_corta($ver['fecha']) ?> · <?= e($ver['solicitante']) ?></div>
  <h2 style="text-transform:none;font-size:18px;margin:2px 0 8px"><?= codigo_solicitud($ver) ?> <span class="chip <?= $est[$ver['estado']][1] ?>"><?= e($est[$ver['estado']][0]) ?></span><?= $ver['urgencia'] === 'urgente' ? ' <span class="chip chip-danado">Urgente</span>' : '' ?></h2>
  <div class="tarea-meta" style="margin-bottom:10px">
    <span class="tm"><?= in_array($ver['estado'], ['pendiente', 'en_compra'], true) ? 'Lleva ' . $d . ' día' . ($d === 1 ? '' : 's') : 'Tardó ' . $d . ' día' . ($d === 1 ? '' : 's') ?></span>
    <?php if ($ver['colaborador']): ?><span class="tm">👤 <?= e($ver['colaborador']) ?></span><?php endif; ?>
    <?php if ($ver['item_etiqueta']): ?><a class="tm mono" href="item.php?id=<?= (int)$ver['item_id'] ?>">🖥 <?= e($ver['item_etiqueta'] . ' · ' . $ver['item_nombre']) ?></a><?php endif; ?>
    <?php if ($ver['costo_total'] !== null): ?><span class="tm">💲 <?= pesos_sol($ver['costo_total']) ?></span><?php endif; ?>
    <?php if ($ver['fecha_recibida']): ?><span class="tm tm-hecha">Recibida <?= fecha_corta($ver['fecha_recibida']) ?></span><?php endif; ?>
  </div>
  <div class="tabla-envoltura"><table class="tabla"><thead><tr><th style="width:60px;text-align:right">Cant.</th><th>Qué se pidió</th><th>Categoría</th><?php if ($ver['estado'] === 'recibida'): ?><th></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($lin as $l): ?><tr><td class="mono" style="text-align:right"><b><?= (int)$l['cantidad'] ?></b></td><td><?= e($l['descripcion']) ?></td><td class="tenue"><?= e($l['categoria']) ?></td>
      <?php if ($ver['estado'] === 'recibida'): ?><td style="text-align:right"><a class="btn btn-borde btn-sm" href="item.php?nombre=<?= urlencode($l['descripcion']) ?>" title="Crear la ficha en el inventario">+ Inventario</a></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($ver['motivo']): ?><div class="caso-obs" style="margin-top:10px"><b>Motivo:</b> <?= nl2br(e($ver['motivo'])) ?></div><?php endif; ?>
  <p style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 0">
    <?php if ($ver['formato_archivo']): ?><a class="btn btn-borde btn-sm" href="<?= e(url_archivo($ver['formato_archivo'])) ?>" target="_blank">📄 Formato en papel</a><?php endif; ?>
    <?php if ($ver['factura_archivo']): ?><a class="btn btn-borde btn-sm" href="<?= e(url_archivo($ver['factura_archivo'])) ?>" target="_blank">🧾 Factura</a><?php endif; ?>
    <a class="btn btn-borde btn-sm" href="?imprimir=<?= $ver['id'] ?>" target="_blank">🖨 Imprimir formato</a>
    <?php if (puede_editar_sol($ver)): ?><a class="btn btn-borde btn-sm" href="?editar=<?= $ver['id'] ?>">Editar</a><?php endif; ?>
  </p>

  <h3 class="caso-sub">Cambiar estado</h3>
  <form method="post" enctype="multipart/form-data" class="sol-estado"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $ver['id'] ?>">
    <div class="rejilla">
      <div class="campo"><label>Estado</label><select name="estado" data-estado-sol><?php foreach ($est as $k => [$n]): ?><option value="<?= $k ?>"<?= $ver['estado'] === $k ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
      <div class="campo"><label>Fecha en que se recibió</label><input type="date" name="fecha_recibida" max="<?= date('Y-m-d') ?>" value="<?= e($ver['fecha_recibida'] ?: date('Y-m-d')) ?>"></div>
      <div class="campo"><label>Costo total (si se sabe)</label><input name="costo" inputmode="numeric" placeholder="$" value="<?= $ver['costo_total'] !== null ? number_format((float)$ver['costo_total'], 0, ',', '.') : '' ?>"></div>
      <div class="campo"><label>Factura (opcional)</label><input type="file" name="factura_archivo" accept="image/*,application/pdf"></div>
      <div class="campo" style="grid-column:1/-1"><label>Nota</label><input name="nota" maxlength="1000" placeholder="Ej.: la compran el viernes · llegó solo 1 de 2 · no hay presupuesto"></div>
    </div>
    <button class="btn btn-primario btn-sm">Guardar estado y avisar</button>
  </form>

  <h3 class="caso-sub">Historial</h3>
  <div class="caso-notas">
    <?php foreach ($evs as $e): ?><div class="caso-nota"><div class="caso-quien"><b><?= e($e['nombre'] ?? 'Sistema') ?></b> <span class="tenue"><?= fecha_hora($e['creado']) ?></span></div><div><?= nl2br(e($e['texto'])) ?></div></div><?php endforeach; ?>
  </div>
  <form method="post" enctype="multipart/form-data" class="caso-form"><?= csrf_campo() ?><input type="hidden" name="accion" value="nota"><input type="hidden" name="id" value="<?= $ver['id'] ?>">
    <textarea name="nota" rows="1" placeholder="Agregar una nota (ej. hablé con la administradora, dice que llega la otra semana)"></textarea>
    <?php if (!$ver['formato_archivo']): ?><label class="btn btn-borde btn-sm" style="cursor:pointer" title="Foto del formato en papel">📷<input type="file" name="formato_archivo" accept="image/*,application/pdf" capture="environment" hidden onchange="this.form.submit()"></label><?php endif; ?>
    <button class="btn btn-borde btn-sm">Agregar</button>
  </form>
</div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
