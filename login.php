<?php
require __DIR__ . '/config.php';

if (usuario()) ir('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // los intentos fallidos se cuentan en la base: 5 por correo o 20 por ip en 15 minutos
    $st = db()->prepare("SELECT SUM(email = ?) por_correo, SUM(ip = ?) por_ip FROM accesos WHERE ok = 0 AND fecha > NOW() - INTERVAL 15 MINUTE AND (email = ? OR ip = ?)");
    $st->execute([$email, $ip, $email, $ip]);
    $f = $st->fetch();
    if ((int)$f['por_correo'] >= 5 || (int)$f['por_ip'] >= 20) {
        $error = 'Demasiados intentos fallidos. Espera 15 minutos.';
        registrar_acceso($email, 0, 'bloqueado');
    } else {
        // se entra con el usuario propio o con el correo
        $st = db()->prepare('SELECT * FROM usuarios WHERE (LOWER(email) = ? OR LOWER(usuario) = ?) AND activo = 1 LIMIT 1');
        $st->execute([$email, $email]);
        $u = $st->fetch();
        // mismo tiempo de respuesta exista o no el correo
        $ok = password_verify($_POST['clave'] ?? '', $u['password_hash'] ?? '$2y$10$izdJ5rlhOYEO7CmgjofSTurXjgxpZA6sKyRrCfEUFA1gR4MX7bTfq');
        if ($u && $ok) {
            session_regenerate_id(true);
            $_SESSION = ['usuario' => ['id' => $u['id'], 'nombre' => $u['nombre'], 'email' => $u['email'], 'rol' => $u['rol']],
                'chequeo' => time(), 'inicio' => time(), 'ultimo' => time(), 'rotado' => time()];
            registrar_acceso($email, 1);
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($_POST['clave'], PASSWORD_DEFAULT), $u['id']]);
            }
            db()->prepare('UPDATE usuarios SET ultimo_ingreso = NOW() WHERE id = ?')->execute([$u['id']]);
            ir('index.php');
        }
        registrar_acceso($email, 0);
        // al quinto fallo se avisa a sistemas una sola vez
        if ((int)$f['por_correo'] === 4) avisar_bloqueo($email, $ip);
        sleep(1);
        $error = 'Correo o clave incorrectos';
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#000000">
<title>Ingresar · Inventario Agro-Costa</title>
<script>try{if(localStorage.getItem("tema")==="claro")document.documentElement.setAttribute("data-tema","claro")}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=21">
<style>
  body { display: grid; place-items: center; min-height: 100vh; padding: 16px; }
  .caja { background: var(--tarjeta); border: 1px solid var(--borde); border-radius: 6px; width: 100%; max-width: 380px; overflow: hidden; }
  .caja::before { content: ""; display: block; height: 8px; background: var(--franja); }
  .caja form { padding: 26px; display: grid; gap: 14px; }
  .prompt { font-family: var(--mono); font-size: 12px; color: var(--tenue); }
  .prompt b { color: var(--cat); }
</style>
</head>
<body>
<div style="position:fixed;top:14px;right:14px"><button type="button" class="tema-btn" data-tema-toggle title="Cambiar modo claro / oscuro"><svg class="luna" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg><svg class="sol" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></button></div>
<div class="caja"><form method="post">
  <div class="marca" style="padding:0"><span class="logo">AC</span><span>inventario<small>empresa:~/sistemas$</small></span></div>
  <div class="prompt"><b>$</b> ssh sistemas@empresa <span style="animation:parpadeo 1s steps(1) infinite">_</span></div>
  <?php if ($error): ?><div class="aviso aviso-error" style="margin:0"><?= e($error) ?></div><?php endif; ?>
  <?= csrf_campo() ?>
  <div class="campo"><label>Usuario o correo</label><input type="text" name="email" required autofocus autocomplete="username" autocapitalize="off" spellcheck="false" value="<?= e($_POST['email'] ?? '') ?>"></div>
  <div class="campo"><label>Clave</label><input type="password" name="clave" required autocomplete="current-password"></div>
  <button class="btn btn-primario btn-bloque">Ingresar</button>
</form></div>
<style>@keyframes parpadeo { 50% { opacity: 0; } }</style>
<script src="assets/app.js?v=7"></script>
</body>
</html>
