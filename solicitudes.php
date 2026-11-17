<?php
// solicitudes a administración (formato SGSST-AC-F55): el auxiliar radica, Paolo o Carlos aprueban,
// se libera el PDF (se imprime una sola vez), se entrega a administración y se sigue hasta recibirla.
// Además: historial, resumen de lo que se pide y cuántas veces, y Excel
require __DIR__ . '/config.php';
// si falta algo de la instalacion, decirlo en vez de un error 500
$falta = [];
if (!is_file(__DIR__ . '/inc/solicitudes.php')) $falta[] = 'el archivo <b>inc/solicitudes.php</b> (debe ir dentro de la carpeta inc)';
if (!is_file(__DIR__ . '/inc/fpdf/fpdf.php')) $falta[] = 'la carpeta <b>inc/fpdf</b> (para el PDF)';
try { db()->query('SELECT 1 FROM solicitudes_lineas LIMIT 1'); db()->query('SELECT 1 FROM solicitudes_eventos LIMIT 1'); db()->query('SELECT aviso_atraso FROM solicitudes LIMIT 1'); }
catch (Throwable $e) { $falta[] = 'las tablas: corre <b>65_solicitudes.sql</b> en phpMyAdmin'; }
if (!$falta) {
    try { db()->query('SELECT token, impreso_en FROM solicitudes LIMIT 1'); db()->query('SELECT unidad, especificaciones, costo_estimado FROM solicitudes_lineas LIMIT 1'); }
    catch (Throwable $e) { $falta[] = 'la aprobación y el formato: corre <b>66_solicitudes_aprobacion.sql</b> en phpMyAdmin'; }
}
if ($falta) {
    requiere_login();
    $titulo = 'Solicitudes';
    $menu = 'solicitudes';
    require __DIR__ . '/inc/header.php';
    echo '<div class="cabecera"><div><h1>Solicitudes a administración</h1></div></div><div class="aviso aviso-error">Falta instalar: ' . implode(' y ', $falta) . '.</div>';
    require __DIR__ . '/inc/footer.php';
    exit;
}
require_once __DIR__ . '/inc/solicitudes.php';
requiere_modulo('solicitudes');

$yo = (int)usuario()['id'];
$aprueba = gestiona_usuarios(); // Paolo y Carlos
$est = estados_solicitud();
$cats = categorias_solicitud();
$tipos = tipos_compra();
const MAX_LINEAS = 6; // el formato en papel tiene 6 renglones

function ir_sol($msg = null, $tipo = 'ok', $ver = 0) {
    if ($msg) flash($msg, $tipo);
    ir('solicitudes.php' . ($ver ? '?ver=' . (int)$ver : ''));
}

// articulos del formulario
function leer_lineas($cats) {
    $out = [];
    foreach ((array)($_POST['descripcion'] ?? []) as $i => $d) {
        $d = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$d)), 0, 200);
        if ($d === '') continue;
        $c = (string)($_POST['categoria'][$i] ?? 'Otro');
        $costo = trim(str_replace(['$', '.', ' '], '', (string)($_POST['costo_estimado'][$i] ?? '')));
        $out[] = [
            'categoria' => in_array($c, $cats, true) ? $c : 'Otro',
            'descripcion' => $d,
            'cantidad' => max(1, min(9999, (int)($_POST['cantidad'][$i] ?? 1))),
            'unidad' => mb_substr(trim((string)($_POST['unidad'][$i] ?? '')), 0, 30) ?: 'Unidad',
            'especificaciones' => mb_substr(trim((string)($_POST['especificaciones'][$i] ?? '')), 0, 255) ?: null,
            'costo_estimado' => $costo === '' ? null : (float)str_replace(',', '.', $costo),
        ];
    }
    return $out;
}

function guardar_lineas($id, $lineas) {
    db()->prepare('DELETE FROM solicitudes_lineas WHERE solicitud_id = ?')->execute([$id]);
    $ins = db()->prepare('INSERT INTO solicitudes_lineas (solicitud_id, categoria, descripcion, cantidad, unidad, especificaciones, costo_estimado) VALUES (?,?,?,?,?,?,?)');
    foreach ($lineas as $l) $ins->execute([$id, $l['categoria'], $l['descripcion'], $l['cantidad'], $l['unidad'], $l['especificaciones'], $l['costo_estimado']]);
}

// se corrige mientras no este aprobada (o si la rechazaron, para volver a enviarla)
function puede_editar_sol($s) {
    global $yo, $aprueba;
    if (!in_array($s['estado'], ['por_aprobar', 'rechazada'], true)) return false;
    return $aprueba || (int)$s['solicitado_por'] === $yo;
}

// ---------- acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $acc = $_POST['accion'] ?? '';
    $s = !empty($_POST['id']) ? solicitud($_POST['id']) : null;
    if ($acc !== 'crear' && !$s) ir_sol('Esa solicitud no existe', 'error');

    if ($acc === 'crear' || $acc === 'editar') {
        if ($acc === 'editar' && !puede_editar_sol($s)) ir_sol('Ya está aprobada: no se puede cambiar', 'error', $s['id']);
        $lineas = leer_lineas($cats);
        if (!$lineas) ir_sol('Escribe al menos un artículo', 'error', $s['id'] ?? 0);
        if (count($lineas) > MAX_LINEAS) ir_sol('El formato tiene ' . MAX_LINEAS . ' renglones: haz otra solicitud con el resto', 'error', $s['id'] ?? 0);
        $trabajo = mb_substr(trim(preg_replace('/\s+/', ' ', $_POST['trabajo_realizado'] ?? '')), 0, 255);
        if ($trabajo === '') ir_sol('Escribe el trabajo a realizar (para qué se necesita)', 'error', $s['id'] ?? 0);
        $tipo = isset($tipos[$_POST['tipo_compra'] ?? '']) ? $_POST['tipo_compra'] : 'consumibles';
        $col = (int)($_POST['colaborador_id'] ?? 0) ?: null;
        $item = null;
        if (($et = trim($_POST['item'] ?? '')) !== '') {
            $st = db()->prepare('SELECT id FROM items WHERE etiqueta = ?');
            $st->execute([preg_replace('/\s.*$/', '', $et)]);
            $item = $st->fetchColumn() ?: null;
            if (!$item) ir_sol('No encontré el equipo “' . $et . '”', 'error', $s['id'] ?? 0);
        }
        $datos = [$tipo, $trabajo, trim($_POST['motivo'] ?? '') ?: null, ($_POST['urgencia'] ?? '') === 'urgente' ? 'urgente' : 'normal', $col, $item];
        if ($acc === 'crear') {
            db()->prepare("INSERT INTO solicitudes (fecha, tipo_compra, trabajo_realizado, motivo, urgencia, colaborador_id, item_id, solicitado_por, estado, token) VALUES (CURDATE(),?,?,?,?,?,?,?, 'por_aprobar', ?)")
                ->execute([...$datos, $yo, token_solicitud()]);
            $id = (int)db()->lastInsertId();
            $reenvio = false;
        } else {
            $id = (int)$s['id'];
            $reenvio = $s['estado'] === 'rechazada';
            db()->prepare("UPDATE solicitudes SET tipo_compra = ?, trabajo_realizado = ?, motivo = ?, urgencia = ?, colaborador_id = ?, item_id = ?, estado = 'por_aprobar', rechazo_motivo = NULL, actualizado = NOW() WHERE id = ?")
                ->execute([...$datos, $id]);
        }
        guardar_lineas($id, $lineas);
        evento_solicitud($id, $acc === 'crear' ? 'Radicó la solicitud (queda por aprobar)' : ($reenvio ? 'La corrigió y la volvió a enviar para aprobación' : 'Editó la solicitud'));
        if ($acc === 'crear' || $reenvio) avisar_solicitud($id, 'radicada');
        ir_sol($acc === 'crear' ? 'Solicitud radicada: ya llegó el aviso para aprobarla' : 'Solicitud guardada', 'ok', $id);
    }

    if ($acc === 'aprobar' || $acc === 'rechazar') {
        if (!$aprueba) ir_sol('Solo Paolo o Carlos pueden aprobar', 'error', $s['id']);
        if ($s['estado'] !== 'por_aprobar') ir_sol('Esa solicitud no está por aprobar', 'error', $s['id']);
        if ($acc === 'aprobar') {
            db()->prepare("UPDATE solicitudes SET estado = 'aprobada', aprobado_por = ?, aprobado_en = NOW(), fecha_estado = NOW(), token = COALESCE(token, ?), actualizado = NOW() WHERE id = ? AND estado = 'por_aprobar'")
                ->execute([$yo, token_solicitud(), $s['id']]);
            evento_solicitud($s['id'], 'La aprobó: el formato queda liberado para imprimir' . (trim($_POST['nota'] ?? '') !== '' ? "\n" . trim($_POST['nota']) : ''));
            avisar_solicitud($s['id'], 'aprobada', trim($_POST['nota'] ?? ''));
            ir_sol('Aprobada: el auxiliar ya puede imprimir el formato', 'ok', $s['id']);
        }
        $motivo = trim($_POST['nota'] ?? '');
        if ($motivo === '') ir_sol('Escribe por qué la rechazas', 'error', $s['id']);
        db()->prepare("UPDATE solicitudes SET estado = 'rechazada', rechazo_motivo = ?, aprobado_por = ?, aprobado_en = NOW(), fecha_estado = NOW(), actualizado = NOW() WHERE id = ?")
            ->execute([$motivo, $yo, $s['id']]);
        evento_solicitud($s['id'], "La rechazó.\nMotivo: " . $motivo);
        avisar_solicitud($s['id'], 'rechazada', $motivo);
        ir_sol('Rechazada: le llegó el aviso a quien la radicó', 'ok', $s['id']);
    }

    if ($acc === 'liberar_pdf') {
        // si el PDF se perdio o no imprimio, Paolo o Carlos lo pueden volver a liberar (queda en el historial)
        if (!$aprueba || !$s['impreso_en']) ir_sol('No hay nada que liberar', 'error', $s['id']);
        $motivo = trim($_POST['nota'] ?? '');
        if ($motivo === '') ir_sol('Escribe por qué se vuelve a liberar', 'error', $s['id']);
        db()->prepare('UPDATE solicitudes SET impreso_en = NULL, impreso_por = NULL WHERE id = ?')->execute([$s['id']]);
        evento_solicitud($s['id'], "Volvió a liberar el PDF para imprimir.\nMotivo: " . $motivo);
        ir_sol('El PDF quedó liberado otra vez (una sola impresión más)', 'ok', $s['id']);
    }

    if ($acc === 'estado') {
        $nuevo = $_POST['estado'] ?? '';
        $permitidos = ['pendiente', 'en_compra', 'recibida', 'cancelada'];
        if (!in_array($nuevo, $permitidos, true)) ir_sol('Escoge el estado', 'error', $s['id']);
        if (in_array($s['estado'], ['por_aprobar', 'rechazada'], true) && $nuevo !== 'cancelada') ir_sol('Primero tiene que estar aprobada', 'error', $s['id']);
        if ($nuevo !== 'cancelada' && $s['estado'] === 'aprobada' && !$s['impreso_en']) ir_sol('Primero imprime el formato', 'error', $s['id']);
        $nota = trim($_POST['nota'] ?? '');
        $costo = trim(str_replace(['$', '.', ' '], '', $_POST['costo'] ?? ''));
        $costo = $costo === '' ? $s['costo_total'] : (float)str_replace(',', '.', $costo);
        $frec = $nuevo === 'recibida' ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha_recibida'] ?? '') ? $_POST['fecha_recibida'] : date('Y-m-d')) : null;
        db()->prepare('UPDATE solicitudes SET estado = ?, fecha_estado = NOW(), fecha_recibida = ?, costo_total = ?, actualizado = NOW() WHERE id = ?')
            ->execute([$nuevo, $frec, $costo, $s['id']]);
        if ($a = guardar_archivo_subido('factura_archivo', 'solicitudes')) db()->prepare('UPDATE solicitudes SET factura_archivo = ? WHERE id = ?')->execute([$a, $s['id']]);
        if ($a = guardar_archivo_subido('formato_archivo', 'solicitudes')) db()->prepare('UPDATE solicitudes SET formato_archivo = ? WHERE id = ?')->execute([$a, $s['id']]);
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
        evento_solicitud($s['id'], $nota !== '' ? $nota : 'Adjuntó la foto del formato firmado');
        ir_sol('Guardado', 'ok', $s['id']);
    }

    if ($acc === 'borrar') {
        if (!puede_editar_sol($s)) ir_sol('Ya está aprobada: no se puede borrar. Si no va, cancélala', 'error', $s['id']);
        foreach (['solicitudes_lineas', 'solicitudes_eventos'] as $t) db()->prepare("DELETE FROM $t WHERE solicitud_id = ?")->execute([$s['id']]);
        db()->prepare('DELETE FROM solicitudes WHERE id = ?')->execute([$s['id']]);
        ir_sol('Solicitud borrada');
    }
    ir_sol();
}

// ---------- PDF para imprimir: sale UNA sola vez, despues de aprobada ----------
if (!empty($_GET['pdf'])) {
    $s = solicitud($_GET['pdf']);
    if (!$s) ir_sol('Esa solicitud no existe', 'error');
    if (!in_array($s['estado'], ['aprobada', 'pendiente', 'en_compra', 'recibida'], true) || !$s['aprobado_por']) ir_sol('El formato se libera cuando Paolo o Carlos aprueben la solicitud', 'error', $s['id']);
    if ((int)$s['solicitado_por'] !== $yo && !$aprueba) ir_sol('El formato lo imprime quien radicó la solicitud', 'error', $s['id']);
    // se marca como impreso en el mismo paso: si dos personas le dan al tiempo, solo a una le sale
    $st = db()->prepare('UPDATE solicitudes SET impreso_en = NOW(), impreso_por = ? WHERE id = ? AND impreso_en IS NULL');
    $st->execute([$yo, $s['id']]);
    if ($st->rowCount() !== 1) ir_sol('Este formato ya se imprimió' . ($s['impreso_en'] ? ' el ' . fecha_hora($s['impreso_en']) . ($s['impresor'] ? ' (' . $s['impresor'] . ')' : '') : '') . '. Solo sale una vez', 'error', $s['id']);
    require_once __DIR__ . '/inc/formato_solicitud.php';
    try {
        $pdf = pdf_formato_solicitud($s, lineas_solicitud($s['id']), (string)$s['aprobador'], (string)$s['solicitante']);
    } catch (Throwable $e) {
        db()->prepare('UPDATE solicitudes SET impreso_en = NULL, impreso_por = NULL WHERE id = ?')->execute([$s['id']]);
        error_log('pdf solicitud: ' . $e->getMessage());
        ir_sol('No se pudo generar el PDF. Intenta otra vez', 'error', $s['id']);
    }
    evento_solicitud($s['id'], 'Descargó el formato para imprimir (código ' . $s['token'] . ')');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . codigo_solicitud($s) . '_requerimiento.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store, private');
    echo $pdf;
    exit;
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
    $f1 = [['Código', 'Radicada', 'Tipo', 'Gestionó (auxiliar)', 'Aprobó', 'Trabajo', 'Artículos', 'Unidades', 'Para', 'Equipo', 'Estado', 'Impresa', 'Recibida', 'Días', 'Estimado', 'Costo real', 'Token']];
    foreach ($sols as $s) $f1[] = [codigo_solicitud($s), fecha_corta($s['fecha']), $tipos[$s['tipo_compra']] ?? $s['tipo_compra'], $s['solicitante'], $s['aprobador'], $s['trabajo_realizado'], $s['resumen'], (int)$s['unidades'], $s['colaborador'], $s['item_etiqueta'],
        $est[$s['estado']][0], fecha_hora($s['impreso_en']), fecha_corta($s['fecha_recibida']), dias_solicitud($s), $s['estimado'] !== null ? (float)$s['estimado'] : '', $s['costo_total'] !== null ? (float)$s['costo_total'] : '', $s['token']];
    $st = db()->prepare("SELECT l.categoria, l.descripcion, COUNT(DISTINCT l.solicitud_id) veces, SUM(l.cantidad) unidades, MAX(s.fecha) ultima FROM solicitudes_lineas l JOIN solicitudes s ON s.id = l.solicitud_id WHERE YEAR(s.fecha) = ? AND s.estado NOT IN ('cancelada','rechazada') GROUP BY l.categoria, l.descripcion ORDER BY veces DESC, unidades DESC");
    $st->execute([$anio]);
    $f2 = [['Categoría', 'Artículo', 'Veces', 'Unidades', 'Última vez']];
    foreach ($st as $r) $f2[] = [$r['categoria'], $r['descripcion'], (int)$r['veces'], (int)$r['unidades'], fecha_corta($r['ultima'])];
    xlsx_enviar('Solicitudes_administracion_' . $anio . '.xlsx', [
        'Solicitudes' => ['anchos' => [11, 11, 13, 22, 20, 40, 50, 9, 22, 12, 30, 17, 11, 7, 12, 12, 11], 'filas' => $f1, 'negrita' => [0]],
        'Lo que se pide' => ['anchos' => [30, 45, 8, 10, 12], 'filas' => $f2, 'negrita' => [0]],
    ]);
    exit;
}

// ---------- datos de la vista ----------
$vista = ($_GET['vista'] ?? '') === 'resumen' ? 'resumen' : 'lista';
$filtro = $_GET['estado'] ?? 'abiertas';
$q = trim($_GET['q'] ?? '');
$colabs = db()->query('SELECT id, nombre FROM colaboradores WHERE activo = 1 ORDER BY nombre')->fetchAll();
$equipos = db()->query("SELECT etiqueta, nombre FROM items WHERE etiqueta IS NOT NULL AND etiqueta <> '' ORDER BY etiqueta LIMIT 3000")->fetchAll();
$descs = db()->query('SELECT descripcion, MAX(categoria) categoria, MAX(unidad) unidad, COUNT(*) n FROM solicitudes_lineas GROUP BY descripcion ORDER BY n DESC, descripcion LIMIT 500')->fetchAll();
$abiertas = (int)db()->query("SELECT COUNT(*) FROM solicitudes WHERE estado IN ('por_aprobar','aprobada','pendiente','en_compra')")->fetchColumn();
$por_aprobar = (int)db()->query("SELECT COUNT(*) FROM solicitudes WHERE estado = 'por_aprobar'")->fetchColumn();

$ver = !empty($_GET['ver']) ? solicitud($_GET['ver']) : null;
$editar = !empty($_GET['editar']) ? solicitud($_GET['editar']) : null;
if ($editar && !puede_editar_sol($editar)) $editar = null;
$nueva = !empty($_GET['nueva']);

function form_lineas($cats, $lineas) {
    if (!$lineas) $lineas = [['categoria' => '', 'descripcion' => '', 'cantidad' => 1, 'unidad' => 'Unidad', 'especificaciones' => '', 'costo_estimado' => null]];
    $h = '<div class="sol-cab"><span>Cant.</span><span>U. medida</span><span>Nombre del artículo</span><span>Especificaciones</span><span>Costo est.</span><span>Categoría</span><span></span></div><div class="sol-lineas" data-lineas data-max="' . MAX_LINEAS . '">';
    foreach ($lineas as $l) {
        $h .= '<div class="sol-linea"><input type="number" name="cantidad[]" min="1" max="9999" value="' . (int)$l['cantidad'] . '" title="Cantidad">'
            . '<input name="unidad[]" list="lista-unidades" maxlength="30" value="' . e($l['unidad'] ?? 'Unidad') . '" title="Unidad de medida">'
            . '<input name="descripcion[]" list="lista-descs" maxlength="200" placeholder="Ej. Cable HDMI" value="' . e($l['descripcion']) . '" data-desc title="Nombre del artículo">'
            . '<input name="especificaciones[]" maxlength="255" placeholder="Ej. 3 m, macho-macho" value="' . e($l['especificaciones'] ?? '') . '" title="Especificaciones">'
            . '<input name="costo_estimado[]" inputmode="numeric" placeholder="$" value="' . ($l['costo_estimado'] !== null && $l['costo_estimado'] !== '' ? number_format((float)$l['costo_estimado'], 0, ',', '.') : '') . '" title="Costo estimado (opcional)">'
            . '<select name="categoria[]" title="Categoría (para el resumen, no sale en el formato)"><option value="">Categoría…</option>';
        foreach ($cats as $c) $h .= '<option' . (($l['categoria'] ?? '') === $c ? ' selected' : '') . '>' . e($c) . '</option>';
        $h .= '</select><button type="button" class="btn btn-borde btn-sm" data-quitar-linea title="Quitar">×</button></div>';
    }
    return $h . '</div><button type="button" class="btn btn-borde btn-sm" data-agregar-linea>+ Otro artículo</button> <small class="tenue">Máximo ' . MAX_LINEAS . ' (los renglones del formato)</small>';
}

function form_solicitud($s, $cats, $colabs, $lineas, $tipos) {
    $tc = $s['tipo_compra'] ?? 'consumibles';
    $h = '<div class="rejilla">'
        . '<div class="campo" style="grid-column:1/-1"><label>Tipo de compra</label><div class="sol-tipos">';
    foreach ($tipos as $k => $n) $h .= '<label><input type="radio" name="tipo_compra" value="' . $k . '"' . ($tc === $k ? ' checked' : '') . '> ' . e($n) . '</label>';
    $h .= '</div></div>'
        . '<div class="campo" style="grid-column:1/-1"><label>Trabajo realizado (para qué se necesita) · sale en el formato</label><input name="trabajo_realizado" required maxlength="255" value="' . e($s['trabajo_realizado'] ?? '') . '" placeholder="Ej. Cambio de cable del monitor de facturación"></div>'
        . '<div class="campo" style="grid-column:1/-1"><label>Artículos</label>' . form_lineas($cats, $lineas) . '</div>'
        . '<div class="campo"><label>Para quién (opcional)</label><select name="colaborador_id"><option value="">—</option>';
    foreach ($colabs as $c) $h .= '<option value="' . $c['id'] . '"' . ((int)($s['colaborador_id'] ?? 0) === (int)$c['id'] ? ' selected' : '') . '>' . e($c['nombre']) . '</option>';
    $h .= '</select></div><div class="campo"><label>Equipo (opcional)</label><input name="item" list="lista-equipos" value="' . e($s['item_etiqueta'] ?? '') . '" placeholder="Etiqueta, ej. AGR-0004"></div>'
        . '<div class="campo"><label>Urgencia</label><select name="urgencia"><option value="normal">Normal</option><option value="urgente"' . (($s['urgencia'] ?? '') === 'urgente' ? ' selected' : '') . '>Urgente</option></select></div>'
        . '<div class="campo" style="grid-column:1/-1"><label>Nota para quien aprueba (opcional, no sale en el formato)</label><textarea name="motivo" rows="2" placeholder="Algo que Paolo o Carlos deban saber para aprobarla">' . e($s['motivo'] ?? '') . '</textarea></div>'
        . '</div>';
    return $h;
}

$titulo = 'Solicitudes';
$menu = 'solicitudes';
require __DIR__ . '/inc/header.php';
?>
<datalist id="lista-equipos"><?php foreach ($equipos as $q2): ?><option value="<?= e($q2['etiqueta']) ?>"><?= e($q2['nombre']) ?></option><?php endforeach; ?></datalist>
<datalist id="lista-descs"><?php foreach ($descs as $d): ?><option value="<?= e($d['descripcion']) ?>" data-cat="<?= e($d['categoria']) ?>" data-unidad="<?= e($d['unidad']) ?>"><?= e($d['categoria']) ?> · pedido <?= (int)$d['n'] ?> vez/veces</option><?php endforeach; ?></datalist>
<datalist id="lista-unidades"><?php foreach (unidades_medida() as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>

<div class="cabecera">
  <div><h1>Solicitudes a administración</h1><small>Requerimiento de materiales/suministros (SGSST-AC-F55): se radica aquí, lo aprueban Paolo o Carlos y se imprime para entregarlo a administración</small></div>
  <div class="acciones"><a class="btn btn-primario" href="?nueva=1">+ Radicar solicitud</a></div>
</div>

<?php if ($aprueba && $por_aprobar): ?><div class="aviso" style="border-color:var(--cat-linea);background:var(--cat-suave)"><b><?= $por_aprobar ?> solicitud<?= $por_aprobar > 1 ? 'es' : '' ?> por aprobar.</b> <a href="?estado=por_aprobar">Revisarlas</a></div><?php endif; ?>

<div class="pestanas-herr" style="margin:4px 0 14px">
  <a href="solicitudes.php" class="<?= $vista === 'lista' ? 'activa' : '' ?>">Solicitudes<?= $abiertas ? '<small>' . $abiertas . ' abiertas</small>' : '' ?></a>
  <a href="?vista=resumen" class="<?= $vista === 'resumen' ? 'activa' : '' ?>">Resumen: qué se pide y cuánto</a>
</div>

<?php if ($vista === 'lista'):
  $where = []; $args = [];
  if ($filtro === 'abiertas') $where[] = "s.estado IN ('por_aprobar','aprobada','pendiente','en_compra')";
  elseif (isset($est[$filtro])) { $where[] = 's.estado = ?'; $args[] = $filtro; }
  if (!in_array($filtro, ['abiertas', 'por_aprobar', 'aprobada'], true)) { $where[] = 'YEAR(s.fecha) = ?'; $args[] = $anio; }
  if ($q !== '') { $where[] = '(EXISTS (SELECT 1 FROM solicitudes_lineas l WHERE l.solicitud_id = s.id AND (l.descripcion LIKE ? OR l.categoria LIKE ? OR l.especificaciones LIKE ?)) OR s.trabajo_realizado LIKE ? OR c.nombre LIKE ? OR i.etiqueta LIKE ? OR s.token = ?)'; array_push($args, ...array_fill(0, 6, '%' . $q . '%')); $args[] = strtoupper($q); }
  $st = db()->prepare(sql_solicitudes() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY s.estado = 'por_aprobar' DESC, s.fecha DESC, s.id DESC LIMIT 500");
  $st->execute($args);
  $lista = $st->fetchAll(); ?>
  <form class="filtros" method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
    <select name="estado" onchange="this.form.submit()">
      <option value="abiertas"<?= $filtro === 'abiertas' ? ' selected' : '' ?>>Abiertas</option>
      <option value="todas"<?= $filtro === 'todas' ? ' selected' : '' ?>>Todas</option>
      <?php foreach ($est as $k => [$n]): ?><option value="<?= $k ?>"<?= $filtro === $k ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
    </select>
    <?php if (!in_array($filtro, ['abiertas', 'por_aprobar', 'aprobada'], true)): ?><select name="anio" onchange="this.form.submit()"><?php foreach ($anios as $a): ?><option<?= (int)$a === $anio ? ' selected' : '' ?>><?= (int)$a ?></option><?php endforeach; ?></select><?php endif; ?>
    <input name="q" value="<?= e($q) ?>" placeholder="Buscar: artículo, trabajo, persona, equipo o código del formato" style="flex:1 1 260px">
    <button class="btn btn-borde">Buscar</button>
  </form>
  <div class="tabla-envoltura"><table class="tabla">
    <thead><tr><th>Solicitud</th><th>Qué se pidió</th><th>Gestión</th><th>Estado</th><th style="text-align:right">Días</th></tr></thead><tbody>
    <?php if (!$lista): ?><tr><td colspan="5" class="vacio"><?= $filtro === 'abiertas' ? 'No hay solicitudes abiertas.' : 'No hay solicitudes con ese filtro.' ?></td></tr><?php endif; ?>
    <?php foreach ($lista as $s): $d = dias_solicitud($s); $abierta = in_array($s['estado'], estados_abiertos(), true); ?>
      <tr><td><a href="?ver=<?= $s['id'] ?>" class="mono"><b><?= codigo_solicitud($s) ?></b></a><?= $s['urgencia'] === 'urgente' ? ' <span class="chip chip-danado">Urgente</span>' : '' ?><small><?= fecha_corta($s['fecha']) ?> · <?= e($tipos[$s['tipo_compra']] ?? '') ?></small></td>
        <td><?= e($s['resumen']) ?><?= $s['trabajo_realizado'] ? '<small>' . e(mb_strimwidth($s['trabajo_realizado'], 0, 90, '…')) . '</small>' : '' ?></td>
        <td><small style="margin:0">Radicó: <?= e(explode(' ', (string)$s['solicitante'])[0]) ?></small><?= $s['aprobador'] ? '<small style="margin:0">' . ($s['estado'] === 'rechazada' ? 'Rechazó' : 'Aprobó') . ': ' . e(explode(' ', (string)$s['aprobador'])[0]) . '</small>' : '' ?></td>
        <td><span class="chip <?= $est[$s['estado']][1] ?>"><?= e($est[$s['estado']][0]) ?></span><?= $s['estado'] === 'aprobada' ? '<small>' . ($s['impreso_en'] ? 'Impresa, falta entregar' : 'Falta imprimir') . '</small>' : '' ?><?= $s['costo_total'] !== null ? '<small>' . pesos_sol($s['costo_total']) . '</small>' : '' ?></td>
        <td class="mono" style="text-align:right;<?= $abierta && $d >= (int)env('SOLICITUDES_DIAS_ALERTA', 8) ? 'color:var(--rojo);font-weight:700' : '' ?>"><?= $d ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>

<?php else:
  // ---------- resumen del año ----------
  $no = "('cancelada','rechazada')";
  $base = "FROM solicitudes s WHERE YEAR(s.fecha) = ? AND s.estado NOT IN $no";
  $one = function ($sql) use ($anio) { $st = db()->prepare($sql); $st->execute([$anio]); return $st->fetchColumn(); };
  $all = function ($sql) use ($anio) { $st = db()->prepare($sql); $st->execute([$anio]); return $st->fetchAll(); };
  $n_sol = (int)$one("SELECT COUNT(*) $base");
  $n_uni = (int)$one("SELECT COALESCE(SUM(l.cantidad),0) FROM solicitudes_lineas l JOIN solicitudes s ON s.id = l.solicitud_id WHERE YEAR(s.fecha) = ? AND s.estado NOT IN $no");
  $gasto = (float)$one("SELECT COALESCE(SUM(costo_total),0) $base AND s.estado = 'recibida'");
  $prom = $one("SELECT AVG(DATEDIFF(fecha_recibida, fecha)) $base AND s.estado = 'recibida' AND fecha_recibida IS NOT NULL");
  $rech = (int)$one("SELECT COUNT(*) FROM solicitudes s WHERE YEAR(s.fecha) = ? AND s.estado = 'rechazada'");
  $mes = array_fill(1, 12, 0);
  foreach ($all("SELECT MONTH(fecha) m, COUNT(*) n $base GROUP BY m") as $r) $mes[(int)$r['m']] = (int)$r['n'];
  $max_mes = max(1, max($mes));
  $por_cat = $all("SELECT l.categoria, COUNT(DISTINCT l.solicitud_id) veces, SUM(l.cantidad) unidades FROM solicitudes_lineas l JOIN solicitudes s ON s.id = l.solicitud_id WHERE YEAR(s.fecha) = ? AND s.estado NOT IN $no GROUP BY l.categoria ORDER BY veces DESC");
  $max_cat = max(1, (int)max(array_column($por_cat, 'veces') ?: [1]));
  $top = $all("SELECT l.descripcion, MAX(l.categoria) categoria, COUNT(DISTINCT l.solicitud_id) veces, SUM(l.cantidad) unidades, MAX(s.fecha) ultima FROM solicitudes_lineas l JOIN solicitudes s ON s.id = l.solicitud_id WHERE YEAR(s.fecha) = ? AND s.estado NOT IN $no GROUP BY l.descripcion ORDER BY veces DESC, unidades DESC LIMIT 20");
  $quien = $all("SELECT u.nombre, COUNT(*) n FROM solicitudes s JOIN usuarios u ON u.id = s.solicitado_por WHERE YEAR(s.fecha) = ? AND s.estado NOT IN $no GROUP BY u.nombre ORDER BY n DESC");
  $aprob = $all("SELECT u.nombre, SUM(s.estado <> 'rechazada') aprobadas, SUM(s.estado = 'rechazada') rechazadas FROM solicitudes s JOIN usuarios u ON u.id = s.aprobado_por WHERE YEAR(s.fecha) = ? GROUP BY u.nombre ORDER BY aprobadas DESC");
  $por_tipo = $all("SELECT tipo_compra, COUNT(*) n $base GROUP BY tipo_compra ORDER BY n DESC");
  $para = $all("SELECT c.nombre, COUNT(*) n FROM solicitudes s JOIN colaboradores c ON c.id = s.colaborador_id WHERE YEAR(s.fecha) = ? AND s.estado NOT IN $no GROUP BY c.nombre ORDER BY n DESC LIMIT 10");
  $meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']; ?>
  <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:12px"><input type="hidden" name="vista" value="resumen">
    <select name="anio" onchange="this.form.submit()"><?php foreach ($anios as $a): ?><option<?= (int)$a === $anio ? ' selected' : '' ?>><?= (int)$a ?></option><?php endforeach; ?></select>
    <a class="btn btn-borde" href="?anio=<?= $anio ?>&amp;formato=xlsx">Descargar Excel <?= $anio ?></a>
  </form>
  <div class="kpis">
    <div class="kpi"><small>Solicitudes</small><b><?= $n_sol ?></b><span class="tenue"><?= $rech ? $rech . ' rechazada' . ($rech > 1 ? 's' : '') . ' aparte' : 'en ' . $anio ?></span></div>
    <div class="kpi"><small>Unidades pedidas</small><b><?= $n_uni ?></b></div>
    <div class="kpi"><small>Gasto registrado</small><b style="font-size:22px"><?= pesos_sol($gasto) ?: '$0' ?></b><span class="tenue">de las recibidas con costo</span></div>
    <div class="kpi"><small>Tardan en llegar</small><b><?= $prom !== null ? round((float)$prom, 1) : '—' ?></b><span class="tenue">días en promedio</span></div>
    <div class="kpi" style="<?= $abiertas ? 'border-left-color:var(--rojo)' : '' ?>"><small>Abiertas ahora</small><b style="<?= $abiertas ? 'color:var(--rojo)' : '' ?>"><?= $abiertas ?></b><?= $por_aprobar ? '<span class="tenue">' . $por_aprobar . ' por aprobar</span>' : '' ?></div>
  </div>
  <div class="dos-col">
    <div>
      <div class="tarjeta"><h2>Lo que más se pide · <?= $anio ?></h2>
        <?php if (!$top): ?><p class="tenue">Todavía no hay solicitudes en <?= $anio ?>.</p><?php else: ?>
        <div class="tabla-envoltura" style="border:0"><table class="tabla"><thead><tr><th>Artículo</th><th style="text-align:right">Veces</th><th style="text-align:right">Unidades</th><th>Última</th></tr></thead><tbody>
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
        <?php if ($por_tipo): ?><p class="tenue" style="font-size:12px;margin-bottom:0">Tipo de compra: <?= e(implode(' · ', array_map(fn($r) => ($tipos[$r['tipo_compra']] ?? $r['tipo_compra']) . ' ' . $r['n'], $por_tipo))) ?></p><?php endif; ?>
      </div>
      <div class="tarjeta"><h2>Quién radica (auxiliar)</h2>
        <?php foreach ($quien as $r): ?><div class="fila-dato"><span><?= e($r['nombre']) ?></span><b class="mono"><?= (int)$r['n'] ?></b></div><?php endforeach; ?>
        <?php if (!$quien): ?><p class="tenue">—</p><?php endif; ?>
      </div>
      <?php if ($aprob): ?><div class="tarjeta"><h2>Quién aprueba</h2>
        <?php foreach ($aprob as $r): ?><div class="fila-dato"><span><?= e($r['nombre']) ?></span><span class="mono"><b><?= (int)$r['aprobadas'] ?></b><?= $r['rechazadas'] ? ' <span class="tenue">· ' . (int)$r['rechazadas'] . ' rech.</span>' : '' ?></span></div><?php endforeach; ?>
      </div><?php endif; ?>
      <?php if ($para): ?><div class="tarjeta"><h2>Para quién se pide más</h2>
        <?php foreach ($para as $r): ?><div class="fila-dato"><span><?= e($r['nombre']) ?></span><b class="mono"><?= (int)$r['n'] ?></b></div><?php endforeach; ?>
      </div><?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($nueva || $editar): $s0 = $editar ?: []; ?>
<div class="modal-fondo" data-cerrar="<?= $editar ? '?ver=' . $editar['id'] : 'solicitudes.php' ?>">
<form method="post" class="tarjeta modal-caja sol-modal" role="dialog" aria-modal="true"><?= csrf_campo() ?><input type="hidden" name="accion" value="<?= $editar ? 'editar' : 'crear' ?>"><?php if ($editar): ?><input type="hidden" name="id" value="<?= $editar['id'] ?>"><?php endif; ?>
  <a class="modal-x" href="<?= $editar ? '?ver=' . $editar['id'] : 'solicitudes.php' ?>" aria-label="Cerrar">×</a>
  <h2><?= $editar ? ($editar['estado'] === 'rechazada' ? 'Corregir y volver a enviar ' : 'Editar ') . codigo_solicitud($editar) : 'Radicar solicitud' ?></h2>
  <?php if ($editar && $editar['estado'] === 'rechazada'): ?><div class="aviso aviso-error" style="margin-top:-4px">La rechazó <?= e($editar['aprobador']) ?>: <?= e($editar['rechazo_motivo']) ?></div>
  <?php elseif (!$editar): ?><p class="tenue" style="margin-top:-6px">Departamento: <b>SISTEMAS</b>. Al radicarla llega el aviso para aprobarla; cuando la aprueben, podrás imprimir el formato (una sola vez).</p><?php endif; ?>
  <?= form_solicitud($s0, $cats, $colabs, $editar ? lineas_solicitud($editar['id']) : [], $tipos) ?>
  <p style="margin-bottom:0;display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primario"><?= $editar ? ($editar['estado'] === 'rechazada' ? 'Volver a enviar para aprobación' : 'Guardar') : 'Radicar y enviar para aprobación' ?></button> <a class="btn btn-borde" href="<?= $editar ? '?ver=' . $editar['id'] : 'solicitudes.php' ?>">Cancelar</a>
  <?php if ($editar): ?><button class="btn btn-borde" style="margin-left:auto;color:var(--rojo)" name="accion" value="borrar" formnovalidate onclick="return confirm('¿Borrar esta solicitud?')">Borrar</button><?php endif; ?></p>
</form>
</div>

<?php elseif ($ver): $lin = lineas_solicitud($ver['id']); $evs = eventos_solicitud($ver['id']); $d = dias_solicitud($ver);
  $es_mia = (int)$ver['solicitado_por'] === $yo;
  $puede_pdf = in_array($ver['estado'], ['aprobada', 'pendiente', 'en_compra', 'recibida'], true) && $ver['aprobado_por'] && !$ver['impreso_en'] && ($es_mia || $aprueba); ?>
<div class="modal-fondo" data-cerrar="solicitudes.php">
<div class="tarjeta modal-caja caso sol-modal" role="dialog" aria-modal="true">
  <a class="modal-x" href="solicitudes.php" aria-label="Cerrar">×</a>
  <div class="tenue mono" style="font-size:12px">Radicada <?= fecha_corta($ver['fecha']) ?> · <?= e($tipos[$ver['tipo_compra']] ?? '') ?> · Dpto SISTEMAS</div>
  <h2 style="text-transform:none;font-size:18px;margin:2px 0 8px"><?= codigo_solicitud($ver) ?> <span class="chip <?= $est[$ver['estado']][1] ?>"><?= e($est[$ver['estado']][0]) ?></span><?= $ver['urgencia'] === 'urgente' ? ' <span class="chip chip-danado">Urgente</span>' : '' ?></h2>

  <div class="sol-gestion">
    <div><small>Gestionó (auxiliar)</small><b><?= e($ver['solicitante']) ?></b><span><?= fecha_corta($ver['fecha']) ?></span></div>
    <div><small><?= $ver['estado'] === 'rechazada' ? 'Rechazó' : 'Autorizó' ?></small><b><?= $ver['aprobador'] ? e($ver['aprobador']) : '<span class="tenue">Pendiente</span>' ?></b><span><?= $ver['aprobado_en'] ? fecha_hora($ver['aprobado_en']) : '' ?></span></div>
    <div><small>Formato impreso</small><b><?= $ver['impreso_en'] ? e($ver['impresor']) : '<span class="tenue">No</span>' ?></b><span><?= $ver['impreso_en'] ? fecha_hora($ver['impreso_en']) : '' ?></span></div>
    <div><small>Código del formato</small><b class="mono"><?= e($ver['token']) ?></b><span><?= in_array($ver['estado'], estados_abiertos(), true) ? 'Lleva ' . $d . ' día' . ($d === 1 ? '' : 's') : 'Tardó ' . $d . ' día' . ($d === 1 ? '' : 's') ?></span></div>
  </div>

  <?php if ($ver['trabajo_realizado']): ?><div class="caso-obs" style="margin-bottom:10px"><b>Trabajo realizado:</b> <?= e($ver['trabajo_realizado']) ?></div><?php endif; ?>
  <div class="tabla-envoltura"><table class="tabla"><thead><tr><th style="width:50px;text-align:right">Cant.</th><th>U. medida</th><th>Artículo</th><th>Especificaciones</th><th style="text-align:right">Costo est.</th><?php if ($ver['estado'] === 'recibida'): ?><th></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($lin as $l): ?><tr><td class="mono" style="text-align:right"><b><?= (int)$l['cantidad'] ?></b></td><td><?= e($l['unidad']) ?></td><td><?= e($l['descripcion']) ?><small><?= e($l['categoria']) ?></small></td><td class="tenue"><?= e($l['especificaciones']) ?></td><td class="mono" style="text-align:right"><?= pesos_sol($l['costo_estimado']) ?></td>
      <?php if ($ver['estado'] === 'recibida'): ?><td style="text-align:right"><a class="btn btn-borde btn-sm" href="item.php?nombre=<?= urlencode($l['descripcion']) ?>" title="Crear la ficha en el inventario">+ Inventario</a></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($ver['estimado']): ?><p class="tenue" style="text-align:right;margin:6px 0 0">Total estimado: <b><?= pesos_sol($ver['estimado']) ?></b><?= $ver['costo_total'] !== null ? ' · costo real: <b>' . pesos_sol($ver['costo_total']) . '</b>' : '' ?></p><?php endif; ?>
  <?php if ($ver['motivo']): ?><div class="caso-obs" style="margin-top:10px"><b>Nota para quien aprueba:</b> <?= nl2br(e($ver['motivo'])) ?></div><?php endif; ?>
  <?php if ($ver['estado'] === 'rechazada' && $ver['rechazo_motivo']): ?><div class="aviso aviso-error" style="margin-top:10px"><b>Rechazada:</b> <?= e($ver['rechazo_motivo']) ?></div><?php endif; ?>

  <?php // ---- lo que toca hacer ahora, segun el estado y quien la mira ?>
  <?php if ($ver['estado'] === 'por_aprobar'): ?>
    <?php if ($aprueba): ?>
    <form method="post" class="sol-paso"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= $ver['id'] ?>">
      <h3 class="caso-sub" style="margin-top:0">Aprobar</h3>
      <input name="nota" maxlength="1000" placeholder="Nota (obligatoria si la rechazas)">
      <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap"><button class="btn btn-primario" name="accion" value="aprobar">✔ Aprobar y liberar el formato</button><button class="btn btn-borde" name="accion" value="rechazar" style="color:var(--rojo)">✘ Rechazar</button></div>
    </form>
    <?php else: ?><div class="sol-paso"><b>Esperando aprobación.</b> Ya llegó el aviso; cuando la aprueben te llega a ti y aquí aparece el botón para imprimir.</div><?php endif; ?>
  <?php elseif ($ver['estado'] === 'aprobada' && $puede_pdf): ?>
    <div class="sol-paso sol-paso-pdf"><b>Aprobada por <?= e($ver['aprobador']) ?>.</b> Imprime el formato y entrégalo a administración. <b>El PDF sale una sola vez:</b> ábrelo e imprímelo de una (hoja carta, usa el de arriba y recorta por la línea).
      <div style="margin-top:8px"><a class="btn btn-primario" href="?pdf=<?= $ver['id'] ?>" target="_blank" data-pdf-unico>🖨 Abrir PDF para imprimir</a></div></div>
  <?php elseif ($ver['estado'] === 'aprobada' && !$ver['impreso_en']): ?>
    <div class="sol-paso">Aprobada. Falta que <?= e($ver['solicitante']) ?> imprima el formato.</div>
  <?php elseif ($ver['estado'] === 'aprobada'): ?>
    <div class="sol-paso">Formato impreso el <?= fecha_hora($ver['impreso_en']) ?>. Cuando se lo entregues a administración, márcalo abajo como <b>Entregada a administración</b>.</div>
  <?php endif; ?>
  <?php if ($ver['impreso_en'] && $aprueba && in_array($ver['estado'], ['aprobada', 'pendiente'], true)): ?>
    <details class="caso-transferir"><summary>Volver a liberar el PDF (si se perdió o no imprimió)</summary>
      <form method="post" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap"><?= csrf_campo() ?><input type="hidden" name="accion" value="liberar_pdf"><input type="hidden" name="id" value="<?= $ver['id'] ?>">
        <input name="nota" required maxlength="500" placeholder="Por qué (queda en el historial)" style="flex:1 1 240px"><button class="btn btn-borde btn-sm">Liberar una vez más</button></form>
    </details>
  <?php endif; ?>

  <p style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0 0">
    <?php if ($ver['formato_archivo']): ?><a class="btn btn-borde btn-sm" href="<?= e(url_archivo($ver['formato_archivo'])) ?>" target="_blank">📄 Formato firmado</a><?php endif; ?>
    <?php if ($ver['factura_archivo']): ?><a class="btn btn-borde btn-sm" href="<?= e(url_archivo($ver['factura_archivo'])) ?>" target="_blank">🧾 Factura</a><?php endif; ?>
    <?php if (puede_editar_sol($ver)): ?><a class="btn btn-borde btn-sm" href="?editar=<?= $ver['id'] ?>"><?= $ver['estado'] === 'rechazada' ? 'Corregir y volver a enviar' : 'Editar' ?></a><?php endif; ?>
  </p>

  <?php if (!in_array($ver['estado'], ['por_aprobar', 'rechazada'], true) && ($ver['estado'] !== 'aprobada' || $ver['impreso_en'])): ?>
  <h3 class="caso-sub">Seguimiento</h3>
  <form method="post" enctype="multipart/form-data" class="sol-estado"><?= csrf_campo() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= $ver['id'] ?>">
    <div class="rejilla">
      <div class="campo"><label>Estado</label><select name="estado"><?php foreach (['pendiente', 'en_compra', 'recibida', 'cancelada'] as $k): ?><option value="<?= $k ?>"<?= $ver['estado'] === $k || ($ver['estado'] === 'aprobada' && $k === 'pendiente') ? ' selected' : '' ?>><?= e($est[$k][0]) ?></option><?php endforeach; ?></select></div>
      <div class="campo"><label>Fecha en que se recibió</label><input type="date" name="fecha_recibida" max="<?= date('Y-m-d') ?>" value="<?= e($ver['fecha_recibida'] ?: date('Y-m-d')) ?>"></div>
      <div class="campo"><label>Costo real (si se sabe)</label><input name="costo" inputmode="numeric" placeholder="$" value="<?= $ver['costo_total'] !== null ? number_format((float)$ver['costo_total'], 0, ',', '.') : '' ?>"></div>
      <div class="campo"><label>Factura (opcional)</label><input type="file" name="factura_archivo" accept="image/*,application/pdf"></div>
      <div class="campo"><label>Foto del formato firmado (opcional)</label><input type="file" name="formato_archivo" accept="image/*,application/pdf" capture="environment"></div>
      <div class="campo"><label>Nota</label><input name="nota" maxlength="1000" placeholder="Ej.: la compran el viernes · llegó solo 1 de 2"></div>
    </div>
    <button class="btn btn-primario btn-sm">Guardar y avisar</button>
  </form>
  <?php endif; ?>

  <h3 class="caso-sub">Historial</h3>
  <div class="caso-notas">
    <?php foreach ($evs as $e): ?><div class="caso-nota"><div class="caso-quien"><b><?= e($e['nombre'] ?? 'Sistema') ?></b> <span class="tenue"><?= fecha_hora($e['creado']) ?></span></div><div><?= nl2br(e($e['texto'])) ?></div></div><?php endforeach; ?>
  </div>
  <form method="post" enctype="multipart/form-data" class="caso-form"><?= csrf_campo() ?><input type="hidden" name="accion" value="nota"><input type="hidden" name="id" value="<?= $ver['id'] ?>">
    <textarea name="nota" rows="1" placeholder="Agregar una nota (ej. hablé con administración, llega la otra semana)"></textarea>
    <button class="btn btn-borde btn-sm">Agregar</button>
  </form>
</div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
