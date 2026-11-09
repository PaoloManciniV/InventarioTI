<?php
// herramientas: links de descarga de lo que usamos en sistemas y para que sirve cada una
require __DIR__ . '/config.php';
requiere_modulo('herramientas', 'contactos');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin()) {
    verificar_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $acc = $_POST['accion'] ?? '';
    if ((($_POST['que'] ?? '') === 'contacto') ? !puede('contactos') : !puede('herramientas')) { http_response_code(403); exit('Sin permiso'); }
    if (($_POST['que'] ?? '') === 'contacto') {
        if ($acc === 'borrar' && $id) {
            $st = db()->prepare('SELECT empresa FROM contactos WHERE id = ?');
            $st->execute([$id]);
            $n = $st->fetchColumn();
            db()->prepare('DELETE FROM contactos WHERE id = ?')->execute([$id]);
            auditar('contactos', $id, ['empresa' => $n], ['empresa' => null], 'Contacto eliminado');
            flash('Contacto eliminado');
            ir('herramientas.php?ver=contactos');
        }
        $c = [];
        foreach (['empresa', 'persona', 'para_que', 'telefonos', 'email', 'web', 'grupo', 'notas'] as $k) $c[$k] = trim($_POST[$k] ?? '') ?: null;
        $c['orden'] = ($_POST['orden'] ?? '') !== '' ? (int)$_POST['orden'] : null;
        if (!$c['empresa']) $error = 'Falta la empresa';
        elseif ($c['web'] && (!preg_match('#^https?://#i', $c['web']) || !filter_var($c['web'], FILTER_VALIDATE_URL))) $error = 'La web debe empezar por http:// o https://';
        elseif ($c['email']) foreach (preg_split('#[\s,;]+#', $c['email'], -1, PREG_SPLIT_NO_EMPTY) as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) { $error = 'Correo no válido: ' . $m; break; }
        if (!$error) {
            $cols = array_keys($c);
            if ($id) {
                $st = db()->prepare('SELECT * FROM contactos WHERE id = ?');
                $st->execute([$id]);
                $antes = $st->fetch();
                db()->prepare('UPDATE contactos SET ' . implode(', ', array_map(fn($x) => "$x = ?", $cols)) . ' WHERE id = ?')->execute(array_merge(array_values($c), [$id]));
                if ($antes) auditar('contactos', $id, $antes, $c);
            } else {
                db()->prepare('INSERT INTO contactos (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($c));
                auditar('contactos', (int)db()->lastInsertId(), null, null, 'Contacto creado');
            }
            flash('Contacto guardado');
            ir('herramientas.php?ver=contactos');
        }
        $_GET['ver'] = 'contactos';
        $_GET['ec'] = $id ?: 'nuevo';
    } else {
    if ($acc === 'borrar' && $id) {
        $st = db()->prepare('SELECT nombre FROM herramientas WHERE id = ?');
        $st->execute([$id]);
        $n = $st->fetchColumn();
        db()->prepare('DELETE FROM herramientas WHERE id = ?')->execute([$id]);
        auditar('herramientas', $id, ['nombre' => $n], ['nombre' => null], 'Herramienta eliminada');
        flash('Herramienta eliminada');
        ir('herramientas.php');
    }
    $d = [];
    foreach (['nombre', 'categoria', 'para_que', 'url', 'version', 'notas'] as $k) $d[$k] = trim($_POST[$k] ?? '') ?: null;
    $d['orden'] = ($_POST['orden'] ?? '') !== '' ? (int)$_POST['orden'] : null;
    // tambien se puede subir el archivo y queda para descargar desde aqui con sesion
    if (!empty($_FILES['archivo']['name']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $nom = preg_replace('#[^A-Za-z0-9_.() -]#', '_', basename($_FILES['archivo']['name']));
        $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
        if (!in_array($ext, ['zip', '7z', 'rar', 'exe', 'msi', 'pdf', 'docx', 'xlsx', 'csv', 'txt', 'png', 'jpg'], true)) $error = 'Tipo de archivo no permitido (.' . $ext . ')';
        else {
            $dir = DIR_UPLOADS . '/descargas';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            move_uploaded_file($_FILES['archivo']['tmp_name'], $dir . '/' . $nom);
            $d['url'] = 'descargar.php?f=' . rawurlencode($nom);
        }
    } elseif (!empty($_FILES['archivo']['name'])) {
        $error = 'No se pudo subir el archivo (muy pesado para el servidor o error de subida)';
    }
    $interno = $d['url'] && (preg_match('#^descargar\.php\?f=[A-Za-z0-9_.()%+ -]+$#', $d['url'])
        || (preg_match('#^[a-z0-9_]+\.php$#', $d['url']) && is_file(__DIR__ . '/' . $d['url'])));
    if (!$error && !$d['nombre']) $error = 'Falta el nombre';
    elseif (!$error && $d['url'] && !$interno && (!preg_match('#^https?://#i', $d['url']) || !filter_var($d['url'], FILTER_VALIDATE_URL))) $error = 'Pon un link que empiece por http:// o https://, o sube el archivo';
    elseif (!$error && !$d['url'] && !$d['notas']) $error = 'Sin link, pon en Notas cómo se hace (es una configuración)';
    if (!$error) {
        $cols = array_keys($d);
        if ($id) {
            $st = db()->prepare('SELECT * FROM herramientas WHERE id = ?');
            $st->execute([$id]);
            $antes = $st->fetch();
            db()->prepare('UPDATE herramientas SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute(array_merge(array_values($d), [$id]));
            if ($antes) auditar('herramientas', $id, $antes, $d);
        } else {
            db()->prepare('INSERT INTO herramientas (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($d));
            auditar('herramientas', (int)db()->lastInsertId(), null, null, 'Herramienta creada');
        }
        flash('Herramienta guardada');
        ir('herramientas.php');
    }
    }
}

$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM herramientas';
$par = [];
if ($q !== '') { $sql .= ' WHERE nombre LIKE ? OR para_que LIKE ? OR categoria LIKE ? OR notas LIKE ?'; $par = ["%$q%", "%$q%", "%$q%", "%$q%"]; }
$st = db()->prepare($sql . ' ORDER BY COALESCE(categoria, \'zz\'), orden IS NULL, orden, nombre');
$st->execute($par);
$lista = $st->fetchAll();
$grupos = [];
foreach ($lista as $h) $grupos[$h['categoria'] ?: 'Otras'][] = $h;
$hist = cambios_de_varios('herramientas', array_column($lista, 'id'), 10);
$ed = (int)($_GET['ed'] ?? 0);
$edit = $ed ? current(array_filter($lista, fn($h) => (int)$h['id'] === $ed)) : null;
$nuevo = isset($_GET['nueva']) || $edit || $error;
$cats = db()->query("SELECT DISTINCT categoria FROM herramientas WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

// contactos de soporte y compras
$ver = ($_GET['ver'] ?? '') === 'contactos' ? 'contactos' : 'herramientas';
if (!puede('herramientas')) { $ver = 'contactos'; $lista = []; $grupos = []; }
if (!puede('contactos')) $ver = 'herramientas';
$sqlc = 'SELECT * FROM contactos';
$parc = [];
if (!puede('contactos')) $sqlc .= ' WHERE 1 = 0';
elseif ($q !== '') { $sqlc .= ' WHERE CONCAT_WS(\' \', empresa, persona, para_que, telefonos, email, grupo, notas) LIKE ?'; $parc = ["%$q%"]; }
$st = db()->prepare($sqlc . ' ORDER BY COALESCE(grupo, \'zz\'), orden IS NULL, orden, empresa');
$st->execute($parc);
$contactos = $st->fetchAll();
$gruposc = [];
foreach ($contactos as $c) $gruposc[$c['grupo'] ?: 'Otros'][] = $c;
$histc = cambios_de_varios('contactos', array_column($contactos, 'id'), 10);
$ec = $_GET['ec'] ?? '';
$editc = ($ec && $ec !== 'nuevo') ? current(array_filter($contactos, fn($c) => (int)$c['id'] === (int)$ec)) : null;
$gruposc_lista = db()->query("SELECT DISTINCT grupo FROM contactos WHERE grupo IS NOT NULL AND grupo <> '' ORDER BY grupo")->fetchAll(PDO::FETCH_COLUMN);
if ($q !== '' && !$lista && $contactos && puede('contactos')) $ver = 'contactos';

// telefono: link para llamar y, si es celular de colombia, whatsapp
function link_tel(string $linea): string {
    $dig = preg_replace('#\D#', '', preg_replace('#\b(ext|extension|opci[oó]n)\b.*$#iu', '', $linea));
    if ($dig === '') return e($linea);
    $wa = '';
    $cel = preg_match('#^(57)?(3\d{9})$#', $dig, $m) ? '57' . $m[2] : '';
    if ($cel) $wa = ' <a class="chip chip-wa" href="https://wa.me/' . $cel . '" target="_blank" rel="noopener noreferrer" title="WhatsApp">WhatsApp</a>';
    return '<a href="tel:+' . (strlen($dig) === 10 && $dig[0] === '3' ? '57' . $dig : ltrim($dig, '0')) . '">' . e($linea) . '</a>' . $wa;
}

// que boton mostrar: descargar un archivo o abrir una pagina (web, portal, modulo del sistema)
function tipo_herr(?string $url): array {
    if (!$url) return ['local' => true, 'boton' => '', 'link' => '', 'donde' => 'configuración'];
    $local = !preg_match('#^https?://#i', $url);
    $ruta = strtolower((string)parse_url($url, PHP_URL_PATH));
    $archivo = strpos($url, 'descargar.php') === 0 || preg_match('#\.(zip|7z|rar|exe|msi|pdf|iso|dmg|apk|docx|xlsx|csv)$#', $ruta);
    return ['local' => $local, 'boton' => $archivo ? '↓ Descargar' : ($local ? 'Abrir' : 'Abrir ↗'),
        'link' => $local ? APP_URL . '/' . $url : $url,
        'donde' => strpos($url, 'descargar.php') === 0 ? 'archivo en el sistema' : ($local ? 'en este sistema' : (parse_url($url, PHP_URL_HOST) ?: $url))];
}

$titulo = 'Herramientas';
$menu = 'herramientas';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1><?= puede('herramientas') ? 'Herramientas' : 'Contactos de soporte y compras' ?></h1><small>Programas, portales, configuraciones y contactos de soporte que usamos en sistemas</small></div>
  <div class="acciones">
  <?php if ($ver === 'contactos'): ?><?= boton_cambios($histc, array_column($contactos, 'empresa', 'id'), 'herramientas.php?ver=contactos&ec=') ?>
    <?php if (es_admin()): ?><a class="btn btn-primario" href="?ver=contactos&ec=nuevo">+ Agregar contacto</a><?php endif; ?>
  <?php else: ?><?= boton_cambios($hist, array_column($lista, 'nombre', 'id'), 'herramientas.php?ed=') ?>
    <?php if (es_admin()): ?><a class="btn btn-primario" href="?nueva=1">+ Agregar herramienta</a><?php endif; ?><?php endif; ?></div>
</div>
<?php if (puede('herramientas') && puede('contactos')): ?><div class="pestanas-herr">
  <a href="herramientas.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>" class="<?= $ver === 'herramientas' ? 'activa' : '' ?>">Herramientas <small><?= count($lista) ?></small></a>
  <a href="herramientas.php?ver=contactos<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="<?= $ver === 'contactos' ? 'activa' : '' ?>">Contactos de soporte y compras <small><?= count($contactos) ?></small></a>
</div><?php endif; ?>
<?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($ver === 'herramientas'): ?>
<?php if ($nuevo && es_admin()): ?>
<form method="post" class="tarjeta" enctype="multipart/form-data"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <h2><?= $edit ? 'Editar herramienta' : 'Nueva herramienta' ?></h2>
  <div class="rejilla">
    <div class="campo"><label>Nombre</label><input name="nombre" required value="<?= e($_POST['nombre'] ?? $edit['nombre'] ?? '') ?>" placeholder="Kyocera Net Viewer"></div>
    <div class="campo"><label>Grupo</label><input name="categoria" list="cats" value="<?= e($_POST['categoria'] ?? $edit['categoria'] ?? '') ?>" placeholder="Impresoras, Red, Acceso remoto..."><datalist id="cats"><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
    <div class="campo" style="grid-column:1/-1"><label>Link (descarga, portal web o página del sistema) · vacío si es una configuración</label><input name="url" value="<?= e($_POST['url'] ?? $edit['url'] ?? '') ?>" placeholder="https://admin.cloud.microsoft"><small>Si es un archivo (.exe, .zip, .msi…) sale “Descargar”; si es una página sale “Abrir”</small></div>
    <div class="campo" style="grid-column:1/-1"><label>…o sube el archivo (zip, exe, msi, pdf…)</label><input type="file" name="archivo"><small>Queda guardado aquí y solo se descarga con sesión iniciada</small></div>
    <div class="campo" style="grid-column:1/-1"><label>¿Para qué sirve?</label><textarea name="para_que" rows="2" placeholder="Ver el nivel de tóner y la IP de todas las Kyocera de la red"><?= e($_POST['para_que'] ?? $edit['para_que'] ?? '') ?></textarea></div>
    <div class="campo"><label>Versión (opcional)</label><input name="version" value="<?= e($_POST['version'] ?? $edit['version'] ?? '') ?>"></div>
    <div class="campo"><label>Orden en el grupo (opcional)</label><input name="orden" type="number" min="0" value="<?= e($_POST['orden'] ?? $edit['orden'] ?? '') ?>"></div>
    <div class="campo" style="grid-column:1/-1"><label>Notas (instalación, licencia, usuario...)</label><textarea name="notas" rows="2"><?= e($_POST['notas'] ?? $edit['notas'] ?? '') ?></textarea></div>
  </div>
  <p style="display:flex;gap:8px;margin-bottom:0"><button class="btn btn-primario">Guardar</button><a class="btn btn-borde" href="herramientas.php">Cancelar</a></p>
</form>
<?php endif; ?>

<form class="filtros"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar herramienta, configuración o para qué sirve" style="flex:1"><button class="btn btn-borde">Buscar</button></form>

<?php if (!$lista): ?><div class="tarjeta vacio"><?= $q !== '' ? 'Nada con esa búsqueda.' : 'Todavía no hay herramientas. Agrega la primera con "+ Agregar herramienta".' ?></div><?php endif; ?>
<div class="grupos-herr">
<?php foreach ($grupos as $g => $hs): ?>
  <section class="grupo-bloque">
  <h2 class="grupo-herr"><?= e($g) ?></h2>
  <div class="herramientas">
  <?php foreach ($hs as $h): ?>
    <?php $t = tipo_herr((string)$h['url']); ?>
    <div class="herramienta">
      <div class="herr-cab"><b><?= e($h['nombre']) ?></b><?php if ($h['version']): ?><span class="chip chip-gris"><?= e($h['version']) ?></span><?php endif; ?></div>
      <p><?= nl2br(e($h['para_que'] ?: '—')) ?></p>
      <?php if ($h['notas']): ?><small class="<?= $h['url'] ? 'tenue' : 'herr-pasos' ?>" style="white-space:pre-line"><?= e($h['notas']) ?></small><?php endif; ?>
      <div class="herr-acc">
        <?php if ($t['boton']): ?><a class="btn btn-primario btn-sm" href="<?= e($h['url']) ?>" <?= $t['local'] ? '' : 'target="_blank" rel="noopener noreferrer"' ?>><?= $t['boton'] ?></a>
        <button type="button" class="btn btn-borde btn-sm" data-copiar="<?= e($t['link']) ?>">Copiar link</button>
        <?php elseif (preg_match('#(Equipo\\\\)?HKEY_[^\r\n]+#', (string)$h['notas'], $rk)): ?><button type="button" class="btn btn-primario btn-sm" data-copiar="<?= e(trim($rk[0])) ?>">Copiar ruta del registro</button>
        <?php else: ?><button type="button" class="btn btn-primario btn-sm" data-copiar="<?= e($h['notas']) ?>">Copiar pasos</button><?php endif; ?>
        <?php if (es_admin()): ?><a class="btn btn-borde btn-sm" href="?ed=<?= $h['id'] ?>">✎</a>
          <form method="post" style="display:contents" onsubmit="return confirm('¿Eliminar <?= e(addslashes($h['nombre'])) ?>?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= $h['id'] ?>"><button class="btn btn-borde btn-sm" title="Eliminar">✕</button></form><?php endif; ?>
      </div>
      <small class="herr-url mono"><?= e($t['donde']) ?></small>
    </div>
  <?php endforeach; ?>
  </div>
  </section>
<?php endforeach; ?>
</div>

<?php else: /* contactos */ ?>
<?php if ($ec && es_admin()): $cv = fn($k) => e($_POST[$k] ?? $editc[$k] ?? ''); ?>
<form method="post" class="tarjeta"><?= csrf_campo() ?><input type="hidden" name="que" value="contacto"><input type="hidden" name="id" value="<?= (int)($editc['id'] ?? 0) ?>">
  <h2><?= $editc ? 'Editar contacto' : 'Nuevo contacto' ?></h2>
  <div class="rejilla">
    <div class="campo"><label>Empresa</label><input name="empresa" required value="<?= $cv('empresa') ?>" placeholder="Copytel del Caribe"></div>
    <div class="campo"><label>Persona (opcional)</label><input name="persona" value="<?= $cv('persona') ?>"></div>
    <div class="campo"><label>Grupo</label><input name="grupo" list="gruposc" value="<?= $cv('grupo') ?>" placeholder="Impresoras, Compras y repuestos..."><datalist id="gruposc"><?php foreach ($gruposc_lista as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist></div>
    <div class="campo" style="grid-column:1/-1"><label>¿Para qué lo llamamos?</label><input name="para_que" value="<?= $cv('para_que') ?>" placeholder="Impresoras que no son Kyocera"></div>
    <div class="campo"><label>Teléfonos (uno por línea)</label><textarea name="telefonos" rows="2" placeholder="+57 310 3698104"><?= $cv('telefonos') ?></textarea></div>
    <div class="campo"><label>Correo(s)</label><input name="email" value="<?= $cv('email') ?>" placeholder="soporte@empresa.com"></div>
    <div class="campo"><label>Web / portal</label><input name="web" value="<?= $cv('web') ?>" placeholder="https://..."></div>
    <div class="campo"><label>Orden en el grupo</label><input name="orden" type="number" min="0" value="<?= $cv('orden') ?>"></div>
    <div class="campo" style="grid-column:1/-1"><label>Notas (crédito, horario, escalamiento...)</label><textarea name="notas" rows="2"><?= $cv('notas') ?></textarea></div>
  </div>
  <p style="display:flex;gap:8px;margin-bottom:0"><button class="btn btn-primario">Guardar</button><a class="btn btn-borde" href="herramientas.php?ver=contactos">Cancelar</a></p>
</form>
<?php endif; ?>

<form class="filtros"><input type="hidden" name="ver" value="contactos"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar empresa, persona, para qué, teléfono..." style="flex:1"><button class="btn btn-borde">Buscar</button></form>
<?php if (!$contactos): ?><div class="tarjeta vacio"><?= $q !== '' ? 'Nada con esa búsqueda.' : 'Todavía no hay contactos.' ?></div><?php endif; ?>
<?php foreach ($gruposc as $g => $cs): ?>
  <h2 class="grupo-herr"><?= e($g) ?></h2>
  <div class="herramientas">
  <?php foreach ($cs as $c): ?>
    <div class="herramienta contacto">
      <div class="herr-cab"><b><?= e($c['empresa']) ?></b></div>
      <?php if ($c['persona']): ?><div class="tenue" style="margin-top:-4px"><?= e($c['persona']) ?></div><?php endif; ?>
      <p><?= e($c['para_que'] ?: '—') ?></p>
      <div class="cont-datos">
        <?php foreach (preg_split('#\r?\n#', (string)$c['telefonos'], -1, PREG_SPLIT_NO_EMPTY) as $tl): ?><div>☎ <?= link_tel(trim($tl)) ?></div><?php endforeach; ?>
        <?php foreach (preg_split('#[\s,;]+#', (string)$c['email'], -1, PREG_SPLIT_NO_EMPTY) as $m): ?><div>✉ <a href="mailto:<?= e($m) ?>"><?= e($m) ?></a> <button type="button" class="btn-copiar-mini" data-copiar="<?= e($m) ?>" title="Copiar">⧉</button></div><?php endforeach; ?>
        <?php if ($c['web']): ?><div>↗ <a href="<?= e($c['web']) ?>" target="_blank" rel="noopener noreferrer"><?= e(parse_url($c['web'], PHP_URL_HOST) ?: $c['web']) ?></a></div><?php endif; ?>
      </div>
      <?php if ($c['notas']): ?><small class="herr-pasos" style="white-space:pre-line"><?= e($c['notas']) ?></small><?php endif; ?>
      <?php if (es_admin()): ?><div class="herr-acc"><a class="btn btn-borde btn-sm" href="?ver=contactos&ec=<?= $c['id'] ?>">✎</a>
        <form method="post" style="display:contents" onsubmit="return confirm('¿Eliminar este contacto?')"><?= csrf_campo() ?><input type="hidden" name="que" value="contacto"><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-borde btn-sm" title="Eliminar">✕</button></form></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
