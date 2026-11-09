<?php
// pagina publica para firmar desde el link
require __DIR__ . '/config.php';

$token = $_GET['t'] ?? '';
$a = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = db()->prepare('SELECT id, token_expira, estado FROM actas WHERE token = ?');
    $st->execute([$token]);
    $fila = $st->fetch();
    if ($fila) $a = cargar_acta($fila['id']);
}
$vencido = $a && $a['estado'] === 'pendiente' && strtotime((string)$a['token_expira']) < time();
$error = '';

if ($a && $a['estado'] === 'pendiente' && !$vencido && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['acepto'])) {
        $error = 'Debes confirmar que revisaste el acta';
    } else {
        $error = firmar_acta($a['id'], $_POST['firma_data'] ?? '', $_POST['firma_nombre'] ?? '', $_POST['firma_cedula'] ?? '', 'link');
        if (!$error) ir('firmar.php?t=' . $token . '&ok=1');
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#000000">
<meta name="robots" content="noindex">
<title>Firmar acta · Agro-Costa</title>
<script>try{if(localStorage.getItem("tema")==="claro")document.documentElement.setAttribute("data-tema","claro")}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=21">
</head>
<body>
<div class="publico">
  <div class="marca"><span class="logo">AC</span><span>Agro-Costa<small>acta de elementos</small></span><span style="margin-left:auto"><button type="button" class="tema-btn" data-tema-toggle title="Cambiar modo claro / oscuro"><svg class="luna" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg><svg class="sol" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></button></span></div>
  <?php if (!$a || $a['estado'] === 'anulada'): ?>
    <div class="tarjeta vacio">Este enlace no es valido. Comunicate con el area de sistemas.</div>
  <?php elseif ($a['estado'] === 'firmada'): ?>
    <div class="aviso aviso-ok"><?= !empty($_GET['ok']) ? 'Listo, el acta quedo firmada. Gracias.' : 'Esta acta ya fue firmada.' ?></div>
    <?php require __DIR__ . '/inc/acta_documento.php'; ?>
    <p class="no-imprimir"><button class="btn btn-borde" onclick="window.print()">Guardar copia en PDF</button></p>
  <?php elseif ($vencido): ?>
    <div class="tarjeta vacio">Este enlace vencio. Pide uno nuevo al area de sistemas.</div>
  <?php else: ?>
    <?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>
    <p>Revisa los elementos y si todo esta bien firma al final.</p>
    <?php require __DIR__ . '/inc/acta_documento.php'; ?>
    <form method="post" class="tarjeta">
      <input type="hidden" name="firma_data" id="firma_data">
      <div class="rejilla" style="margin-bottom:12px">
        <div class="campo"><label>Nombre completo</label><input name="firma_nombre" required value="<?= e($_POST['firma_nombre'] ?? $a['colaborador']) ?>"></div>
        <div class="campo"><label>Cedula</label><input name="firma_cedula" required inputmode="numeric" value="<?= e($_POST['firma_cedula'] ?? $a['cedula']) ?>"></div>
      </div>
      <label style="color:var(--tenue)">Firma con el dedo</label>
      <div class="firma-caja"><canvas id="firma"></canvas><div class="guia"></div></div>
      <label style="display:flex;gap:8px;align-items:flex-start;color:var(--texto);margin:12px 0">
        <input type="checkbox" name="acepto" value="1" required style="margin-top:3px"> Revise el acta y estoy de acuerdo con su contenido</label>
      <p style="display:flex;gap:8px;justify-content:space-between;margin:0">
        <button type="button" class="btn btn-borde" data-borrar-firma>Borrar</button>
        <button type="submit" class="btn btn-primario">Firmar</button></p>
    </form>
  <?php endif; ?>
</div>
<script src="assets/app.js?v=7"></script>
</body>
</html>
