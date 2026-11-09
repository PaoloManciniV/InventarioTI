<?php
// generador de firmas de correo en la web: mismo diseno del programa de escritorio
require __DIR__ . '/config.php';
requiere_modulo('herramientas');

// datos fijos de la firma, editables por admin
$def = ['sede1' => 'Sede Principal: Vía 40 # 85-1247', 'sede2' => 'Barranquilla - Colombia', 'pbx' => '(+57) (605) 3869999',
    'whatsapp' => '(+57) 311 415 6001', 'web' => 'www.agro-costa.com', 'direccion' => 'Via 40 #85-1247', 'ciudad' => 'Barranquilla'];
$aj = $def;
foreach (db()->query("SELECT clave, valor FROM ajustes WHERE clave LIKE 'firma_%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $aj[substr($k, 6)] = $v;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin()) {
    verificar_csrf();
    $antes = [];
    foreach ($def as $k => $_) {
        $v = trim($_POST[$k] ?? '');
        if ($v === '') continue;
        $antes[$k] = $aj[$k];
        db()->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute(['firma_' . $k, $v]);
        if ($aj[$k] !== $v) auditar('ajustes', 0, [$k => $aj[$k]], [$k => $v], 'Datos fijos de la firma');
    }
    flash('Datos fijos de la firma guardados');
    ir('firmas.php');
}

// colaboradores con su extension
$gente = db()->query("SELECT c.id, c.nombre, c.cargo, c.email, c.telefono, COALESCE(a.nombre, c.area) area,
    (SELECT i.extension FROM items i WHERE i.colaborador_id = c.id AND COALESCE(i.extension, '') <> '' AND i.estado <> 'baja' ORDER BY i.id LIMIT 1) extension
    FROM colaboradores c LEFT JOIN areas a ON a.id = c.area_id WHERE c.activo = 1 AND COALESCE(c.email, '') <> '' ORDER BY c.nombre")->fetchAll();

$titulo = 'Generador de firmas';
$menu = 'herramientas';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><a href="herramientas.php" class="tenue">&larr; Herramientas</a><h1>Generador de firmas</h1><small>Firma de correo en PNG con QR de contacto · igual al programa de escritorio, sin instalar nada</small></div>
</div>

<div class="dos-col firmas-dos">
  <div>
    <div class="tarjeta">
      <h2>Datos del empleado</h2>
      <div class="campo"><label>Llenar desde un colaborador (opcional)</label>
        <select id="f-colab" data-buscar><option value="">Escribe nombre…</option>
          <?php foreach ($gente as $g): ?><option value="<?= $g['id'] ?>"><?= e($g['nombre']) ?><?= $g['area'] ? ' · ' . e($g['area']) : '' ?></option><?php endforeach; ?></select></div>
      <div class="rejilla">
        <div class="campo"><label>Nombre completo *</label><input id="f-nombre" placeholder="Mario Diaz"></div>
        <div class="campo"><label>Cargo *</label><input id="f-cargo" placeholder="Asesor Comercial"></div>
        <div class="campo"><label>Email *</label><input id="f-email" type="email" placeholder="nombre@agro-costa.com"></div>
        <div class="campo"><label>Celular</label><input id="f-cel" placeholder="(+57) 300 392 1206"></div>
        <div class="campo"><label>Extensión</label><input id="f-ext" inputmode="numeric" placeholder="123"></div>
      </div>
      <p style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:0">
        <button type="button" class="btn btn-primario" id="b-descargar">↓ Descargar PNG</button>
        <button type="button" class="btn btn-borde" id="b-copiar">Copiar imagen</button>
        <button type="button" class="btn btn-borde" id="b-limpiar">Limpiar</button></p>
    </div>

    <div class="tarjeta">
      <h2>Varias a la vez</h2>
      <p class="tenue" style="margin-top:-6px">Escoge colaboradores o sube un CSV (en Excel: Guardar como → CSV UTF-8) con columnas <span class="mono">nombre, cargo, email, cel, extension</span>. Se descarga un ZIP con todas.</p>
      <div class="filtros" style="margin-bottom:8px">
        <select id="m-area"><option value="">Todas las áreas</option><?php foreach (array_unique(array_filter(array_column($gente, 'area'))) as $a): ?><option><?= e($a) ?></option><?php endforeach; ?></select>
        <button type="button" class="btn btn-borde btn-sm" id="m-todos">Marcar todos</button>
        <button type="button" class="btn btn-borde btn-sm" id="m-ninguno">Ninguno</button>
      </div>
      <div class="lista-firmas" id="m-lista">
        <?php foreach ($gente as $g): ?><label data-area="<?= e($g['area']) ?>"><input type="checkbox" value="<?= $g['id'] ?>"> <?= e($g['nombre']) ?> <small class="tenue"><?= e($g['cargo'] ?: 'sin cargo') ?></small></label><?php endforeach; ?>
      </div>
      <div class="campo" style="margin-top:10px"><label>…o sube un CSV (UTF-8)</label><input type="file" id="m-csv" accept=".csv,text/csv"></div>
      <p style="margin-bottom:0"><button type="button" class="btn btn-primario" id="m-generar">↓ Descargar ZIP</button> <span class="tenue" id="m-estado"></span></p>
    </div>
  </div>

  <div>
    <div class="tarjeta">
      <h2>Vista previa</h2>
      <div class="firma-prev"><canvas id="lienzo" width="695" height="181"></canvas></div>
      <small class="tenue">Para Outlook: Archivo → Opciones → Correo → Firmas → insertar imagen.</small>
    </div>
    <details class="tarjeta" <?= es_admin() ? '' : 'style="display:none"' ?>>
      <summary style="cursor:pointer"><b>Datos fijos de la firma</b> <small class="tenue">(sede, PBX, WhatsApp, web)</small></summary>
      <form method="post" style="margin-top:10px"><?= csrf_campo() ?>
        <div class="rejilla">
          <div class="campo"><label>Sede línea 1</label><input name="sede1" value="<?= e($aj['sede1']) ?>"></div>
          <div class="campo"><label>Sede línea 2</label><input name="sede2" value="<?= e($aj['sede2']) ?>"></div>
          <div class="campo"><label>PBX</label><input name="pbx" value="<?= e($aj['pbx']) ?>"></div>
          <div class="campo"><label>WhatsApp ventas</label><input name="whatsapp" value="<?= e($aj['whatsapp']) ?>"></div>
          <div class="campo"><label>Web</label><input name="web" value="<?= e($aj['web']) ?>"></div>
          <div class="campo"><label>Dirección (QR)</label><input name="direccion" value="<?= e($aj['direccion']) ?>"></div>
          <div class="campo"><label>Ciudad (QR)</label><input name="ciudad" value="<?= e($aj['ciudad']) ?>"></div>
        </div>
        <p style="margin-bottom:0"><button class="btn btn-borde">Guardar datos fijos</button></p>
      </form>
    </details>
  </div>
</div>

<script src="assets/vendor/qrcode.js"></script>
<script src="assets/vendor/jszip.min.js"></script>
<script>
(function () {
  var FIJO = <?= json_encode($aj, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var GENTE = <?= json_encode(array_column($gente, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var REG = '"Arial", "Liberation Sans", "Helvetica Neue", Helvetica, sans-serif';
  var plantilla = new Image();
  plantilla.src = 'assets/firmas/template.png';
  var lienzo = document.getElementById('lienzo');
  var $ = function (id) { return document.getElementById(id); };

  // posiciones del diseno original
  var M_SEDE = 405, M_TEL = 405, M_EMAIL = 428, M_WEB = 443, CX = 569;
  var Y_SEDE1 = 12, Y_SEDE2 = 31, Y_EMAIL = 122, Y_WEB = 158, Y_NOMBRE = 34, Y_CARGO = 78, ALTO = 18;
  var QR_X = 10, QR_Y = 14, QR_T = 145;

  function fuente(t, bold) { return (bold ? 'bold ' : '') + t + 'px ' + REG; }
  function ancho(ctx, txt, t, bold) { ctx.font = fuente(t, bold); return ctx.measureText(txt).width; }
  // pil dibuja desde la linea del ascendente; en canvas se baja el ascendente de arial
  function texto(ctx, txt, x, y, t, bold, color) { ctx.font = fuente(t, bold); ctx.fillStyle = color; ctx.textBaseline = 'alphabetic'; ctx.fillText(txt, x, y + Math.round(t * 0.905)); }
  function tel(n) { return (n || '').replace(/[^0-9+]/g, ''); }

  function qr(d) {
    var v = ['BEGIN:VCARD', 'VERSION:3.0', 'N:;' + d.nombre + ';;;', 'FN:' + d.nombre, 'ORG:Agro-Costa', 'TITLE:' + d.cargo];
    if (tel(d.cel)) v.push('TEL;TYPE=CELL:' + tel(d.cel));
    v.push('TEL;TYPE=WORK:' + tel(FIJO.pbx), 'TEL;TYPE=CELL,VOICE:' + tel(FIJO.whatsapp), 'EMAIL:' + d.email,
      'ADR;TYPE=WORK:;;' + FIJO.direccion + ';' + FIJO.ciudad + ';;;Colombia', 'URL:https://' + FIJO.web.replace(/^https?:\/\//, ''), 'END:VCARD');
    qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
    var q = qrcode(0, 'M');
    q.addData(v.join('\n'), 'Byte');
    q.make();
    var n = q.getModuleCount(), tot = n + 2;
    var c = document.createElement('canvas'); c.width = tot; c.height = tot;
    var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, tot, tot); x.fillStyle = '#000';
    for (var r = 0; r < n; r++) for (var k = 0; k < n; k++) if (q.isDark(r, k)) x.fillRect(k + 1, r + 1, 1, 1);
    return c;
  }

  function dibujar(ctx, d) {
    ctx.clearRect(0, 0, 695, 181);
    ctx.drawImage(plantilla, 0, 0);
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(qr(d), QR_X, QR_Y, QR_T, QR_T);
    ctx.imageSmoothingEnabled = true;
    var blanco = '#fff', negro = '#000';
    function derecha(txt, y, margen) {
      var t = 14, disp = margen - 158;
      while (t > 7 && ancho(ctx, txt, t) > disp) t--;
      texto(ctx, txt, margen - ancho(ctx, txt, t), y, t, false, blanco);
      return t;
    }
    var ts = derecha(FIJO.sede1, Y_SEDE1, M_SEDE);
    var w1 = ancho(ctx, FIJO.sede1, ts), centro = M_SEDE - w1 + w1 / 2;
    texto(ctx, FIJO.sede2, centro - ancho(ctx, FIJO.sede2, ts) / 2, Y_SEDE2, ts, false, blanco);

    var tels = [];
    if (d.cel) tels.push('Cel: ' + d.cel);
    tels.push('Pbx. ' + FIJO.pbx, 'Whatsapp Ventas: ' + FIJO.whatsapp);
    if (d.ext) tels.push('Ext. ' + d.ext);
    var tt = 14;
    while (tt > 7 && tels.some(function (l) { return ancho(ctx, l, tt) > M_TEL - 158; })) tt--;
    var y0 = 80 - Math.floor(tels.length * ALTO / 2) + 8;
    tels.forEach(function (l, i) { texto(ctx, l, M_TEL - ancho(ctx, l, tt), y0 + i * ALTO, tt, false, blanco); });

    derecha(d.email, Y_EMAIL, M_EMAIL);
    derecha(FIJO.web, Y_WEB, M_WEB);

    var tn = 19;
    while (tn > 11 && ancho(ctx, d.nombre, tn, true) > 200) tn--;
    texto(ctx, d.nombre, CX - ancho(ctx, d.nombre, tn, true) / 2, Y_NOMBRE, tn, true, blanco);

    var cargo = d.cargo, dos = cargo.replace(/ /g, '').length > 19 || cargo.length > 22, MAX = 210;
    if (dos) {
      var p = cargo.split(/\s+/), corte = 0, mejor = 1e9;
      for (var i = 1; i < p.length; i++) { var df = Math.abs(p.slice(0, i).join(' ').length - p.slice(i).join(' ').length); if (df < mejor) { mejor = df; corte = i; } }
      var l1 = p.slice(0, corte).join(' '), l2 = p.slice(corte).join(' '), tc = 12;
      while (tc > 7 && Math.max(ancho(ctx, l1, tc, true), ancho(ctx, l2, tc, true)) > MAX) tc--;
      var alto = Math.round(tc * 0.905) + 2, y = Y_CARGO - Math.floor(alto / 2);
      [l1, l2].forEach(function (l) { texto(ctx, l, CX - ancho(ctx, l, tc, true) / 2, y, tc, true, negro); y += alto; });
    } else {
      var tc1 = 12;
      while (tc1 > 8 && ancho(ctx, cargo, tc1, true) > MAX) tc1--;
      texto(ctx, cargo, CX - ancho(ctx, cargo, tc1, true) / 2, Y_CARGO, tc1, true, negro);
    }
  }

  function datos() {
    return { nombre: $('f-nombre').value.trim().toUpperCase(), cargo: $('f-cargo').value.trim().toUpperCase(),
      email: $('f-email').value.trim(), cel: $('f-cel').value.trim(), ext: $('f-ext').value.trim() };
  }
  function archivo(n) { return n.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ /g, '_').replace(/[^a-z0-9_]/g, '') + '_firma.png'; }
  function pintar() { var d = datos(); if (!d.nombre) d.nombre = 'NOMBRE APELLIDO'; if (!d.cargo) d.cargo = 'CARGO'; if (!d.email) d.email = 'correo@agro-costa.com'; dibujar(lienzo.getContext('2d'), d); }
  function valido(d) { if (!d.nombre || !d.cargo || !d.email) { alert('Nombre, cargo y email son obligatorios'); return false; } return true; }

  plantilla.onload = function () { (document.fonts ? document.fonts.ready : Promise.resolve()).then(pintar); };
  ['f-nombre', 'f-cargo', 'f-email', 'f-cel', 'f-ext'].forEach(function (id) { $(id).addEventListener('input', pintar); });
  $('f-colab').addEventListener('change', function () {
    var g = GENTE[this.value]; if (!g) return;
    $('f-nombre').value = g.nombre || ''; $('f-cargo').value = g.cargo || ''; $('f-email').value = (g.email || '').toLowerCase();
    $('f-cel').value = g.telefono || ''; $('f-ext').value = g.extension || ''; pintar();
  });
  $('b-limpiar').addEventListener('click', function () { ['f-nombre', 'f-cargo', 'f-email', 'f-cel', 'f-ext'].forEach(function (id) { $(id).value = ''; }); pintar(); });
  $('b-descargar').addEventListener('click', function () {
    var d = datos(); if (!valido(d)) return; dibujar(lienzo.getContext('2d'), d);
    var a = document.createElement('a'); a.download = archivo(d.nombre); a.href = lienzo.toDataURL('image/png'); a.click();
  });
  $('b-copiar').addEventListener('click', function () {
    var d = datos(); if (!valido(d)) return; dibujar(lienzo.getContext('2d'), d);
    if (!navigator.clipboard || !window.ClipboardItem) { alert('Este navegador no deja copiar imágenes; usa Descargar PNG'); return; }
    lienzo.toBlob(function (b) { navigator.clipboard.write([new ClipboardItem({ 'image/png': b })]).then(function () { $('b-copiar').textContent = '✓ Copiada'; setTimeout(function () { $('b-copiar').textContent = 'Copiar imagen'; }, 1500); }); });
  });

  // masivo
  var csv = [];
  $('m-area').addEventListener('change', function () { var a = this.value; document.querySelectorAll('#m-lista label').forEach(function (l) { l.style.display = !a || l.dataset.area === a ? '' : 'none'; }); });
  $('m-todos').addEventListener('click', function () { document.querySelectorAll('#m-lista label').forEach(function (l) { if (l.style.display !== 'none') l.querySelector('input').checked = true; }); });
  $('m-ninguno').addEventListener('click', function () { document.querySelectorAll('#m-lista input').forEach(function (i) { i.checked = false; }); });
  function parsearCsv(t) {
    var filas = [], f = [], c = '', q = false;
    for (var i = 0; i < t.length; i++) {
      var ch = t[i];
      if (q) { if (ch === '"' && t[i + 1] === '"') { c += '"'; i++; } else if (ch === '"') q = false; else c += ch; }
      else if (ch === '"') q = true; else if (ch === ',' || ch === ';') { f.push(c); c = ''; }
      else if (ch === '\n' || ch === '\r') { if (ch === '\r' && t[i + 1] === '\n') i++; f.push(c); filas.push(f); f = []; c = ''; }
      else c += ch;
    }
    if (c !== '' || f.length) { f.push(c); filas.push(f); }
    var cab = (filas.shift() || []).map(function (h) { return h.trim().toLowerCase().replace(/^﻿/, ''); });
    var ix = function (n) { return cab.indexOf(n); };
    if (ix('nombre') < 0 || ix('cargo') < 0 || ix('email') < 0) throw new Error('El CSV debe tener columnas nombre, cargo, email (y opcional cel, extension)');
    return filas.filter(function (r) { return r.some(function (x) { return x.trim(); }); }).map(function (r) {
      var v = function (n) { return ix(n) >= 0 ? (r[ix(n)] || '').trim() : ''; };
      return { nombre: v('nombre').toUpperCase(), cargo: v('cargo').toUpperCase(), email: v('email'), cel: v('cel'), ext: v('extension') };
    });
  }
  $('m-csv').addEventListener('change', function () {
    var f = this.files[0]; if (!f) return;
    f.text().then(function (t) { try { csv = parsearCsv(t); $('m-estado').textContent = csv.length + ' filas del CSV listas'; } catch (e) { csv = []; alert(e.message); } });
  });
  $('m-generar').addEventListener('click', function () {
    var lista = csv.slice();
    document.querySelectorAll('#m-lista input:checked').forEach(function (i) {
      var g = GENTE[i.value]; lista.push({ nombre: (g.nombre || '').toUpperCase(), cargo: (g.cargo || '').toUpperCase(), email: (g.email || '').toLowerCase(), cel: g.telefono || '', ext: g.extension || '' });
    });
    if (!lista.length) { alert('Escoge colaboradores o sube un CSV'); return; }
    var zip = new JSZip(), c = document.createElement('canvas'); c.width = 695; c.height = 181;
    var faltan = [];
    var prom = lista.map(function (d) {
      if (!d.nombre || !d.cargo || !d.email) { faltan.push(d.nombre || d.email || '?'); return Promise.resolve(); }
      dibujar(c.getContext('2d'), d);
      var url = c.toDataURL('image/png');
      zip.file(archivo(d.nombre), url.split(',')[1], { base64: true });
      return Promise.resolve();
    });
    Promise.all(prom).then(function () { return zip.generateAsync({ type: 'blob' }); }).then(function (b) {
      var a = document.createElement('a'); a.download = 'firmas_agro-costa.zip'; a.href = URL.createObjectURL(b); a.click();
      $('m-estado').textContent = (lista.length - faltan.length) + ' firmas en el ZIP' + (faltan.length ? ' · sin cargo o correo: ' + faltan.join(', ') : '');
    });
  });
})();
</script>
<?php require __DIR__ . '/inc/footer.php';
