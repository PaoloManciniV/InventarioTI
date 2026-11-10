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
