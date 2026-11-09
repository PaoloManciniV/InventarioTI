<?php
// carga inicial desde el excel de inventario
// acepta xlsx o csv y muestra vista previa antes de guardar
require __DIR__ . '/config.php';
requiere_admin();
requiere_modulo('importar');

function normalizar($s) {
    $s = mb_strtolower(trim((string)$s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    return preg_replace('/[^a-z0-9]+/', '_', $s);
}

// lector minimo de xlsx sin librerias toma la primera hoja
function leer_xlsx($ruta) {
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) return null;
    $comp = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml);
        foreach ($sx->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            else foreach ($si->r as $r) $t .= (string)$r->t;
            $comp[] = $t;
        }
    }
    $hoja = 'xl/worksheets/sheet1.xml';
    if (($wb = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
        $w = simplexml_load_string($wb);
        $w->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $primera = $w->xpath('//m:sheets/m:sheet')[0] ?? null;
        if ($primera) {
            $rid = (string)$primera->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach (simplexml_load_string($rels)->Relationship as $r) {
                if ((string)$r['Id'] === $rid) $hoja = 'xl/' . ltrim(str_replace('/xl/', '', (string)$r['Target']), '/');
            }
        }
    }
    $xml = $zip->getFromName($hoja);
    $zip->close();
    if ($xml === false) return null;
    $filas = [];
    foreach (simplexml_load_string($xml)->sheetData->row as $row) {
        $f = [];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $col = 0;
            foreach (str_split($m[1]) as $ch) $col = $col * 26 + ord($ch) - 64;
            $t = (string)$c['t'];
            if ($t === 's') $v = $comp[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') $v = (string)$c->is->t;
            else $v = (string)$c->v;
            $f[$col - 1] = $v;
        }
        if ($f) {
            $max = max(array_keys($f));
            $fila = [];
            for ($i = 0; $i <= $max; $i++) $fila[] = $f[$i] ?? '';
            $filas[] = $fila;
        }
    }
    return $filas;
}

function leer_csv($ruta) {
    $txt = file_get_contents($ruta);
    if (substr($txt, 0, 3) === "\xEF\xBB\xBF") $txt = substr($txt, 3);
    if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    $primera = strtok($txt, "\n");
    $sep = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';
    $filas = [];
    $h = fopen('php://memory', 'r+');
    fwrite($h, $txt);
    rewind($h);
    while (($f = fgetcsv($h, 0, $sep)) !== false) $filas[] = $f;
    return $filas;
}

// excel guarda fechas como numero de dias desde 1900
function fecha_excel($v) {
    $v = trim($v);
    if ($v === '') return null;
    if (is_numeric($v) && $v > 20000 && $v < 80000) return date('Y-m-d', ((int)$v - 25569) * 86400);
    foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d) return $d->format('Y-m-d');
    }
    return null;
}

$alias = [
    'etiqueta' => ['etiqueta', 'codigo', 'placa', 'activo', 'id', 'cod'],
    'nombre' => ['nombre', 'descripcion', 'item', 'equipo', 'articulo', 'elemento'],
    'categoria' => ['categoria', 'tipo', 'clase'],
    'marca' => ['marca'],
    'modelo' => ['modelo', 'referencia', 'ref'],
    'serial' => ['serial', 'serie', 'no_serie', 'numero_de_serie', 's_n', 'sn', 'imei'],
    'sede' => ['sede', 'ubicacion', 'sucursal'],
    'estado' => ['estado'],
    'responsable' => ['responsable', 'asignado', 'asignado_a', 'usuario', 'colaborador', 'empleado', 'persona'],
    'cedula' => ['cedula', 'cc', 'documento', 'cedula_responsable'],
    'cargo' => ['cargo'],
    'area' => ['area', 'departamento', 'dependencia'],
    'cantidad' => ['cantidad', 'cant'],
    'especificaciones' => ['especificaciones', 'caracteristicas', 'specs', 'procesador', 'detalle'],
    'valor' => ['valor', 'costo', 'precio'],
    'fecha_compra' => ['fecha_compra', 'fecha_de_compra', 'fecha', 'compra'],
    'notas' => ['notas', 'observaciones', 'observacion', 'comentarios'],
];

$vista = $_SESSION['importar'] ?? null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'subir' && !empty($_FILES['archivo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
        $filas = $ext === 'xlsx' ? leer_xlsx($_FILES['archivo']['tmp_name']) : leer_csv($_FILES['archivo']['tmp_name']);
        if (!$filas || count($filas) < 2) {
            $error = 'No pude leer filas del archivo. Si es .xls viejo guardalo como .xlsx o .csv';
        } else {
            // busca la fila de encabezados en las primeras 10
            $fila_enc = 0;
            $mejor = 0;
            foreach (array_slice($filas, 0, 10) as $n => $f) {
                $hits = 0;
                foreach ($f as $v) foreach ($alias as $al) if (in_array(normalizar($v), $al, true)) $hits++;
                if ($hits > $mejor) { $mejor = $hits; $fila_enc = $n; }
            }
            $mapa = [];
            foreach ($filas[$fila_enc] as $i => $v) {
                $n = normalizar($v);
                foreach ($alias as $campo => $al) {
                    if (!isset($mapa[$campo]) && in_array($n, $al, true)) { $mapa[$campo] = $i; break; }
                }
            }
            if (!isset($mapa['nombre'])) {
                $error = 'No encontre una columna de nombre o descripcion. Encabezados leidos: ' . implode(', ', $filas[$fila_enc]);
            } else {
                $datos = [];
                foreach (array_slice($filas, $fila_enc + 1) as $f) {
                    $r = [];
                    foreach ($mapa as $campo => $i) $r[$campo] = trim((string)($f[$i] ?? ''));
                    if (($r['nombre'] ?? '') === '') continue;
                    $datos[] = $r;
                }
                $vista = $_SESSION['importar'] = ['mapa' => array_keys($mapa), 'filas' => $datos, 'archivo' => $_FILES['archivo']['name']];
            }
        }
    }

    if ($accion === 'cancelar') { unset($_SESSION['importar']); ir('importar.php'); }

    if ($accion === 'confirmar' && $vista) {
        $pdo = db();
        $pdo->beginTransaction();
        $cache_cat = array_column(categorias(false), 'id', 'nombre');
        $cache_sede = array_column(sedes(false), 'id', 'nombre');
        $cache_area = array_column(areas(false), 'id', 'nombre');
        $creados = $actualizados = $personas = 0;
        $buscar = function ($cache, $nombre) {
            foreach ($cache as $k => $v) if (normalizar($k) === normalizar($nombre)) return $v;
            return null;
        };
        foreach ($vista['filas'] as $r) {
            $cat_id = $sede_id = $colab_id = null;
            if (!empty($r['categoria'])) {
                $cat_id = $buscar($cache_cat, $r['categoria']);
                if (!$cat_id) {
                    $pref = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', normalizar($r['categoria'])), 0, 3)) ?: 'OTR';
                    $pdo->prepare('INSERT INTO categorias (nombre, prefijo) VALUES (?, ?)')->execute([$r['categoria'], $pref]);
                    $cat_id = $cache_cat[$r['categoria']] = (int)$pdo->lastInsertId();
                }
            }
            if (!empty($r['sede'])) {
                $sede_id = $buscar($cache_sede, $r['sede']);
                if (!$sede_id) {
                    $pdo->prepare('INSERT INTO sedes (nombre) VALUES (?)')->execute([$r['sede']]);
                    $sede_id = $cache_sede[$r['sede']] = (int)$pdo->lastInsertId();
                }
            }
            if (!empty($r['cedula'])) {
                $st = $pdo->prepare('SELECT id FROM colaboradores WHERE cedula = ?');
                $st->execute([$r['cedula']]);
                $colab_id = $st->fetchColumn() ?: null;
            }
            if (!$colab_id && !empty($r['responsable'])) {
                $st = $pdo->prepare('SELECT id FROM colaboradores WHERE LOWER(nombre) = LOWER(?)');
                $st->execute([$r['responsable']]);
                $colab_id = $st->fetchColumn() ?: null;
            }
            if (!$colab_id && !empty($r['responsable'])) {
                // si el area del excel coincide con una de la lista queda enlazada
                $area_id = null;
                $area_txt = ($r['area'] ?? '') ?: null;
                if ($area_txt) {
                    foreach ($cache_area as $an => $aid) if (normalizar($an) === normalizar($area_txt)) { $area_id = $aid; $area_txt = $an; }
                }
                $pdo->prepare('INSERT INTO colaboradores (nombre, cedula, cargo, area, area_id, sede_id) VALUES (?,?,?,?,?,?)')
                    ->execute([$r['responsable'], ($r['cedula'] ?? '') ?: null, $r['cargo'] ?? null ?: null, $area_txt, $area_id, $sede_id]);
                $colab_id = (int)$pdo->lastInsertId();
                $personas++;
            }

            $estado = normalizar($r['estado'] ?? '');
            if (strpos($estado, 'baja') !== false || strpos($estado, 'dano') !== false) $estado = 'baja';
            elseif (strpos($estado, 'manten') !== false || strpos($estado, 'repar') !== false) $estado = 'mantenimiento';
            elseif ($colab_id) $estado = 'asignado';
            else $estado = 'disponible';

            $valor = isset($r['valor']) && $r['valor'] !== '' ? preg_replace('/[^0-9]/', '', explode(',', $r['valor'])[0]) : null;
            $d = [
                'nombre' => $r['nombre'], 'categoria_id' => $cat_id, 'marca' => $r['marca'] ?? null ?: null,
                'modelo' => $r['modelo'] ?? null ?: null, 'serial' => $r['serial'] ?? null ?: null, 'sede_id' => $sede_id,
                'estado' => $estado, 'colaborador_id' => $estado === 'baja' ? null : $colab_id,
                'cantidad' => max(1, (int)($r['cantidad'] ?? 1)), 'especificaciones' => $r['especificaciones'] ?? null ?: null,
                'valor' => $valor ?: null, 'fecha_compra' => fecha_excel($r['fecha_compra'] ?? ''), 'notas' => $r['notas'] ?? null ?: null,
            ];
            $etq = strtoupper($r['etiqueta'] ?? '');
            $existe = null;
            if ($etq !== '') {
                $st = $pdo->prepare('SELECT id FROM items WHERE etiqueta = ?');
                $st->execute([$etq]);
                $existe = $st->fetchColumn();
            }
            if ($existe) {
                // solo pisa lo que venga lleno
                $d = array_filter($d, fn($v) => $v !== null);
                $pdo->prepare('UPDATE items SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($d))) . ', actualizado = NOW() WHERE id = ?')
                    ->execute(array_merge(array_values($d), [$existe]));
                registrar_movimiento($existe, 'importacion', 'Actualizado desde ' . $vista['archivo'], $colab_id);
                $actualizados++;
            } else {
                if ($etq === '') $etq = siguiente_etiqueta($cat_id);
                $cols = array_keys($d);
                $pdo->prepare('INSERT INTO items (etiqueta, ' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')
                    ->execute(array_merge([$etq], array_values($d)));
                registrar_movimiento((int)$pdo->lastInsertId(), 'importacion', 'Importado desde ' . $vista['archivo'], $colab_id);
                $creados++;
            }
        }
        $pdo->commit();
        unset($_SESSION['importar']);
        flash("Importacion lista: $creados nuevos, $actualizados actualizados, $personas colaboradores creados");
        ir('items.php');
    }
}

$titulo = 'Importar Excel';
$menu = 'importar';
require __DIR__ . '/inc/header.php';
?>
<div class="cabecera"><div><h1>Importar desde Excel</h1><small>Para pasar el inventario actual de una sola vez</small></div></div>
<?php if ($error): ?><div class="aviso aviso-error"><?= e($error) ?></div><?php endif; ?>

<?php if (!$vista): ?>
<form method="post" enctype="multipart/form-data" class="tarjeta">
  <?= csrf_campo() ?><input type="hidden" name="accion" value="subir">
  <p>Sube el archivo <b>.xlsx</b> o <b>.csv</b>. Se lee la primera hoja y se buscan solas estas columnas (no importa el orden ni las tildes):</p>
  <p class="tenue">etiqueta / codigo · nombre / descripcion <b>(obligatoria)</b> · categoria / tipo · marca · modelo · serial / imei · bodega / sede · estado · responsable · cedula · cargo · area · cantidad · especificaciones · valor · fecha compra · notas</p>
  <p class="tenue">Si la etiqueta ya existe se actualiza ese item. Si viene vacia se genera con el prefijo de la categoria. Las categorias, bodegas y responsables que no existan se crean.</p>
  <div class="filtros"><input type="file" name="archivo" accept=".xlsx,.csv" required><button class="btn btn-primario">Ver vista previa</button></div>
</form>
<?php else: ?>
<form method="post" class="tarjeta">
  <?= csrf_campo() ?>
  <div class="cabecera" style="margin:0">
    <div><h2 style="margin:0"><?= count($vista['filas']) ?> filas en <?= e($vista['archivo']) ?></h2>
      <small>Columnas detectadas: <?= e(implode(', ', $vista['mapa'])) ?></small></div>
    <div class="acciones"><button class="btn btn-borde" name="accion" value="cancelar">Cancelar</button>
      <button class="btn btn-primario" name="accion" value="confirmar">Importar todo</button></div>
  </div>
</form>
<div class="tabla-envoltura"><table class="tabla"><thead><tr>
  <?php foreach ($vista['mapa'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach (array_slice($vista['filas'], 0, 200) as $r): ?><tr>
    <?php foreach ($vista['mapa'] as $c): ?><td><?= e($r[$c] ?? '') ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
</tbody></table></div>
<?php if (count($vista['filas']) > 200): ?><p class="tenue">Mostrando las primeras 200</p><?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php';
