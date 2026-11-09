<?php
require __DIR__ . '/config.php';
requiere_modulo('actas');

$id = (int)($_GET['id'] ?? 0);
$a = cargar_acta($id);
if (!$a) { http_response_code(404); exit('No existe'); }
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin()) {
    verificar_csrf();
    $acc = $_POST['accion'] ?? '';

    if ($acc === 'firmar') {
        $error = firmar_acta($id, $_POST['firma_data'] ?? '', $_POST['firma_nombre'] ?? '', $_POST['firma_cedula'] ?? '', 'pantalla');
        if (!$error) { flash('Acta firmada' . (!empty($GLOBALS['copia_firma']) ? '. ' . $GLOBALS['copia_firma'] : '')); ir('acta.php?id=' . $id); }
    }

    if (in_array($acc, ['link', 'correo'], true) && $a['estado'] === 'pendiente') {
        // si ya hay un enlace vigente se reusa para no dejar sin servir uno que ya mandaron
        $vigente = $a['token'] && strtotime((string)$a['token_expira']) > time();
        if ($acc === 'link' || !$vigente) {
            $tok = bin2hex(random_bytes(32));
            db()->prepare('UPDATE actas SET token = ?, token_expira = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?')->execute([$tok, $id]);
        } else {
            $tok = $a['token'];
        }
        $link = APP_URL . '/firmar.php?t=' . $tok;
        if ($acc === 'correo') {
            $para = strtolower(trim($_POST['para'] ?? ''));
            if (enviar_correo($para, $a['colaborador'], 'Acta ' . $a['consecutivo'] . ' pendiente de firma', correo_acta($a, $link))) {
                db()->prepare('UPDATE actas SET enviado_a = ?, enviado_en = NOW() WHERE id = ?')->execute([$para, $id]);
                // si la ficha no tenia correo queda guardado
                db()->prepare("UPDATE colaboradores SET email = ? WHERE id = ? AND COALESCE(email, '') = ''")->execute([$para, $a['colaborador_id']]);
                flash('Correo enviado a ' . $para);
            } else {
                flash('No se envió el correo: ' . $GLOBALS['correo_error'] . '. El enlace quedó generado, puedes copiarlo o mandarlo por WhatsApp.', 'error');
            }
        } else {
            flash('Enlace generado, vence en 7 días');
        }
        ir('acta.php?id=' . $id);
    }

    if ($acc === 'anular' && $a['estado'] === 'pendiente') {
        db()->prepare("UPDATE actas SET estado = 'anulada', anulado_por = ?, anulado_motivo = ?, token = NULL WHERE id = ?")
            ->execute([usuario()['id'], trim($_POST['motivo'] ?? '') ?: null, $id]);
        flash('Acta anulada');
        ir('acta.php?id=' . $id);
    }

    if ($acc === 'soporte') {
        if ($ruta = guardar_archivo_subido('archivo', 'actas')) {
            db()->prepare('INSERT INTO acta_archivos (acta_id, archivo) VALUES (?, ?)')->execute([$id, $ruta]);
            flash('Soporte agregado');
        } else {
            flash('Archivo no valido, solo imagen o pdf de hasta 15 MB', 'error');
        }
        ir('acta.php?id=' . $id);
    }
}

$st = db()->prepare('SELECT * FROM acta_archivos WHERE acta_id = ? ORDER BY id');
$st->execute([$id]);
$soportes = $st->fetchAll();
$link = $a['estado'] === 'pendiente' && $a['token'] && strtotime($a['token_expira']) > time() ? APP_URL . '/firmar.php?t=' . $a['token'] : null;
$integridad = $a['hash_contenido'] ? hash_equals($a['hash_contenido'], hash_acta($a)) : null;

$titulo = $a['consecutivo'];
$menu = 'actas';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera no-imprimir">
  <div><a href="actas.php" class="tenue">&larr; Actas</a>
    <h1><?= e($a['consecutivo']) ?> <span class="chip chip-<?= e($a['estado']) ?>" style="vertical-align:middle"><?= e(ucfirst($a['estado'])) ?></span>
      <?= $a['historica'] ? '<span class="chip chip-gris" style="vertical-align:middle">Papel</span>' : '' ?>
      <?= !empty($a['prestamo']) ? '<span class="chip chip-pendiente" style="vertical-align:middle">Préstamo</span>' : '' ?></h1>
    <?php if ($a['estado'] === 'anulada'): ?><small>Motivo: <?= e($a['anulado_motivo'] ?: 'sin motivo') ?></small><?php endif; ?>
    <?php if ($integridad === false): ?><div class="aviso aviso-error" style="margin:8px 0 0">El contenido cambio despues de la firma</div><?php endif; ?></div>
  <div class="acciones"><button class="btn btn-borde" onclick="window.print()">Imprimir / PDF</button>
    <a class="btn btn-borde" href="colaborador.php?id=<?= $a['colaborador_id'] ?>">Ver colaborador</a></div>
</div>
<?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>

<?php require __DIR__ . '/inc/acta_documento.php'; ?>

<?php if ($a['estado'] === 'pendiente' && es_admin()): ?>
<form method="post" class="tarjeta no-imprimir" id="firmar">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="firmar"><input type="hidden" name="firma_data" id="firma_data">
  <h2>Firmar aqui con el dedo</h2>
  <p class="tenue" style="margin-top:-6px">Pasale el celular a <?= e($a['colaborador']) ?> para que revise el acta y firme</p>
  <div class="rejilla" style="margin-bottom:12px">
    <div class="campo"><label>Nombre completo</label><input name="firma_nombre" required value="<?= e($_POST['firma_nombre'] ?? $a['colaborador']) ?>"></div>
    <div class="campo"><label>Cedula</label><input name="firma_cedula" required inputmode="numeric" value="<?= e($_POST['firma_cedula'] ?? $a['cedula']) ?>"></div>
  </div>
  <div class="firma-caja"><canvas id="firma"></canvas><div class="guia"></div></div>
  <p style="display:flex;gap:8px;justify-content:space-between">
    <button type="button" class="btn btn-borde" data-borrar-firma>Borrar</button>
    <button type="submit" class="btn btn-primario">Firmar y guardar</button></p>
</form>

<div class="dos-col no-imprimir">
  <div class="tarjeta">
    <h2>O enviar enlace para firmar desde su celular</h2>
    <?php if ($link): ?>
      <div class="filtros"><input value="<?= e($link) ?>" readonly onclick="this.select()" style="flex:1">
        <button type="button" class="btn btn-borde" data-copiar="<?= e($link) ?>">Copiar</button>
        <?php $tel = preg_replace('/\D/', '', $a['telefono'] ?? ''); if (strlen($tel) === 10) $tel = '57' . $tel; ?>
        <a class="btn btn-borde" target="_blank" href="https://wa.me/<?= e($tel) ?>?text=<?= rawurlencode('Hola, por favor revisa y firma el acta ' . $a['consecutivo'] . ': ' . $link) ?>">WhatsApp</a></div>
      <small class="tenue">Vence el <?= fecha_hora($a['token_expira']) ?></small>
    <?php endif; ?>
    <?php if ($a['enviado_en']): ?><p class="tenue" style="margin:8px 0 0">Último correo: <?= e($a['enviado_a']) ?> · <?= fecha_hora($a['enviado_en']) ?></p><?php endif; ?>
    <form method="post" class="filtros" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="correo">
      <input type="email" name="para" required placeholder="correo@agro-costa.com" value="<?= e($a['email'] ?? '') ?>" style="flex:1;min-width:220px">
      <button class="btn btn-primario">Enviar por correo</button></form>
    <?php if (!correo_configurado()): ?><p class="aviso aviso-error" style="margin:8px 0 0">No encontré la configuración de correo de empleados (private/.env y /empleados/PHPMailer).</p><?php endif; ?>
    <?php if (!$a['email']): ?><small class="tenue">La ficha no tiene correo; el que escribas aquí queda guardado en la ficha.</small><?php endif; ?>
    <form method="post" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="link">
      <button class="btn btn-borde btn-sm"><?= $link ? 'Generar otro enlace (el anterior deja de servir)' : 'Solo generar enlace' ?></button></form>
  </div>
  <form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="accion" value="anular">
    <h2>Anular</h2><input name="motivo" placeholder="Motivo" style="width:100%;margin-bottom:8px">
    <button class="btn btn-borde btn-sm" data-confirmar="Anular esta acta? Los items no se mueven">Anular acta</button></form>
</div>
<?php endif; ?>

<?php if ($soportes || es_admin()): ?>
<div class="tarjeta no-imprimir">
  <h2>Soportes (papel escaneado)</h2>
  <div style="display:flex;gap:12px;flex-wrap:wrap">
  <?php foreach ($soportes as $s): $u = url_archivo($s['archivo']); ?>
    <?php if (str_ends_with($s['archivo'], '.pdf')): ?><a class="btn btn-borde" target="_blank" href="<?= e($u) ?>">Ver PDF</a>
    <?php else: ?><a target="_blank" href="<?= e($u) ?>"><img src="<?= e($u) ?>" style="height:160px;border-radius:8px;border:1px solid var(--borde)"></a><?php endif; ?>
  <?php endforeach; ?>
  </div>
  <?php if (es_admin()): ?>
  <form method="post" enctype="multipart/form-data" class="filtros" style="margin-top:10px"><?= csrf_campo() ?><input type="hidden" name="accion" value="soporte">
    <input type="file" name="archivo" accept="image/*,application/pdf" required><button class="btn btn-borde btn-sm">Agregar soporte</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
