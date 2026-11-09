<?php
// registro de un mantenimiento con firma a dedo del usuario del equipo
// formato agr-f-074
require __DIR__ . '/config.php';
requiere_modulo('mant');

$actividades_base = [
    'Limpieza interna y externa', 'Cambio de pasta termica', 'Actualizaciones del sistema', 'Revision de antivirus',
    'Limpieza de temporales', 'Revision de disco', 'Revision de bateria y cargador', 'Respaldo de informacion',
    'Revision de cables y perifericos', 'Limpieza de rodillos (impresora)', 'Hoja de prueba (impresora)',
];

$id = (int)($_GET['id'] ?? 0);
$error = '';

function cargar_mant($id) {
    $st = db()->prepare('SELECT m.*, i.etiqueta, i.nombre item_nombre, i.marca, i.modelo, i.serial, i.ubicacion, cat.nombre categoria,
        c.nombre colaborador, c.cedula, u.nombre tecnico, s.nombre sede
        FROM mantenimientos m JOIN items i ON i.id = m.item_id LEFT JOIN categorias cat ON cat.id = i.categoria_id
        LEFT JOIN colaboradores c ON c.id = m.colaborador_id LEFT JOIN usuarios u ON u.id = m.tecnico_id
        LEFT JOIN sedes s ON s.id = i.sede_id WHERE m.id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// nuevo
if (!$id) {
    requiere_admin();
    $st = db()->prepare('SELECT i.*, cat.nombre categoria, c.nombre responsable FROM items i LEFT JOIN categorias cat ON cat.id = i.categoria_id
        LEFT JOIN colaboradores c ON c.id = i.colaborador_id WHERE i.id = ?');
    $st->execute([(int)($_GET['item'] ?? 0)]);
    $item = $st->fetch();
    if (!$item) { http_response_code(404); exit('Item no existe'); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verificar_csrf();
        $fecha = $_POST['fecha'] ?? '';
        $tipo = ($_POST['tipo'] ?? '') === 'correctivo' ? 'correctivo' : 'preventivo';
        $acts = array_values(array_filter(array_map('trim', $_POST['act'] ?? [])));
        if (trim($_POST['otra'] ?? '') !== '') $acts[] = trim($_POST['otra']);
        $colab = (int)($_POST['colaborador_id'] ?? 0) ?: null;
        $firmar = ($_POST['firma_modo'] ?? 'ahora') === 'ahora' && $colab;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) $error = 'Fecha invalida';
        elseif (!$acts) $error = 'Marca al menos una actividad';
        if (!$error) {
            $pdo = db();
            $pdo->beginTransaction();
            $cons = siguiente_consecutivo_mant(substr($fecha, 0, 4));
            $pdo->prepare('INSERT INTO mantenimientos (consecutivo, item_id, fecha, tipo, actividades, observaciones, colaborador_id, tecnico_id, estado)
                VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$cons, $item['id'], $fecha, $tipo, implode("\n", $acts), trim($_POST['observaciones'] ?? '') ?: null,
                    $colab, usuario()['id'], $firmar ? 'pendiente' : 'sin_firma']);
            $mid = (int)$pdo->lastInsertId();
            // el preventivo reinicia el cronograma
            if ($tipo === 'preventivo') {
                $pdo->prepare('UPDATE items SET ultimo_mant = GREATEST(COALESCE(ultimo_mant, ?), ?), proximo_mant = NULL WHERE id = ?')->execute([$fecha, $fecha, $item['id']]);
            }
            if (!empty($_POST['quedo_ok']) && in_array($item['estado'], ['mantenimiento', 'danado'], true)) {
                // vuelve a como estaba: con persona, en su ubicacion o libre
                $fijas = implode(',', array_map(fn($c) => $pdo->quote($c), categorias_fijas()));
                $pdo->prepare("UPDATE items i LEFT JOIN categorias c ON c.id = i.categoria_id SET i.estado = IF(i.colaborador_id IS NOT NULL, 'asignado',
                    IF(c.nombre IN ($fijas) OR COALESCE(TRIM(i.ubicacion), '') NOT IN ('', 'Sistemas'), 'asignado', 'disponible')) WHERE i.id = ?")->execute([$item['id']]);
                quitar_ip_si_libre($item['id']);
            }
            registrar_movimiento($item['id'], 'mantenimiento', 'Mantenimiento ' . $tipo . ' ' . $cons, $colab);
            $pdo->commit();
            flash($firmar ? 'Mantenimiento registrado, falta la firma' : 'Mantenimiento registrado');
            ir('mantenimiento.php?id=' . $mid . ($firmar ? '#firmar' : ''));
        }
    }

    $colabs = db()->query('SELECT id, nombre FROM colaboradores WHERE activo = 1 ORDER BY nombre')->fetchAll();
    $titulo = 'Registrar mantenimiento';
    $menu = 'mant';
    require __DIR__ . '/inc/header.php';
    ?>
    <div class="cabecera"><div><a href="item.php?id=<?= $item['id'] ?>" class="tenue">&larr; <?= e($item['etiqueta']) ?></a>
      <h1>Registrar mantenimiento</h1><small><?= e($item['etiqueta'] . ' · ' . $item['nombre'] . ' · ' . $item['categoria']) ?></small></div></div>
    <?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="tarjeta">
      <?= csrf_campo() ?>
      <div class="rejilla">
        <div class="campo"><label>Fecha</label><input type="date" name="fecha" required max="<?= date('Y-m-d') ?>" value="<?= e($_POST['fecha'] ?? date('Y-m-d')) ?>"></div>
        <div class="campo"><label>Tipo</label><select name="tipo"><option value="preventivo">Preventivo</option>
          <option value="correctivo" <?= ($_POST['tipo'] ?? '') === 'correctivo' ? 'selected' : '' ?>>Correctivo</option></select></div>
        <div class="campo"><label>Usuario del equipo (firma)</label><select name="colaborador_id" data-buscar>
          <?= opciones($colabs, $_POST['colaborador_id'] ?? $item['colaborador_id'], 'nombre', 'Sin usuario') ?></select></div>
        <div class="campo"><label>Firma</label><select name="firma_modo">
          <option value="ahora">Firmar ahora en el celular</option><option value="no">Sin firma</option></select></div>
        <div class="campo ancho"><label>Actividades realizadas</label>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px">
          <?php foreach ($actividades_base as $a): ?>
            <label style="display:flex;gap:8px;align-items:center;color:var(--texto);margin:0"><input type="checkbox" name="act[]" value="<?= e($a) ?>"
              <?= in_array($a, $_POST['act'] ?? [], true) ? 'checked' : '' ?>> <?= e($a) ?></label>
          <?php endforeach; ?></div>
          <input name="otra" placeholder="Otra actividad" style="width:100%;margin-top:8px" value="<?= e($_POST['otra'] ?? '') ?>"></div>
        <div class="campo ancho"><label>Observaciones / hallazgos</label><textarea name="observaciones"><?= e($_POST['observaciones'] ?? '') ?></textarea></div>
        <?php if (in_array($item['estado'], ['mantenimiento', 'danado'], true)): ?>
        <div class="campo ancho"><label style="display:flex;gap:8px;align-items:center;color:var(--texto)"><input type="checkbox" name="quedo_ok" value="1"> El equipo quedo funcionando (sale de <?= e(estados_item()[$item['estado']]) ?>)</label></div>
        <?php endif; ?>
      </div>
      <p><button class="btn btn-primario">Guardar</button> <a class="btn btn-borde" href="item.php?id=<?= $item['id'] ?>">Cancelar</a></p>
    </form>
    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

// ver y firmar
$m = cargar_mant($id);
if (!$m) { http_response_code(404); exit('No existe'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin() && $m['estado'] === 'pendiente') {
    verificar_csrf();
    if (($_POST['accion'] ?? '') === 'sin_firma') {
        db()->prepare("UPDATE mantenimientos SET estado = 'sin_firma' WHERE id = ?")->execute([$id]);
        ir('mantenimiento.php?id=' . $id);
    }
    $nombre = trim($_POST['firma_nombre'] ?? '');
    $png = mb_strlen($nombre) >= 5 ? guardar_firma_png($_POST['firma_data'] ?? '', $m['consecutivo']) : null;
    if (!$png) {
        $error = mb_strlen($nombre) < 5 ? 'Escribe el nombre completo' : 'No se pudo leer la firma';
    } else {
        db()->prepare("UPDATE mantenimientos SET estado = 'firmado', firma_archivo = ?, firma_nombre = ?, firmado_en = NOW(), firmado_ip = ? WHERE id = ? AND estado = 'pendiente'")
            ->execute([$png, $nombre, $_SERVER['REMOTE_ADDR'] ?? null, $id]);
        flash('Mantenimiento firmado');
        ir('mantenimiento.php?id=' . $id);
    }
}

$titulo = $m['consecutivo'];
$menu = 'mant';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera no-imprimir">
  <div><a href="item.php?id=<?= $m['item_id'] ?>" class="tenue">&larr; <?= e($m['etiqueta']) ?></a><h1><?= e($m['consecutivo']) ?></h1></div>
  <div class="acciones"><button class="btn btn-borde" onclick="window.print()">Imprimir / PDF</button></div>
</div>
<?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>

<div class="tarjeta papel">
  <div class="cabecera" style="margin-bottom:10px">
    <div><b style="font-size:15px">AGRO-COSTA S.A.S.</b><br><span class="tenue">Registro de mantenimiento de equipos · AGR-F-074</span></div>
    <div style="text-align:right"><b class="mono"><?= e($m['consecutivo']) ?></b><br><span class="tenue"><?= fecha_corta($m['fecha']) ?> · <?= e(ucfirst($m['tipo'])) ?></span></div>
  </div>
  <div class="ficha" style="margin-bottom:12px">
    <div><small>Equipo</small><span class="mono"><?= e($m['etiqueta']) ?></span></div>
    <div><small>Descripcion</small><span><?= e(trim($m['item_nombre'] . ' ' . $m['marca'] . ' ' . $m['modelo'])) ?></span></div>
    <div><small>Serial</small><span class="mono"><?= e($m['serial'] ?: '—') ?></span></div>
    <div><small>Ubicacion</small><span><?= e($m['ubicacion'] ?: ($m['sede'] ?: '—')) ?></span></div>
    <div><small>Usuario</small><span><?= e($m['colaborador'] ?: '—') ?></span></div>
    <div><small>Tecnico</small><span><?= e($m['tecnico'] ?: '—') ?></span></div>
  </div>
  <p><small class="tenue">Actividades</small></p>
  <ul style="margin-top:0"><?php foreach (array_filter(explode("\n", (string)$m['actividades'])) as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>
  <?php if ($m['observaciones']): ?><p style="white-space:pre-line"><small class="tenue">Observaciones</small><br><?= e($m['observaciones']) ?></p><?php endif; ?>
  <div class="rejilla" style="margin-top:20px;align-items:end">
    <div>
      <?php if ($m['firma_archivo']): ?><img class="firma-img" src="<?= e(url_archivo($m['firma_archivo'])) ?>" alt="Firma"><br>
      <?php else: ?><div style="border-bottom:1px solid #999;height:70px"></div><?php endif; ?>
      <small>Recibe a satisfaccion: <?= e($m['firma_nombre'] ?: ($m['colaborador'] ?: '')) ?></small>
      <?php if ($m['firmado_en']): ?><br><small class="tenue">Firmado electronicamente el <?= fecha_hora($m['firmado_en']) ?> · IP <?= e($m['firmado_ip']) ?></small><?php endif; ?>
      <?php if ($m['estado'] === 'sin_firma'): ?><br><small class="tenue"><?= $m['historico'] ? 'Registro historico' : 'Registrado sin firma' ?></small><?php endif; ?>
    </div>
    <div><div style="border-bottom:1px solid #999;padding:46px 0 6px"><?= e($m['tecnico']) ?></div><small>Tecnico de sistemas</small></div>
  </div>
</div>

<?php if ($m['estado'] === 'pendiente' && es_admin()): ?>
<form method="post" class="tarjeta no-imprimir" id="firmar">
  <?= csrf_campo() ?><input type="hidden" name="firma_data" id="firma_data">
  <h2>Firma de <?= e($m['colaborador']) ?></h2>
  <div class="campo" style="margin-bottom:12px;max-width:360px"><label>Nombre completo</label><input name="firma_nombre" required value="<?= e($m['colaborador']) ?>"></div>
  <div class="firma-caja"><canvas id="firma"></canvas><div class="guia"></div></div>
  <p style="display:flex;gap:8px;justify-content:space-between">
    <button type="button" class="btn btn-borde" data-borrar-firma>Borrar</button>
    <button type="submit" class="btn btn-primario">Firmar</button></p>
</form>
<form method="post" class="no-imprimir"><?= csrf_campo() ?><input type="hidden" name="accion" value="sin_firma">
  <button class="btn btn-borde btn-sm" data-confirmar="Dejar este mantenimiento sin firma?">Dejar sin firma</button></form>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
