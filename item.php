<?php
require __DIR__ . '/config.php';
requiere_modulo('items');

$id = (int)($_GET['id'] ?? 0);
$item = null;
if ($id) {
    $st = db()->prepare('SELECT i.*, cat.nombre categoria, cat.mant_meses cat_mant_meses, s.nombre sede, c.nombre responsable, c.id resp_id, pr.nombre prestado_nombre
        FROM items i LEFT JOIN categorias cat ON cat.id = i.categoria_id
        LEFT JOIN sedes s ON s.id = i.sede_id LEFT JOIN colaboradores c ON c.id = i.colaborador_id LEFT JOIN colaboradores pr ON pr.id = i.prestado_a WHERE i.id = ?');
    $st->execute([$id]);
    $item = $st->fetch();
    if (!$item) { http_response_code(404); exit('No existe'); }
}
$editar = !$item || isset($_GET['editar']);
// a donde volver despues de guardar (solo paginas propias)
$volver = $_REQUEST['volver'] ?? '';
if (!preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&%.+\-]*)?$#', $volver)) $volver = '';
if ($editar && !es_admin()) $editar = false;
if (!$item && !es_admin()) { http_response_code(403); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin()) {
    verificar_csrf();
    $accion = $_POST['accion'] ?? 'guardar';

    if ($accion === 'estado' && $item) {
        // mantenimiento baja o volver a disponible sin acta
        $nuevo = $_POST['estado'] ?? '';
        // asignado a mano solo cuando no tiene persona: queda asignado a su ubicacion; a una persona va por acta
        $ok_asig = $nuevo === 'asignado' && empty($item['colaborador_id']);
        if (isset(estados_item()[$nuevo]) && $nuevo !== 'prestado' && ($nuevo !== 'asignado' || $ok_asig)) {
            $nota = trim($_POST['nota'] ?? '');
            if (in_array($nuevo, ['disponible', 'danado', 'baja', 'extraviado'], true)) guardar_ultimo_dueno($id);
            db()->prepare('UPDATE items SET estado = ?, colaborador_id = IF(? IN (\'baja\', \'extraviado\', \'cedido\'), NULL, colaborador_id), prestado_a = NULL, actualizado = NOW() WHERE id = ?')
                ->execute([$nuevo, $nuevo, $id]);
            auditar('items', $id, ['estado' => $item['estado']], ['estado' => $nuevo], $nota ?: null);
            quitar_ip_si_libre($id);
            registrar_movimiento($id, $nuevo, 'Cambio a ' . estados_item()[$nuevo] . ($nota ? ': ' . $nota : ''));
            flash('Estado actualizado');
        }
        ir('item.php?id=' . $id);
    }

    // cambiar el responsable a mano (sin acta): queda en la hoja de vida, en quien lo ha tenido y en ver cambios
    if ($accion === 'propietario' && $item) {
        $nuevo = (int)($_POST['colaborador_id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        $fecha = preg_match('#^\d{4}-\d{2}-\d{2}$#', $_POST['fecha'] ?? '') && $_POST['fecha'] <= date('Y-m-d') ? $_POST['fecha'] : date('Y-m-d');
        $antes_id = (int)($item['colaborador_id'] ?? 0);
        $nombre = function ($cid) { if (!$cid) return 'Sistemas'; $s = db()->prepare('SELECT nombre FROM colaboradores WHERE id = ?'); $s->execute([$cid]); return $s->fetchColumn() ?: '¿?'; };
        if (!in_array($item['estado'], ['asignado', 'instalado', 'disponible', 'mantenimiento', 'danado', 'extraviado'], true)) flash('Con el equipo ' . estados_item()[$item['estado']] . ' no se cambia el responsable aquí', 'error');
        elseif ($motivo === '') flash('Escribe el motivo del cambio', 'error');
        elseif ($nuevo === $antes_id) flash('Ese ya es el responsable', 'error');
        elseif ($nuevo && !$nombre($nuevo)) flash('Colaborador no válido', 'error');
        else {
            $de = $nombre($antes_id);
            $a = $nombre($nuevo);
            if ($antes_id) guardar_ultimo_dueno($id);
            // si estaba por ubicar (extraviado) y aparecio, queda asignado a quien lo tiene o disponible en sistemas
            $estado = $nuevo ? (in_array($item['estado'], ['disponible', 'instalado', 'extraviado'], true) ? 'asignado' : $item['estado']) : (in_array($item['estado'], ['asignado', 'instalado', 'extraviado'], true) ? 'disponible' : $item['estado']);
            db()->prepare('UPDATE items SET colaborador_id = ?, estado = ?, prestado_a = NULL, actualizado = NOW() WHERE id = ?')->execute([$nuevo ?: null, $estado, $id]);
            if (!$nuevo) quitar_ip_si_libre($id);
            db()->prepare('INSERT INTO movimientos (item_id, tipo, colaborador_id, usuario_id, detalle, fecha) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, 'cambio_dueno', $nuevo ?: null, usuario()['id'], 'Cambio de responsable: de ' . $de . ' a ' . $a . ' — ' . $motivo, $fecha . ' ' . date('H:i:s')]);
            auditar('items', $id, ['responsable' => $de, 'estado' => $item['estado']], ['responsable' => $a, 'estado' => $estado], $motivo);
            flash('Responsable cambiado: ahora lo tiene ' . $a);
        }
        ir('item.php?id=' . $id);
    }

    // separar un combo (teclado + mouse...) en items sueltos, para entregar o prestar solo una parte
    if ($accion === 'separar' && $item) {
        $partes = array_values(array_filter(array_map('trim', preg_split('#\r?\n#', (string)($_POST['partes'] ?? ''))), fn($x) => $x !== ''));
        if (count($partes) < 2 || count($partes) > 6) { flash('Escribe entre 2 y 6 partes, una por línea', 'error'); ir('item.php?id=' . $id); }
        if ($item['estado'] !== 'disponible') { flash('Solo se separa lo que está disponible en sistemas', 'error'); ir('item.php?id=' . $id); }
        $pdo = db();
        $pdo->beginTransaction();
        $chk = $pdo->prepare('SELECT 1 FROM items WHERE etiqueta = ?');
        $ins = $pdo->prepare("INSERT INTO items (etiqueta, nombre, categoria_id, marca, modelo, cantidad, estado, sede_id, ubicacion, condicion, notas, creado)
            VALUES (?, ?, ?, ?, NULL, 1, 'disponible', ?, ?, ?, ?, NOW())");
        $nuevas = [];
        foreach ($partes as $k => $parte) {
            $suf = strtoupper(mb_substr(preg_replace('#[^A-Za-z]#', '', iconv('UTF-8', 'ASCII//TRANSLIT', $parte)) ?: 'P', 0, 1));
            $et = $item['etiqueta'] . '-' . $suf;
            $n = 2;
            while (in_array($et, $nuevas, true) || ($chk->execute([$et]) && $chk->fetchColumn())) $et = $item['etiqueta'] . '-' . $suf . $n++;
            $ins->execute([$et, mb_substr($parte, 0, 150), $item['categoria_id'], $item['marca'], $item['sede_id'], $item['ubicacion'], $item['condicion'] ?? null,
                'Parte del combo ' . $item['etiqueta'] . ' (' . $item['nombre'] . ')']);
            registrar_movimiento((int)$pdo->lastInsertId(), 'creado', 'Separado del combo ' . $item['etiqueta']);
            $nuevas[] = $et;
        }
        // el combo deja de existir: si ya tiene historia (actas) queda de baja como registro, si no se borra
        $st = $pdo->prepare('SELECT COUNT(*) FROM acta_items WHERE item_id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE items SET estado = 'baja', notas = CONCAT(COALESCE(notas, ''), '\nSeparado en ', ?), actualizado = NOW() WHERE id = ?")->execute([implode(', ', $nuevas), $id]);
            registrar_movimiento($id, 'baja', 'Separado en ' . implode(', ', $nuevas));
        } else {
            $pdo->prepare('DELETE FROM movimientos WHERE item_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
        }
        auditar('items', $id, ['nombre' => $item['nombre']], ['nombre' => null], 'Combo ' . $item['etiqueta'] . ' separado en ' . implode(', ', $nuevas));
        $pdo->commit();
        flash('Separado en ' . implode(', ', $nuevas) . '. Cada parte se entrega o presta por aparte.');
        ir('items.php?q=' . urlencode($item['etiqueta']));
    }

    if ($accion === 'nota' && $item) {
        $nota = trim($_POST['nota'] ?? '');
        if ($nota !== '') registrar_movimiento($id, 'nota', $nota);
        ir('item.php?id=' . $id);
    }

    $d = [];
    foreach (['nombre', 'marca', 'modelo', 'serial', 'especificaciones', 'notas', 'condicion', 'fecha_compra', 'valor', 'categoria_id', 'sede_id',
        'ubicacion', 'ip', 'mac', 'hostname', 'extension', 'consumible', 'mant_meses', 'formato', 'proximo_mant', 'orden'] as $k) {
        $v = trim($_POST[$k] ?? '');
        $d[$k] = $v === '' ? null : $v;
    }
    $d['cantidad'] = max(1, (int)($_POST['cantidad'] ?? 1));
    $etiqueta = strtoupper(trim($_POST['etiqueta'] ?? ''));
    if ($d['ip'] !== null && !filter_var($d['ip'], FILTER_VALIDATE_IP)) $error = 'La IP no es valida';
    // libre = apagado en sistemas, sin ip
    if ($d['ip'] !== null && $item && ($item['estado'] ?? '') === 'disponible') $error = 'Este equipo está Disponible (libre, apagado): no puede tener IP. Primero cámbialo a "Asignado" o entrégalo con un acta.';
    if ($d['mac'] !== null) $d['mac'] = strtoupper(str_replace('-', ':', $d['mac']));
    // el pin solo se cambia si escriben uno nuevo
    $pin_nuevo = trim($_POST['pin'] ?? '');
    if ($pin_nuevo !== '') {
        try { $d['clave_cifrada'] = cifrar($pin_nuevo); } catch (Throwable $ex) { $error = 'No se pudo guardar el PIN: falta APP_KEY en el .env'; }
    }
    if (!empty($_POST['pin_borrar'])) $d['clave_cifrada'] = null;
    if ($d['valor'] !== null) $d['valor'] = str_replace(['.', ','], ['', '.'], $d['valor']);

    if (!$d['nombre'] && !$error) $error = 'El nombre es obligatorio';
    if (!$error && $etiqueta === '') $etiqueta = siguiente_etiqueta($d['categoria_id']);
    if (!$error) {
        $st = db()->prepare('SELECT id FROM items WHERE etiqueta = ? AND id <> ?');
        $st->execute([$etiqueta, $id]);
        if ($st->fetch()) $error = 'La etiqueta ' . $etiqueta . ' ya existe';
    }
    if (!$error && $d['ip']) {
        $st = db()->prepare("SELECT etiqueta FROM items WHERE ip = ? AND id <> ? AND estado <> 'baja'");
        $st->execute([$d['ip'], $id]);
        if ($otro = $st->fetchColumn()) flash('Ojo la IP ' . $d['ip'] . ' tambien la tiene ' . $otro, 'info');
    }
    if (!$error && $d['serial']) {
        $st = db()->prepare('SELECT etiqueta FROM items WHERE serial = ? AND id <> ?');
        $st->execute([$d['serial'], $id]);
        if ($otro = $st->fetchColumn()) flash('Ojo el serial tambien esta en ' . $otro, 'info');
    }
    if (!$error) {
        $cols = array_keys($d);
        if ($item) {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
            db()->prepare("UPDATE items SET etiqueta = ?, $set, actualizado = NOW() WHERE id = ?")
                ->execute(array_merge([$etiqueta], array_values($d), [$id]));
            auditar('items', $id, $item, array_merge(['etiqueta' => $etiqueta], $d));
        } else {
            $ph = implode(',', array_fill(0, count($cols) + 1, '?'));
            db()->prepare('INSERT INTO items (etiqueta, ' . implode(',', $cols) . ") VALUES ($ph)")
                ->execute(array_merge([$etiqueta], array_values($d)));
            $id = (int)db()->lastInsertId();
            registrar_movimiento($id, 'alta', 'Item creado');
            auditar('items', $id, null, null, 'Equipo creado');
        }
        if (!flash_pendiente()) flash('Guardado');
        ir($volver ?: 'item.php?id=' . $id);
    }
    $item = array_merge($item ?? [], $d, ['etiqueta' => $etiqueta]);
}

function flash_pendiente() { return !empty($_SESSION['flash']); }

$titulo = $item ? ($item['etiqueta'] ?? 'Item') : 'Nuevo item';
$menu = 'items';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera">
  <div><a href="items.php" class="tenue">&larr; Inventario</a>
    <h1><?= $item && !empty($item['id']) ? e($item['etiqueta']) . ' · ' . e($item['nombre']) : 'Nuevo item' ?></h1></div>
  <?php if (!$editar && es_admin()): ?>
  <div class="acciones">
    <?= boton_cambios([$id => cambios_de('items', $id)], [$id => $item['etiqueta']]) ?>
    <?php if ($item['estado'] === 'asignado' && $item['resp_id']): ?>
      <a class="btn btn-borde" href="acta_nueva.php?tipo=devolucion&colab=<?= $item['resp_id'] ?>&items=<?= $id ?>">Recibir devolucion</a>
    <?php elseif ($item['estado'] === 'disponible'): ?>
      <a class="btn btn-primario" href="acta_nueva.php?tipo=entrega&items=<?= $id ?>">Entregar</a>
    <?php endif; ?>
    <a class="btn btn-borde" href="item.php?id=<?= $id ?>&editar=1">Editar datos</a>
  </div>
  <?php endif; ?>
</div>
<?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($editar): ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?><?php if ($volver): ?><input type="hidden" name="volver" value="<?= e($volver) ?>"><?php endif; ?>
  <div class="rejilla">
    <div class="campo"><label>Categoria</label><select name="categoria_id"><?= opciones(categorias(), $item['categoria_id'] ?? '', 'nombre', 'Sin categoria') ?></select></div>
    <div class="campo"><label>Etiqueta (vacio = automatica)</label><input name="etiqueta" value="<?= e($item['etiqueta'] ?? '') ?>" placeholder="PC-0001"></div>
    <div class="campo"><label>Nombre *</label><input name="nombre" required value="<?= e($item['nombre'] ?? '') ?>" placeholder="Portatil contabilidad"></div>
    <div class="campo"><label>Marca</label><input name="marca" value="<?= e($item['marca'] ?? '') ?>"></div>
    <div class="campo"><label>Modelo</label><input name="modelo" value="<?= e($item['modelo'] ?? '') ?>"></div>
    <div class="campo"><label>Serial</label><input name="serial" value="<?= e($item['serial'] ?? '') ?>"></div>
    <div class="campo"><label>Bodega</label><select name="sede_id"><?= opciones(sedes(), $item['sede_id'] ?? '', 'nombre', 'Sin bodega') ?></select></div>
    <div class="campo"><label>Cantidad</label><input type="number" min="1" name="cantidad" value="<?= e($item['cantidad'] ?? 1) ?>"></div>
    <div class="campo"><label>Fecha de compra</label><input type="date" name="fecha_compra" value="<?= e($item['fecha_compra'] ?? '') ?>"></div>
    <div class="campo"><label>Valor</label><input name="valor" inputmode="decimal" value="<?= e($item['valor'] ?? '') ?>"></div>
    <div class="campo"><label>Tipo de PC (si es computador)</label><select name="formato">
      <?php foreach (formatos_pc() as $k => $v): ?><option value="<?= $k ?>" <?= ($item['formato'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="campo"><label>Ubicacion fisica</label><input name="ubicacion" value="<?= e($item['ubicacion'] ?? '') ?>" placeholder="1er piso · Recepción"></div>
    <div class="campo"><label>Orden en la lista (opcional)</label><input type="number" min="0" name="orden" value="<?= e($item['orden'] ?? '') ?>" placeholder="1 = primero"><small>Para impresoras y access points: de 1er piso hacia arriba</small></div>
    <div class="campo"><label>IP</label><input name="ip" value="<?= e($item['ip'] ?? '') ?>" placeholder="192.168.0.10" inputmode="decimal"></div>
    <div class="campo"><label>MAC</label><input name="mac" value="<?= e($item['mac'] ?? '') ?>"></div>
    <div class="campo"><label>Nombre en red (hostname)</label><input name="hostname" value="<?= e($item['hostname'] ?? '') ?>"></div>
    <div class="campo"><label>Extension</label><input name="extension" value="<?= e($item['extension'] ?? '') ?>" inputmode="numeric"></div>
    <div class="campo"><label>Toner / consumible</label><input name="consumible" value="<?= e($item['consumible'] ?? '') ?>" placeholder="TK 3182"></div>
    <div class="campo"><label>Mantenimiento cada (meses)</label><input type="number" min="0" name="mant_meses" value="<?= e($item['mant_meses'] ?? '') ?>" placeholder="El de la categoria"></div>
    <div class="campo"><label>Próximo mantenimiento (fecha fija, opcional)</label><input type="date" name="proximo_mant" value="<?= e($item['proximo_mant'] ?? '') ?>"></div>
    <div class="campo"><label>PIN o clave del equipo<?= !empty($item['clave_cifrada']) ? ' (vacio = no cambia)' : '' ?></label><input type="password" name="pin" autocomplete="new-password">
      <?php if (!empty($item['clave_cifrada'])): ?><label style="display:flex;gap:6px;align-items:center;margin-top:4px"><input type="checkbox" name="pin_borrar" value="1"> Quitar el PIN guardado</label><?php endif; ?></div>
    <div class="campo ancho"><label>Especificaciones (procesador, ram, disco, imei, talla...)</label><textarea name="especificaciones"><?= e($item['especificaciones'] ?? '') ?></textarea></div>
    <div class="campo"><label>Condición física (opcional)</label><input name="condicion" list="condiciones" maxlength="120" value="<?= e($item['condicion'] ?? '') ?>" placeholder="Ej. Mal estado · sin disco"><datalist id="condiciones"><option value="Nuevo"><option value="Usado"><option value="Mal estado"><option value="Regular"><option value="Mal estado · sin disco"><option value="Pantalla rota"><option value="Batería dañada"></datalist><small>Nuevo sale en verde, Usado en gris y cualquier falla en rojo, junto al estado</small></div>
    <div class="campo ancho"><label>Notas</label><textarea name="notas"><?= e($item['notas'] ?? '') ?></textarea></div>
  </div>
  <p><button class="btn btn-primario">Guardar</button>
    <a class="btn btn-borde" href="<?= e($volver ?: ($id ? 'item.php?id=' . $id : 'items.php')) ?>">Cancelar</a></p>
</form>
<?php else: ?>
<div class="dos-col">
  <div>
    <div class="tarjeta">
      <div class="ficha">
        <div><small>Estado</small><span><?= chip_estado($item['estado']) ?><?= chip_condicion($item['condicion'] ?? null, $id) ?></span></div>
        <div><small>Responsable</small><span><?php if (in_array($item['estado'], ['disponible', 'danado'], true) && !empty($item['ultimo_dueno'])): ?>Sistemas<small class="tenue">antes: <?= e($item['ultimo_dueno']) ?></small><?php else: ?><?= $item['estado'] === 'cedido' ? 'Cedido a ' . e($item['ubicacion'] ?: 'otra empresa') : (in_array($item['estado'], ['asignado', 'instalado'], true) && !$item['colaborador_id'] ? e($item['ubicacion'] ?: 'Su ubicación') : ($item['estado'] === 'prestado' ? '<b>Sistemas</b> · prestado a ' . ($item['prestado_a'] ? '<a href="colaborador.php?id=' . (int)$item['prestado_a'] . '">' . e($item['prestado_nombre']) . '</a>' : '—') : ($item['responsable'] ? '<a href="colaborador.php?id=' . $item['resp_id'] . '">' . e($item['responsable']) . '</a>' : '—'))) ?><?php endif; ?></span></div>
        <div><small>Categoria</small><span><?= e(($item['categoria'] === 'Computador' ? tipo_pc($item) : $item['categoria']) ?: '—') ?></span></div>
        <div><small>Bodega</small><span><?= e($item['sede'] ?: '—') ?></span></div>
        <div><small>Marca</small><span><?= e($item['marca'] ?: '—') ?></span></div>
        <div><small>Modelo</small><span><?= e($item['modelo'] ?: '—') ?></span></div>
        <div><small>Serial</small><span class="mono"><?= e($item['serial'] ?: '—') ?></span></div>
        <div><small>Cantidad</small><span><?= (int)$item['cantidad'] ?></span></div>
        <div><small>Fecha de compra</small><span><?= fecha_corta($item['fecha_compra']) ?: '—' ?></span></div>
        <div><small>Valor</small><span><?= $item['valor'] !== null ? '$' . number_format($item['valor'], 0, ',', '.') : '—' ?></span></div>
        <?php foreach (['ubicacion' => 'Ubicacion', 'ip' => 'IP', 'mac' => 'MAC', 'hostname' => 'Hostname', 'extension' => 'Extension', 'consumible' => 'Toner / consumible'] as $k => $lbl): if (!$item[$k]) continue; ?>
          <div><small><?= $lbl ?></small><span class="<?= in_array($k, ['ip', 'mac'], true) ? 'mono' : '' ?>"><?= e($item[$k]) ?></span></div>
        <?php endforeach; ?>
        <?php if ($item['clave_cifrada'] && es_admin()): ?><div><small>PIN / clave</small><?= campo_secreto('item', $id, true) ?></div><?php endif; ?>
        <?php if ($freq = frecuencia_item($item)): $prox = proximo_mant($item); ?>
          <div><small>Mantenimiento</small><span>Cada <?= $freq ?> meses</span></div>
          <div><small>Ultimo / proximo</small><span><?= fecha_corta($item['ultimo_mant']) ?: 'Nunca' ?> · <?= $prox <= date('Y-m-d') ? '<span class="chip chip-vencido">Vencido</span>' : fecha_corta($prox) ?></span></div>
        <?php endif; ?>
      </div>
      <?php if ($item['especificaciones']): ?><p style="white-space:pre-line"><small class="tenue">Especificaciones</small><br><?= e($item['especificaciones']) ?></p><?php endif; ?>
      <?php if ($item['notas']): ?><p style="white-space:pre-line"><small class="tenue">Notas</small><br><?= e($item['notas']) ?></p><?php endif; ?>
    </div>
    <div class="tarjeta">
      <h2>Quién lo ha tenido y cuánto duró</h2>
      <?= pintar_tenencias(tenencias_item($item)) ?>
      <p class="tenue" style="margin:8px 0 0;font-size:12px">Cada entrega dura hasta el siguiente movimiento del equipo (otra entrega, préstamo o devolución).</p>
    </div>
    <div class="tarjeta">
      <div class="cabecera" style="margin-bottom:6px"><h2 style="margin:0">Hoja de vida</h2>
        <?php if (es_admin()): ?><div class="acciones">
          <a class="btn btn-borde btn-sm" href="mantenimiento.php?item=<?= $id ?>">+ Mantenimiento / reparación</a>
          <?php if ($item['consumible']): ?><a class="btn btn-borde btn-sm" href="impresoras.php?toner=<?= $id ?>&volver=item#formulario">+ Cambio de tóner</a><?php endif; ?>
        </div><?php endif; ?></div>
      <p class="tenue" style="margin:0 0 8px;font-size:12.5px">Asignaciones, préstamos, devoluciones, reparaciones, mantenimientos y cambios, del más reciente al más antiguo.</p>
      <?php if (es_admin()): ?>
      <form method="post" style="display:flex;gap:6px;margin-bottom:6px"><?= csrf_campo() ?><input type="hidden" name="accion" value="nota">
        <input name="nota" placeholder="Agregar una nota a la hoja de vida" style="flex:1"><button class="btn btn-borde btn-sm">Agregar</button></form>
      <?php endif; ?>
      <?= pintar_linea_tiempo(linea_tiempo_item($id)) ?>
    </div>
  </div>
  <div>
    <?php if (es_admin()): ?>
    <?php if (in_array($item['estado'], ['asignado', 'instalado', 'disponible', 'mantenimiento', 'danado', 'extraviado'], true)):
      $gente = db()->query('SELECT id, nombre, area FROM colaboradores WHERE activo = 1 ORDER BY nombre')->fetchAll(); ?>
    <form method="post" class="tarjeta">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="propietario">
      <h2>Cambiar responsable</h2>
      <div class="campo"><label>Ahora lo tiene</label><select name="colaborador_id" data-buscar required>
        <option value="">Escribe el nombre…</option>
        <option value="0" <?= empty($item['colaborador_id']) ? 'disabled' : '' ?>>Sistemas (sin responsable, vuelve a estar libre)</option>
        <?php foreach ($gente as $g): if ((int)$g['id'] === (int)$item['colaborador_id']) continue; ?><option value="<?= $g['id'] ?>"><?= e($g['nombre']) ?><?= $g['area'] ? ' · ' . e($g['area']) : '' ?></option><?php endforeach; ?>
      </select></div>
      <div class="rejilla" style="margin-top:8px">
        <div class="campo"><label>Desde</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
        <div class="campo"><label>Motivo</label><input name="motivo" required placeholder="Ej. se lo pasaron en el área"></div>
      </div>
      <button class="btn btn-borde btn-sm" style="margin-top:8px">Cambiar</button>
      <p class="tenue" style="margin-bottom:0">Queda en la hoja de vida, en "quién lo ha tenido" y en Ver cambios. Si necesitas la firma de quien lo recibe, usa Entregar.</p>
    </form>
    <?php endif; ?>
    <form method="post" class="tarjeta">
      <?= csrf_campo() ?><input type="hidden" name="accion" value="estado">
      <h2>Cambiar estado</h2>
      <div class="campo"><select name="estado">
        <?php if (empty($item['colaborador_id'])): ?><option value="asignado">Asignado (a su ubicación)</option><?php endif; ?>
        <option value="disponible">Disponible (libre, apagado)</option><option value="mantenimiento">Mantenimiento</option><option value="danado">Dañado</option><option value="extraviado">Extraviado</option><option value="cedido">Cedido a otra empresa</option><option value="baja">Dar de baja</option>
      </select></div>
      <div class="campo" style="margin:8px 0"><input name="nota" placeholder="Motivo o detalle" style="width:100%"></div>
      <button class="btn btn-borde btn-sm">Aplicar</button>
      <p class="tenue" style="margin-bottom:0">Para asignar o recibir usa un acta, así queda la firma.</p>
    </form>
    <?php if ($item['estado'] === 'disponible'):
      // sugerencia de partes: "Combo teclado + mouse Logitech" -> Teclado Logitech / Mouse Logitech
      $base = preg_replace('#^\s*(combo|kit|juego)\s+(de\s+)?#iu', '', $item['nombre']);
      $marca = $item['marca'] && stripos($base, $item['marca']) !== false ? $item['marca'] : '';
      if ($marca) $base = trim(str_ireplace($marca, '', $base));
      $sug = strpos($base, '+') !== false ? implode("\n", array_map(fn($x) => trim(mb_strtoupper(mb_substr(trim($x), 0, 1)) . mb_substr(trim($x), 1) . ' ' . $marca), explode('+', $base))) : ''; ?>
    <details class="tarjeta" <?= $sug ? 'open' : '' ?>>
      <summary style="cursor:pointer"><b>Separar en partes</b> <small class="tenue">(combo teclado + mouse, kit…)</small></summary>
      <form method="post" style="margin-top:10px" onsubmit="return confirm('¿Separar <?= e(addslashes($item['etiqueta'])) ?> en items sueltos?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="separar">
        <div class="campo"><label>Una parte por línea</label><textarea name="partes" rows="3" placeholder="Teclado Logitech&#10;Mouse Logitech"><?= e($sug) ?></textarea>
          <small>Cada parte queda como item disponible (<?= e($item['etiqueta']) ?>-T, <?= e($item['etiqueta']) ?>-M…) y se entrega o presta sola; las que no salgan siguen disponibles.</small></div>
        <button class="btn btn-borde btn-sm">Separar</button>
      </form>
    </details>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
