<?php
// stickers de ubicaciones de bodega para la zebra: misma etiqueta del programa de escritorio, en la web
require __DIR__ . '/config.php';
requiere_modulo('herramientas');

$titulo = 'Stickers Zebra';
$menu = 'herramientas';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><a href="herramientas.php" class="tenue">&larr; Herramientas</a><h1>Stickers de ubicaciones</h1><small>Excel → stickers con código de barras para la Zebra · igual al programa de escritorio, sin instalar nada</small></div>
</div>

<div class="dos-col firmas-dos">
  <div>
    <div class="tarjeta">
      <h2>1. Archivo</h2>
      <div class="campo"><label>Excel (.xlsx) o CSV</label><input type="file" id="s-archivo" accept=".xlsx,.csv,.txt"></div>
      <div class="rejilla">
        <div class="campo"><label>Hoja</label><select id="s-hoja" disabled></select></div>
        <div class="campo"><label>Columna</label><select id="s-col" disabled></select></div>
      </div>
      <label class="check-linea"><input type="checkbox" id="s-titulo"> La primera fila es título (no se imprime)</label>
      <details style="margin-top:10px"><summary class="tenue" style="cursor:pointer">…o pega las ubicaciones (una por línea)</summary>
        <textarea id="s-pegar" rows="5" class="mono" placeholder="G001&#10;G002&#10;R-12-A" style="margin-top:8px;width:100%"></textarea></details>
    </div>

    <div class="tarjeta">
      <h2>2. Qué imprimir</h2>
      <div class="rejilla">
        <div class="campo"><label>Desde el #</label><input id="s-desde" type="number" min="1" value="1"></div>
        <div class="campo"><label>Hasta el #</label><input id="s-hasta" type="number" min="1"></div>
        <div class="campo"><label>Copias c/u</label><input id="s-copias" type="number" min="1" max="50" value="1"></div>
      </div>
      <p id="s-rango" class="tenue" style="margin:0">Carga un Excel para empezar.</p>
    </div>

    <div class="tarjeta">
      <h2>3. Etiqueta</h2>
      <div class="rejilla">
        <div class="campo"><label>Ancho (mm)</label><input id="s-ancho" type="number" min="20" max="200" step="0.5" value="100"></div>
        <div class="campo"><label>Alto (mm)</label><input id="s-alto" type="number" min="10" max="200" step="0.5" value="50"></div>
        <div class="campo"><label>Resolución (dpi)</label><select id="s-dpi"><option>203</option><option>300</option><option>600</option></select><small>La ZT410 normal es 203</small></div>
        <div class="campo"><label>Ajuste X / Y (puntos)</label><div style="display:flex;gap:6px"><input id="s-ox" type="number" value="0"><input id="s-oy" type="number" value="0"></div></div>
      </div>
      <label class="check-linea"><input type="checkbox" id="s-borde"> Recuadro negro alrededor</label>
    </div>
  </div>

  <div>
    <div class="tarjeta">
      <h2>Vista previa</h2>
      <div class="sticker-prev" id="s-prev"><span class="tenue">Aquí se ven los primeros stickers</span></div>
      <p id="s-aviso" class="aviso aviso-error" style="display:none;margin:10px 0 0"></p>
      <p style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 0">
        <button type="button" class="btn btn-primario" id="s-imprimir" disabled>🖨 Imprimir</button>
        <button type="button" class="btn btn-borde" id="s-prueba" disabled>Imprimir 1 de prueba</button>
        <button type="button" class="btn btn-borde" id="s-zpl" disabled>Guardar .zpl</button></p>
    </div>
    <div class="tarjeta">
      <h2>Para que salga bien</h2>
      <ol class="tenue" style="margin:0;padding-left:18px;line-height:1.7">
        <li>El PC necesita el driver de la Zebra (ZDesigner ZT410) instalado.</li>
        <li>Al imprimir escoge la <b>ZDesigner ZT410</b>, márgenes <b>Ninguno</b> y escala <b>100%</b> (no “Ajustar”).</li>
        <li>En las preferencias del driver deja el papel en el mismo tamaño de la etiqueta (100 × 50 mm).</li>
        <li>Haz primero “1 de prueba”. Si sale corrido, usa Ajuste X / Y.</li>
      </ol>
      <small class="tenue">“Guardar .zpl” es el mismo archivo del programa de escritorio, por si lo quieres mandar con Zebra Setup Utilities.</small>
    </div>
  </div>
</div>

<script src="assets/vendor/jszip.min.js"></script>
<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var ANCHO_LETRA = 0.62;
  var libro = {}; // hoja -> filas
  var nombres = [];

  // ---------- lectura de excel (xlsx es un zip con xml) ----------
  function texto_celda(v) {
    if (v == null) return '';
    v = String(v).trim();
    if (/^-?\d+(\.\d+)?(E[+-]?\d+)?$/i.test(v)) { var n = Number(v); if (isFinite(n) && Number.isInteger(n)) return String(n); if (isFinite(n)) return String(+n.toPrecision(15)); }
    return v;
  }
  function col_num(ref) { var l = ref.replace(/[0-9]/g, ''), n = 0; for (var i = 0; i < l.length; i++) n = n * 26 + l.charCodeAt(i) - 64; return n - 1; }
  function xml(t) { return new DOMParser().parseFromString(t, 'application/xml'); }
  function hijos(nodo, nombre) { return Array.prototype.filter.call(nodo.getElementsByTagName('*'), function (x) { return x.localName === nombre; }); }
  function leer_xlsx(buf) {
    return JSZip.loadAsync(buf).then(function (z) {
      var leer = function (p) { var f = z.file(p); return f ? f.async('string') : Promise.resolve(''); };
      return Promise.all([leer('xl/workbook.xml'), leer('xl/_rels/workbook.xml.rels'), leer('xl/sharedStrings.xml')]).then(function (r) {
        var comp = [];
        if (r[2]) hijos(xml(r[2]), 'si').forEach(function (si) { comp.push(hijos(si, 't').map(function (t) { return t.textContent; }).join('')); });
        var rels = {};
        hijos(xml(r[1]), 'Relationship').forEach(function (x) { rels[x.getAttribute('Id')] = x.getAttribute('Target'); });
        var hojas = hijos(xml(r[0]), 'sheet').map(function (s) {
          var rid = s.getAttribute('r:id') || s.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
          var t = (rels[rid] || '').replace(/^\/?xl\//, '').replace(/^\//, '');
          return { nombre: s.getAttribute('name'), ruta: 'xl/' + t };
        });
        return Promise.all(hojas.map(function (h) { return leer(h.ruta); })).then(function (xs) {
          var out = {};
          hojas.forEach(function (h, k) {
            if (!xs[k]) return;
            var filas = [];
            hijos(xml(xs[k]), 'row').forEach(function (row) {
              var f = [];
              hijos(row, 'c').forEach(function (c, idx) {
                var ref = c.getAttribute('r'), i = ref ? col_num(ref) : idx, tipo = c.getAttribute('t'), v = hijos(c, 'v')[0], val = '';
                if (tipo === 's' && v) val = comp[+v.textContent] || '';
                else if (tipo === 'inlineStr') val = hijos(c, 't').map(function (t) { return t.textContent; }).join('');
                else if (v) val = v.textContent;
                while (f.length < i) f.push('');
                f[i] = texto_celda(val);
              });
              if (f.some(function (x) { return x; })) filas.push(f);
            });
            if (filas.length) out[h.nombre] = filas;
          });
          return out;
        });
      });
    });
  }
  function leer_csv(t) {
    var sep = (t.split('\n')[0].match(/;/g) || []).length > (t.split('\n')[0].match(/,/g) || []).length ? ';' : ',';
    var filas = [], f = [], c = '', q = false;
    for (var i = 0; i < t.length; i++) {
      var ch = t[i];
      if (q) { if (ch === '"' && t[i + 1] === '"') { c += '"'; i++; } else if (ch === '"') q = false; else c += ch; }
      else if (ch === '"') q = true; else if (ch === sep) { f.push(texto_celda(c)); c = ''; }
      else if (ch === '\n' || ch === '\r') { if (ch === '\r' && t[i + 1] === '\n') i++; f.push(texto_celda(c)); if (f.some(Boolean)) filas.push(f); f = []; c = ''; }
      else c += ch;
    }
    f.push(texto_celda(c)); if (f.some(Boolean)) filas.push(f);
    return { CSV: filas };
  }
  function parece_encabezado(filas, col) {
    if (filas.length < 2) return false;
    var v0 = filas[0][col] || '';
    if (!v0) return true;
    if (/\d/.test(v0)) return false;
    return filas.slice(1, 21).some(function (f) { return /\d/.test(f[col] || ''); });
  }

  // ---------- code 128 igual al programa (para el zpl y para dibujar) ----------
  function a_ascii(t) { return t.normalize('NFKD').replace(/[̀-ͯ]/g, '').split('').filter(function (ch) { var o = ch.charCodeAt(0); return o >= 32 && o <= 126; }).join(''); }
  var PAT = '212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 211214 211232 2331112'.split(' ');
  function code128(texto) {
    var s = a_ascii(texto), n = s.length;
    var run = function (i) { var j = i; while (j < n && s[j] >= '0' && s[j] <= '9') j++; return j - i; };
    var zpl = [], val = [], modo, i = 0, r = run(0);
    if (n >= 2 && (r >= 4 || (r === n && r % 2 === 0))) { modo = 'C'; zpl.push('>;'); val.push(105); } else { modo = 'B'; zpl.push('>:'); val.push(104); }
    while (i < n) {
      if (modo === 'C') {
        if (run(i) >= 2) { zpl.push(s.substr(i, 2)); val.push(+s.substr(i, 2)); i += 2; }
        else { zpl.push('>6'); val.push(100); modo = 'B'; }
      } else {
        r = run(i);
        if (r >= 4) {
          if (r % 2 === 1) { zpl.push(s[i] === '>' ? '>0' : s[i]); val.push(s.charCodeAt(i) - 32); i++; }
          zpl.push('>5'); val.push(99); modo = 'C';
        } else { zpl.push(s[i] === '>' ? '>0' : s[i]); val.push(s.charCodeAt(i) - 32); i++; }
      }
    }
    var suma = val[0]; for (var k = 1; k < val.length; k++) suma += k * val[k];
    val.push(suma % 103); val.push(106);
    var barras = val.map(function (v) { return PAT[v]; }).join('');
    return { zpl: zpl.join(''), modulos: (val.length - 1) * 11 + 13, barras: barras };
  }
  function zpl_texto(t) { return t.replace(/_/g, '_5F').replace(/\^/g, '_5E').replace(/~/g, '_7E'); }

  // ---------- disposicion de la etiqueta (misma cuenta del programa) ----------
  function cfg() {
    return { ancho_mm: +$('s-ancho').value || 100, alto_mm: +$('s-alto').value || 50, dpi: +$('s-dpi').value || 203,
      borde: $('s-borde').checked, ox: parseInt($('s-ox').value, 10) || 0, oy: parseInt($('s-oy').value, 10) || 0,
      copias: Math.max(1, Math.min(50, parseInt($('s-copias').value, 10) || 1)) };
  }
  function disenar(texto, c) {
    var dpmm = c.dpi / 25.4, W = Math.round(c.ancho_mm * dpmm), H = Math.round(c.alto_mm * dpmm);
    var margen = Math.max(8, Math.floor(2.5 * dpmm)), grosor = c.borde ? Math.max(3, Math.floor(0.6 * dpmm)) : 0, pad = Math.max(6, Math.floor(2 * dpmm));
    var x0 = margen + grosor + pad, y0 = x0, iw = W - 2 * x0, ih = H - 2 * y0, gap = Math.max(8, Math.floor(3 * dpmm)), quiet = 10;
    var largo = Math.max(1, texto.length), bc = code128(texto), total = bc.modulos + 2 * quiet, aviso = '';
    var mejor = null, lado = false, modulo, fuente, ancho_bc, d = { W: W, H: H, margen: margen, grosor: grosor, texto: texto, bc: bc };
    [4, 3, 2].some(function (m) {
      if (total * m > 0.55 * iw) return false;
      var abm = bc.modulos * m, libre = Math.max(0, quiet * m - (W - margen - grosor - (x0 + iw)));
      var atm = iw - abm - libre - quiet * m, f = Math.floor(Math.min(ih * 0.6, atm / (ANCHO_LETRA * largo)));
      if (f >= ih * 0.45) { mejor = [m, f]; return true; }
      if (f >= ih * 0.28 && (!mejor || f > mejor[1])) mejor = [m, f];
      return false;
    });
    var apilado = Math.floor(Math.min(ih * 0.42, iw / (ANCHO_LETRA * largo)));
    if (mejor && mejor[1] >= apilado) {
      lado = true; modulo = mejor[0]; fuente = mejor[1]; ancho_bc = bc.modulos * modulo;
      d.alto_bc = Math.floor(ih * 0.8);
      d.bx = Math.min(x0 + iw, W - margen - grosor - quiet * modulo) - ancho_bc;
      d.ancho_txt = d.bx - quiet * modulo - x0;
      d.by = y0 + Math.floor((ih - d.alto_bc) / 2); d.fy = y0 + Math.floor((ih - fuente) / 2);
    } else {
      modulo = Math.min(4, Math.floor(iw / total));
      if (modulo < 1) { modulo = 1; aviso = "'" + texto + "' es muy largo para este tamaño de etiqueta: las barras pueden salir cortadas."; }
      ancho_bc = bc.modulos * modulo;
      var alto_txt = Math.floor(ih * 0.42);
      fuente = Math.max(18, Math.floor(Math.min(alto_txt, iw / (ANCHO_LETRA * largo))));
      d.alto_bc = ih - alto_txt - gap; d.fy = y0 + Math.floor((alto_txt - fuente) / 2);
      d.bx = x0 + Math.max(0, Math.floor((iw - ancho_bc) / 2)); d.by = y0 + alto_txt + gap; d.ancho_txt = iw;
    }
    d.x0 = x0; d.modulo = modulo; d.fuente = fuente; d.aviso = aviso;
    return d;
  }
  function zpl(texto, c) {
    var d = disenar(texto, c), z = ['^XA', '^CI28', '^PW' + d.W, '^LL' + d.H, '^LH' + c.ox + ',' + c.oy];
    if (c.borde) z.push('^FO' + d.margen + ',' + d.margen + '^GB' + (d.W - 2 * d.margen) + ',' + (d.H - 2 * d.margen) + ',' + d.grosor + '^FS');
    z.push('^FO' + d.x0 + ',' + d.fy + '^A0N,' + d.fuente + ',' + d.fuente + '^FB' + d.ancho_txt + ',1,0,C,0^FH^FD' + zpl_texto(texto) + '^FS');
    z.push('^BY' + d.modulo + ',3^FO' + d.bx + ',' + d.by + '^BCN,' + d.alto_bc + ',N,N,N,N^FD' + d.bc.zpl + '^FS');
    if (c.copias > 1) z.push('^PQ' + c.copias);
    z.push('^XZ');
    return { zpl: z.join('\n'), aviso: d.aviso };
  }
  var NS = 'http://www.w3.org/2000/svg';
  function el(n, a) { var e = document.createElementNS(NS, n); for (var k in a) e.setAttribute(k, a[k]); return e; }
  function svg(texto, c) {
    var d = disenar(texto, c), s = el('svg', { viewBox: '0 0 ' + d.W + ' ' + d.H, width: c.ancho_mm + 'mm', height: c.alto_mm + 'mm', 'shape-rendering': 'crispEdges' });
    s.appendChild(el('rect', { x: 0, y: 0, width: d.W, height: d.H, fill: '#fff' }));
    var g = el('g', { transform: 'translate(' + c.ox + ',' + c.oy + ')' }); s.appendChild(g);
    if (c.borde) g.appendChild(el('rect', { x: d.margen + d.grosor / 2, y: d.margen + d.grosor / 2, width: d.W - 2 * d.margen - d.grosor, height: d.H - 2 * d.margen - d.grosor, fill: 'none', stroke: '#000', 'stroke-width': d.grosor }));
    var t = el('text', { x: d.x0 + d.ancho_txt / 2, y: d.fy + Math.round(d.fuente * 0.86), 'text-anchor': 'middle', 'font-family': 'Arial Narrow, Roboto Condensed, Arial, Helvetica, sans-serif', 'font-weight': '700', 'font-size': d.fuente, fill: '#000' });
    t.textContent = texto; g.appendChild(t);
    var x = d.bx, b = d.bc.barras;
    for (var i = 0; i < b.length; i++) { var w = +b[i] * d.modulo; if (i % 2 === 0) g.appendChild(el('rect', { x: x, y: d.by, width: w, height: d.alto_bc, fill: '#000' })); x += w; }
    // si la letra del pc sale mas ancha que la caja, se encoge a lo ancho como la zebra
    return { svg: s, texto: t, ancho: d.ancho_txt };
  }
  function ajustar(o) { try { var w = o.texto.getComputedTextLength(); if (w > o.ancho) { o.texto.setAttribute('textLength', o.ancho); o.texto.setAttribute('lengthAdjust', 'spacingAndGlyphs'); } } catch (e) {} }

  // ---------- datos seleccionados ----------
  function registros() {
    var p = $('s-pegar').value.trim();
    if (p) return p.split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean);
    var filas = libro[$('s-hoja').value] || [], col = +$('s-col').value || 0;
    return filas.slice($('s-titulo').checked ? 1 : 0).map(function (f) { return f[col] || ''; }).filter(Boolean);
  }
  function rango() {
    var todos = registros(), n = todos.length, de = parseInt($('s-desde').value, 10) || 1, a = parseInt($('s-hasta').value, 10) || n;
    if (!n) return { lista: [], txt: 'Carga un Excel o pega ubicaciones para empezar.' };
    if (de < 1 || a > n || de > a) return { lista: [], txt: 'Rango no válido (hay ' + n + ' registros)' };
    var l = todos.slice(de - 1, a), c = cfg();
    return { lista: l, txt: 'Se imprimen ' + l.length * c.copias + ' stickers:  ' + l[0] + '  →  ' + l[l.length - 1] + '   (de ' + n + ' en el archivo)' };
  }
  function guardar_cfg() { try { localStorage.setItem('stickers_cfg', JSON.stringify(cfg())); } catch (e) {} }
  function cargar_cfg() {
    try { var c = JSON.parse(localStorage.getItem('stickers_cfg') || 'null'); if (!c) return;
      $('s-ancho').value = c.ancho_mm; $('s-alto').value = c.alto_mm; $('s-dpi').value = c.dpi; $('s-borde').checked = !!c.borde; $('s-ox').value = c.ox; $('s-oy').value = c.oy; } catch (e) {}
  }

  function refrescar() {
    var r = rango(), c = cfg(), prev = $('s-prev');
    $('s-rango').textContent = r.txt;
    ['s-imprimir', 's-prueba', 's-zpl'].forEach(function (id) { $(id).disabled = !r.lista.length; });
    prev.innerHTML = '';
    if (!r.lista.length) { prev.innerHTML = '<span class="tenue">Aquí se ven los primeros stickers</span>'; $('s-aviso').style.display = 'none'; return; }
    r.lista.slice(0, 4).forEach(function (t) { var o = svg(t, c); o.svg.removeAttribute('width'); o.svg.removeAttribute('height'); prev.appendChild(o.svg); ajustar(o); });
    if (r.lista.length > 4) { var m = document.createElement('small'); m.className = 'tenue'; m.textContent = '… y ' + (r.lista.length - 4) + ' más'; prev.appendChild(m); }
    var avisos = r.lista.map(function (t) { return disenar(t, c).aviso; }).filter(Boolean);
    $('s-aviso').style.display = avisos.length ? '' : 'none';
    $('s-aviso').textContent = avisos.slice(0, 3).join(' ') + (avisos.length > 3 ? ' (y ' + (avisos.length - 3) + ' más)' : '');
    guardar_cfg();
  }

  function llenar_hojas() {
    nombres = Object.keys(libro);
    $('s-hoja').innerHTML = ''; nombres.forEach(function (n) { var o = document.createElement('option'); o.textContent = n; $('s-hoja').appendChild(o); });
    $('s-hoja').disabled = !nombres.length; llenar_columnas();
  }
  function letra(i) { var s = ''; i++; while (i) { var m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); } return s; }
  function llenar_columnas() {
    var filas = libro[$('s-hoja').value] || [], ancho = filas.reduce(function (m, f) { return Math.max(m, f.length); }, 0);
    $('s-col').innerHTML = '';
    for (var i = 0; i < ancho; i++) { var o = document.createElement('option'); o.value = i; o.textContent = letra(i) + ': ' + ((filas[0] || [])[i] || '(vacía)'); $('s-col').appendChild(o); }
    $('s-col').disabled = !ancho; cambiar_columna();
  }
  function cambiar_columna() {
    var filas = libro[$('s-hoja').value] || [];
    $('s-titulo').checked = parece_encabezado(filas, +$('s-col').value || 0);
    $('s-desde').value = 1; $('s-hasta').value = registros().length || ''; refrescar();
  }

  $('s-archivo').addEventListener('change', function () {
    var f = this.files[0]; if (!f) return;
    $('s-pegar').value = '';
    var p = /\.xlsx$/i.test(f.name) ? f.arrayBuffer().then(leer_xlsx) : f.text().then(leer_csv);
    p.then(function (d) { if (!Object.keys(d).length) throw new Error('El archivo no tiene datos.'); libro = d; llenar_hojas(); })
     .catch(function (e) { alert('No se pudo leer el archivo: ' + e.message); });
  });
  $('s-hoja').addEventListener('change', llenar_columnas);
  $('s-col').addEventListener('change', cambiar_columna);
  $('s-pegar').addEventListener('input', function () { $('s-desde').value = 1; $('s-hasta').value = registros().length || ''; refrescar(); });
  ['s-titulo', 's-borde', 's-dpi'].forEach(function (id) { $(id).addEventListener('change', function () { if (id === 's-titulo') $('s-hasta').value = registros().length || ''; refrescar(); }); });
  ['s-desde', 's-hasta', 's-copias', 's-ancho', 's-alto', 's-ox', 's-oy'].forEach(function (id) { $(id).addEventListener('input', refrescar); });

  function imprimir(lista) {
    var c = cfg(), cont = $('impresion') || document.body.appendChild(Object.assign(document.createElement('div'), { id: 'impresion' }));
    cont.innerHTML = '';
    var est = $('impresion-pagina') || document.head.appendChild(Object.assign(document.createElement('style'), { id: 'impresion-pagina' }));
    est.textContent = '@page { size: ' + c.ancho_mm + 'mm ' + c.alto_mm + 'mm; margin: 0; }';
    lista.forEach(function (t) { for (var k = 0; k < c.copias; k++) { var o = svg(t, c), h = document.createElement('div'); h.className = 'hoja-sticker'; h.appendChild(o.svg); cont.appendChild(h); ajustar(o); } });
    setTimeout(function () { window.print(); }, 50);
  }
  $('s-imprimir').addEventListener('click', function () {
    var r = rango(); if (!r.lista.length) return;
    if (r.lista.length * cfg().copias > 20 && !confirm('Se van a imprimir ' + r.lista.length * cfg().copias + ' stickers. ¿Seguir?')) return;
    imprimir(r.lista);
  });
  $('s-prueba').addEventListener('click', function () { var r = rango(); if (r.lista.length) { var c = $('s-copias').value; $('s-copias').value = 1; imprimir(r.lista.slice(0, 1)); $('s-copias').value = c; } });
  $('s-zpl').addEventListener('click', function () {
    var r = rango(), c = cfg(); if (!r.lista.length) return;
    var b = new Blob([r.lista.map(function (t) { return zpl(t, c).zpl; }).join('\n') + '\n'], { type: 'text/plain' });
    var a = document.createElement('a'); a.download = 'stickers.zpl'; a.href = URL.createObjectURL(b); a.click();
  });
  cargar_cfg();
  window.__stickers = { code128: code128, zpl: zpl, disenar: disenar };
})();
</script>
<?php require __DIR__ . '/inc/footer.php';
