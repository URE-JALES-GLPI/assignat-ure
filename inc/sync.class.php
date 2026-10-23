<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/request.class.php';

class PluginAssignatureSync extends CommonDBTM
{
    public static string $rightname = PluginAssignatureProfile::RIGHT_ADMIN;

    public static function getTypeName($nb = 0)
    {
        return 'Sincronização Assignat-ure';
    }

    public static function cronInfo(): array
    {
        return ['description' => 'Sincronizar pedidos de assinatura e confirmar origens'];
    }

    public static function cronSync(CronTask $task): int
    {
        $count = 0;
        foreach (PluginAssignatureSource::all(true) as $source) {
            try {
                $count += PluginAssignatureRequest::syncSource($source);
            } catch (Throwable $e) {
                $task->log('Fonte ' . $source['slug'] . ': ' . $e->getMessage());
            }
        }
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['id'], 'FROM' => PluginAssignatureRequest::TABLE,
            'WHERE' => ['status' => 'done', 'callback_state' => ['pending', 'failed']],
            'LIMIT' => 100,
        ]) as $row) {
            try {
                PluginAssignatureRequest::sendCallback((int) $row['id']);
                $count++;
            } catch (Throwable $e) {
                $task->log('Retorno #' . $row['id'] . ': ' . $e->getMessage());
            }
        }
        return $count > 0 ? 1 : 0;
    }
}
