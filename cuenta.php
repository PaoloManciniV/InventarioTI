<?php
// mi cuenta: cada quien cambia su usuario de ingreso y su clave
require __DIR__ . '/config.php';
requiere_login();

$uid = (int)usuario()['id'];
$st = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
$st->execute([$uid]);
$yo = $st->fetch();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $actual = $_POST['actual'] ?? '';
    $login = strtolower(trim($_POST['usuario'] ?? ''));
    $nueva = $_POST['nueva'] ?? '';
    $repite = $_POST['repite'] ?? '';
    if (!password_verify($actual, $yo['password_hash'])) {
        $error = 'La clave actual no es correcta';
        registrar_acceso($yo['email'], 0, 'clave actual mala en mi cuenta');
    } elseif ($err = usuario_invalido($login, $uid)) {
        $error = $err;
    } elseif ($nueva !== '' && ($err = clave_debil($nueva))) {
        $error = $err;
    } elseif ($nueva !== $repite) {
        $error = 'La clave nueva y la confirmación no son iguales';
    } else {
        $d = ['usuario' => $login ?: null];
        if ($nueva !== '') $d['password_hash'] = password_hash($nueva, PASSWORD_DEFAULT);
        db()->prepare('UPDATE usuarios SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($d))) . ' WHERE id = ?')
            ->execute(array_merge(array_values($d), [$uid]));
        auditar('usuarios', $uid, $yo, $d);
        if ($nueva !== '') session_regenerate_id(true);
        flash($nueva !== '' ? 'Listo: usuario y clave actualizados. Actualiza la clave guardada en Google.' : 'Usuario actualizado');
        ir('cuenta.php');
    }
}

$titulo = 'Mi cuenta';
$menu = '';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera"><div><h1>Mi cuenta</h1><small><?= e($yo['nombre']) ?> · <?= e($yo['email']) ?></small></div></div>

<?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="tarjeta" style="max-width:620px" autocomplete="on">
  <?= csrf_campo() ?>
  <h2>Usuario y clave</h2>
  <p class="tenue" style="margin-top:-6px">Puedes entrar con tu usuario o con tu correo. Un usuario propio (ej. <span class="mono">nombre.inventario</span>) evita que Google lo confunda con las claves de empleados, salas o camionetas.</p>
  <div class="campo"><label>Usuario para entrar</label>
    <input name="usuario" autocomplete="username" autocapitalize="off" spellcheck="false" value="<?= e($_POST['usuario'] ?? $yo['usuario'] ?? '') ?>" placeholder="ej. nombre.inventario">
    <small>Letras, números, punto o guion. Déjalo vacío para entrar solo con el correo.</small></div>
  <div class="rejilla">
    <div class="campo"><label>Clave nueva</label><input type="password" name="nueva" autocomplete="new-password" minlength="10"><small>Vacío = no cambia. Mínimo 10, con letras y números</small></div>
    <div class="campo"><label>Repite la clave nueva</label><input type="password" name="repite" autocomplete="new-password"></div>
  </div>
  <div class="campo"><label>Clave actual (para confirmar)</label><input type="password" name="actual" required autocomplete="current-password"></div>
  <p style="margin-bottom:0"><button class="btn btn-primario">Guardar</button></p>
</form>
<?php require __DIR__ . '/inc/footer.php';
