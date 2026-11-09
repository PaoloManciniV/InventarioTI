<?php
// carga de .env conexion y sesion

function cargar_env($ruta) {
    if (!is_file($ruta)) return;
    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) continue;
        [$k, $v] = explode('=', $linea, 2);
        $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'")) $v = substr($v, 1, -1);
        $_ENV[trim($k)] = $v;
    }
}
// el .env puede vivir fuera de public_html (recomendado) o junto al proyecto
$env_privado = dirname(__DIR__, 2) . '/private/inventarioti.env';
cargar_env(is_file($env_privado) ? $env_privado : __DIR__ . '/.env');

// el smtp es el mismo de empleados salas y camionetas: se toma del .env privado fuera de public_html
function cargar_correo_compartido() {
    $ruta = env('MAIL_ENV', dirname(__DIR__, 2) . '/private/.env');
    if (!is_file($ruta) || !is_readable($ruta)) return;
    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $linea, 2));
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'")) $v = substr($v, 1, -1);
        // solo lo del correo y sin pisar lo que ya tenga el .env propio
        if (in_array($k, ['MAIL_HOST', 'MAIL_PORT', 'MAIL_USER', 'MAIL_PASS'], true) && ($_ENV[$k] ?? '') === '') $_ENV[$k] = $v;
    }
}

function env($k, $def = null) { return $_ENV[$k] ?? $def; }
cargar_correo_compartido();

define('APP_ENV', env('APP_ENV', 'local'));
define('APP_URL', rtrim(env('APP_URL', 'http://localhost/inventarioti'), '/'));
define('APP_NOMBRE', 'Inventario Agro-Costa');
define('DIR_UPLOADS', __DIR__ . '/uploads');

if (APP_ENV === 'prod') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

date_default_timezone_set('America/Bogota');

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . env('DB_NAME', 'agrocosta_inventario') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '-05:00'");
    return $pdo;
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    // cabeceras de seguridad en todas las paginas
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    if (APP_ENV === 'prod') header('Strict-Transport-Security: max-age=31536000');
    header_remove('X-Powered-By');
}

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('inventario_sess');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => APP_ENV === 'prod',
        'path' => parse_url(APP_URL, PHP_URL_PATH) ?: '/',
    ]);
    session_start();
    // sesion vence por inactividad y a las 12 horas aunque se use
    $ahora = time();
    if (!empty($_SESSION['usuario']) && (($ahora - ($_SESSION['ultimo'] ?? $ahora)) > 4 * 3600 || ($ahora - ($_SESSION['inicio'] ?? $ahora)) > 12 * 3600)) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['ultimo'] = $ahora;
    // cambia el id de sesion cada 30 minutos
    if (($_SESSION['rotado'] ?? 0) < $ahora - 1800) { session_regenerate_id(true); $_SESSION['rotado'] = $ahora; }
}

require_once __DIR__ . '/inc/funciones.php';
