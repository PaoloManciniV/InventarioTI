// panel de firma con el dedo o mouse
function iniciarFirma(canvas, campo, form) {
  var ctx = canvas.getContext('2d');
  var dibujando = false, trazos = 0, ultimo = null;

  function ajustar() {
    var r = canvas.getBoundingClientRect();
    var dpr = window.devicePixelRatio || 1;
    canvas.width = r.width * dpr;
    canvas.height = r.height * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.lineWidth = 2.4;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111';
    trazos = 0;
  }
  ajustar();
  window.addEventListener('resize', function () { if (!trazos) ajustar(); });

  function punto(ev) {
    var r = canvas.getBoundingClientRect();
    return { x: ev.clientX - r.left, y: ev.clientY - r.top };
  }
  canvas.addEventListener('pointerdown', function (ev) {
    ev.preventDefault();
    canvas.setPointerCapture(ev.pointerId);
    dibujando = true;
    ultimo = punto(ev);
    ctx.beginPath();
    ctx.arc(ultimo.x, ultimo.y, 1.1, 0, Math.PI * 2);
    ctx.fill();
  });
  canvas.addEventListener('pointermove', function (ev) {
    if (!dibujando) return;
    ev.preventDefault();
    var p = punto(ev);
    ctx.beginPath();
    ctx.moveTo(ultimo.x, ultimo.y);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    ultimo = p;
    trazos++;
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) {
    canvas.addEventListener(t, function () { dibujando = false; });
  });

  var borrar = document.querySelector('[data-borrar-firma]');
  if (borrar) borrar.addEventListener('click', function () {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    trazos = 0;
  });

  form.addEventListener('submit', function (ev) {
    if (trazos < 8) {
      ev.preventDefault();
      alert('Falta la firma');
      return;
    }
    // recorta a fondo blanco para que el png se vea bien en el acta
    var tmp = document.createElement('canvas');
    tmp.width = canvas.width; tmp.height = canvas.height;
    var t = tmp.getContext('2d');
    t.fillStyle = '#fff';
    t.fillRect(0, 0, tmp.width, tmp.height);
    t.drawImage(canvas, 0, 0);
    campo.value = tmp.toDataURL('image/png');
    var b = form.querySelector('button[type=submit]');
    if (b) { b.disabled = true; b.textContent = 'Guardando...'; }
  });
}

// ojito para ver u ocultar cualquier campo de clave
function ojitos() {
  var ojo = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var tachado = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19C5 19 1 12 1 12a18.5 18.5 0 0 1 5.1-5.9M9.9 5.2A9.6 9.6 0 0 1 12 5c7 0 11 7 11 7a18.6 18.6 0 0 1-2.2 3.2M14.1 14.1a3 3 0 1 1-4.2-4.2"/><path d="M1 1l22 22"/></svg>';
  document.querySelectorAll('input[type=password]').forEach(function (inp) {
    if (inp.dataset.ojito) return;
    inp.dataset.ojito = 1;
    var caja = document.createElement('span');
    caja.className = 'clave-caja';
    inp.parentNode.insertBefore(caja, inp);
    caja.appendChild(inp);
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'ojito';
    b.title = 'Mostrar clave';
    b.innerHTML = ojo;
    b.addEventListener('click', function () {
      var ver = inp.type === 'password';
      inp.type = ver ? 'text' : 'password';
      b.innerHTML = ver ? tachado : ojo;
      b.title = ver ? 'Ocultar clave' : 'Mostrar clave';
      inp.focus();
    });
    caja.appendChild(b);
  });
}

// revela claves guardadas pidiendolas al servidor queda registro de quien las vio
function secretos() {
  document.querySelectorAll('.secreto').forEach(function (sp) {
    var b = sp.querySelector('.ojito-mini'), v = sp.querySelector('.secreto-valor'), visto = null;
    b.addEventListener('click', function () {
      if (visto !== null) {
        var ocultar = v.textContent !== '••••••••';
        v.textContent = ocultar ? '••••••••' : visto;
        return;
      }
      var fd = new FormData();
      fd.append('tipo', sp.dataset.tipo);
      fd.append('id', sp.dataset.id);
      fd.append('csrf', document.querySelector('meta[name=csrf]').content);
      fetch('clave.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { visto = j.clave || '(no se pudo leer)'; v.textContent = visto; })
        .catch(function () { v.textContent = '(error)'; });
    });
  });
}

// modo claro u oscuro se recuerda en este navegador
function temas() {
  document.querySelectorAll('[data-tema-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var raiz = document.documentElement;
      var claro = raiz.getAttribute('data-tema') !== 'claro';
      if (claro) raiz.setAttribute('data-tema', 'claro'); else raiz.removeAttribute('data-tema');
      try { localStorage.setItem('tema', claro ? 'claro' : 'oscuro'); } catch (e) {}
    });
  });
}

// buscador para selects largos: se escribe parte del nombre o la cedula como un like
function buscadores() {
  var norm = function (t) { return (t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  document.querySelectorAll('select[data-buscar]').forEach(function (sel) {
    var caja = document.createElement('div');
    caja.className = 'combo';
    var inp = document.createElement('input');
    inp.type = 'search';
    inp.autocomplete = 'off';
    inp.placeholder = 'Escribe nombre o cédula...';
    var lista = document.createElement('div');
    lista.className = 'combo-lista';
    sel.parentNode.insertBefore(caja, sel);
    caja.appendChild(inp);
    caja.appendChild(lista);
    caja.appendChild(sel);
    sel.style.display = 'none';
    var requerido = sel.required;
    sel.required = false;
    inp.required = requerido;
    var opts = Array.prototype.filter.call(sel.options, function (o) { return o.value !== ''; });
    var actual = sel.options[sel.selectedIndex];
    if (actual && actual.value !== '') inp.value = actual.text;
    var marcado = -1, vis = [];

    function pintar() {
      var palabras = norm(inp.value).split(/\s+/).filter(Boolean);
      vis = opts.filter(function (o) {
        var t = norm(o.text);
        return palabras.every(function (p) { return t.indexOf(p) >= 0; });
      }).slice(0, 60);
      lista.innerHTML = '';
      if (!vis.length) { lista.innerHTML = '<div class="vacio">Sin resultados</div>'; }
      vis.forEach(function (o, k) {
        var d = document.createElement('div');
        d.textContent = o.text;
        d.className = k === marcado ? 'marcado' : '';
        d.addEventListener('mousedown', function (ev) { ev.preventDefault(); escoger(o); });
        lista.appendChild(d);
      });
      lista.style.display = 'block';
    }
    function escoger(o) {
      inp.value = o.text;
      lista.style.display = 'none';
      inp.setCustomValidity('');
      if (sel.value !== o.value) {
        sel.value = o.value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    inp.addEventListener('focus', function () { inp.select(); marcado = -1; pintar(); });
    inp.addEventListener('input', function () {
      marcado = 0;
      if (sel.value !== '') { sel.value = ''; }
      pintar();
    });
    inp.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowDown') { marcado = Math.min(marcado + 1, vis.length - 1); pintar(); ev.preventDefault(); }
      else if (ev.key === 'ArrowUp') { marcado = Math.max(marcado - 1, 0); pintar(); ev.preventDefault(); }
      else if (ev.key === 'Enter') { if (vis[marcado]) { escoger(vis[marcado]); } ev.preventDefault(); }
      else if (ev.key === 'Escape') { lista.style.display = 'none'; }
    });
    inp.addEventListener('blur', function () {
      lista.style.display = 'none';
      // si escribio y no escogio, se vuelve a lo que estaba
      var o = sel.options[sel.selectedIndex];
      if (sel.value === '') inp.value = inp.value.trim() && !requerido ? '' : inp.value;
      else if (o) inp.value = o.text;
    });
    if (sel.form) sel.form.addEventListener('submit', function (ev) {
      if (requerido && sel.value === '') { inp.setCustomValidity('Escoge una opción de la lista'); inp.reportValidity(); ev.preventDefault(); }
    });
  });
}

document.addEventListener('DOMContentLoaded', function () {
  temas();
  buscadores();
  ojitos();
  secretos();
  var c = document.getElementById('firma');
  if (c) iniciarFirma(c, document.getElementById('firma_data'), c.closest('form'));

  // confirmaciones
  document.querySelectorAll('[data-confirmar]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!confirm(el.getAttribute('data-confirmar'))) ev.preventDefault();
    });
  });

  // copiar link
  document.querySelectorAll('[data-copiar]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.getAttribute('data-copiar');
      if (navigator.clipboard) navigator.clipboard.writeText(v);
      b.textContent = 'Copiado';
    });
  });
});

// condicion visible de un item (nota bajo el estado): poner, editar o quitar ahi mismo
(function () {
  var pop = null;
  var SUG = ['Nuevo', 'Usado', 'Mal estado', 'No sirve', 'Pantalla rota', 'Batería dañada', 'Sin disco', 'Sin cargador'];
  function cerrar() { if (pop) { pop.remove(); pop = null; } }
  function guardar(b, valor, boton) {
    var f = new FormData();
    f.append('csrf', (document.querySelector('meta[name=csrf]') || {}).content || '');
    f.append('id', b.dataset.id); f.append('condicion', valor);
    boton.disabled = true;
    fetch('condicion.php', { method: 'POST', body: f, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.error || 'No se pudo guardar');
        var t = document.createElement('span'); t.innerHTML = j.html.trim();
        b.replaceWith(t.firstChild); cerrar();
      })
      .catch(function (e) { boton.disabled = false; alert(e.message || 'No se pudo guardar'); });
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-condicion]');
    if (!b) { if (pop && !pop.contains(ev.target)) cerrar(); return; }
    ev.preventDefault(); ev.stopPropagation();
    cerrar();
    pop = document.createElement('div'); pop.className = 'cond-pop';
    var lista = 'cond-sug-' + b.dataset.id;
    pop.innerHTML = '<label>Nota visible (condición)</label><input maxlength="120" list="' + lista + '" placeholder="Ej. Batería dañada, no sirve">' +
      '<datalist id="' + lista + '">' + SUG.map(function (s) { return '<option value="' + s + '">'; }).join('') + '</datalist>' +
      '<div class="cond-acc"><button type="button" class="btn btn-primario btn-sm" data-g>Guardar</button>' +
      (b.dataset.valor ? '<button type="button" class="btn btn-borde btn-sm" data-q>Quitar</button>' : '') +
      '<button type="button" class="btn btn-borde btn-sm" data-c>Cancelar</button></div>';
    document.body.appendChild(pop);
    var r = b.getBoundingClientRect();
    pop.style.top = (window.scrollY + r.bottom + 6) + 'px';
    pop.style.left = Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - pop.offsetWidth - 8)) + 'px';
    var inp = pop.querySelector('input'); inp.value = b.dataset.valor || ''; inp.focus(); inp.select();
    pop.addEventListener('click', function (e) { e.stopPropagation(); });
    pop.querySelector('[data-g]').addEventListener('click', function () { guardar(b, inp.value.trim(), this); });
    var q = pop.querySelector('[data-q]'); if (q) q.addEventListener('click', function () { guardar(b, '', this); });
    pop.querySelector('[data-c]').addEventListener('click', cerrar);
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); guardar(b, inp.value.trim(), pop.querySelector('[data-g]')); } if (e.key === 'Escape') cerrar(); });
  }, true);
})();

/* ventana emergente (impresoras: toner, reparar, regreso): Esc o clic afuera la cierra */
(function () {
  var m = document.querySelector('.modal-fondo');
  if (!m) return;
  var ir = function () { location.href = m.dataset.cerrar || location.pathname; };
  m.addEventListener('mousedown', function (e) { if (e.target === m) ir(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') ir(); });
  var f = m.querySelector('input:not([type=hidden]):not([type=date]), textarea, select');
  if (f) f.focus();
})();

/* campos con sugerencias (condición, grupo, proveedor): al entrar se ven TODAS las opciones,
   no solo las que coinciden con lo escrito. Si sales sin escoger nada, queda lo que tenía */
(function () {
  document.addEventListener('focusin', function (e) {
    var i = e.target;
    if (!i.matches || !i.matches('input[list]') || i.closest('.cond-pop') || i.value === '') return;
    i.dataset.antes = i.value;
    i.dataset.ph = i.placeholder;
    i.placeholder = i.value;
    i.value = '';
  });
  // ya escogió una opción y vuelve a hacer clic (sin salir del campo): otra vez todas las opciones
  document.addEventListener('click', function (e) {
    var i = e.target;
    if (!i.matches || !i.matches('input[list]') || i.closest('.cond-pop') || i.value === '' || document.activeElement !== i) return;
    if (i.dataset.ph === undefined) i.dataset.ph = i.placeholder;
    i.dataset.antes = i.value;
    i.placeholder = i.value;
    i.value = '';
  });
  document.addEventListener('focusout', function (e) {
    var i = e.target;
    if (!i.matches || !i.matches('input[list]') || i.dataset.antes === undefined) return;
    if (i.value.trim() === '') i.value = i.dataset.antes;
    i.placeholder = i.dataset.ph || '';
    delete i.dataset.antes; delete i.dataset.ph;
  });
  // si envian el formulario con el campo aun enfocado y vacio, no se pierde lo que tenia
  document.addEventListener('submit', function (e) {
    e.target.querySelectorAll('input[list]').forEach(function (i) {
      if (i.dataset.antes !== undefined && i.value.trim() === '') i.value = i.dataset.antes;
    });
  }, true);
})();

/* tareas: marcar como hecha sin recargar la pagina */
(function () {
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.classList || !f.classList.contains('tarea-check')) return;
    e.preventDefault();
    var fila = f.closest('.tarea'), btn = f.querySelector('.check'), val = f.querySelector('[name=valor]');
    btn.disabled = true;
    fetch(location.pathname, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { alert(d.msg || 'No se pudo'); return; }
        var hecha = val.value === '1';
        fila.classList.toggle('hecha', hecha);
        if (hecha) fila.classList.remove('vencida');
        val.value = hecha ? '0' : '1';
        btn.title = btn.ariaLabel = hecha ? 'Reabrir' : 'Marcar como hecha';
      })
      .catch(function () { btn.disabled = false; f.submit(); });
  });
})();

/* bloc de notas personal: se guarda solo mientras se escribe */
(function () {
  document.querySelectorAll('form[data-autoguardar]').forEach(function (f) {
    var ta = f.querySelector('textarea'), est = f.parentNode.querySelector('.sticky-estado'), tm = null, ultimo = ta.value;
    function guardar() {
      if (ta.value === ultimo) return;
      var enviado = ta.value;
      if (est) est.textContent = 'Guardando…';
      fetch(location.pathname, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) { ultimo = enviado; if (est) est.textContent = 'Guardada'; } else if (est) est.textContent = 'No se guardó'; })
        .catch(function () { if (est) est.textContent = 'Sin conexión: no se guardó'; });
    }
    ta.addEventListener('input', function () { clearTimeout(tm); if (est) est.textContent = '…'; tm = setTimeout(guardar, 800); });
    ta.addEventListener('blur', function () { clearTimeout(tm); guardar(); });
    f.addEventListener('submit', function (e) { e.preventDefault(); guardar(); });
    window.addEventListener('beforeunload', function (e) { if (ta.value !== ultimo) { guardar(); e.preventDefault(); e.returnValue = ''; } });
  });
})();

/* notas personales: al estirarlas (ancho y alto) se guarda el tamaño de cada una */
(function () {
  if (!window.ResizeObserver) return;
  var notas = document.querySelectorAll('.sticky[data-nota]');
  if (!notas.length) return;
  var csrf = (document.querySelector('meta[name=csrf]') || {}).content || '';
  var tiempos = {}, listo = false;
  var ro = new ResizeObserver(function (entradas) {
    if (!listo) return; // la primera medida es al cargar la pagina, no un cambio
    entradas.forEach(function (en) {
      var el = en.target, id = el.dataset.nota;
      clearTimeout(tiempos[id]);
      tiempos[id] = setTimeout(function () {
        var f = new FormData();
        f.append('csrf', csrf); f.append('accion', 'np_tamano'); f.append('id', id);
        f.append('ancho', Math.round(el.offsetWidth)); f.append('alto', Math.round(el.offsetHeight));
        fetch(location.pathname, { method: 'POST', body: f, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
      }, 500);
    });
  });
  notas.forEach(function (n) { ro.observe(n); });
  setTimeout(function () { listo = true; }, 300);
})();

/* solicitudes: agregar o quitar lineas y poner la categoria sola si ya se habia pedido antes */
(function () {
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-agregar-linea]');
    if (b) {
      var cont = b.parentNode.querySelector('[data-lineas]'), ult = cont.lastElementChild, n = ult.cloneNode(true);
      var max = parseInt(cont.dataset.max || '0', 10);
      if (max && cont.children.length >= max) { alert('El formato tiene ' + max + ' renglones. Si necesitas más, haz otra solicitud.'); return; }
      n.querySelectorAll('input').forEach(function (i) { i.value = i.type === 'number' ? 1 : (i.name === 'unidad[]' ? 'Unidad' : ''); });
      n.querySelector('select').selectedIndex = 0;
      cont.appendChild(n); n.querySelector('[data-desc]').focus();
      return;
    }
    var q = e.target.closest('[data-quitar-linea]');
    if (q) {
      var c = q.closest('[data-lineas]');
      if (c.children.length > 1) q.closest('.sol-linea').remove();
      else q.closest('.sol-linea').querySelectorAll('input[data-desc]').forEach(function (i) { i.value = ''; });
    }
  });
  document.addEventListener('change', function (e) {
    var i = e.target;
    if (!i.matches || !i.matches('[data-desc]')) return;
    var op = document.querySelector('#lista-descs option[value="' + CSS.escape(i.value) + '"]');
    var sel = i.closest('.sol-linea').querySelector('select');
    if (op && op.dataset.cat && sel && !sel.value) sel.value = op.dataset.cat;
    var un = i.closest('.sol-linea').querySelector('[name="unidad[]"]');
    if (op && op.dataset.unidad && un && (!un.value || un.value === 'Unidad')) un.value = op.dataset.unidad;
  });
})();

/* el PDF de la solicitud sale una sola vez: al abrirlo, la ventana se recarga para que el boton ya no este */
document.addEventListener('click', function (e) {
  var a = e.target.closest('[data-pdf-unico]');
  if (!a) return;
  a.textContent = 'Descargando… ábrelo desde tus descargas';
  a.classList.add('btn-borde'); a.classList.remove('btn-primario');
  setTimeout(function () { location.reload(); }, 3000);
});

/* si hay una ventana emergente abierta, el aviso (ok o error) tambien sale adentro, arriba: si no, queda tapado */
(function () {
  var caja = document.querySelector('.modal-fondo .modal-caja'), f = document.querySelector('[data-flash]');
  if (!caja || !f) return;
  var c = f.cloneNode(true);
  c.removeAttribute('data-flash');
  c.style.margin = '0 0 12px';
  var x = caja.querySelector('.modal-x');
  caja.insertBefore(c, x ? x.nextSibling : caja.firstChild);
})();

/* las ventanas emergentes se pueden mover arrastrándolas desde el título */
(function () {
  var caja = document.querySelector('.modal-fondo .modal-caja');
  if (!caja) return;
  var agarre = caja.querySelector('h2') || caja;
  agarre.classList.add('modal-agarre');
  agarre.title = 'Arrastra para mover la ventana';
  var dx = 0, dy = 0, x0 = 0, y0 = 0, mov = false;
  function ini(e) {
    if (e.target.closest('a, button, input, select, textarea, label')) return;
    var p = e.touches ? e.touches[0] : e;
    mov = true; x0 = p.clientX - dx; y0 = p.clientY - dy;
    e.preventDefault();
  }
  function mover(e) {
    if (!mov) return;
    var p = e.touches ? e.touches[0] : e;
    dx = p.clientX - x0; dy = p.clientY - y0;
    caja.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
  }
  function fin() { mov = false; }
  agarre.addEventListener('mousedown', ini);
  agarre.addEventListener('touchstart', ini, { passive: false });
  document.addEventListener('mousemove', mover);
  document.addEventListener('touchmove', mover, { passive: true });
  document.addEventListener('mouseup', fin);
  document.addEventListener('touchend', fin);
})();
