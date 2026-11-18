<?php
// copias de seguridad al disco externo: historial, ultima copia, registro a mano y descarga del script
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/copias.php';
requiere_modulo('copias');

// descarga del script ya configurado (la clave va adentro: solo administradores)
$desc = $_GET['descargar'] ?? '';
if ($desc !== '' && es_admin()) {
    if (clave_copias() === '') { flash('Falta APP_KEY o BACKUP_TOKEN en el .env', 'error'); ir('copias.php'); }
    if ($desc === 'ps1') { $nom = 'reportar_copia.ps1'; $txt = script_copias_ps1(); }
    else {
        $nom = 'REPORTAR_COPIA.bat';
        $txt = "@echo off\r\nREM Reporte de copia de seguridad - Inventario TI Agro-Costa\r\nREM Solo corre reportar_copia.ps1 (que esta en esta misma carpeta). Ese archivo solo LEE el disco.\r\n"
            . "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"%~dp0reportar_copia.ps1\"\r\n";
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . strlen($txt));
    header('Cache-Control: no-store');
    echo $txt;
    exit;
}

// registrar una copia a mano (cuando el script no se pudo usar): con una captura del disco
if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin() && ($_POST['accion'] ?? '') === 'manual') {
    verificar_csrf();
    $persona = trim($_POST['persona'] ?? '') === '__otra' ? trim($_POST['persona_otra'] ?? '') : trim($_POST['persona'] ?? '');
    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $_POST['fecha'] ?? '') ? str_replace('T', ' ', $_POST['fecha']) . ':00' : date('Y-m-d H:i:s');
    if (strtotime($fecha) > time() + 300) $fecha = date('Y-m-d H:i:s');
    $arch = guardar_archivo_subido('captura', 'copias');
    if ($persona === '' || !$arch) { flash($persona === '' ? 'Pon quién hizo la copia' : 'Sube la captura del disco (imagen o PDF de hasta 15 MB)', 'error'); ir('copias.php?manual=1'); }
    $st = db()->prepare('SELECT id FROM usuarios WHERE nombre = ? LIMIT 1');
    $st->execute([$persona]);
    db()->prepare("INSERT INTO copias_seguridad (fecha, persona, usuario_id, origen, ip, estado, captura, nota, registrado_por) VALUES (?, ?, ?, 'manual', ?, 'ok', ?, ?, ?)")
        ->execute([$fecha, mb_substr($persona, 0, 120), $st->fetchColumn() ?: null, $_SERVER['REMOTE_ADDR'] ?? '', $arch, trim($_POST['nota'] ?? '') ?: null, usuario()['id']]);
    $id = (int)db()->lastInsertId();
    $ok = false;
    try { $ok = correo_copia($id); } catch (Throwable $t) { error_log('correo copia: ' . $t->getMessage()); }
    flash('Copia registrada' . ($ok ? ' y avisada por correo' : ''));
    ir('copias.php');
}

$dias = dias_copias();
$ultima = ultima_copia();
$vencida = copia_atrasada($ultima);
$lista = db()->query('SELECT c.*, u.nombre registro FROM copias_seguridad c LEFT JOIN usuarios u ON u.id = c.registrado_por ORDER BY c.fecha DESC, c.id DESC LIMIT 200')->fetchAll();
$total_anio = (int)db()->query('SELECT COUNT(*) FROM copias_seguridad WHERE YEAR(fecha) = YEAR(CURDATE())')->fetchColumn();
$revisar = (int)db()->query("SELECT COUNT(*) FROM copias_seguridad WHERE estado = 'revisar' AND fecha > NOW() - INTERVAL 90 DAY")->fetchColumn();

$ver = null;
if (!empty($_GET['id'])) {
    $st = db()->prepare('SELECT c.*, u.nombre registro FROM copias_seguridad c LEFT JOIN usuarios u ON u.id = c.registrado_por WHERE c.id = ?');
    $st->execute([(int)$_GET['id']]);
    $ver = $st->fetch() ?: null;
}
$manual = !empty($_GET['manual']) && es_admin();

$titulo = 'Copias de seguridad';
$menu = 'copias';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1>Copias de seguridad</h1><small>Copia desconectada de la base al disco externo · se espera una cada <?= $dias ?> día<?= $dias > 1 ? 's' : '' ?></small></div>
  <?php if (es_admin()): ?><div class="acciones"><a class="btn btn-borde" href="?manual=1">Registrar a mano</a></div><?php endif; ?>
</div>

<div class="kpis">
  <div class="kpi" style="<?= $vencida ? 'border-left-color:var(--rojo)' : 'border-left-color:var(--verde)' ?>"><small>Última copia</small>
    <b style="color:<?= $vencida ? 'var(--rojo)' : 'var(--verde)' ?>;font-size:22px"><?= $ultima ? hace_dias($ultima['fecha']) : 'Ninguna' ?></b>
    <?= $ultima ? '<span class="tenue">' . fecha_hora($ultima['fecha']) . ' · ' . e($ultima['persona']) . '</span>' : '' ?></div>
  <div class="kpi"><small>La próxima, a más tardar</small>
    <b style="font-size:22px"><?= $ultima ? date('d/m/Y', strtotime(copia_limite($ultima['fecha']))) : 'Ya' ?></b>
    <span class="tenue"><?= $vencida ? 'Está atrasada: ya les llega el aviso por correo' : 'Si no se hace, llega aviso por correo' ?></span></div>
  <div class="kpi"><small>Copias en <?= date('Y') ?></small><b><?= $total_anio ?></b></div>
  <div class="kpi" style="<?= $revisar ? 'border-left-color:var(--rojo)' : '' ?>"><small>Para revisar (90 días)</small><b style="<?= $revisar ? 'color:var(--rojo)' : '' ?>"><?= $revisar ?></b></div>
</div>

<?php if ($ver): ?>
<div class="modal-fondo" data-cerrar="copias.php">
<div class="tarjeta modal-caja" role="dialog" aria-modal="true">
  <a class="modal-x" href="copias.php" aria-label="Cerrar">×</a>
  <h2>Copia del <?= fecha_hora($ver['fecha']) ?></h2>
  <p style="margin-top:-4px"><span class="chip <?= $ver['estado'] === 'ok' ? 'chip-disponible' : 'chip-danado' ?>"><?= $ver['estado'] === 'ok' ? 'En orden' : 'Revisar' ?></span>
    <b><?= e($ver['persona']) ?></b>
    <span class="tenue"><?= $ver['origen'] === 'manual' ? '· registrada a mano' . ($ver['registro'] ? ' por ' . e($ver['registro']) : '') : '· reportada por el script del disco' ?></span></p>
  <?php $disco = json_decode((string)$ver['disco'], true) ?: []; $info = [];
    if (!empty($disco['dia'])) $info[] = 'Carpeta del día ' . $disco['dia'];
    if (!empty($disco['etiqueta'])) $info[] = 'Disco ' . $disco['etiqueta'];
    if (!empty($disco['total_gb'])) $info[] = 'libres ' . (int)$disco['libre_gb'] . ' de ' . (int)$disco['total_gb'] . ' GB';
    if ($ver['pc']) $info[] = 'PC ' . $ver['pc'] . ($ver['usuario_windows'] ? ' · usuario de Windows ' . $ver['usuario_windows'] : '');
    if ($ver['ip']) $info[] = 'IP ' . $ver['ip']; ?>
  <?php if ($info): ?><p class="tenue mono" style="font-size:12px"><?= e(implode(' · ', $info)) ?></p><?php endif; ?>
  <?php $carps = json_decode((string)$ver['carpetas'], true) ?: []; if ($carps): ?>
  <div class="tabla-envoltura"><table class="tabla">
    <thead><tr><th>Carpeta</th><th style="text-align:right">Archivos</th><th style="text-align:right">Tamaño</th><th>Archivo más reciente</th></tr></thead><tbody>
    <?php foreach ($carps as $c): $bien = ($c['estado'] ?? 'ok') === 'ok'; ?>
      <tr><td><?= $bien ? '<span style="color:var(--verde)">✔</span>' : '<span style="color:var(--rojo)">✘</span>' ?> <b><?= e($c['nombre']) ?></b><?= !empty($c['nota']) ? '<small style="color:' . ($bien ? 'var(--tenue)' : 'var(--rojo)') . '">' . e($c['nota']) . '</small>' : '' ?></td>
        <td class="mono" style="text-align:right"><?= number_format((int)$c['archivos'], 0, ',', '.') ?></td>
        <td class="mono" style="text-align:right"><?= mb_legible($c['mb']) ?></td>
        <td><?= $c['ultimo'] ? fecha_hora($c['ultimo']) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  <?php if ($ver['nota']): ?><p><b>Nota:</b> <?= nl2br(e($ver['nota'])) ?></p><?php endif; ?>
  <?php if ($ver['captura']): ?><p><a href="<?= e(url_archivo($ver['captura'])) ?>" target="_blank">
    <?php if (preg_match('/\.(png|jpe?g|webp)$/i', $ver['captura'])): ?><img src="<?= e(url_archivo($ver['captura'])) ?>" alt="Captura del disco" style="max-width:100%;border:1px solid var(--borde);border-radius:4px"><?php else: ?>Ver captura (PDF)<?php endif; ?></a></p><?php endif; ?>
</div>
</div>
<?php endif; ?>

<?php if ($manual): $pers = personas_copias(); ?>
<div class="modal-fondo" data-cerrar="copias.php">
<form method="post" class="tarjeta modal-caja" role="dialog" aria-modal="true" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="accion" value="manual">
  <a class="modal-x" href="copias.php" aria-label="Cerrar">×</a>
  <h2>Registrar copia a mano</h2>
  <p class="tenue" style="margin-top:-6px">Solo si el script del disco no se pudo usar. Sube una captura del disco con las carpetas en vista “Detalles” (que se vea la fecha y el tamaño).</p>
  <div class="rejilla">
    <div class="campo"><label>Quién hizo la copia</label><select name="persona" required>
      <option value="">Escoge…</option><?php foreach ($pers as $p): ?><option<?= $p === usuario()['nombre'] ? ' selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?><option value="__otra">Otra persona…</option></select></div>
    <div class="campo"><label>Otra persona (si no está en la lista)</label><input name="persona_otra" maxlength="120"></div>
    <div class="campo"><label>Fecha y hora</label><input type="datetime-local" name="fecha" required value="<?= date('Y-m-d\TH:i') ?>" max="<?= date('Y-m-d\TH:i') ?>"></div>
    <div class="campo"><label>Captura del disco</label><input type="file" name="captura" required accept="image/*,application/pdf"></div>
    <div class="campo" style="grid-column:1/-1"><label>Nota</label><input name="nota" maxlength="1000" placeholder="Por qué se registró a mano, algo que pasó…"></div>
  </div>
  <p style="margin-bottom:0"><button class="btn btn-primario">Guardar y avisar</button> <a class="btn btn-borde" href="copias.php">Cancelar</a></p>
</form>
</div>
<?php endif; ?>

<div class="dos-col">
  <div class="tabla-envoltura"><table class="tabla">
    <thead><tr><th>Fecha</th><th>Quién</th><th>Carpetas</th><th style="text-align:right">Tamaño</th><th>Estado</th></tr></thead><tbody>
    <?php if (!$lista): ?><tr><td colspan="5" class="vacio">Todavía no hay copias registradas. Cuando el script del disco reporte la primera, sale aquí.</td></tr><?php endif; ?>
    <?php foreach ($lista as $c): $carps = json_decode((string)$c['carpetas'], true) ?: []; $bien = count(array_filter($carps, fn($x) => ($x['estado'] ?? 'ok') === 'ok')); ?>
      <tr><td><a href="?id=<?= $c['id'] ?>"><?= fecha_hora($c['fecha']) ?></a><small><?= hace_dias($c['fecha']) ?></small></td>
        <td><?= e($c['persona']) ?><small class="tenue"><?= $c['origen'] === 'manual' ? 'A mano, con captura' : e($c['pc'] ? 'PC ' . $c['pc'] : 'Script del disco') ?></small></td>
        <td class="mono"><?= $carps ? $bien . ' de ' . count($carps) . ' bien' : '—' ?></td>
        <td class="mono" style="text-align:right"><?= $c['total_mb'] !== null ? mb_legible($c['total_mb']) : '—' ?></td>
        <td><a href="?id=<?= $c['id'] ?>" style="text-decoration:none"><span class="chip <?= $c['estado'] === 'ok' ? 'chip-disponible' : 'chip-danado' ?>"><?= $c['estado'] === 'ok' ? 'En orden' : 'Revisar' ?></span></a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>

  <div class="tarjeta">
    <h2>Cómo se reporta</h2>
    <ol style="padding-left:18px;margin:0 0 12px;line-height:1.6">
      <li>Se hace la copia al disco externo como siempre.</li>
      <li>En la raíz del disco se le da doble clic a <b class="mono">REPORTAR_COPIA.bat</b>.</li>
      <li>El script revisa las carpetas, pregunta quién hizo la copia y manda el reporte.</li>
      <li>Les llega el correo con cada carpeta en verde o en rojo.</li>
    </ol>
    <p class="tenue" style="font-size:13px">El script <b>solo lee</b> el disco: nombre de cada carpeta, cuántos archivos tiene, cuánto pesa y la fecha del archivo más reciente. No copia, no mueve ni borra nada.</p>
    <p class="tenue" style="font-size:13px">Una carpeta sale en rojo si está vacía, si falta, si quedó igual a la copia anterior (no se copió nada nuevo) o si pesa mucho menos que la vez pasada.</p>
    <?php $fijas = carpetas_esperadas();
      $ult_script = db()->query("SELECT carpetas FROM copias_seguridad WHERE origen = 'script' AND carpetas IS NOT NULL ORDER BY fecha DESC, id DESC LIMIT 1")->fetchColumn();
      $vistas_ult = array_column(json_decode((string)$ult_script, true) ?: [], 'nombre'); ?>
    <h3 style="margin:16px 0 6px">Carpetas que se revisan</h3>
    <?php if (base_copias() !== ''): ?><p class="tenue" style="font-size:13px;margin:0 0 6px">Copias diarias: dentro de <span class="mono"><?= e(base_copias()) ?></span> se toma solo la carpeta del día más reciente (03nov, 04nov…) y se revisa lo que tiene adentro. Si no hay carpeta de hoy, sale para revisar. Se cambia con <span class="mono">BACKUP_BASE</span> en el .env.</p><?php endif; ?>
    <?php if ($fijas): ?>
      <p style="margin:0 0 4px"><?php foreach ($fijas as $n): ?><span class="chip chip-gris" style="margin:0 4px 4px 0"><?= e($n) ?></span><?php endforeach; ?></p>
      <p class="tenue" style="font-size:12px;margin-top:0">Fijadas en el .env (<span class="mono">BACKUP_CARPETAS</span>). Si una no está en el disco, sale en rojo.</p>
    <?php elseif ($vistas_ult): ?>
      <p style="margin:0 0 4px"><?php foreach ($vistas_ult as $n): ?><span class="chip chip-gris" style="margin:0 4px 4px 0"><?= e($n) ?></span><?php endforeach; ?></p>
      <p class="tenue" style="font-size:12px;margin-top:0">Las que encontró el script en la última copia. Si en la próxima falta alguna, sale en rojo.</p>
    <?php else: ?>
      <p class="tenue" style="font-size:13px;margin-top:0">Todas las carpetas de la raíz del disco. Se pueden fijar con <span class="mono">BACKUP_CARPETAS</span> en el .env.</p>
    <?php endif; ?>
    <?php if (es_admin()): ?>
    <h3 style="margin:16px 0 8px">Instalar en el disco</h3>
    <p class="tenue" style="font-size:13px;margin-top:0">Descarga los dos archivos y ponlos en la raíz del disco externo (no dentro de una carpeta). Ya traen la dirección y la clave.</p>
    <p><a class="btn btn-primario btn-sm" href="?descargar=bat">REPORTAR_COPIA.bat</a> <a class="btn btn-primario btn-sm" href="?descargar=ps1">reportar_copia.ps1</a></p>
    <p class="tenue" style="font-size:12px">Si Windows avisa la primera vez: clic derecho al archivo → Propiedades → marcar “Desbloquear”.</p>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/inc/footer.php';
