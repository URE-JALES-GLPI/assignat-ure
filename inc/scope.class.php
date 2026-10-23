<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/app.class.php';
require_once __DIR__ . '/source.class.php';

class PluginAssignatureScope
{
    public const TABLE = 'glpi_plugin_assignature_scopes';

    public static function forUser(int $userId): array
    {
        global $DB;
        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        $result = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['users_id' => $userId]]) as $row) {
            $result[(int) $row['sources_id']] = $row;
        }
        $cache[$userId] = $result;
        return $result;
    }

    public static function canSee(int $userId, int $sourceId, string $status): bool
    {
        if (!PluginAssignatureApp::canView()) {
            return false;
        }
        if (PluginAssignatureApp::canAdmin()) {
            return true;
        }
        $scope = self::forUser($userId)[$sourceId] ?? null;
        return $scope !== null && (int) ($scope[$status === 'done' ? 'see_done' : 'see_pending'] ?? 0) === 1;
    }

    public static function canSign(int $userId, int $sourceId): bool
    {
        if (!self::canSee($userId, $sourceId, 'pending')) {
            return false;
        }
        if (PluginAssignatureApp::canAdmin()) {
            return true;
        }
        $scope = self::forUser($userId)[$sourceId] ?? null;
        return $scope !== null && (int) ($scope['can_sign'] ?? 0) === 1;
    }

    public static function save(int $userId, int $sourceId, bool $pending, bool $done, bool $sign): void
    {
        global $DB;
        if ($userId <= 0 || $sourceId <= 0) {
            throw new RuntimeException('Usuário ou fonte inválidos.');
        }
        $user = new User();
        if (!$user->getFromDB($userId) || PluginAssignatureSource::get($sourceId) === null) {
            throw new RuntimeException('Usuário ou fonte não encontrados.');
        }
        $values = [
            'see_pending' => $pending ? 1 : 0,
            'see_done' => $done ? 1 : 0,
            'can_sign' => $pending && $sign ? 1 : 0,
        ];
        $row = $DB->request([
            'SELECT' => ['id'], 'FROM' => self::TABLE,
            'WHERE' => ['users_id' => $userId, 'sources_id' => $sourceId], 'LIMIT' => 1,
        ])->current();
        if (is_array($row)) {
            $DB->update(self::TABLE, $values, ['id' => (int) $row['id']]);
        } else {
            $DB->insert(self::TABLE, $values + ['users_id' => $userId, 'sources_id' => $sourceId]);
        }
    }
}
