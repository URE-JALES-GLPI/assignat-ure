<?php
/** Consulta leve para atualizar a lista aberta quando os pedidos mudarem. */
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/request.class.php';

Session::checkRight(PluginAssignatureProfile::RIGHT_VIEW, READ);
$userId = (int) Session::getLoginUserID();
$sourceId = max(0, (int) ($_GET['source'] ?? 0));
PluginAssignatureRequest::syncDueSources($userId);
$requests = PluginAssignatureRequest::visible($userId, '', $sourceId);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
echo json_encode([
    'fingerprint' => PluginAssignatureRequest::fingerprint($requests),
], JSON_THROW_ON_ERROR);
