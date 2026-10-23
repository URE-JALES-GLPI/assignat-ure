<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/scope.class.php';

class PluginAssignatureRequest
{
    public const TABLE = 'glpi_plugin_assignature_requests';

    public static function get(int $id): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return is_array($row) ? $row : null;
    }

    public static function byExternal(int $sourceId, string $externalId): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => ['sources_id' => $sourceId, 'external_id' => $externalId],
            'LIMIT' => 1,
        ])->current();
        return is_array($row) ? $row : null;
    }

    public static function visible(int $userId, string $status = '', int $sourceId = 0): array
    {
        global $DB;
        $result = [];
        $sources = [];
        foreach (PluginAssignatureSource::all() as $source) {
            $sources[(int) $source['id']] = $source;
        }
        $where = [];
        if ($status !== '') {
            $where['status'] = $status;
        }
        if ($sourceId > 0) {
            $where['sources_id'] = $sourceId;
        }
        $query = ['FROM' => self::TABLE, 'ORDER' => 'date_mod DESC, id DESC'];
        if ($where !== []) {
            $query['WHERE'] = $where;
        }
        foreach ($DB->request($query) as $row) {
            $source = $sources[(int) $row['sources_id']] ?? null;
            if ($source === null || (int) $source['is_active'] !== 1) {
                continue;
            }
            if ((string) $row['status'] === 'pending' && (int) $row['is_active'] !== 1) {
                continue;
            }
            if (!PluginAssignatureScope::canSee($userId, (int) $row['sources_id'], (string) $row['status'])) {
                continue;
            }
            $row['source_name'] = (string) $source['name'];
            $row['source_slug'] = (string) $source['slug'];
            $result[] = $row;
        }
        return $result;
    }

    /** Atualiza pedidos ao abrir o painel, mesmo sem o cron do GLPI configurado. */
    public static function syncDueSources(int $userId): void
    {
        foreach (PluginAssignatureSource::all(true) as $source) {
            $sourceId = (int) $source['id'];
            if (!PluginAssignatureScope::canSee($userId, $sourceId, 'pending')
                && !PluginAssignatureScope::canSee($userId, $sourceId, 'done')) {
                continue;
            }
            $lastAttempt = strtotime((string) ($source['last_sync'] ?? '')) ?: 0;
            if ($lastAttempt >= time() - 60) {
                continue;
            }
            try {
                self::syncSource($source);
            } catch (Throwable $e) {
                // A falha fica registrada na fonte e é exibida ao administrador.
            }
        }
    }

    public static function syncSource(array $source): int
    {
        global $DB;
        if ((int) $source['is_active'] !== 1) {
            return 0;
        }
        try {
            $isTralla = (string) $source['adapter'] === 'tralla';
            $count = 0;
            $seen = [];
            $offset = 0;
            do {
                $data = PluginAssignatureSource::api($source, 'GET', [
                    'list' => $isTralla ? 'withdrawal' : 'pending',
                    'limit' => 500,
                    'offset' => $offset,
                ]);
                $items = $isTralla ? ($data['cards'] ?? null) : ($data['requests'] ?? null);
                if (!is_array($items)) {
                    throw new RuntimeException('A fonte não retornou uma lista de pedidos.');
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $externalId = trim((string) ($item['id'] ?? ''));
                    if ($externalId === '' || mb_strlen($externalId) > 100) {
                        continue;
                    }
                    $seen[$externalId] = true;
                    $existing = self::byExternal((int) $source['id'], $externalId);
                    if ($existing !== null && (string) $existing['status'] === 'done') {
                        continue;
                    }
                    $values = [
                        'sources_id' => (int) $source['id'],
                        'external_id' => $externalId,
                        'code' => mb_substr((string) ($item['code'] ?? $externalId), 0, 100),
                        'title' => mb_substr((string) ($item['name'] ?? $item['title'] ?? $externalId), 0, 255),
                        'summary' => (string) ($item['description'] ?? $item['board']['name'] ?? ''),
                        'payload_json' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'status' => 'pending',
                        'is_active' => 1,
                        'date_mod' => assignature_now(),
                    ];
                    if ($existing !== null) {
                        $DB->update(self::TABLE, $values, ['id' => (int) $existing['id']]);
                    } else {
                        $values['date_creation'] = assignature_now();
                        $DB->insert(self::TABLE, $values);
                    }
                    $count++;
                }
                $pageSize = count($items);
                $offset += $pageSize;
                if ($offset > 50_000) {
                    throw new RuntimeException('A fonte retornou mais de 50.000 pedidos; divida a sincronização.');
                }
            } while ($pageSize === 500);
            foreach ($DB->request([
                'SELECT' => ['id', 'external_id'], 'FROM' => self::TABLE,
                'WHERE' => ['sources_id' => (int) $source['id'], 'status' => 'pending', 'is_active' => 1],
            ]) as $row) {
                if (!isset($seen[(string) $row['external_id']])) {
                    $DB->update(self::TABLE, ['is_active' => 0], ['id' => (int) $row['id']]);
                }
            }
            $DB->update(PluginAssignatureSource::TABLE, [
                'last_sync' => assignature_now(), 'last_error' => '',
            ], ['id' => (int) $source['id']]);
            return $count;
        } catch (Throwable $e) {
            $DB->update(PluginAssignatureSource::TABLE, [
                'last_sync' => assignature_now(), 'last_error' => $e->getMessage(),
            ], ['id' => (int) $source['id']]);
            throw $e;
        }
    }

    public static function sign(int $id, int $userId, string $signerName, string $document, string $image): array
    {
        global $DB;
        $request = self::get($id);
        if ($request === null || (string) $request['status'] !== 'pending' || (int) $request['is_active'] !== 1) {
            throw new RuntimeException('Este pedido não está disponível para assinatura.');
        }
        $source = PluginAssignatureSource::get((int) $request['sources_id']);
        if ($source === null || (int) $source['is_active'] !== 1
            || !PluginAssignatureScope::canSign($userId, (int) $source['id'])) {
            throw new RuntimeException('Sem permissão para assinar este pedido.');
        }
        $signerName = trim($signerName);
        $document = trim($document);
        if ($signerName === '' || mb_strlen($signerName) > 255 || mb_strlen($document) > 100) {
            throw new RuntimeException('Informe o nome de quem assina.');
        }
        if (strlen($image) > 450_000 || !preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~D', $image, $matches)) {
            throw new RuntimeException('Assinatura inválida. Use o campo de desenho.');
        }
        $binary = base64_decode($matches[1], true);
        if ($binary === false || strlen($binary) < 100 || strlen($binary) > 300_000
            || substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n" || getimagesizefromstring($binary) === false) {
            throw new RuntimeException('Imagem de assinatura inválida ou muito grande.');
        }
        $detail = PluginAssignatureSource::api($source, 'GET',
            (string) $source['adapter'] === 'tralla'
                ? ['card' => $request['external_id']]
                : ['id' => $request['external_id']]
        );
        $expectedStatus = (string) $source['adapter'] === 'tralla' ? 'withdrawal' : 'pending';
        if ((string) ($detail['status'] ?? '') !== $expectedStatus) {
            throw new RuntimeException('O pedido não está mais pendente na fonte. Sincronize novamente.');
        }
        $DB->beginTransaction();
        try {
            $DB->doQuery('SELECT `id` FROM `' . self::TABLE . '` WHERE `id` = ' . $id . ' FOR UPDATE');
            $fresh = self::get($id);
            if ($fresh === null || (string) $fresh['status'] !== 'pending' || (int) $fresh['is_active'] !== 1) {
                throw new RuntimeException('Este pedido já foi assinado.');
            }
            if (!PluginAssignatureScope::canSign($userId, (int) $source['id'])) {
                throw new RuntimeException('Sem permissão para assinar este pedido.');
            }
            $DB->update(self::TABLE, [
                'status' => 'done',
                'payload_json' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'signature_png' => $matches[1],
                'signer_name' => $signerName,
                'signer_document' => $document,
                'signed_by_users_id' => $userId,
                'signed_at' => assignature_now(),
                'receipt_key' => bin2hex(random_bytes(24)),
                'callback_state' => 'pending',
                'date_mod' => assignature_now(),
            ], ['id' => $id]);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        try {
            self::sendCallback($id);
        } catch (Throwable $e) {
            // O comprovante já está salvo; a sincronização tentará novamente.
        }
        return self::get($id) ?? [];
    }

    public static function sendCallback(int $id): void
    {
        global $DB;
        $request = self::get($id);
        if ($request === null || (string) $request['status'] !== 'done') {
            throw new RuntimeException('Assinatura não encontrada.');
        }
        if ((string) $request['callback_state'] === 'confirmed') {
            return;
        }
        $source = PluginAssignatureSource::get((int) $request['sources_id']);
        if ($source === null) {
            throw new RuntimeException('Fonte não encontrada.');
        }
        try {
            $hash = hash('sha256', base64_decode((string) $request['signature_png'], true) ?: '');
            if ((int) ($request['batches_id'] ?? 0) > 0) {
                require_once __DIR__ . '/batch.class.php';
                $batch = PluginAssignatureBatch::get((int) $request['batches_id']);
                if ($batch === null || (string) $batch['status'] !== 'done') {
                    throw new RuntimeException('Comprovante do lote não encontrado.');
                }
                $hash = (string) $batch['method'] === 'paper'
                    ? (string) $batch['file_sha256']
                    : hash('sha256', base64_decode((string) $batch['signature_png'], true) ?: '');
            }
            PluginAssignatureSource::api($source, 'POST', [
                'action' => 'signature_completed',
                'id' => (string) $request['external_id'],
                'signer_name' => (string) $request['signer_name'],
                'signed_at' => (string) $request['signed_at'],
                'signature_sha256' => $hash,
            ]);
            $DB->update(self::TABLE, ['callback_state' => 'confirmed', 'callback_error' => ''], ['id' => $id]);
        } catch (Throwable $e) {
            $DB->update(self::TABLE, ['callback_state' => 'failed', 'callback_error' => $e->getMessage()], ['id' => $id]);
            throw $e;
        }
    }
}
