<?php
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/batch.class.php';

Session::checkRight(PluginAssignatureProfile::RIGHT_VIEW, READ);
$batch = PluginAssignatureBatch::get((int) ($_GET['id'] ?? 0));
$userId = (int) Session::getLoginUserID();
if ($batch === null || (string) $batch['status'] !== 'done' || (string) $batch['method'] !== 'paper'
    || !PluginAssignatureBatch::canManage($batch, $userId)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}
$path = PluginAssignatureBatch::filePath($batch);
if (!is_file($path) || !hash_equals((string) $batch['file_sha256'], (string) hash_file('sha256', $path))) {
    http_response_code(404);
    exit('Arquivo não encontrado ou alterado.');
}
$types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'];
$extension = (string) $batch['file_extension'];
header('Content-Type: ' . $types[$extension]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="termo-assinado-' . (int) $batch['id'] . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
