<?php
// "olvidé mi clave": se pide con el usuario o el correo, llega un código de 6 dígitos al correo registrado,
// dura 10 minutos y tiene 5 intentos. Con el código se pone la clave nueva (y se quita el bloqueo de ingreso)
require __DIR__ . '/config.php';

if (usuario()) ir('index.php');

const MINUTOS_CODIGO = 10;
const INTENTOS_CODIGO = 5;
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$error = '';
$aviso = '';
$paso = !empty($_SESSION['recup']) && ($_GET['otro'] ?? '') === '' ? 2 : 1;
if (($_GET['otro'] ?? '') !== '') unset($_SESSION['recup']);

// si falta la tabla, decirlo en vez de un error 500
try { db()->query('SELECT 1 FROM claves_recuperacion LIMIT 1'); $instalado = true; } catch (Throwable $e) { $instalado = false; }

if ($instalado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $acc = $_POST['accion'] ?? '';

    if ($acc === 'pedir') {
        $quien = strtolower(trim($_POST['email'] ?? ''));
        // limites: 5 pedidos por ip y 3 por persona en una hora
        $st = db()->prepare('SELECT COUNT(*) FROM claves_recuperacion WHERE ip = ? AND creado > NOW() - INTERVAL 1 HOUR');
        $st->execute([$ip]);
        if ($quien === '') {
            $error = 'Escribe tu usuario o correo';
        } elseif ((int)$st->fetchColumn() >= 5) {
            $error = 'Demasiadas solicitudes desde este equipo. Intenta en una hora o pídele a sistemas que te cambie la clave.';
        } else {
            $st = db()->prepare('SELECT * FROM usuarios WHERE (LOWER(email) = ? OR LOWER(usuario) = ?) AND activo = 1 LIMIT 1');
            $st->execute([$quien, $quien]);
            $u = $st->fetch();
            $rid = 0;
            if ($u && $u['email']) {
                $st = db()->prepare('SELECT COUNT(*) FROM claves_recuperacion WHERE usuario_id = ? AND creado > NOW() - INTERVAL 1 HOUR');
                $st->execute([$u['id']]);
                if ((int)$st->fetchColumn() < 3) {
                    $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    // el codigo anterior que no se uso deja de servir
                    db()->prepare('UPDATE claves_recuperacion SET usado = 1 WHERE usuario_id = ? AND usado = 0')->execute([$u['id']]);
                    db()->prepare('INSERT INTO claves_recuperacion (usuario_id, codigo_hash, expira, ip) VALUES (?, ?, NOW() + INTERVAL ' . MINUTOS_CODIGO . ' MINUTE, ?)')
                        ->execute([$u['id'], password_hash($codigo, PASSWORD_DEFAULT), $ip]);
                    $rid = (int)db()->lastInsertId();
                    $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;border:1px solid #ddd">'
                        . '<div style="background:#000;color:#ffcd11;padding:14px 20px;font-weight:bold;letter-spacing:1px">INVENTARIO TI · CAMBIO DE CLAVE</div>'
                        . '<div style="padding:22px;color:#222"><p style="margin-top:0">Hola, <b>' . e(explode(' ', $u['nombre'])[0]) . '</b>. Pediste cambiar tu clave del inventario TI. Tu código es:</p>'
                        . '<div style="font-family:Consolas,monospace;font-size:34px;font-weight:bold;letter-spacing:8px;text-align:center;background:#f6f6f6;border:1px solid #e3e3e3;padding:14px;margin:12px 0">' . $codigo . '</div>'
                        . '<p>Vence en <b>' . MINUTOS_CODIGO . ' minutos</b> y sirve una sola vez.</p>'
                        . '<p style="color:#777;font-size:13px">Si no fuiste tú, ignora este correo: tu clave no cambia. Pedido desde la IP ' . e($ip) . ' el ' . date('d/m/Y g:i a') . '.</p></div></div>';
                    enviar_correo($u['email'], $u['nombre'], 'Tu código para cambiar la clave: ' . $codigo, $html);
                }
            } else {
                usleep(400000); // mismo tiempo de respuesta exista o no
            }
            // no se dice si el usuario existe: el paso 2 sale igual
            $_SESSION['recup'] = ['rid' => $rid, 'desde' => time()];
            $paso = 2;
            $aviso = 'Si el usuario existe, le llegó un código de 6 dígitos al correo registrado. Vence en ' . MINUTOS_CODIGO . ' minutos.';
        }
    }

    if ($acc === 'cambiar' && !empty($_SESSION['recup'])) {
        $paso = 2;
        $codigo = preg_replace('/\D/', '', $_POST['codigo'] ?? '');
        $clave = $_POST['clave'] ?? '';
        $rid = (int)$_SESSION['recup']['rid'];
        $st = db()->prepare('SELECT r.*, u.email, u.usuario, u.nombre FROM claves_recuperacion r JOIN usuarios u ON u.id = r.usuario_id AND u.activo = 1 WHERE r.id = ?');
        $st->execute([$rid]);
        $r = $rid ? $st->fetch() : null;
        $vencido = !$r || $r['usado'] || strtotime($r['expira']) < time() || (int)$r['intentos'] >= INTENTOS_CODIGO;
        if ($vencido) {
            sleep(1);
            $error = 'El código no es válido o ya venció. Pide uno nuevo.';
        } elseif (!password_verify($codigo, $r['codigo_hash'])) {
            db()->prepare('UPDATE claves_recuperacion SET intentos = intentos + 1 WHERE id = ?')->execute([$rid]);
            $quedan = INTENTOS_CODIGO - (int)$r['intentos'] - 1;
            sleep(1);
            $error = $quedan > 0 ? 'Código incorrecto. Te quedan ' . $quedan . ' intento' . ($quedan > 1 ? 's' : '') . '.' : 'Código incorrecto. Ya no sirve: pide uno nuevo.';
        } elseif ($clave !== ($_POST['clave2'] ?? '')) {
            $error = 'Las dos claves no coinciden';
        } elseif ($debil = clave_debil($clave)) {
            $error = $debil;
        } else {
            db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $r['usuario_id']]);
            db()->prepare('UPDATE claves_recuperacion SET usado = 1 WHERE usuario_id = ? AND usado = 0')->execute([$r['usuario_id']]);
            // se quita el bloqueo por claves malas para que pueda entrar ya
            db()->prepare('DELETE FROM accesos WHERE ok = 0 AND fecha > NOW() - INTERVAL 15 MINUTE AND (LOWER(email) = LOWER(?) OR LOWER(email) = LOWER(?))')->execute([$r['email'], (string)$r['usuario']]);
            try { auditar('usuarios', (int)$r['usuario_id'], null, null, 'Cambió su clave con el código del correo'); } catch (Throwable $e) { }
            enviar_correo($r['email'], $r['nombre'], 'Se cambió tu clave del inventario TI',
                '<p>Hola, ' . e(explode(' ', $r['nombre'])[0]) . '. Tu clave del inventario TI se cambió el ' . date('d/m/Y g:i a') . ' con el código que llegó a este correo (IP ' . e($ip) . ').</p><p>Si no fuiste tú, avísale de inmediato a sistemas.</p>');
            if ($alerta = env('MAIL_ALERTAS', '')) {
                enviar_correo($alerta, 'Sistemas', 'Cambio de clave con código: ' . $r['nombre'],
                    '<p><b>' . e($r['nombre']) . '</b> (' . e($r['email']) . ') cambió su clave del inventario TI con el código del correo.</p><p>IP: ' . e($ip) . '<br>Hora: ' . date('d/m/Y g:i a') . '</p>');
            }
            unset($_SESSION['recup']);
            flash('Listo: tu clave quedó cambiada. Ya puedes ingresar.');
            ir('login.php');
        }
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#000000">
<title>Recuperar clave · Inventario Agro-Costa</title>
<script>try{if(localStorage.getItem("tema")==="claro")document.documentElement.setAttribute("data-tema","claro")}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=35">
<style>
  body { display: grid; place-items: center; min-height: 100vh; padding: 16px; }
  .caja { background: var(--tarjeta); border: 1px solid var(--borde); border-radius: 6px; width: 100%; max-width: 400px; overflow: hidden; }
  .caja::before { content: ""; display: block; height: 8px; background: var(--franja); }
  .caja form { padding: 26px; display: grid; gap: 14px; }
  .prompt { font-family: var(--mono); font-size: 12px; color: var(--tenue); }
  .prompt b { color: var(--cat); }
  .codigo { font-family: var(--mono); font-size: 26px; letter-spacing: 10px; text-align: center; }
  .pie { display: flex; justify-content: space-between; font-size: 13px; }
</style>
</head>
<body>
<div class="caja"><form method="post" autocomplete="off">
  <div class="marca" style="padding:0"><span class="logo">AC</span><span>inventario<small>empresa:~/sistemas$</small></span></div>
  <div class="prompt"><b>$</b> passwd <?= $paso === 2 ? '--codigo' : '--olvide' ?></div>
  <?php if (!$instalado): ?><div class="aviso aviso-error" style="margin:0">Falta correr <b>68_recuperar_clave.sql</b> en phpMyAdmin.</div><?php endif; ?>
  <?php if ($error): ?><div class="aviso aviso-error" style="margin:0"><?= e($error) ?></div><?php endif; ?>
  <?php if ($aviso): ?><div class="aviso" style="margin:0"><?= e($aviso) ?></div><?php endif; ?>
  <?= csrf_campo() ?>
  <?php if ($paso === 1): ?>
    <input type="hidden" name="accion" value="pedir">
    <p class="tenue" style="margin:0">Escribe tu usuario o tu correo. Te llega un código al correo registrado para que pongas una clave nueva.</p>
    <div class="campo"><label>Usuario o correo</label><input type="text" name="email" required autofocus autocapitalize="off" spellcheck="false" value="<?= e($_POST['email'] ?? '') ?>"></div>
    <button class="btn btn-primario btn-bloque">Enviarme el código</button>
  <?php else: ?>
    <input type="hidden" name="accion" value="cambiar">
    <?php if (!$aviso && !$error): ?><p class="tenue" style="margin:0">Escribe el código que te llegó al correo (vence en <?= MINUTOS_CODIGO ?> minutos) y tu clave nueva.</p><?php endif; ?>
    <div class="campo"><label>Código de 6 dígitos</label><input class="codigo" name="codigo" required inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" autofocus></div>
    <div class="campo"><label>Clave nueva</label><input type="password" name="clave" required minlength="10" autocomplete="new-password"><small>Mínimo 10 caracteres, con letras y números</small></div>
    <div class="campo"><label>Repite la clave nueva</label><input type="password" name="clave2" required minlength="10" autocomplete="new-password"></div>
    <button class="btn btn-primario btn-bloque">Cambiar clave</button>
  <?php endif; ?>
  <div class="pie"><a href="login.php">← Volver a ingresar</a><?php if ($paso === 2): ?><a href="recuperar.php?otro=1">Pedir otro código</a><?php endif; ?></div>
</form></div>
<script src="assets/app.js?v=16"></script>
</body>
</html>
