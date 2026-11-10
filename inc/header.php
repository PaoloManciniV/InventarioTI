<?php
// encabezado y menu lateral
// antes de incluir definir $titulo y $menu
$u = usuario();
$pend = (int)db()->query("SELECT COUNT(*) FROM actas WHERE estado = 'pendiente'")->fetchColumn();
if (es_admin()) cifrar_pendientes();
$menu = $menu ?? '';
$enlaces = [
    'inicio' => ['index.php', 'Vista general', '&#9638;'],
    'firma' => ['firma.php', 'Firmar', '&#9997;'],
    'items' => ['items.php', 'Inventario', '&#9635;'],
    'colaboradores' => ['colaboradores.php', 'Colaboradores', '&#9786;'],
    'actas' => ['actas.php', 'Actas', '&#9998;'],
    'mant' => ['mantenimientos.php', 'Mantenimientos', '&#9874;'],
    'red' => ['red.php', 'Red y extensiones', '&#8644;'],
    'impresoras' => ['impresoras.php', 'Impresoras', '&#9113;'],
    'informe' => ['informe.php', 'Informe anual', '&#9783;'],
    'herramientas' => ['herramientas.php', 'Herramientas', '&#9874;'],
];
$enlaces['wifi'] = ['wifi.php', 'Redes WiFi', '&#128274;'];
$enlaces['importar'] = ['importar.php', 'Importar Excel', '&#8682;'];
$enlaces['config'] = ['config_panel.php', 'Configuracion', '&#9881;'];
// el menu solo muestra los modulos que el usuario tiene permitidos
if (!puede('herramientas') && puede('contactos')) $enlaces['herramientas'] = ['herramientas.php?ver=contactos', 'Contactos soporte', '&#9742;'];
foreach (array_keys($enlaces) as $k) {
    $mod = $k === 'firma' ? 'actas' : $k;
    if ($k === 'herramientas' && (puede('herramientas') || puede('contactos'))) continue;
    if (!puede($mod) || ($k === 'firma' && !es_admin())) unset($enlaces[$k]);
}
$puede_entregar = es_admin() && puede('actas');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#000000">
<title><?= e($titulo ?? 'Inventario') ?> · Inventario Agro-Costa</title>
<meta name="csrf" content="<?= csrf() ?>">
<script>try{if(localStorage.getItem("tema")==="claro")document.documentElement.setAttribute("data-tema","claro")}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=22">
</head>
<body>
<div class="app">
  <aside class="lateral" id="lateral">
    <a class="marca" href="<?= e(primer_modulo() ?? 'index.php') ?>"><span class="logo">AC</span><span>inventario<small>agro-costa:~/sistemas$</small></span></a>
    <nav>
      <div class="nav-titulo">modulos</div>
      <?php foreach ($enlaces as $k => [$href, $txt, $ico]): ?>
        <a href="<?= $href ?>" class="<?= $menu === $k ? 'activo' : '' ?>"><span class="ico"><?= $ico ?></span><?= $txt ?>
          <?php if (($k === 'actas' || $k === 'firma') && $pend): ?><span class="badge"><?= $pend ?></span><?php endif; ?>
          <?php if ($k === 'mant' && ($venc = mant_vencidos_total())): ?><span class="badge"><?= $venc ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="lateral-pie">
      <div class="quien"><span class="avatar"><?= e(iniciales($u['nombre'])) ?></span>
        <div><b><?= e($u['nombre']) ?></b><small><?= $u['rol'] === 'admin' ? 'Administrador' : 'Consulta' ?> · <a href="cuenta.php">Mi cuenta</a></small></div></div>
      <a class="btn btn-sm btn-borde" href="logout.php">Cerrar sesion</a>
    </div>
  </aside>
  <div class="principal">
    <header class="barra">
      <button class="hamburguesa" onclick="document.getElementById('lateral').classList.toggle('abierto')">&#9776;</button>
      <?php if (puede('items')): ?><form class="buscador" action="items.php"><input name="q" placeholder="Buscar por etiqueta, serial, marca, modelo o persona..." value="<?= e($_GET['q'] ?? '') ?>"></form><?php else: ?><div class="buscador"></div><?php endif; ?>
      <button type="button" class="tema-btn" data-tema-toggle title="Cambiar modo claro / oscuro"><svg class="luna" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg><svg class="sol" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></button>
      <?php if ($puede_entregar): ?>
        <a class="btn btn-borde" href="firma.php">Firmar</a>
        <a class="btn btn-borde" href="acta_nueva.php?tipo=entrega">Entregar</a>
      <?php endif; ?>
      <?php if (es_admin() && puede('items')): ?><a class="btn btn-primario" href="item.php">+ Nuevo item</a><?php endif; ?>
    </header>
    <?php if ($puede_entregar && $menu !== 'firma'): ?><a class="fab-firmar" href="firma.php">&#9997; Firmar<?= $pend ? ' <b>' . $pend . '</b>' : '' ?></a><?php endif; ?>
    <main class="contenido">
    <?php if ($f = flash()): ?><div class="aviso aviso-<?= e($f[1]) ?>"><?= e($f[0]) ?></div><?php endif; ?>
