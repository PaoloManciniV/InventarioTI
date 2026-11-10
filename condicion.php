<?php
// poner, editar o quitar la condicion visible de un item (la nota que sale debajo del estado) sin entrar a la ficha
require __DIR__ . '/config.php';
requiere_modulo('items');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !es_admin()) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Sin permiso'])); }
verificar_csrf();
$id = (int)($_POST['id'] ?? 0);
$nueva = trim(preg_replace('/\s+/', ' ', (string)($_POST['condicion'] ?? '')));
$nueva = mb_substr($nueva, 0, 120);
$st = db()->prepare('SELECT id, etiqueta, condicion FROM items WHERE id = ?');
$st->execute([$id]);
$it = $st->fetch();
if (!$it) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'No existe'])); }
$antes = trim((string)$it['condicion']);
if ($antes !== $nueva) {
    db()->prepare('UPDATE items SET condicion = ?, actualizado = NOW() WHERE id = ?')->execute([$nueva === '' ? null : $nueva, $id]);
    auditar('items', $id, ['condicion' => $antes ?: null], ['condicion' => $nueva ?: null], 'Desde la lista');
    registrar_movimiento($id, 'nota', $nueva === '' ? 'Se quitó la condición “' . $antes . '”' : ($antes === '' ? 'Condición: ' . $nueva : 'Condición: ' . $nueva . ' (antes: ' . $antes . ')'));
}
echo json_encode(['ok' => true, 'html' => chip_condicion($nueva, $id)], JSON_UNESCAPED_UNICODE);
