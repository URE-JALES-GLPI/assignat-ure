<?php
/** Termo assinado de um pedido, aberto a partir do plugin de origem. */
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/batch.class.php';

Session::checkRight(PluginAssignatureProfile::RIGHT_VIEW, READ);

$cardId = (int) ($_GET['card'] ?? 0);
$source = PluginAssignatureSource::bySlug('tralla');
$request = $cardId > 0 && $source !== null
    ? PluginAssignatureRequest::byExternal((int) $source['id'], (string) $cardId)
    : null;
$userId = (int) Session::getLoginUserID();
$batch = $request !== null && (int) ($request['batches_id'] ?? 0) > 0
    ? PluginAssignatureBatch::get((int) $request['batches_id'])
    : null;

if ($request === null || (string) $request['status'] !== 'done' || $batch === null
    || (string) $batch['status'] !== 'done'
    || !PluginAssignatureScope::canSee($userId, (int) $source['id'], 'done')
    || !PluginAssignatureBatch::canManage($batch, $userId)) {
    http_response_code(404);
    exit('Termo assinado não encontrado ou sem acesso.');
}

if ((string) $batch['method'] === 'paper') {
    $path = PluginAssignatureBatch::filePath($batch);
    if (!is_file($path) || !hash_equals((string) $batch['file_sha256'], (string) hash_file('sha256', $path))) {
        http_response_code(404);
        exit('Arquivo assinado não encontrado ou alterado.');
    }
    $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'];
    $extension = (string) $batch['file_extension'];
    if (!isset($types[$extension])) {
        http_response_code(404);
        exit('Formato de arquivo inválido.');
    }
    header('Content-Type: ' . $types[$extension]);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="termo-assinado-' . (int) $batch['id'] . '.' . $extension . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

try {
    $term = PluginAssignatureBatch::termPdf($batch, PluginAssignatureBatch::requests((int) $batch['id']));
} catch (Throwable $e) {
    http_response_code(500);
    exit('Não foi possível gerar o termo: ' . $e->getMessage());
}
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($term['content']));
header('Content-Disposition: inline; filename="termo-assinado-' . (int) $batch['id'] . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $term['content'];
