<?php
// tareas del equipo de sistemas: se escogen de una lista (tipos), con observacion, notas del caso,
// transferencias entre compañeros y un bloc de notas personal que solo ve su dueño
require __DIR__ . '/config.php';
require_once __DIR__ . '/inc/tareas.php';
requiere_modulo('tareas');

$yo = (int)usuario()['id'];
$miembros = miembros_tareas();
$json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function volver($msg = null, $tipo = 'ok', $extra = '') {
    global $json;
    if ($json) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => $tipo === 'ok', 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit; }
    if ($msg) flash($msg, $tipo);
    $v = preg_replace('/[^a-z]/', '', $_POST['vista'] ?? $_GET['vista'] ?? '');
    // si la accion se hizo desde la ventana de la tarea, se vuelve a abrir esa ventana
    $ver = ($_POST['desde'] ?? '') === 'ver' && ($_POST['accion'] ?? '') !== 'borrar' ? (int)($_POST['id'] ?? 0) : 0;
    $q = array_filter(['vista' => $v, 'ver' => $ver ?: null]);
    ir('tareas.php' . ($q ? '?' . http_build_query($q) : '') . ($ver ? '' : $extra));
}

// fecha + hora opcional -> [DATETIME o null, con_hora]
function leer_vence() {
    $f = $_POST['fecha'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) return [null, 0];
    $h = $_POST['hora'] ?? '';
    if (preg_match('/^\d{2}:\d{2}$/', $h)) return [$f . ' ' . $h . ':00', 1];
    return [$f . ' 23:59:00', 0];
}

function leer_para($miembros, $yo) {
    $p = $_POST['para'] ?? 'yo';
    if ($p === 'yo') return [$yo, 1];
    if ($p === 'grupo') return [null, 0];
    $id = (int)$p;
    if (!isset($miembros[$id])) volver('Esa persona no está en el grupo', 'error');
    return [$id, 0];
}

// tipo escogido de la lista + persona y equipo relacionados
function leer_tipo_y_relacion() {
    $st = db()->prepare('SELECT * FROM tareas_tipos WHERE id = ? AND activo = 1');
    $st->execute([(int)($_POST['tipo_id'] ?? 0)]);
    $tipo = $st->fetch();
    if (!$tipo) volver('Escoge la tarea de la lista', 'error');
    $obs = trim($_POST['detalle'] ?? '');
    if ($obs === '' && stripos($tipo['nombre'], 'otra') === 0) volver('Para “Otra” escribe en la observación de qué se trata', 'error');
    $col = (int)($_POST['colaborador_id'] ?? 0) ?: null;
    $item = null;
    $et = trim($_POST['item'] ?? '');
    if ($et !== '') {
        $st = db()->prepare('SELECT id FROM items WHERE etiqueta = ?');
        $st->execute([preg_replace('/\s.*$/', '', $et)]);
        $item = $st->fetchColumn() ?: null;
        if (!$item) volver('No encontré el equipo “' . $et . '”. Escoge uno de la lista', 'error');
    }
    return [$tipo, $obs === '' ? null : mb_substr($obs, 0, 4000), $col, $item];
}

// ---------- acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $acc = $_POST['accion'] ?? '';

    // bloc de notas personal: todo filtrado por usuario_id, nadie mas lo ve
    if (str_starts_with($acc, 'np_')) {
        $nid = (int)($_POST['id'] ?? 0);
        if ($acc === 'np_nueva') {
            db()->prepare('INSERT INTO notas_personales (usuario_id, texto, color, actualizado) VALUES (?, ?, ?, NOW())')
                ->execute([$yo, trim($_POST['texto'] ?? '') ?: null, 'amarillo']);
            volver(null, 'ok', '#n' . db()->lastInsertId());
        }
        $colores = ['amarillo', 'verde', 'azul', 'rosa', 'gris'];
        if ($acc === 'np_guardar') {
            db()->prepare('UPDATE notas_personales SET texto = ?, actualizado = NOW() WHERE id = ? AND usuario_id = ?')
                ->execute([mb_substr((string)($_POST['texto'] ?? ''), 0, 20000), $nid, $yo]);
            volver('Guardada');
        }
        if ($acc === 'np_color' && in_array($_POST['color'] ?? '', $colores, true)) {
            db()->prepare('UPDATE notas_personales SET color = ? WHERE id = ? AND usuario_id = ?')->execute([$_POST['color'], $nid, $yo]);
        }
        if ($acc === 'np_tamano') {
            // el tamaño que le dio al estirarla (se guarda solo, sin recargar)
            db()->prepare('UPDATE notas_personales SET ancho = ?, alto = ? WHERE id = ? AND usuario_id = ?')
                ->execute([max(180, min(1200, (int)($_POST['ancho'] ?? 250))), max(150, min(1200, (int)($_POST['alto'] ?? 230))), $nid, $yo]);
            volver('Guardada');
        }
        if ($acc === 'np_fijar') {
            db()->prepare('UPDATE notas_personales SET fijada = 1 - fijada WHERE id = ? AND usuario_id = ?')->execute([$nid, $yo]);
        }
        if ($acc === 'np_borrar') {
            db()->prepare('DELETE FROM notas_personales WHERE id = ? AND usuario_id = ?')->execute([$nid, $yo]);
        }
        volver(null, 'ok', $acc === 'np_borrar' ? '' : '#n' . $nid);
    }

    // lista de tipos: solo quienes administran usuarios (Paolo y Carlos)
    if ($acc === 'tipo_guardar' && gestiona_usuarios()) {
        $cat = mb_substr(trim($_POST['categoria'] ?? ''), 0, 80);
        $nom = mb_substr(trim($_POST['nombre'] ?? ''), 0, 150);
        $tid = (int)($_POST['id'] ?? 0);
        if ($cat === '' || $nom === '') volver('Falta la categoría o el nombre', 'error');
        try {
            if ($tid) {
                db()->prepare('UPDATE tareas_tipos SET categoria = ?, nombre = ?, activo = ? WHERE id = ?')->execute([$cat, $nom, empty($_POST['activo']) ? 0 : 1, $tid]);
            } else {
                $orden = (int)db()->query('SELECT COALESCE(MAX(orden), 0) + 10 FROM tareas_tipos')->fetchColumn();
                db()->prepare('INSERT INTO tareas_tipos (categoria, nombre, orden) VALUES (?,?,?)')->execute([$cat, $nom, $orden]);
            }
        } catch (PDOException $e) { volver('Ya existe esa tarea en esa categoría', 'error'); }
        volver('Lista guardada');
    }

    $t = !empty($_POST['id']) ? tarea($_POST['id']) : null;
    if ($acc !== 'crear' && (!$t || !ve_tarea($t, $yo))) volver('Esa tarea no existe', 'error');
    $soy_creador = $t && (int)$t['creado_por'] === $yo;

    if ($acc === 'crear') {
        [$tipo, $obs, $col, $item] = leer_tipo_y_relacion();
        [$para_id, $privada] = leer_para($miembros, $yo);
        [$vence, $con_hora] = leer_vence();
        db()->prepare('INSERT INTO tareas (tipo_id, titulo, detalle, colaborador_id, item_id, creado_por, para_id, privada, vence, con_hora, prioridad) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tipo['id'], $tipo['nombre'], $obs, $col, $item, $yo, $para_id, $privada, $vence, $con_hora, ($_POST['prioridad'] ?? '') === 'alta' ? 'alta' : 'normal']);
        $id = (int)db()->lastInsertId();
        nota_tarea($id, 'Creó la tarea' . ($privada ? '' : ($para_id === null ? ' para todo el grupo' : ' para ' . $miembros[$para_id]['nombre'])));
        if (!$privada) avisar_tarea($id, 'nueva', array_diff(involucrados_tarea(tarea($id)), [$yo]));
        volver(null, 'ok', '#t' . $id);
    }

    if (!trabaja_tarea($t, $yo)) volver('Esa tarea no es tuya', 'error');

    if ($acc === 'hecha') {
        $valor = !empty($_POST['valor']) ? 1 : 0;
        if ((int)$t['hecha'] !== $valor) {
            db()->prepare('UPDATE tareas SET hecha = ?, hecha_por = ?, hecha_en = ?, actualizado = NOW() WHERE id = ?')
                ->execute([$valor, $valor ? $yo : null, $valor ? date('Y-m-d H:i:s') : null, $t['id']]);
            nota_tarea($t['id'], $valor ? 'La marcó como hecha' : 'La volvió a abrir');
            if (!$t['privada']) avisar_tarea($t['id'], $valor ? 'hecha' : 'reabierta', array_diff(involucrados_tarea($t), [$yo]));
        }
        volver($valor ? 'Hecha' : 'Reabierta', 'ok', '#t' . $t['id']);
    }

    if ($acc === 'nota') {
        $txt = trim($_POST['texto'] ?? '');
        if ($txt === '') volver('Escribe la nota', 'error');
        nota_tarea($t['id'], $txt, 'nota');
        db()->prepare('UPDATE tareas SET actualizado = NOW() WHERE id = ?')->execute([$t['id']]);
        volver('Nota agregada', 'ok', '#t' . $t['id']);
    }

    if ($acc === 'tomar') {
        // una tarea del grupo pasa a ser de quien la toma
        if ($t['para_id'] !== null || $t['privada'] || $t['hecha']) volver('Esa tarea ya tiene responsable', 'error');
        db()->prepare('UPDATE tareas SET para_id = ?, actualizado = NOW() WHERE id = ?')->execute([$yo, $t['id']]);
        nota_tarea($t['id'], 'Tomó la tarea del grupo: queda a su cargo');
        avisar_tarea($t['id'], 'tomada', array_diff([(int)$t['creado_por']], [$yo]));
        volver('La tarea quedó a tu cargo', 'ok', '#t' . $t['id']);
    }

    if ($acc === 'transferir') {
        if ($t['privada'] || $t['hecha']) volver('Esa tarea no se puede transferir', 'error');
        $a = $_POST['a'] ?? '';
        $nuevo = $a === 'grupo' ? null : (int)$a;
        if ($nuevo !== null && !isset($miembros[$nuevo])) volver('Escoge a quién se la pasas', 'error');
        if ($nuevo === ($t['para_id'] === null ? null : (int)$t['para_id'])) volver('Ya está a cargo de esa persona', 'error');
        $motivo = trim($_POST['motivo'] ?? '');
        if ($motivo === '') volver('Escribe el motivo y hasta dónde llegaste', 'error');
        $antes = $t['para_id'] === null ? 'todo el grupo' : $t['para'];
        db()->prepare('UPDATE tareas SET para_id = ?, aviso_vencida = NULL, actualizado = NOW() WHERE id = ?')->execute([$nuevo, $t['id']]);
        nota_tarea($t['id'], 'Transfirió la tarea de ' . $antes . ' a ' . ($nuevo === null ? 'todo el grupo' : $miembros[$nuevo]['nombre']) . ".\nMotivo: " . $motivo, 'transferencia');
        $t2 = tarea($t['id']);
        avisar_tarea($t['id'], 'transferida', array_diff(array_merge(involucrados_tarea($t2), [(int)$t['creado_por']]), [$yo]), $motivo);
        volver('Tarea transferida', 'ok', '#t' . $t['id']);
    }

    if ($acc === 'editar') {
        [$vence, $con_hora] = leer_vence();
        $antes_vence = $t['vence'] ? vence_texto($t) . ' (' . fecha_corta($t['vence']) . ')' : 'sin fecha';
        $cambio_fecha = $vence !== $t['vence'] || (int)$con_hora !== (int)$t['con_hora'];
        if ($soy_creador) {
            [$tipo, $obs, $col, $item] = leer_tipo_y_relacion();
            [$para_id, $privada] = leer_para($miembros, $yo);
            $nuevo = ['tipo_id' => $tipo['id'], 'titulo' => $tipo['nombre'], 'detalle' => $obs, 'colaborador_id' => $col, 'item_id' => $item,
                'para_id' => $para_id, 'privada' => $privada, 'vence' => $vence, 'con_hora' => $con_hora, 'prioridad' => ($_POST['prioridad'] ?? '') === 'alta' ? 'alta' : 'normal'];
        } else {
            // a quien se la pusieron (o el grupo) solo mueve la fecha de entrega
            $nuevo = ['vence' => $vence, 'con_hora' => $con_hora];
        }
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($nuevo)));
        db()->prepare("UPDATE tareas SET $sets" . ($cambio_fecha ? ', aviso_vencida = NULL' : '') . ', actualizado = NOW() WHERE id = ?')
            ->execute([...array_values($nuevo), $t['id']]);
        $t2 = tarea($t['id']);
        if ($cambio_fecha) nota_tarea($t['id'], 'Cambió la entrega de ' . $antes_vence . ' a ' . ($t2['vence'] ? vence_texto($t2) . ' (' . fecha_corta($t2['vence']) . ')' : 'sin fecha'));
        if (!$t2['privada'] && !$t2['hecha']) {
            $nuevos = array_diff(involucrados_tarea($t2), $t['privada'] ? [] : involucrados_tarea($t), [$yo]);
            if ($nuevos) avisar_tarea($t2['id'], 'nueva', $nuevos);
            if ($cambio_fecha) avisar_tarea($t2['id'], 'fecha', array_diff(involucrados_tarea($t2), $nuevos, [$yo]), $antes_vence);
        }
        volver('Tarea guardada', 'ok', '#t' . $t['id']);
    }

    if ($acc === 'recordar') {
        if ($t['privada'] || $t['hecha']) volver('No hay a quién recordarle', 'error');
        if ($t['ultimo_recordatorio'] && strtotime($t['ultimo_recordatorio']) > time() - 3600) volver('Ya se le recordó hace menos de una hora', 'error', '#t' . $t['id']);
        $a = array_diff($t['para_id'] === null ? involucrados_tarea($t) : [(int)$t['para_id']], [$yo]);
        if (!$a) volver('La tarea es tuya: no hay a quién recordarle', 'error', '#t' . $t['id']);
        db()->prepare('UPDATE tareas SET ultimo_recordatorio = NOW() WHERE id = ?')->execute([$t['id']]);
        nota_tarea($t['id'], 'Mandó un recordatorio por correo');
        avisar_tarea($t['id'], 'recordar', $a);
        volver('Recordatorio enviado por correo', 'ok', '#t' . $t['id']);
    }

    if ($acc === 'borrar') {
        if (!$soy_creador) volver('Solo quien la creó la puede borrar', 'error');
        db()->prepare('DELETE FROM tareas_notas WHERE tarea_id = ?')->execute([$t['id']]);
        db()->prepare('DELETE FROM tareas WHERE id = ?')->execute([$t['id']]);
        volver('Tarea borrada');
    }
    volver();
}

// ---------- vista ----------
$vistas = ['mias' => 'Para mí', 'mande' => 'Las que mandé', 'equipo' => 'Todo el equipo', 'hechas' => 'Hechas', 'notas' => '📌 Mis notas'];
if (gestiona_usuarios()) $vistas['tipos'] = 'Lista de tareas';
$vista = isset($vistas[$_GET['vista'] ?? '']) ? $_GET['vista'] : 'mias';
$sql = sql_tareas();
$tareas = [];
if ($vista === 'mias') {
    $tareas = pendientes_de($yo);
} elseif ($vista === 'mande') {
    $st = db()->prepare("$sql WHERE t.creado_por = ? AND t.privada = 0 AND (t.para_id IS NULL OR t.para_id <> ?) AND (t.hecha = 0 OR t.hecha_en > NOW() - INTERVAL 7 DAY) ORDER BY t.hecha, t.vence IS NULL, t.vence, t.id");
    $st->execute([$yo, $yo]);
    $tareas = $st->fetchAll();
} elseif ($vista === 'equipo') {
    $tareas = db()->query("$sql WHERE t.privada = 0 AND t.hecha = 0 ORDER BY t.vence IS NULL, t.vence, t.id")->fetchAll();
} elseif ($vista === 'hechas') {
    $st = db()->prepare("$sql WHERE t.hecha = 1 AND t.hecha_en > NOW() - INTERVAL 30 DAY AND (t.privada = 0 OR t.creado_por = ?) ORDER BY t.hecha_en DESC");
    $st->execute([$yo]);
    $tareas = $st->fetchAll();
}

$grupos = [];
foreach ($tareas as $t) {
    if ($vista === 'equipo') $g = $t['para_id'] === null ? 'Del grupo (sin tomar)' : $t['para'];
    elseif ($vista === 'hechas') $g = 'Hechas en los últimos 30 días';
    elseif ($vista === 'mande' && $t['hecha']) $g = 'Cumplidas esta semana';
    elseif (!$t['vence']) $g = 'Sin fecha';
    elseif (tarea_vencida($t)) $g = 'Vencidas';
    elseif (date('Y-m-d', strtotime($t['vence'])) === date('Y-m-d')) $g = 'Hoy';
    elseif (date('Y-m-d', strtotime($t['vence'])) === date('Y-m-d', strtotime('+1 day'))) $g = 'Mañana';
    else $g = 'Próximas';
    $grupos[$g][] = $t;
}
if ($vista !== 'equipo') {
    $prio = ['Vencidas' => 0, 'Hoy' => 1, 'Mañana' => 2, 'Próximas' => 3, 'Sin fecha' => 4];
    uksort($grupos, fn($a, $b) => ($prio[$a] ?? 9) <=> ($prio[$b] ?? 9));
}
$conteo = ['mias' => count($vista === 'mias' ? $tareas : pendientes_de($yo))];
$st = db()->prepare('SELECT COUNT(*) FROM tareas WHERE creado_por = ? AND privada = 0 AND hecha = 0 AND (para_id IS NULL OR para_id <> ?)');
$st->execute([$yo, $yo]);
$conteo['mande'] = (int)$st->fetchColumn();
$conteo['equipo'] = (int)db()->query('SELECT COUNT(*) FROM tareas WHERE privada = 0 AND hecha = 0')->fetchColumn();

$tipos = tipos_tarea();
$colabs = db()->query('SELECT id, nombre FROM colaboradores WHERE activo = 1 ORDER BY nombre')->fetchAll();
$equipos = db()->query("SELECT etiqueta, nombre, marca, modelo FROM items WHERE etiqueta IS NOT NULL AND etiqueta <> '' ORDER BY etiqueta LIMIT 3000")->fetchAll();

$abrir = function ($k) use ($yo) {
    if (empty($_GET[$k])) return null;
    $t = tarea($_GET[$k]);
    return $t && ve_tarea($t, $yo) && trabaja_tarea($t, $yo) ? $t : ($t && ve_tarea($t, $yo) && $k === 'ver' ? $t : null);
};
$editar = $abrir('editar');
$verla = $abrir('ver');

function opciones_para($sel_val, $miembros, $yo, $con_yo = true) {
    $h = ($con_yo ? '<option value="yo"' . ($sel_val === 'yo' ? ' selected' : '') . '>Solo yo (privada)</option>' : '')
        . '<option value="grupo"' . ($sel_val === 'grupo' ? ' selected' : '') . '>Todo el grupo</option><optgroup label="Asignar a">';
    foreach ($miembros as $id => $m) {
        if ($id === $yo) continue;
        $h .= '<option value="' . $id . '"' . ((string)$sel_val === (string)$id ? ' selected' : '') . '>' . e($m['nombre']) . '</option>';
    }
    return $h . '</optgroup>';
}
function opciones_tipo($tipos, $sel) {
    $h = '<option value="">Escoge la tarea…</option>';
    foreach ($tipos as $cat => $lista) {
        $h .= '<optgroup label="' . e($cat) . '">';
        foreach ($lista as $tt) $h .= '<option value="' . $tt['id'] . '"' . ((int)$sel === (int)$tt['id'] ? ' selected' : '') . '>' . e($tt['nombre']) . '</option>';
        $h .= '</optgroup>';
    }
    return $h;
}
function campos_relacion($colabs, $col_sel = null, $item_val = '') {
    $h = '<div class="campo"><label>Persona (opcional)</label><select name="colaborador_id"><option value="">—</option>';
    foreach ($colabs as $c) $h .= '<option value="' . $c['id'] . '"' . ((int)$col_sel === (int)$c['id'] ? ' selected' : '') . '>' . e($c['nombre']) . '</option>';
    $h .= '</select></div><div class="campo"><label>Equipo (opcional)</label><input name="item" list="lista-equipos" value="' . e($item_val) . '" placeholder="Etiqueta, ej. AGR-0004"></div>';
    return $h;
}

$titulo = 'Tareas';
$menu = 'tareas';
require __DIR__ . '/inc/header.php';
?>
<datalist id="lista-equipos"><?php foreach ($equipos as $q): ?><option value="<?= e($q['etiqueta']) ?>"><?= e(trim($q['nombre'] . ' ' . $q['marca'] . ' ' . $q['modelo'])) ?></option><?php endforeach; ?></datalist>

<div class="cabecera">
  <div><h1>Tareas del equipo</h1><small>Grupo de sistemas: <?= e(implode(', ', array_map(fn($m) => explode(' ', $m['nombre'])[0], $miembros))) ?></small></div>
</div>

<?php if ($vista !== 'notas' && $vista !== 'tipos'): ?>
<form method="post" class="tarjeta tarea-nueva" autocomplete="off"><?= csrf_campo() ?><input type="hidden" name="accion" value="crear"><input type="hidden" name="vista" value="<?= e($vista) ?>">
  <div class="tn-fila">
    <select name="tipo_id" class="tn-tipo" required><?= opciones_tipo($tipos, 0) ?></select>
    <select name="para" title="Para quién"><?= opciones_para('yo', $miembros, $yo) ?></select>
    <input type="date" name="fecha" title="Fecha de entrega" value="<?= date('Y-m-d') ?>">
    <input type="time" name="hora" title="Hora de entrega (opcional)">
    <label class="tn-alta" title="Prioridad alta"><input type="checkbox" name="prioridad" value="alta"> Alta</label>
    <button class="btn btn-primario">Agregar</button>
  </div>
  <div class="tn-fila" style="margin-top:8px">
    <input name="detalle" class="tn-obs" maxlength="4000" placeholder="Observación (opcional): qué pasa, a quién, dónde…">
    <select name="colaborador_id" title="Persona relacionada (opcional)"><option value="">Persona (opcional)</option><?php foreach ($colabs as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select>
    <input name="item" list="lista-equipos" placeholder="Equipo (opcional)" title="Equipo relacionado (opcional)" style="flex:0 1 170px">
  </div>
</form>
<?php endif; ?>

<div class="pestanas-herr" style="margin:16px 0 12px">
  <?php foreach ($vistas as $k => $n): ?><a href="?vista=<?= $k ?>" class="<?= $vista === $k ? 'activa' : '' ?>"><?= $n ?><?= !empty($conteo[$k]) ? '<small>' . $conteo[$k] . '</small>' : '' ?></a><?php endforeach; ?>
</div>

<?php if ($vista === 'notas'):
  $st = db()->prepare('SELECT * FROM notas_personales WHERE usuario_id = ? ORDER BY fijada DESC, actualizado DESC, id DESC');
  $st->execute([$yo]);
  $notas = $st->fetchAll(); ?>
  <p class="tenue" style="margin-top:-4px">🔒 Tu bloc de notas. Solo lo ves tú: nadie más, ni los administradores, lo ve desde la plataforma. Se guarda solo mientras escribes.</p>
  <div class="stickies">
    <form method="post" class="sticky sticky-nueva"><?= csrf_campo() ?><input type="hidden" name="accion" value="np_nueva"><input type="hidden" name="vista" value="notas">
      <button>+ Nota nueva</button></form>
    <?php foreach ($notas as $n): ?>
    <div class="sticky sticky-<?= e($n['color']) ?><?= $n['fijada'] ? ' fijada' : '' ?>" id="n<?= $n['id'] ?>" data-nota="<?= $n['id'] ?>"<?= !empty($n['ancho']) ? ' style="width:' . (int)$n['ancho'] . 'px;height:' . (int)$n['alto'] . 'px"' : '' ?>>
      <form method="post" class="sticky-texto" data-autoguardar><?= csrf_campo() ?><input type="hidden" name="accion" value="np_guardar"><input type="hidden" name="id" value="<?= $n['id'] ?>"><input type="hidden" name="vista" value="notas">
        <textarea name="texto" placeholder="Escribe aquí…" maxlength="20000"><?= e($n['texto']) ?></textarea>
        <noscript><button class="btn btn-sm">Guardar</button></noscript></form>
      <div class="sticky-pie">
        <span class="sticky-estado"><?= $n['actualizado'] ? hace_texto($n['actualizado']) : '' ?></span>
        <?php foreach (['amarillo', 'verde', 'azul', 'rosa', 'gris'] as $c): ?>
          <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="np_color"><input type="hidden" name="id" value="<?= $n['id'] ?>"><input type="hidden" name="vista" value="notas"><input type="hidden" name="color" value="<?= $c ?>"><button class="punto punto-<?= $c ?>" title="<?= ucfirst($c) ?>"></button></form>
        <?php endforeach; ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="np_fijar"><input type="hidden" name="id" value="<?= $n['id'] ?>"><input type="hidden" name="vista" value="notas"><button class="sticky-btn" title="<?= $n['fijada'] ? 'Desfijar' : 'Fijar arriba' ?>"><?= $n['fijada'] ? '📌' : '📍' ?></button></form>
        <form method="post" onsubmit="return confirm('¿Borrar esta nota?')"><?= csrf_campo() ?><input type="hidden" name="accion" value="np_borrar"><input type="hidden" name="id" value="<?= $n['id'] ?>"><input type="hidden" name="vista" value="notas"><button class="sticky-btn" title="Borrar">🗑</button></form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

<?php elseif ($vista === 'tipos'): $todos = tipos_tarea(false); ?>
  <p class="tenue" style="margin-top:-4px">La lista que aparece al crear una tarea. Solo la pueden cambiar quienes administran usuarios. Si desactivas una, deja de salir en la lista, pero las tareas que ya la usan no cambian.</p>
  <form method="post" class="tarjeta" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><?= csrf_campo() ?><input type="hidden" name="accion" value="tipo_guardar"><input type="hidden" name="vista" value="tipos">
    <input name="categoria" list="lista-cats" placeholder="Categoría (escoge o escribe una nueva)" required style="flex:1 1 220px">
    <datalist id="lista-cats"><?php foreach (array_keys($todos) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
    <input name="nombre" placeholder="Nombre de la tarea" required maxlength="150" style="flex:2 1 280px">
    <button class="btn btn-primario">+ Agregar a la lista</button>
  </form>
  <?php foreach ($todos as $cat => $lista): ?>
  <div class="tareas-grupo"><h2><?= e($cat) ?> <span class="tenue">· <?= count($lista) ?></span></h2>
    <div class="tarjeta tareas-lista">
    <?php foreach ($lista as $tt): ?>
      <form method="post" class="tarea tipo-fila<?= $tt['activo'] ? '' : ' inactivo' ?>"><?= csrf_campo() ?><input type="hidden" name="accion" value="tipo_guardar"><input type="hidden" name="vista" value="tipos"><input type="hidden" name="id" value="<?= $tt['id'] ?>">
        <input name="nombre" value="<?= e($tt['nombre']) ?>" required maxlength="150" style="flex:2">
        <input name="categoria" value="<?= e($tt['categoria']) ?>" list="lista-cats" required style="flex:1">
        <label class="tn-alta"><input type="checkbox" name="activo" value="1"<?= $tt['activo'] ? ' checked' : '' ?>> Activa</label>
        <button class="btn btn-borde btn-sm">Guardar</button>
      </form>
    <?php endforeach; ?>
    </div></div>
  <?php endforeach; ?>

<?php else: ?>
  <?php if (!$tareas): ?><div class="tarjeta vacio" style="text-align:center;padding:30px"><?= $vista === 'mias' ? 'No tienes nada pendiente. 🎉' : 'No hay tareas aquí.' ?></div><?php endif; ?>
  <?php foreach ($grupos as $g => $lista): ?>
  <div class="tareas-grupo">
    <h2 class="<?= $g === 'Vencidas' ? 'rojo' : '' ?>"><?= e($g) ?> <span class="tenue">· <?= count($lista) ?></span></h2>
    <div class="tarjeta tareas-lista">
    <?php foreach ($lista as $t):
      $tipo = tipo_tarea($t);
      $venc = tarea_vencida($t);
      $trabaja = trabaja_tarea($t, $yo); ?>
      <div class="tarea<?= $t['hecha'] ? ' hecha' : '' ?><?= $venc ? ' vencida' : '' ?>" id="t<?= $t['id'] ?>">
        <form method="post" class="tarea-check"><?= csrf_campo() ?><input type="hidden" name="accion" value="hecha"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="vista" value="<?= e($vista) ?>">
          <input type="hidden" name="valor" value="<?= $t['hecha'] ? 0 : 1 ?>">
          <button class="check" <?= $trabaja ? '' : 'disabled' ?> title="<?= $t['hecha'] ? 'Reabrir' : 'Marcar como hecha' ?>" aria-label="<?= $t['hecha'] ? 'Reabrir' : 'Marcar como hecha' ?>"></button>
        </form>
        <a class="tarea-cuerpo" href="?vista=<?= $vista ?>&amp;ver=<?= $t['id'] ?>">
          <div class="tarea-titulo"><?= e($t['titulo']) ?><?= $t['prioridad'] === 'alta' ? ' <span class="chip chip-danado">Alta</span>' : '' ?></div>
          <?php if ($t['detalle']): ?><div class="tarea-detalle"><?= nl2br(e(mb_strimwidth($t['detalle'], 0, 220, '…'))) ?></div><?php endif; ?>
          <div class="tarea-meta">
            <?php if ($t['categoria']): ?><span class="tm"><?= e($t['categoria']) ?></span><?php endif; ?>
            <?php if ($tipo === 'propia'): ?><span class="tm">Solo yo</span>
            <?php elseif ($tipo === 'grupal'): ?><span class="tm tm-grupo">Del grupo · sin tomar</span>
            <?php elseif ((int)$t['creado_por'] === $yo && (int)$t['para_id'] !== $yo): ?><span class="tm tm-para">Para <?= e($t['para']) ?></span>
            <?php elseif ((int)$t['para_id'] === $yo && (int)$t['creado_por'] !== $yo): ?><span class="tm tm-de">De <?= e($t['creador']) ?></span>
            <?php elseif ((int)$t['para_id'] === $yo): ?><span class="tm tm-de">A mi cargo</span>
            <?php else: ?><span class="tm"><?= e(explode(' ', $t['creador'])[0]) ?> → <?= e($t['para']) ?></span><?php endif; ?>
            <?php if ($t['colaborador']): ?><span class="tm">👤 <?= e($t['colaborador']) ?></span><?php endif; ?>
            <?php if ($t['item_etiqueta']): ?><span class="tm mono">🖥 <?= e($t['item_etiqueta']) ?></span><?php endif; ?>
            <?php if ($t['vence']): ?><span class="tm <?= $venc ? 'tm-vencida' : '' ?>">🕒 <?= e(vence_texto($t)) ?><?= $venc ? ' · vencida' : '' ?></span><?php endif; ?>
            <?php if ($t['notas']): ?><span class="tm">💬 <?= $t['notas'] ?> nota<?= $t['notas'] > 1 ? 's' : '' ?></span><?php endif; ?>
            <?php if ($t['hecha']): ?><span class="tm tm-hecha">✔ <?= e(explode(' ', (string)$t['hecha_nombre'])[0]) ?> · <?= hace_texto($t['hecha_en']) ?></span><?php endif; ?>
          </div>
        </a>
        <div class="tarea-acc">
          <?php if (!$t['hecha'] && $tipo === 'grupal'): ?>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="tomar"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="vista" value="<?= e($vista) ?>"><button class="btn btn-borde btn-sm" title="Queda a tu cargo">🙋 La tomo</button></form>
          <?php endif; ?>
          <?php if (!$t['hecha'] && $tipo === 'asignada' && (int)$t['creado_por'] === $yo && (int)$t['para_id'] !== $yo): ?>
            <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="recordar"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="vista" value="<?= e($vista) ?>"><button class="btn btn-borde btn-sm" title="Mandarle un correo recordándole">🔔 Recordar</button></form>
          <?php endif; ?>
          <a class="btn btn-borde btn-sm" href="?vista=<?= $vista ?>&amp;ver=<?= $t['id'] ?>">Abrir</a>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <p class="tenue" style="font-size:12px;margin-top:22px">Llegan correos cuando te ponen o te transfieren una tarea, cuando alguien toma una del grupo, cuando se cumple, cuando cambian la fecha de entrega y cuando se vence sin cumplirse. Además, un resumen cada mañana. Las de “Solo yo” solo mandan el resumen y el aviso de vencida.</p>
<?php endif; ?>

<?php if ($verla): $trabaja = trabaja_tarea($verla, $yo); $notas_c = notas_tarea($verla['id']); $venc = tarea_vencida($verla); ?>
<div class="modal-fondo" data-cerrar="tareas.php?vista=<?= e($vista) ?>#t<?= $verla['id'] ?>">
<div class="tarjeta modal-caja caso" role="dialog" aria-modal="true">
  <a class="modal-x" href="tareas.php?vista=<?= e($vista) ?>#t<?= $verla['id'] ?>" aria-label="Cerrar">×</a>
  <div class="tenue mono" style="font-size:12px"><?= e($verla['categoria'] ?? 'Tarea') ?> · #<?= $verla['id'] ?></div>
  <h2 style="text-transform:none;font-size:18px;margin:2px 0 6px;<?= $verla['hecha'] ? 'text-decoration:line-through;color:var(--tenue)' : '' ?>"><?= e($verla['titulo']) ?><?= $verla['prioridad'] === 'alta' ? ' <span class="chip chip-danado">Alta</span>' : '' ?></h2>
  <div class="tarea-meta" style="margin-bottom:10px">
    <span class="tm">De <?= e($verla['creador']) ?></span>
    <span class="tm <?= $verla['para_id'] === null ? 'tm-grupo' : 'tm-para' ?>"><?= $verla['privada'] ? 'Solo para ' . e($verla['creador']) : ($verla['para_id'] === null ? 'Del grupo · sin tomar' : 'A cargo de ' . e($verla['para'])) ?></span>
    <?php if ($verla['vence']): ?><span class="tm <?= $venc ? 'tm-vencida' : '' ?>">🕒 <?= e(vence_texto($verla)) ?> · <?= fecha_corta($verla['vence']) ?><?= $venc ? ' · vencida' : '' ?></span><?php endif; ?>
    <?php if ($verla['colaborador']): ?><span class="tm">👤 <?= e($verla['colaborador']) ?></span><?php endif; ?>
    <?php if ($verla['item_etiqueta']): ?><a class="tm mono" href="item.php?id=<?= (int)$verla['item_id'] ?>">🖥 <?= e($verla['item_etiqueta'] . ' · ' . $verla['item_nombre']) ?></a><?php endif; ?>
    <?php if ($verla['hecha']): ?><span class="tm tm-hecha">✔ Hecha por <?= e($verla['hecha_nombre']) ?> · <?= fecha_hora($verla['hecha_en']) ?></span><?php endif; ?>
  </div>
  <?php if ($verla['detalle']): ?><div class="caso-obs"><b>Observación:</b> <?= nl2br(e($verla['detalle'])) ?></div><?php endif; ?>

  <h3 class="caso-sub">Notas del caso</h3>
  <div class="caso-notas">
    <?php foreach ($notas_c as $n): ?>
      <div class="caso-nota caso-<?= e($n['tipo']) ?>">
        <div class="caso-quien"><b><?= e($n['nombre'] ?? 'Sistema') ?></b> <span class="tenue"><?= fecha_hora($n['creado']) ?></span></div>
        <div><?= nl2br(e($n['texto'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($trabaja): ?>
  <form method="post" class="caso-form"><?= csrf_campo() ?><input type="hidden" name="accion" value="nota"><input type="hidden" name="id" value="<?= $verla['id'] ?>"><input type="hidden" name="vista" value="<?= e($vista) ?>"><input type="hidden" name="desde" value="ver">
    <textarea name="texto" rows="2" required placeholder="¿Qué hiciste? ¿Hasta dónde llegaste? ¿Qué falta? Así quien la tome sabe por dónde seguir…"></textarea>
    <button class="btn btn-primario btn-sm">Agregar nota</button>
  </form>

  <?php if (!$verla['hecha'] && !$verla['privada']): ?>
  <details class="caso-transferir"<?= !empty($_GET['transferir']) ? ' open' : '' ?>>
    <summary>🔁 Transferir a otro compañero</summary>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="transferir"><input type="hidden" name="id" value="<?= $verla['id'] ?>"><input type="hidden" name="vista" value="<?= e($vista) ?>"><input type="hidden" name="desde" value="ver">
      <div class="rejilla">
        <div class="campo"><label>Pasársela a</label><select name="a" required><option value="">Escoge…</option><?= opciones_para('', $miembros, $verla['para_id'] === null ? 0 : (int)$verla['para_id'], false) ?></select></div>
        <div class="campo" style="grid-column:1/-1"><label>Motivo y hasta dónde llegaste</label><textarea name="motivo" rows="2" required placeholder="Ej.: me salió una urgencia en bodega; ya formateé el PC, falta instalar Traverse y la impresora"></textarea></div>
      </div>
      <button class="btn btn-primario btn-sm">Transferir y avisar por correo</button>
    </form>
  </details>
  <?php endif; ?>
  <?php endif; ?>

  <div style="margin:16px 0 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?php if ($trabaja): ?>
      <form method="post" style="margin:0"><?= csrf_campo() ?><input type="hidden" name="accion" value="hecha"><input type="hidden" name="id" value="<?= $verla['id'] ?>"><input type="hidden" name="vista" value="<?= e($vista) ?>"><input type="hidden" name="desde" value="ver"><input type="hidden" name="valor" value="<?= $verla['hecha'] ? 0 : 1 ?>">
        <button class="btn <?= $verla['hecha'] ? 'btn-borde' : 'btn-primario' ?>"><?= $verla['hecha'] ? '↩ Reabrir' : '✔ Marcar como hecha' ?></button></form>
      <?php if (!$verla['hecha'] && $verla['para_id'] === null && !$verla['privada']): ?>
        <form method="post" style="margin:0"><?= csrf_campo() ?><input type="hidden" name="accion" value="tomar"><input type="hidden" name="id" value="<?= $verla['id'] ?>"><input type="hidden" name="vista" value="<?= e($vista) ?>"><input type="hidden" name="desde" value="ver"><button class="btn btn-borde">🙋 La tomo yo</button></form>
      <?php endif; ?>
      <a class="btn btn-borde" href="?vista=<?= e($vista) ?>&amp;editar=<?= $verla['id'] ?>"><?= (int)$verla['creado_por'] === $yo ? 'Editar' : 'Cambiar fecha' ?></a>
    <?php endif; ?>
  </div>
</div>
</div>
<?php endif; ?>

<?php if ($editar): $es_mia = (int)$editar['creado_por'] === $yo; $pv = $editar['privada'] ? 'yo' : ($editar['para_id'] === null ? 'grupo' : (string)$editar['para_id']); ?>
<div class="modal-fondo" data-cerrar="tareas.php?vista=<?= e($vista) ?>&amp;ver=<?= $editar['id'] ?>">
<form method="post" class="tarjeta modal-caja" role="dialog" aria-modal="true"><?= csrf_campo() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= $editar['id'] ?>"><input type="hidden" name="vista" value="<?= e($vista) ?>"><input type="hidden" name="desde" value="ver">
  <a class="modal-x" href="tareas.php?vista=<?= e($vista) ?>&amp;ver=<?= $editar['id'] ?>" aria-label="Cerrar">×</a>
  <h2><?= $es_mia ? 'Editar tarea' : 'Cambiar fecha de entrega' ?></h2>
  <?php if (!$es_mia): ?><p style="margin-top:-6px"><b><?= e($editar['titulo']) ?></b><br><span class="tenue">La puso <?= e($editar['creador']) ?>. Si cambias la fecha, le llega un correo.</span></p><?php endif; ?>
  <div class="rejilla">
    <?php if ($es_mia): ?>
    <div class="campo" style="grid-column:1/-1"><label>Tarea</label><select name="tipo_id" required><?= opciones_tipo($tipos, $editar['tipo_id']) ?></select></div>
    <div class="campo" style="grid-column:1/-1"><label>Observación</label><textarea name="detalle" rows="3"><?= e($editar['detalle']) ?></textarea></div>
    <?= campos_relacion($colabs, $editar['colaborador_id'], $editar['item_etiqueta'] ?? '') ?>
    <div class="campo"><label>Para</label><select name="para"><?= opciones_para($pv, $miembros, $yo) ?></select></div>
    <div class="campo"><label>Prioridad</label><select name="prioridad"><option value="normal">Normal</option><option value="alta"<?= $editar['prioridad'] === 'alta' ? ' selected' : '' ?>>Alta</option></select></div>
    <?php endif; ?>
    <div class="campo"><label>Fecha de entrega</label><input type="date" name="fecha" value="<?= $editar['vence'] ? date('Y-m-d', strtotime($editar['vence'])) : '' ?>"></div>
    <div class="campo"><label>Hora (opcional)</label><input type="time" name="hora" value="<?= $editar['vence'] && $editar['con_hora'] ? date('H:i', strtotime($editar['vence'])) : '' ?>"></div>
  </div>
  <p style="margin-bottom:0;display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primario">Guardar</button> <a class="btn btn-borde" href="tareas.php?vista=<?= e($vista) ?>&amp;ver=<?= $editar['id'] ?>">Cancelar</a>
  <?php if ($es_mia): ?><button class="btn btn-borde" style="margin-left:auto;color:var(--rojo)" name="accion" value="borrar" formnovalidate onclick="return confirm('¿Borrar esta tarea y sus notas?')">Borrar</button><?php endif; ?></p>
</form>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
