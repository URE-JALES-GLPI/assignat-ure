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
        // A separação por fonte vale para todos, inclusive administradores: administrar o plugin
        // (cadastrar fontes e acessos) não dá, por si só, acesso aos pedidos de cada fonte.
        $scope = self::forUser($userId)[$sourceId] ?? null;
        return $scope !== null && (int) ($scope[$status === 'done' ? 'see_done' : 'see_pending'] ?? 0) === 1;
    }

    public static function canSign(int $userId, int $sourceId): bool
    {
        if (!self::canSee($userId, $sourceId, 'pending')) {
            return false;
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

    /**
     * Define de uma vez todas as fontes de um usuário. $rows: [source_id => ['pending'=>bool,'done'=>bool,'sign'=>bool]].
     * Fontes fora de $rows (ou sem nenhum estado marcado) perdem o acesso.
     */
    public static function replaceForUser(int $userId, array $rows): int
    {
        global $DB;
        $user = new User();
        if ($userId <= 0 || !$user->getFromDB($userId)) {
            throw new RuntimeException('Selecione um usuário.');
        }
        $kept = 0;
        foreach (PluginAssignatureSource::all() as $source) {
            $sourceId = (int) $source['id'];
            $row = $rows[$sourceId] ?? null;
            if (is_array($row) && (!empty($row['pending']) || !empty($row['done']))) {
                self::save($userId, $sourceId, !empty($row['pending']), !empty($row['done']), !empty($row['sign']));
                $kept++;
            } else {
                $DB->delete(self::TABLE, ['users_id' => $userId, 'sources_id' => $sourceId]);
            }
        }
        return $kept;
    }
}
