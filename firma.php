<?php
// firma rapida desde el celular de sistemas: lista lo pendiente y abre solo para firmar
require __DIR__ . '/config.php';
requiere_admin();
requiere_modulo('actas');

$id = (int)($_GET['id'] ?? 0);
$error = '';
$a = $id ? cargar_acta($id) : null;
if ($id && !$a) { http_response_code(404); exit('No existe'); }

if ($a && $a['estado'] === 'pendiente' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    if (empty($_POST['acepto'])) {
        $error = 'Falta marcar que revisó el acta';
    } else {
        $error = firmar_acta($id, $_POST['firma_data'] ?? '', $_POST['firma_nombre'] ?? '', $_POST['firma_cedula'] ?? '', 'pantalla');
        if (!$error) {
            $_SESSION['copia_' . $id] = $GLOBALS['copia_firma'] ?? null;
            ir('firma.php?id=' . $id . '&ok=1');
        }
    }
}

// pendientes: la ultima primero
$pend = [];
$mants = [];
if (!$a) {
    $pend = db()->query("SELECT a.id, a.consecutivo, a.tipo, a.prestamo, a.fecha, a.creado, c.nombre colaborador,
        (SELECT GROUP_CONCAT(COALESCE(ai.etiqueta, ai.descripcion) ORDER BY ai.id SEPARATOR ' · ') FROM acta_items ai WHERE ai.acta_id = a.id) resumen
        FROM actas a LEFT JOIN colaboradores c ON c.id = a.colaborador_id
        WHERE a.estado = 'pendiente' ORDER BY a.id DESC LIMIT 30")->fetchAll();
    $mants = db()->query("SELECT m.id, m.consecutivo, m.fecha, m.tipo, i.etiqueta, c.nombre colaborador FROM mantenimientos m
        LEFT JOIN items i ON i.id = m.item_id LEFT JOIN colaboradores c ON c.id = m.colaborador_id
        WHERE m.estado = 'pendiente' ORDER BY m.id DESC LIMIT 20")->fetchAll();
}
$tipo_txt = fn($x) => !empty($x['prestamo']) ? 'Préstamo' : ($x['tipo'] === 'entrega' ? 'Entrega' : 'Devolución');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#000000">
<meta name="robots" content="noindex">
<meta name="csrf" content="<?= csrf() ?>">
<title>Firmar · Inventario Agro-Costa</title>
<script>try{if(localStorage.getItem("tema")==="claro")document.documentElement.setAttribute("data-tema","claro")}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=27">
</head>
<body>
<div class="publico">
  <div class="marca"><span class="logo">AC</span><span>Firmar<small>agro-costa:~/sistemas$</small></span>
    <span style="margin-left:auto;display:flex;gap:6px;align-items:center">
      <?php if ($a): ?><a class="btn btn-borde btn-sm" href="firma.php">Pendientes</a><?php endif; ?>
      <a class="btn btn-borde btn-sm" href="index.php">Menú</a></span></div>

<?php if (!$a): ?>
  <?php if ($pend): $u = $pend[0]; ?>
    <a class="firma-ultima" href="firma.php?id=<?= $u['id'] ?>">
      <small>Firmar la última</small>
      <b><?= e($u['colaborador']) ?></b>
      <span class="mono"><?= e($u['consecutivo']) ?> · <?= $tipo_txt($u) ?></span>
      <span class="resumen"><?= e($u['resumen'] ?: '—') ?></span>
    </a>
  <?php else: ?>
    <div class="tarjeta vacio">No hay actas pendientes de firma.</div>
  <?php endif; ?>
  <p><a class="btn btn-primario btn-bloque" href="acta_nueva.php?tipo=entrega">+ Nueva entrega</a></p>

  <?php if (count($pend) > 1): ?>
    <h2 style="margin-top:22px">Otras pendientes</h2>
    <div class="firma-lista">
    <?php foreach (array_slice($pend, 1) as $p): ?>
      <a href="firma.php?id=<?= $p['id'] ?>"><b><?= e($p['colaborador']) ?></b>
        <span class="mono"><?= e($p['consecutivo']) ?> · <?= $tipo_txt($p) ?> · <?= fecha_corta($p['fecha']) ?></span>
        <span class="resumen"><?= e($p['resumen'] ?: '—') ?></span></a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($mants): ?>
    <h2 style="margin-top:22px">Mantenimientos por firmar</h2>
    <div class="firma-lista">
    <?php foreach ($mants as $m): ?>
      <a href="mantenimiento.php?id=<?= $m['id'] ?>#firmar"><b><?= e($m['colaborador'] ?: 'Sin usuario') ?></b>
        <span class="mono"><?= e($m['consecutivo']) ?> · <?= e($m['etiqueta']) ?> · <?= e(ucfirst($m['tipo'])) ?></span></a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($a['estado'] === 'firmada'): ?>
  <?php $copia = $_SESSION['copia_' . $id] ?? null; unset($_SESSION['copia_' . $id]); ?>
  <div class="aviso aviso-ok"><?= !empty($_GET['ok']) ? '<b>Listo, quedó firmada.</b>' : 'Esta acta ya está firmada.' ?>
    <?php if ($copia): ?><br><small><?= e($copia) ?></small><?php endif; ?></div>
  <?php require __DIR__ . '/inc/acta_documento.php'; ?>
  <p style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-primario" href="firma.php">Ver pendientes</a>
    <a class="btn btn-borde" href="acta_nueva.php?tipo=entrega">Nueva entrega</a>
    <a class="btn btn-borde" href="acta.php?id=<?= $id ?>">Abrir acta</a></p>

<?php elseif ($a['estado'] === 'anulada'): ?>
  <div class="tarjeta vacio">Esta acta fue anulada.</div>

<?php else: ?>
  <?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>
  <p class="tenue" style="margin-top:0">Pásale el celular a <b style="color:var(--texto)"><?= e($a['colaborador']) ?></b> para que revise y firme.</p>
  <?php require __DIR__ . '/inc/acta_documento.php'; ?>
  <form method="post" class="tarjeta">
    <?= csrf_campo() ?><input type="hidden" name="firma_data" id="firma_data">
    <div class="rejilla" style="margin-bottom:12px">
      <div class="campo"><label>Nombre completo</label><input name="firma_nombre" required value="<?= e($_POST['firma_nombre'] ?? $a['colaborador']) ?>"></div>
      <div class="campo"><label>Cédula</label><input name="firma_cedula" required inputmode="numeric" value="<?= e($_POST['firma_cedula'] ?? $a['cedula']) ?>"></div>
    </div>
    <label style="color:var(--tenue)">Firma con el dedo</label>
    <div class="firma-caja"><canvas id="firma"></canvas><div class="guia"></div></div>
    <label style="display:flex;gap:8px;align-items:flex-start;color:var(--texto);margin:12px 0;text-transform:none;font-family:inherit;font-size:14px">
      <input type="checkbox" name="acepto" value="1" required style="margin-top:3px"> Revisé el acta y estoy de acuerdo con su contenido</label>
    <p style="display:flex;gap:8px;justify-content:space-between;margin:0">
      <button type="button" class="btn btn-borde" data-borrar-firma>Borrar</button>
      <button type="submit" class="btn btn-primario">Firmar</button></p>
    <p class="tenue" style="margin:10px 0 0;font-size:12px">Al firmar le llega copia a <?= e($a['email'] ?: 'la persona (no tiene correo en la ficha)') ?> y a sistemas.</p>
  </form>
  <p><a class="tenue" href="acta.php?id=<?= $id ?>#firmar">Mandar enlace o correo en vez de firmar aquí</a></p>
<?php endif; ?>
</div>
<script src="assets/app.js?v=8"></script>
</body>
</html>
