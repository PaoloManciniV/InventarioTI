<?php
// crea las tablas y el primer administrador
// solo funciona mientras no exista ningun usuario
// despues de usarlo se puede borrar del servidor
require __DIR__ . '/config.php';

$pdo = db();
$sql = file_get_contents(__DIR__ . '/schema.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) $pdo->exec($q);

if ((int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0) {
    exit('Ya esta instalado. Borra instalar.php del servidor.');
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave = $_POST['clave'] ?? '';
    if (strlen($clave) < 10) {
        $msg = 'La clave debe tener minimo 10 caracteres';
    } else {
        $st = $pdo->prepare("INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?,?,?,'admin')");
        $st->execute([trim($_POST['nombre']), strtolower(trim($_POST['email'])), password_hash($clave, PASSWORD_DEFAULT)]);
        if (!empty($_POST['sedes'])) {
            $st = $pdo->prepare('INSERT INTO sedes (nombre) VALUES (?)');
            foreach (array_filter(array_map('trim', explode(',', $_POST['sedes']))) as $s) $st->execute([$s]);
        }
        exit('Listo. <a href="login.php">Ingresar</a> y luego borra instalar.php del servidor.');
    }
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalar inventario</title><link rel="stylesheet" href="assets/app.css"></head>
<body><div class="publico"><div class="tarjeta">
<h2>Primer administrador</h2>
<?php if ($msg): ?><div class="aviso aviso-error"><?= e($msg) ?></div><?php endif; ?>
<form method="post" class="rejilla">
  <div class="campo"><label>Nombre</label><input name="nombre" required></div>
  <div class="campo"><label>Correo</label><input type="email" name="email" required></div>
  <div class="campo"><label>Clave</label><input type="password" name="clave" required minlength="10"></div>
  <div class="campo ancho"><label>Sedes separadas por coma</label><input name="sedes" placeholder="Principal, Bodega"></div>
  <div class="ancho"><button class="btn btn-primario">Crear</button></div>
</form></div></div></body></html>
