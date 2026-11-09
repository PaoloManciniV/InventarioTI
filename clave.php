<?php
// devuelve una clave descifrada solo a administradores y deja registro
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!usuario() || !es_admin() || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); exit('{}'); }
verificar_csrf();

$tipo = $_POST['tipo'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$tabla = ['wifi' => 'wifi_redes', 'item' => 'items'][$tipo] ?? null;
if (!$tabla || !$id) { http_response_code(400); exit('{}'); }

$st = db()->prepare("SELECT clave_cifrada FROM $tabla WHERE id = ?");
$st->execute([$id]);
$clave = descifrar($st->fetchColumn());
registrar_vista_clave($tipo, $id);
echo json_encode(['clave' => $clave], JSON_UNESCAPED_UNICODE);
