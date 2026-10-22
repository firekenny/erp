<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/invoice.php';
require_login();
if (!inv_can_view()) { http_response_code(403); exit('Not allowed'); }
$inv = q('SELECT file_name, file_orig FROM invoices WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
$path = $inv && $inv['file_name'] !== '' && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png)$/', $inv['file_name']) ? inv_upload_dir() . '/' . $inv['file_name'] : '';
if ($path === '' || !is_file($path)) { http_response_code(404); exit('File not found'); }
$ext = pathinfo($path, PATHINFO_EXTENSION);
header('Content-Type: ' . ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $inv['file_orig'] ?: 'invoice.' . $ext) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
