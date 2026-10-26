<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/request.class.php';

/** Assinatura do técnico cadastrada pelo administrador para uma ou mais fontes. */
class PluginAssignatureTechnician
{
    public const TABLE = 'glpi_plugin_assignature_technicians';
    public const SOURCES_TABLE = 'glpi_plugin_assignature_technicians_sources';

    public static function get(int $id): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return is_array($row) ? $row : null;
    }

    public static function all(): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'ORDER' => 'full_name ASC']) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function sourceIds(int $id): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['sources_id'], 'FROM' => self::SOURCES_TABLE,
            'WHERE' => ['technicians_id' => $id]]) as $row) {
            $ids[] = (int) $row['sources_id'];
        }
        return $ids;
    }

    public static function availableForSources(array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        $available = [];
        foreach (self::all() as $profile) {
            if ((int) $profile['is_active'] !== 1 || array_diff($sourceIds, self::sourceIds((int) $profile['id'])) !== []) {
                continue;
            }
            $available[] = $profile;
        }
        return $available;
    }

    public static function maskedDocument(array $profile): string
    {
        $value = (string) (new GLPIKey())->decrypt((string) $profile['document_encrypted']);
        $digits = preg_replace('/[^0-9X]/i', '', $value);
        return (string) $profile['document_type'] . ' · final ' . substr($digits, -4);
    }

    public static function save(array $data): int
    {
        global $DB;
        $id = max(0, (int) ($data['id'] ?? 0));
        $existing = $id > 0 ? self::get($id) : null;
        if ($id > 0 && $existing === null) {
            throw new RuntimeException('Técnico não encontrado.');
        }
        $name = mb_strtoupper(trim((string) ($data['full_name'] ?? '')), 'UTF-8');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 255 || count(preg_split('/\s+/u', $name)) < 2) {
            throw new RuntimeException('Informe o nome completo do técnico ou funcionário.');
        }
        $type = strtoupper(trim((string) ($data['document_type'] ?? '')));
        $document = trim((string) ($data['document'] ?? ''));
        if ($document === '' && $existing !== null && $type === (string) $existing['document_type']) {
            $encrypted = (string) $existing['document_encrypted'];
        } else {
            $encrypted = (new GLPIKey())->encrypt(PluginAssignatureBatch::document($type, $document));
        }
        $image = trim((string) ($data['signature_png'] ?? ''));
        $signature = $image !== '' ? PluginAssignatureBatch::signaturePng($image)
            : (string) ($existing['signature_png'] ?? '');
        if ($signature === '') {
            throw new RuntimeException('Desenhe a assinatura do técnico antes de salvar.');
        }
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['sources'] ?? [])))));
        if ($sourceIds === []) {
            throw new RuntimeException('Escolha pelo menos um plugin de origem.');
        }
        foreach ($sourceIds as $sourceId) {
            if (PluginAssignatureSource::get($sourceId) === null) {
                throw new RuntimeException('Uma das fontes selecionadas não existe.');
            }
        }
        $values = [
            'full_name' => $name, 'document_type' => $type, 'document_encrypted' => $encrypted,
            'signature_png' => $signature, 'is_active' => !empty($data['is_active']) ? 1 : 0,
            'date_mod' => assignature_now(),
        ];
        $DB->beginTransaction();
        try {
            if ($existing === null) {
                $DB->insert(self::TABLE, $values);
                $id = (int) $DB->insertId();
            } else {
                $DB->update(self::TABLE, $values, ['id' => $id]);
                $DB->delete(self::SOURCES_TABLE, ['technicians_id' => $id]);
            }
            foreach ($sourceIds as $sourceId) {
                $DB->insert(self::SOURCES_TABLE, ['technicians_id' => $id, 'sources_id' => $sourceId]);
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        return $id;
    }
}
