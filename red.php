<?php
// mapa de ips y directorio de extensiones
require __DIR__ . '/config.php';
requiere_modulo('red');

// asignar una ip libre desde el mapa: se le pone al equipo escogido y, si tenia otra, esa queda libre
$no_ip = ['disponible', 'baja', 'extraviado', 'cedido'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin() && ($_POST['accion'] ?? '') === 'asignar_ip') {
    verificar_csrf();
    $ip = trim($_POST['ip'] ?? '');
    $iid = (int)($_POST['item_id'] ?? 0);
    $st = db()->prepare('SELECT i.*, c.nombre persona FROM items i LEFT JOIN colaboradores c ON c.id = i.colaborador_id WHERE i.id = ?');
    $st->execute([$iid]);
    $it = $st->fetch();
    $ocupada = db()->prepare("SELECT etiqueta FROM items WHERE ip = ? AND estado <> 'baja' AND id <> ? LIMIT 1");
    $ocupada->execute([$ip, $iid]);
    $quien = $ocupada->fetchColumn();
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) flash('IP no válida', 'error');
    elseif (!$it) flash('Escoge a qué equipo se la vas a asignar', 'error');
    elseif (in_array($it['estado'], $no_ip, true)) flash($it['etiqueta'] . ' está ' . strtolower(estados_item()[$it['estado']]) . ': no lleva IP', 'error');
    elseif ($quien) flash('La ' . $ip . ' ya la tiene ' . $quien, 'error');
    else {
        $antes = $it['ip'];
        db()->prepare('UPDATE items SET ip = ?, actualizado = NOW() WHERE id = ?')->execute([$ip, $iid]);
        auditar('items', $iid, ['ip' => $antes], ['ip' => $ip], 'Asignada desde el mapa de IPs');
        registrar_movimiento($iid, 'ip', 'IP asignada: ' . $ip . ($antes ? ' (antes ' . $antes . ', esa queda libre)' : ''));
        flash($ip . ' asignada a ' . $it['etiqueta'] . ($it['persona'] ? ' (' . $it['persona'] . ')' : '') . ($antes ? '. La ' . $antes . ' quedó libre' : ''));
    }
    $n = substr(strrchr($ip, '.'), 1);
    ir('red.php' . (ctype_digit((string)$n) ? '#ip-' . $n : ''));
}

$vista = $_GET['vista'] ?? 'ips';
$q = trim($_GET['q'] ?? '');
$cat = $_GET['cat'] ?? '';

$where = ["i.estado <> 'baja'"];
$par = [];
if ($vista === 'extensiones') $where[] = "i.extension IS NOT NULL AND i.extension <> ''";
else $where[] = "i.ip IS NOT NULL AND i.ip <> ''";
if ($q !== '') {
    $where[] = '(i.ip LIKE ? OR i.etiqueta LIKE ? OR i.nombre LIKE ? OR i.hostname LIKE ? OR i.mac LIKE ? OR i.extension LIKE ? OR i.ubicacion LIKE ? OR c.nombre LIKE ?)';
    array_push($par, ...array_fill(0, 8, "%$q%"));
}
if ($cat !== '') { $where[] = 'i.categoria_id = ?'; $par[] = $cat; }

$st = db()->prepare('SELECT i.*, cat.nombre categoria, c.nombre responsable, c.area FROM items i
    LEFT JOIN categorias cat ON cat.id = i.categoria_id LEFT JOIN colaboradores c ON c.id = i.colaborador_id
    WHERE ' . implode(' AND ', $where) . ($vista === 'extensiones' ? ' ORDER BY CAST(i.extension AS UNSIGNED)' : ' ORDER BY INET_ATON(i.ip), i.ip'));
$st->execute($par);
$filas = $st->fetchAll();
$hist = cambios_de_varios('items', array_column($filas, 'id'), 10);
$volver = rawurlencode('red.php?' . http_build_query(array_filter(['vista' => $vista, 'q' => $q, 'cat' => $cat])));

// ips repetidas entre equipos activos
$repetidas = db()->query("SELECT ip FROM items WHERE ip IS NOT NULL AND ip <> '' AND estado <> 'baja' GROUP BY ip HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_COLUMN);

// mapa completo de la red principal: de la .1 a la .255, lo que no esta ocupado sale libre en verde
$red_base = '192.168.0';
$completo = $vista === 'ips' && $q === '' && $cat === '' && empty($_GET['solo']);
$mapa = [];
$libres_n = 0;
if ($completo) {
    $por_ip = [];
    $otras = [];
    foreach ($filas as $f) {
        $p = explode('.', $f['ip']);
        if (count($p) === 4 && $p[0] . '.' . $p[1] . '.' . $p[2] === $red_base && ctype_digit($p[3])) $por_ip[(int)$p[3]][] = $f;
        else $otras[] = $f;
    }
    // ips reservadas en notas de equipos que aun no se instalan ("IP reservada: 192.168.0.68")
    $reservas = [];
    foreach (db()->query("SELECT etiqueta, nombre, notas FROM items WHERE estado <> 'baja' AND notas LIKE '%IP reservada:%'") as $r)
        if (preg_match_all('#IP reservada:\s*' . preg_quote($red_base, '#') . '\.(\d{1,3})#', $r['notas'], $m)) foreach ($m[1] as $n) $reservas[(int)$n] = $r;
    for ($n = 1; $n <= 255; $n++) {
        if (!empty($por_ip[$n])) { foreach ($por_ip[$n] as $f) $mapa[] = ['item', $f]; continue; }
        if ($n === 255) { $mapa[] = ['fija', "$red_base.255", 'Broadcast de la red (no se asigna)']; continue; }
        if ($n === 1) { $mapa[] = ['fija', "$red_base.1", 'Gateway de la red según el subneteo (no se asigna)']; continue; }
        if (isset($reservas[$n])) { $mapa[] = ['reservada', "$red_base.$n", $reservas[$n]]; continue; }
        $mapa[] = ['libre', "$red_base.$n"];
        $libres_n++;
    }
    if ($otras) { $mapa[] = ['titulo', 'Otras redes']; foreach ($otras as $f) $mapa[] = ['item', $f]; }
} else {
    foreach ($filas as $f) $mapa[] = ['item', $f];
}

// ips libres por segmento
$libres = [];
if ($vista === 'libres') {
    $todas = db()->query("SELECT ip FROM items WHERE ip LIKE '%.%.%.%' AND estado <> 'baja'")->fetchAll(PDO::FETCH_COLUMN);
    $seg = [];
    foreach ($todas as $ip) {
        $p = explode('.', $ip);
        if (count($p) === 4) $seg[$p[0] . '.' . $p[1] . '.' . $p[2]][(int)$p[3]] = true;
    }
    ksort($seg);
    foreach ($seg as $s => $usadas) {
        $rangos = [];
        $ini = null;
        for ($n = 2; $n <= 255; $n++) {
            $libre = $n < 255 && empty($usadas[$n]);
            if ($libre && $ini === null) $ini = $n;
            if (!$libre && $ini !== null) {
                $rangos[] = $ini === $n - 1 ? "$s.$ini" : "$s.$ini a ." . ($n - 1);
                $ini = null;
            }
        }
        $libres[$s] = ['usadas' => count($usadas), 'rangos' => $rangos];
    }
}

$titulo = 'Red y extensiones';
$menu = 'red';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><h1>Red y extensiones</h1><small><?= count($filas) ?> registros<?= $completo ? ' · <b style="color:var(--verde, #16a34a)">' . $libres_n . ' IPs libres</b> en ' . e($red_base) . '.x' : '' ?><?= $repetidas ? ' · <b style="color:var(--rojo)">' . count($repetidas) . ' IP repetidas</b>' : '' ?></small></div>
  <div class="acciones"><?= boton_cambios($hist, array_column($filas, 'etiqueta', 'id')) ?><button class="btn btn-borde" onclick="window.print()">Imprimir</button></div>
</div>
<div class="pildoras no-imprimir">
  <a class="pildora <?= $vista === 'ips' ? 'activa' : '' ?>" href="?vista=ips">Mapa de IPs</a>
  <a class="pildora <?= $vista === 'extensiones' ? 'activa' : '' ?>" href="?vista=extensiones">Directorio de extensiones</a>
  <a class="pildora <?= $vista === 'libres' ? 'activa' : '' ?>" href="?vista=libres">IPs libres</a>
</div>

<?php if ($vista === 'libres'): ?>
  <?php foreach ($libres as $s => $l): ?>
  <div class="tarjeta"><h2><?= e($s) ?>.0/24 <small class="tenue">· <?= $l['usadas'] ?> usadas</small></h2>
    <div style="display:flex;flex-wrap:wrap;gap:6px"><?php foreach ($l['rangos'] as $r): ?><span class="chip chip-disponible mono"><?= e($r) ?></span><?php endforeach; ?></div></div>
  <?php endforeach; ?>
  <p class="tenue">Calculado con las IPs registradas en el inventario (de .2 a .254). Lo que no este registrado aqui no se ve.</p>
<?php else: ?>
<form class="filtros no-imprimir">
  <input type="hidden" name="vista" value="<?= e($vista) ?>">
  <input type="search" name="q" placeholder="IP, nombre, persona, hostname, MAC o extension" value="<?= e($q) ?>">
  <select name="cat" onchange="this.form.submit()"><?= opciones(categorias(false), $cat, 'nombre', 'Todos los tipos') ?></select>
  <button class="btn btn-borde">Buscar</button>
  <?php if ($vista === 'ips'): ?><a class="btn btn-borde" href="?vista=ips<?= $completo ? '&solo=1' : '' ?>"><?= $completo ? 'Ver solo ocupadas' : 'Ver de la .1 a la .255' ?></a><?php endif; ?>
</form>
<div class="tabla-envoltura"><table class="tabla">
  <?php if ($vista === 'extensiones'): ?>
  <thead><tr><th>Ext.</th><th>Persona / lugar</th><th>Area</th><th>IP telefono</th><th>Equipo</th><?php if (es_admin()): ?><th class="no-imprimir"></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($filas as $f): ?>
    <tr><td class="mono"><b><?= e($f['extension']) ?></b></td><td><?= e($f['responsable'] ?: $f['ubicacion'] ?: $f['nombre']) ?></td>
      <td><?= e($f['area'] ?: '—') ?></td><td class="mono"><?= e($f['ip'] ?: '—') ?></td>
      <td class="mono"><a href="item.php?id=<?= $f['id'] ?>"><?= e($f['etiqueta']) ?></a></td>
      <?php if (es_admin()): ?><td class="no-imprimir"><a class="btn btn-borde btn-sm" href="item.php?id=<?= $f['id'] ?>&editar=1&volver=<?= $volver ?>">✎ Editar</a></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php else: ?>
  <thead><tr><th>IP</th><th>Equipo</th><th>Tipo</th><th>Persona / ubicacion</th><th>Hostname</th><th>MAC</th><th>Ext.</th><?php if (es_admin()): ?><th class="no-imprimir"></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($mapa as $fila):
    if ($fila[0] === 'libre'): ?>
    <tr class="ip-libre" id="ip-<?= e(substr(strrchr($fila[1], '.'), 1)) ?>" data-ip="<?= e($fila[1]) ?>"<?= es_admin() ? ' title="Doble clic para asignarla"' : '' ?>><td class="mono"><b><?= e($fila[1]) ?></b></td><td colspan="6"><b>LIBRE</b> <span>· lista para ser asignada<?= es_admin() ? ' · doble clic para asignarla' : '' ?></span></td>
      <?php if (es_admin()): ?><td class="no-imprimir" style="white-space:nowrap"><button type="button" class="btn btn-primario btn-sm" data-asignar>Asignar</button> <button type="button" class="btn-copiar-mini" data-copiar="<?= e($fila[1]) ?>" title="Copiar IP">⧉</button></td><?php endif; ?></tr>
    <?php continue; elseif ($fila[0] === 'reservada'): ?>
    <tr class="ip-reservada"><td class="mono"><b><?= e($fila[1]) ?></b></td><td colspan="6"><b>RESERVADA</b> · para <?= e($fila[2]['nombre']) ?> <span class="mono">(<?= e($fila[2]['etiqueta']) ?>)</span>, aún no se instala</td><?php if (es_admin()): ?><td class="no-imprimir"></td><?php endif; ?></tr>
    <?php continue; elseif ($fila[0] === 'fija'): ?>
    <tr class="ip-fija"><td class="mono"><b><?= e($fila[1]) ?></b></td><td colspan="6"><?= e($fila[2]) ?></td><?php if (es_admin()): ?><td class="no-imprimir"></td><?php endif; ?></tr>
    <?php continue; elseif ($fila[0] === 'titulo'): ?>
    <tr><td colspan="8" style="padding-top:18px"><b><?= e($fila[1]) ?></b></td></tr>
    <?php continue; endif;
    $f = $fila[1]; $rep = in_array($f['ip'], $repetidas, true); $srv = in_array($f['categoria'], ['Servidor', 'Servidor virtual'], true); ?>
    <tr<?= strpos($f['ip'], $red_base . '.') === 0 ? ' id="ip-' . e(substr(strrchr($f['ip'], '.'), 1)) . '"' : '' ?> class="<?= trim(($rep ? 'ip-conflicto ' : '') . ($srv ? 'fila-servidor' : '')) ?>"><td class="mono"><b><?= e($f['ip']) ?></b><?= $rep ? ' <span class="chip chip-danado">repetida</span>' : '' ?></td>
      <td><a class="mono" href="item.php?id=<?= $f['id'] ?>"><?= e($f['etiqueta']) ?></a><small><?= e($f['nombre']) ?></small>
        <?php if ($srv && $f['especificaciones']): ?><small class="srv-desc"><?= e($f['especificaciones']) ?></small><?php endif; ?></td>
      <td><?= $srv ? '<span class="chip chip-servidor">' . e($f['categoria']) . '</span>' : e($f['categoria']) ?></td><td><?= e($f['responsable'] ?: ($f['ubicacion'] ?: '—')) ?></td>
      <td class="mono"><?= e($f['hostname'] ?: '') ?></td><td class="mono"><?= e($f['mac'] ?: '') ?></td><td><?= e($f['extension'] ?: '') ?></td>
      <?php if (es_admin()): ?><td class="no-imprimir"><a class="btn btn-borde btn-sm" href="item.php?id=<?= $f['id'] ?>&editar=1&volver=<?= $volver ?>">✎ Editar</a></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php if (!$filas): ?><tr><td colspan="8" class="vacio">Sin registros</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php if ($completo && es_admin()):
  // equipos que pueden llevar ip: en uso (no disponibles ni de baja); primero los que no tienen
  $cand = db()->query("SELECT i.id, i.etiqueta, i.nombre, i.ip, i.estado, cat.nombre categoria, COALESCE(c.nombre, p.nombre, i.ubicacion) quien FROM items i
      LEFT JOIN categorias cat ON cat.id = i.categoria_id LEFT JOIN colaboradores c ON c.id = i.colaborador_id LEFT JOIN colaboradores p ON p.id = i.prestado_a
      WHERE i.estado NOT IN ('" . implode("','", $no_ip) . "')
        AND (cat.nombre IN ('Computador', 'Portatil', 'Telefono IP', 'Impresora', 'Access point', 'Servidor', 'Servidor virtual', 'Video beam', 'Red', 'Tablet') OR COALESCE(i.ip, '') <> '')
      ORDER BY COALESCE(i.ip, '') <> '', cat.nombre, i.etiqueta")->fetchAll(); ?>
<div id="asignar-ip" class="asignar-ip" hidden>
  <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="asignar_ip"><input type="hidden" name="ip">
    <div class="asignar-cab"><b>Asignar la <span class="mono" data-ip-txt></span></b> <small class="tenue">a un equipo en uso (los disponibles no llevan IP)</small></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
      <div class="campo" style="flex:1;min-width:280px;margin:0"><select name="item_id" data-buscar required>
        <option value="">Escribe etiqueta, equipo o persona…</option>
        <?php foreach ($cand as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['etiqueta'] . ' · ' . $c['nombre'] . ($c['quien'] ? ' · ' . $c['quien'] : '') . ($c['ip'] ? ' — tiene ' . $c['ip'] . ', se cambia' : ' — sin IP')) ?></option><?php endforeach; ?>
      </select></div>
      <button class="btn btn-primario">Guardar</button><button type="button" class="btn btn-borde" data-cerrar>Cancelar</button>
    </div>
  </form>
</div>
<script>
(function () {
  var panel = document.getElementById('asignar-ip'), fila = null;
  function cerrar() { if (fila) { fila.remove(); fila = null; } panel.hidden = true; document.body.appendChild(panel); }
  function abrir(tr) {
    cerrar();
    fila = document.createElement('tr'); fila.className = 'fila-asignar';
    var td = document.createElement('td'); td.colSpan = 8; fila.appendChild(td);
    tr.parentNode.insertBefore(fila, tr.nextSibling); td.appendChild(panel); panel.hidden = false;
    panel.querySelector('input[name=ip]').value = tr.dataset.ip;
    panel.querySelector('[data-ip-txt]').textContent = tr.dataset.ip;
    var campo = panel.querySelector('.combo input') || panel.querySelector('select'); if (campo) campo.focus();
  }
  document.querySelectorAll('tr.ip-libre').forEach(function (tr) {
    tr.addEventListener('dblclick', function () { abrir(tr); });
    var b = tr.querySelector('[data-asignar]'); if (b) b.addEventListener('click', function (e) { e.stopPropagation(); abrir(tr); });
  });
  panel.querySelector('[data-cerrar]').addEventListener('click', cerrar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && fila) cerrar(); });
  if (location.hash) { var t = document.querySelector(location.hash); if (t) { t.scrollIntoView({ block: 'center' }); t.classList.add('destello'); } }
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
