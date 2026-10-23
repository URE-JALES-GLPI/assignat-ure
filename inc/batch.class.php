<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/request.class.php';
require_once __DIR__ . '/technician.class.php';

/** Uma assinatura ou um termo em papel para vários pedidos. */
class PluginAssignatureBatch
{
    public const TABLE = 'glpi_plugin_assignature_batches';

    public static function get(int $id): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return is_array($row) ? $row : null;
    }

    public static function requests(int $id): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => PluginAssignatureRequest::TABLE, 'WHERE' => ['batches_id' => $id], 'ORDER' => 'id ASC']) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function technician(int $id): string
    {
        $user = new User();
        if ($id <= 0 || !$user->getFromDB($id)) {
            throw new RuntimeException('Selecione um técnico cadastrado no GLPI.');
        }
        return (string) $user->getFriendlyName();
    }

    public static function technicianName(array $batch): string
    {
        return (string) ($batch['technician_name'] ?? '') !== ''
            ? (string) $batch['technician_name'] : self::technician((int) $batch['technician_users_id']);
    }

    public static function technicianDocument(array $batch): string
    {
        $encrypted = (string) ($batch['technician_document_encrypted'] ?? '');
        return $encrypted === '' ? '' : (string) (new GLPIKey())->decrypt($encrypted);
    }

    public static function formatDate(string $value, bool $time = false): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date($time ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
    }

    public static function document(string $type, string $value): string
    {
        $type = strtoupper(trim($type));
        $raw = strtoupper(preg_replace('/[^0-9X]/i', '', $value));
        if ($type === 'CPF') {
            if (!preg_match('/^[0-9]{11}$/', $raw) || preg_match('/^(.)\1{10}$/', $raw)) {
                throw new RuntimeException('Informe um CPF válido.');
            }
            for ($length = 9; $length <= 10; $length++) {
                $sum = 0;
                for ($i = 0; $i < $length; $i++) {
                    $sum += (int) $raw[$i] * ($length + 1 - $i);
                }
                $digit = ($sum * 10) % 11;
                if ($digit === 10) {
                    $digit = 0;
                }
                if ((int) $raw[$length] !== $digit) {
                    throw new RuntimeException('O CPF informado não passou na validação.');
                }
            }
            return substr($raw, 0, 3) . '.' . substr($raw, 3, 3) . '.' . substr($raw, 6, 3) . '-' . substr($raw, 9, 2);
        }
        if ($type === 'RG' && preg_match('/^[0-9]{7,9}[0-9X]?$/', $raw)) {
            $length = strlen($raw);
            $first = $length === 10 ? 3 : ($length === 9 ? 2 : ($length === 8 ? 1 : 0));
            if ($first === 0) {
                return substr($raw, 0, 3) . '.' . substr($raw, 3, 3) . '-' . substr($raw, 6);
            }
            return substr($raw, 0, $first) . '.' . substr($raw, $first, 3) . '.'
                . substr($raw, $first + 3, 3) . '-' . substr($raw, -1);
        }
        throw new RuntimeException('Escolha CPF ou RG e informe um documento válido.');
    }

    public static function decryptedDocument(array $batch): string
    {
        $encrypted = (string) ($batch['document_encrypted'] ?? '');
        return $encrypted === '' ? '' : (string) (new GLPIKey())->decrypt($encrypted);
    }

    public static function signaturePng(string $image): string
    {
        if (strlen($image) > 450_000 || !preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~D', $image, $matches)) {
            throw new RuntimeException('Desenhe a assinatura antes de concluir.');
        }
        $binary = base64_decode($matches[1], true);
        if ($binary === false || strlen($binary) < 100 || strlen($binary) > 300_000
            || substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n" || getimagesizefromstring($binary) === false) {
            throw new RuntimeException('Imagem de assinatura inválida ou muito grande.');
        }
        return $matches[1];
    }

    /** Revalida todos os pedidos na origem antes de reservar ou assinar. */
    private static function prepare(array $ids, int $userId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 100) {
            throw new RuntimeException('Selecione de 1 a 100 pedidos pendentes.');
        }
        $prepared = [];
        foreach ($ids as $id) {
            $request = PluginAssignatureRequest::get($id);
            if ($request === null || (string) $request['status'] !== 'pending' || (int) $request['is_active'] !== 1
                || (int) ($request['batches_id'] ?? 0) !== 0) {
                throw new RuntimeException('Um dos pedidos já foi assinado ou está reservado para assinatura em papel.');
            }
            $source = PluginAssignatureSource::get((int) $request['sources_id']);
            if ($source === null || (int) $source['is_active'] !== 1
                || !PluginAssignatureScope::canSign($userId, (int) $source['id'])) {
                throw new RuntimeException('Você não pode assinar todos os pedidos selecionados.');
            }
            $detail = PluginAssignatureSource::api($source, 'GET',
                (string) $source['adapter'] === 'tralla'
                    ? ['card' => $request['external_id']]
                    : ['id' => $request['external_id']]
            );
            $expected = (string) $source['adapter'] === 'tralla' ? 'withdrawal' : 'pending';
            if ((string) ($detail['status'] ?? '') !== $expected) {
                throw new RuntimeException('O pedido ' . $request['code'] . ' não está mais pendente na origem. Sincronize novamente.');
            }
            $prepared[$id] = $detail;
        }
        return $prepared;
    }

    public static function create(array $ids, int $userId, int $technicianId, string $method,
        string $signerName = '', string $documentType = '', string $document = '', string $image = ''): int
    {
        global $DB;
        if (!in_array($method, ['digital', 'paper'], true)) {
            throw new RuntimeException('Escolha como a pessoa vai assinar.');
        }
        $profile = PluginAssignatureTechnician::get($technicianId);
        if ($profile === null || (int) $profile['is_active'] !== 1) {
            throw new RuntimeException('Selecione uma assinatura de técnico cadastrada e ativa.');
        }
        $signerName = trim($signerName);
        if (mb_strlen($signerName) > 255 || ($method === 'digital' && $signerName === '')) {
            throw new RuntimeException('Informe o nome completo de quem retira.');
        }
        $formatted = $document !== '' || $method === 'digital'
            ? self::document($documentType, $document) : '';
        $png = $method === 'digital' ? self::signaturePng($image) : '';
        $prepared = self::prepare($ids, $userId);
        $sourceIds = [];
        foreach (array_keys($prepared) as $id) {
            $request = PluginAssignatureRequest::get((int) $id);
            $sourceIds[] = (int) $request['sources_id'];
        }
        if (array_diff(array_unique($sourceIds), PluginAssignatureTechnician::sourceIds($technicianId)) !== []) {
            throw new RuntimeException('Este técnico não está liberado para todos os plugins do lote.');
        }
        $now = assignature_now();
        $DB->beginTransaction();
        try {
            $DB->doQuery('SELECT `id` FROM `' . PluginAssignatureTechnician::TABLE
                . '` WHERE `id` = ' . $technicianId . ' FOR UPDATE');
            $profile = PluginAssignatureTechnician::get($technicianId);
            if ($profile === null || (int) $profile['is_active'] !== 1
                || array_diff(array_unique($sourceIds), PluginAssignatureTechnician::sourceIds($technicianId)) !== []) {
                throw new RuntimeException('O cadastro do técnico mudou. Escolha novamente antes de assinar.');
            }
            foreach (array_keys($prepared) as $id) {
                $DB->doQuery('SELECT `id` FROM `' . PluginAssignatureRequest::TABLE . '` WHERE `id` = ' . $id . ' FOR UPDATE');
                $fresh = PluginAssignatureRequest::get($id);
                if ($fresh === null || (string) $fresh['status'] !== 'pending' || (int) $fresh['is_active'] !== 1
                    || (int) ($fresh['batches_id'] ?? 0) !== 0) {
                    throw new RuntimeException('Um pedido foi alterado durante a assinatura. Recarregue a página.');
                }
                if (!PluginAssignatureScope::canSign($userId, (int) $fresh['sources_id'])) {
                    throw new RuntimeException('Permissão para assinar removida.');
                }
            }
            $DB->insert(self::TABLE, [
                'method' => $method, 'status' => $method === 'digital' ? 'done' : 'awaiting_upload',
                'technician_users_id' => 0, 'technician_profiles_id' => $technicianId,
                'technician_name' => (string) $profile['full_name'],
                'technician_document_type' => (string) $profile['document_type'],
                'technician_document_encrypted' => (string) $profile['document_encrypted'],
                'technician_signature_png' => (string) $profile['signature_png'],
                'signer_name' => $signerName,
                'document_type' => strtoupper($documentType),
                'document_encrypted' => $formatted !== '' ? (new GLPIKey())->encrypt($formatted) : '',
                'signature_png' => $png, 'created_by_users_id' => $userId,
                'completed_by_users_id' => $method === 'digital' ? $userId : 0,
                'created_at' => $now, 'completed_at' => $method === 'digital' ? $now : null,
            ]);
            $batchId = (int) $DB->insertId();
            foreach ($prepared as $id => $detail) {
                $values = [
                    'batches_id' => $batchId,
                    'payload_json' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'date_mod' => $now,
                ];
                if ($method === 'digital') {
                    $values += [
                        'status' => 'done', 'signature_png' => '',
                        'signer_name' => $signerName, 'signer_document' => '',
                        'signed_by_users_id' => $userId, 'signed_at' => $now,
                        'receipt_key' => bin2hex(random_bytes(24)), 'callback_state' => 'pending',
                    ];
                }
                $DB->update(PluginAssignatureRequest::TABLE, $values, ['id' => $id]);
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
        if ($method === 'digital') {
            self::callbacks(array_keys($prepared));
        }
        return $batchId;
    }

    private static function callbacks(array $ids): void
    {
        foreach ($ids as $id) {
            try {
                PluginAssignatureRequest::sendCallback((int) $id);
            } catch (Throwable $e) {
                // Comprovante salvo; a tarefa automática tenta confirmar novamente.
            }
        }
    }

    public static function canManage(array $batch, int $userId): bool
    {
        if (!PluginAssignatureApp::canView()) {
            return false;
        }
        $requests = self::requests((int) $batch['id']);
        if ($requests === []) {
            return false;
        }
        foreach ($requests as $request) {
            $canSee = PluginAssignatureScope::canSee($userId, (int) $request['sources_id'], (string) $request['status']);
            $ownDigital = (string) $batch['method'] === 'digital'
                && (int) $batch['created_by_users_id'] === $userId
                && PluginAssignatureScope::canSign($userId, (int) $request['sources_id']);
            if (!$canSee && !$ownDigital) {
                return false;
            }
        }
        return true;
    }

    /** Linhas do termo a partir dos dados congelados no momento da assinatura. */
    public static function termRows(array $requests): array
    {
        $rows = [];
        foreach ($requests as $request) {
            $payload = json_decode((string) ($request['payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $source = PluginAssignatureSource::get((int) $request['sources_id']);
            $origin = (string) ($source['name'] ?? 'Plugin de origem');
            $machines = is_array($payload['machines'] ?? null) ? $payload['machines'] : [];
            if ($machines === []) {
                $rows[] = [
                    'code' => (string) $request['code'], 'name' => (string) $request['title'],
                    'type' => $origin, 'status' => 'Conforme pedido',
                    'report' => trim((string) ($payload['description'] ?? $request['summary'] ?? '')),
                ];
                continue;
            }
            foreach ($machines as $machine) {
                if (!is_array($machine)) {
                    continue;
                }
                $name = trim((string) ($machine['label'] ?? ''));
                $model = trim((string) ($machine['model_name'] ?? ''));
                $rows[] = [
                    'code' => (string) $request['code'],
                    'name' => $name !== '' ? $name : ($model !== '' ? $model : (string) $request['title']),
                    'type' => $model !== '' ? $model : $origin,
                    'status' => trim((string) ($machine['status_label'] ?? '')) ?: 'Não informado',
                    'report' => trim((string) ($machine['report'] ?? '')),
                ];
            }
        }
        return $rows;
    }

    public static function termContext(array $requests): array
    {
        $sources = [];
        $boards = [];
        foreach ($requests as $request) {
            $source = PluginAssignatureSource::get((int) $request['sources_id']);
            if ($source !== null) {
                $sources[(int) $source['id']] = (string) $source['name'];
            }
            $payload = json_decode((string) ($request['payload_json'] ?? ''), true);
            if (is_array($payload) && trim((string) ($payload['board']['name'] ?? '')) !== '') {
                $boards[] = trim((string) $payload['board']['name']);
            }
        }
        return ['sources' => implode(', ', array_values($sources)),
            'boards' => implode(', ', array_unique($boards))];
    }

    public static function filePath(array $batch): string
    {
        $extension = (string) ($batch['file_extension'] ?? '');
        if (!in_array($extension, ['pdf', 'png', 'jpg'], true)) {
            throw new RuntimeException('Arquivo não disponível.');
        }
        return GLPI_PLUGIN_DOC_DIR . '/assignature/batch-' . (int) $batch['id'] . '.' . $extension;
    }

    public static function upload(int $batchId, int $userId, string $signerName,
        string $documentType, string $document, array $file): void
    {
        global $DB;
        $batch = self::get($batchId);
        if ($batch === null || (string) $batch['method'] !== 'paper' || (string) $batch['status'] !== 'awaiting_upload'
            || !self::canManage($batch, $userId)) {
            throw new RuntimeException('Lote não disponível para envio.');
        }
        if (empty($batch['printed_at'])) {
            throw new RuntimeException('Imprima o termo na fila configurada antes de enviar o arquivo assinado.');
        }
        $requests = self::requests($batchId);
        if ($requests === []) {
            throw new RuntimeException('O lote não contém pedidos.');
        }
        foreach ($requests as $request) {
            if ((string) $request['status'] !== 'pending' || (int) $request['is_active'] !== 1
                || !PluginAssignatureScope::canSign($userId, (int) $request['sources_id'])) {
                throw new RuntimeException('Um pedido não está mais disponível ou sua permissão mudou.');
            }
            $source = PluginAssignatureSource::get((int) $request['sources_id']);
            if ($source === null || (int) $source['is_active'] !== 1) {
                throw new RuntimeException('A fonte de um pedido está inativa.');
            }
            $detail = PluginAssignatureSource::api($source, 'GET',
                (string) $source['adapter'] === 'tralla'
                    ? ['card' => $request['external_id']]
                    : ['id' => $request['external_id']]
            );
            $expected = (string) $source['adapter'] === 'tralla' ? 'withdrawal' : 'pending';
            if ((string) ($detail['status'] ?? '') !== $expected) {
                throw new RuntimeException('O pedido ' . $request['code'] . ' não está mais pendente na origem.');
            }
        }
        $signerName = trim($signerName);
        if ($signerName === '' || mb_strlen($signerName) > 255) {
            throw new RuntimeException('Informe o nome completo de quem assinou o termo.');
        }
        $formatted = self::document($documentType, $document);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))
            || (int) ($file['size'] ?? 0) < 100 || (int) ($file['size'] ?? 0) > 10_000_000) {
            throw new RuntimeException('Envie um PDF ou imagem de até 10 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $extensions = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'];
        $extension = $extensions[$mime] ?? '';
        if ($extension === '') {
            throw new RuntimeException('Formato não aceito. Use PDF, PNG ou JPG.');
        }
        $content = file_get_contents((string) $file['tmp_name']);
        if ($content === false || ($extension === 'pdf' && !str_starts_with($content, '%PDF-'))
            || ($extension !== 'pdf' && getimagesizefromstring($content) === false)) {
            throw new RuntimeException('O arquivo enviado não é válido.');
        }
        $path = GLPI_PLUGIN_DOC_DIR . '/assignature/batch-' . $batchId . '.' . $extension;
        $now = assignature_now();
        $moved = false;
        $DB->beginTransaction();
        try {
            $DB->doQuery('SELECT `id` FROM `' . self::TABLE . '` WHERE `id` = ' . $batchId . ' FOR UPDATE');
            $fresh = self::get($batchId);
            if ($fresh === null || (string) $fresh['status'] !== 'awaiting_upload') {
                throw new RuntimeException('O lote já foi concluído.');
            }
            foreach ($requests as $request) {
                $id = (int) $request['id'];
                $DB->doQuery('SELECT `id` FROM `' . PluginAssignatureRequest::TABLE . '` WHERE `id` = ' . $id . ' FOR UPDATE');
                $current = PluginAssignatureRequest::get($id);
                if ($current === null || (string) $current['status'] !== 'pending'
                    || (int) $current['batches_id'] !== $batchId || (int) $current['is_active'] !== 1) {
                    throw new RuntimeException('Um pedido mudou durante o envio.');
                }
            }
            if (file_exists($path) && !unlink($path)) {
                throw new RuntimeException('Não foi possível substituir um envio anterior incompleto.');
            }
            if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
                throw new RuntimeException('Não foi possível salvar o arquivo no armazenamento privado do GLPI.');
            }
            $moved = true;
            @chmod($path, 0600);
            $DB->update(self::TABLE, [
                'status' => 'done', 'signer_name' => $signerName,
                'document_type' => strtoupper($documentType),
                'document_encrypted' => (new GLPIKey())->encrypt($formatted),
                'file_name' => mb_substr(basename((string) ($file['name'] ?? 'termo')), 0, 255),
                'file_extension' => $extension, 'file_sha256' => hash('sha256', $content),
                'completed_by_users_id' => $userId, 'completed_at' => $now,
            ], ['id' => $batchId]);
            foreach ($requests as $request) {
                $DB->update(PluginAssignatureRequest::TABLE, [
                    'status' => 'done', 'signer_name' => $signerName,
                    'signed_by_users_id' => $userId, 'signed_at' => $now,
                    'receipt_key' => bin2hex(random_bytes(24)), 'callback_state' => 'pending',
                    'date_mod' => $now,
                ], ['id' => (int) $request['id']]);
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            if ($moved) {
                @unlink($path);
            }
            throw $e;
        }
        self::callbacks(array_column($requests, 'id'));
    }

    public static function cancel(int $batchId, int $userId): void
    {
        global $DB;
        $batch = self::get($batchId);
        if ($batch === null || (string) $batch['status'] !== 'awaiting_upload'
            || ((int) $batch['created_by_users_id'] !== $userId && !PluginAssignatureApp::canAdmin())
            || !self::canManage($batch, $userId)) {
            throw new RuntimeException('Você não pode cancelar este termo.');
        }
        $DB->beginTransaction();
        try {
            $DB->doQuery('SELECT `id` FROM `' . self::TABLE . '` WHERE `id` = ' . $batchId . ' FOR UPDATE');
            $fresh = self::get($batchId);
            if ($fresh === null || (string) $fresh['status'] !== 'awaiting_upload') {
                throw new RuntimeException('Este termo já foi concluído.');
            }
            $DB->update(self::TABLE, ['status' => 'cancelled'], ['id' => $batchId]);
            $DB->update(PluginAssignatureRequest::TABLE, ['batches_id' => 0], [
                'batches_id' => $batchId, 'status' => 'pending',
            ]);
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    public static function printerQueue(): string
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['printer_queue'], 'FROM' => 'glpi_plugin_assignature_settings', 'LIMIT' => 1,
        ])->current();
        return is_array($row) ? (string) $row['printer_queue'] : '';
    }

    public static function setPrinterQueue(string $queue): void
    {
        global $DB;
        $queue = trim($queue);
        if ($queue !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $queue)) {
            throw new RuntimeException('Use o nome exato da fila CUPS, sem espaços.');
        }
        $row = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_assignature_settings', 'LIMIT' => 1])->current();
        if (is_array($row)) {
            $DB->update('glpi_plugin_assignature_settings', ['printer_queue' => $queue], ['id' => (int) $row['id']]);
        } else {
            $DB->insert('glpi_plugin_assignature_settings', ['printer_queue' => $queue]);
        }
    }

    /** Gera o mesmo termo usado na tela, sem acionar a impressora. */
    public static function termPdf(array $batch, array $requests): array
    {
        if (!class_exists('TCPDF')) {
            throw new RuntimeException('O servidor precisa do TCPDF para gerar o termo.');
        }
        $rows = self::termRows($requests);
        $context = self::termContext($requests);
        $paper = (string) $batch['method'] === 'paper';
        $batchId = (int) $batch['id'];
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Assignat-ure');
        $pdf->SetTitle('Termo de entrega de equipamentos #' . $batchId);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(17, 17, 17);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $logo = dirname(__DIR__) . '/logo.png';
        if (is_file($logo)) {
            $pdf->Image($logo, 17, 17, 18, 18, 'PNG');
        }
        $pdf->SetTextColor(83, 96, 116);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetXY(40, 18);
        $pdf->Cell(153, 5, '[URE] ASSIGNAT-URE', 0, 1, 'R');
        $pdf->SetTextColor(17, 102, 178);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->SetX(40);
        $pdf->Cell(153, 8, 'Termo de entrega de equipamentos', 0, 1, 'R');
        $pdf->SetTextColor(83, 96, 116);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetX(40);
        $pdf->Cell(153, 5, 'Lote #' . $batchId . '  |  ' . self::formatDate((string) $batch['created_at'], true), 0, 1, 'R');
        $pdf->SetDrawColor(17, 111, 190);
        $pdf->SetLineWidth(.6);
        $pdf->Line(17, 39, 193, 39);
        $pdf->SetY(44);
        $pdf->SetTextColor(28, 51, 82);
        $pdf->SetFont('dejavusans', '', 9);
        $intro = 'Este termo registra a entrega dos equipamentos abaixo após os procedimentos informados pelos plugins de origem. '
            . 'A pessoa responsável pela retirada deve conferir os itens e as condições registradas antes de assinar.';
        $pdf->writeHTML('<table cellpadding="9" style="background-color:#f3f8fe;border-left:3px solid #1372bd"><tr><td>'
            . assignature_h($intro) . '</td></tr></table>', true, false, true, false, '');
        $pdf->writeHTML('<p>Eu, <b>' . assignature_h(self::technicianName($batch))
            . '</b>, técnico(a) responsável, declaro que os equipamentos listados foram disponibilizados ao responsável identificado neste termo, '
            . 'com os resultados e observações registrados no atendimento.</p>', true, false, true, false, '');
        $recipient = $paper ? '________________________________' : (string) $batch['signer_name'];
        $facts = [
            ['DATA DA ENTREGA', self::formatDate((string) $batch['created_at']), 'PLUGIN(S) DE ORIGEM', $context['sources'] ?: '—'],
            ['TÉCNICO RESPONSÁVEL', self::technicianName($batch) . ' — ' . (string) ($batch['technician_document_type'] ?? '') . ' ' . self::technicianDocument($batch),
                'RESPONSÁVEL PELA RETIRADA', $recipient],
            ['QUADRO / ATENDIMENTO', $context['boards'] ?: 'Não informado',
                'ITENS NESTE TERMO', count($rows) . ' equipamento(s) · ' . count($requests) . ' pedido(s)'],
        ];
        $html = '<table cellpadding="7" cellspacing="4" width="100%">';
        foreach ($facts as $fact) {
            $html .= '<tr><td width="50%" style="border:1px solid #d9e3f0"><span style="color:#61748f;font-size:8px"><b>'
                . assignature_h($fact[0]) . '</b></span><br><b>' . assignature_h($fact[1])
                . '</b></td><td width="50%" style="border:1px solid #d9e3f0"><span style="color:#61748f;font-size:8px"><b>'
                . assignature_h($fact[2]) . '</b></span><br><b>' . assignature_h($fact[3]) . '</b></td></tr>';
        }
        $pdf->writeHTML($html . '</table><br>', true, false, true, false, '');
        $pdf->SetTextColor(17, 102, 178);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(0, 7, 'EQUIPAMENTOS ENTREGUES', 0, 1);
        $pdf->SetTextColor(28, 51, 82);
        $pdf->SetFont('dejavusans', '', 8.5);
        $border = 'border-bottom:1px solid #dce5ef';
        $html = '<table cellpadding="8" width="100%" style="font-size:8.5px">'
            . '<thead><tr style="background-color:#eaf3fc;color:#1b4c7e"><th width="6%" style="' . $border . '"><b>#</b></th>'
            . '<th width="25%" style="' . $border . '"><b>EQUIPAMENTO / PEDIDO</b></th><th width="18%" style="' . $border . '"><b>TIPO</b></th>'
            . '<th width="16%" style="' . $border . '"><b>STATUS FINAL</b></th><th width="35%" style="' . $border . '"><b>SERVIÇO / OBSERVAÇÕES</b></th></tr></thead><tbody>';
        foreach ($rows as $index => $row) {
            $html .= '<tr><td style="' . $border . '">' . ($index + 1) . '</td><td style="' . $border . '"><b>' . assignature_h($row['name'])
                . '</b><br><span style="color:#6d7f96">#' . assignature_h($row['code']) . '</span></td><td style="' . $border . '">'
                . assignature_h($row['type']) . '</td><td style="' . $border . '"><b>' . assignature_h($row['status']) . '</b></td><td style="' . $border . '">'
                . assignature_h($row['report'] !== '' ? $row['report'] : '—') . '</td></tr>';
        }
        $pdf->writeHTML($html . '</tbody></table>', true, false, true, false, '');
        if ($pdf->GetY() > 208) {
            $pdf->AddPage();
        }
        $pdf->Ln(6);
        $pdf->SetTextColor(17, 102, 178);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(0, 7, 'ASSINATURAS', 0, 1);
        $y = $pdf->GetY() + 2;
        $pdf->SetDrawColor(210, 223, 239);
        $pdf->SetLineWidth(.2);
        $pdf->Rect(17, $y, 85, 55);
        $pdf->Rect(108, $y, 85, 55);
        $technicianSignature = (string) ($batch['technician_signature_png'] ?? '');
        if ($technicianSignature !== '') {
            $binary = base64_decode($technicianSignature, true);
            if ($binary === false) {
                throw new RuntimeException('Assinatura cadastrada do técnico inválida.');
            }
            $pdf->Image('@' . $binary, 22, $y + 3, 64, 17, 'PNG');
        }
        if ($paper) {
            $pdf->SetDrawColor(71, 83, 100);
            $pdf->Line(114, $y + 21, 187, $y + 21);
        } else {
            $recipientSignature = base64_decode((string) $batch['signature_png'], true);
            if ($recipientSignature === false) {
                throw new RuntimeException('Assinatura do responsável inválida.');
            }
            $pdf->Image('@' . $recipientSignature, 113, $y + 3, 64, 17, 'PNG');
        }
        $pdf->SetDrawColor(166, 184, 206);
        $pdf->Line(22, $y + 22, 97, $y + 22);
        $pdf->Line(113, $y + 22, 188, $y + 22);
        $pdf->SetTextColor(28, 51, 82);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetXY(22, $y + 24);
        $pdf->Cell(75, 5, 'Responsável pela entrega (técnico)', 0, 0);
        $pdf->SetXY(113, $y + 24);
        $pdf->Cell(75, 5, 'Responsável pelo recebimento', 0, 0);
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetXY(22, $y + 31);
        $pdf->MultiCell(75, 4, 'Nome: ' . self::technicianName($batch) . "\nDocumento: "
            . (string) ($batch['technician_document_type'] ?? '') . ' ' . self::technicianDocument($batch), 0, 'L');
        $pdf->SetXY(113, $y + 31);
        $pdf->MultiCell(75, 4, 'Nome: ' . ($paper ? '____________________________' : (string) $batch['signer_name'])
            . "\nDocumento: " . ($paper ? '____________________________' : (string) $batch['document_type'] . ' '
                . self::decryptedDocument($batch)), 0, 'L');
        if (!$paper) {
            $pdf->SetXY(113, $y + 47);
            $pdf->Cell(75, 4, 'Assinado em ' . self::formatDate((string) $batch['completed_at'], true), 0, 0);
        }
        $pageCount = $pdf->getNumPages();
        $pdf->SetAutoPageBreak(false, 0);
        for ($page = 1; $page <= $pageCount; $page++) {
            $pdf->setPage($page);
            $pdf->SetDrawColor(218, 228, 239);
            $pdf->Line(17, 281, 193, 281);
            $pdf->SetTextColor(105, 121, 142);
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->SetXY(17, 283);
            $pdf->Cell(135, 5, '[URE] Assignat-ure  |  Termo #' . $batchId, 0, 0);
            $pdf->Cell(41, 5, 'Página ' . $page . ' de ' . $pageCount, 0, 0, 'R');
        }
        $content = $pdf->Output('termo.pdf', 'S');
        if (!is_string($content) || !str_starts_with($content, '%PDF-')) {
            throw new RuntimeException('Não foi possível gerar o PDF do termo.');
        }
        return ['content' => $content, 'pages' => $pageCount];
    }

    public static function printTerm(int $batchId, int $userId): string
    {
        $batch = self::get($batchId);
        $paper = $batch !== null && (string) $batch['method'] === 'paper';
        if ($batch === null || !self::canManage($batch, $userId)
            || ($paper && (string) $batch['status'] !== 'awaiting_upload')
            || (!$paper && ((string) $batch['method'] !== 'digital' || (string) $batch['status'] !== 'done'))) {
            throw new RuntimeException('Termo não disponível para impressão.');
        }
        foreach (self::requests($batchId) as $request) {
            if (!PluginAssignatureScope::canSign($userId, (int) $request['sources_id'])) {
                throw new RuntimeException('Sem permissão para imprimir todos os pedidos deste lote.');
            }
        }
        $queue = self::printerQueue();
        if ($queue === '' || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $queue)) {
            throw new RuntimeException('Configure uma fila CUPS válida na administração do Assignat-ure.');
        }
        if (!function_exists('proc_open') || !is_executable('/usr/bin/lp')) {
            throw new RuntimeException('O servidor precisa do comando /usr/bin/lp para imprimir diretamente.');
        }
        $term = self::termPdf($batch, self::requests($batchId));
        $temp = tempnam(GLPI_PLUGIN_DOC_DIR . '/assignature', 'print-');
        if ($temp === false) {
            throw new RuntimeException('Não foi possível preparar o arquivo de impressão.');
        }
        try {
            if (file_put_contents($temp, $term['content']) === false) {
                throw new RuntimeException('Não foi possível gerar o PDF para impressão.');
            }
            $pipes = [];
            $command = self::printCommand($queue, $paper, (int) $term['pages'], $temp);
            $process = proc_open($command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('O comando lp não pôde ser iniciado no servidor.');
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0) {
                throw new RuntimeException('CUPS recusou a impressão: ' . mb_substr(trim((string) $error), 0, 250));
            }
            global $DB;
            $DB->update(self::TABLE, ['printed_at' => assignature_now()], ['id' => $batchId]);
            return trim((string) $output);
        } finally {
            @unlink($temp);
        }
    }

    public static function printCommand(string $queue, bool $paper, int $pages, string $path): array
    {
        $command = ['/usr/bin/lp', '-d', $queue, '-n', $paper ? '1' : '2', '-o', 'media=A4'];
        if ($pages > 1) {
            array_push($command, '-o', 'sides=two-sided-long-edge', '-o', 'Duplex=DuplexNoTumble');
            if ($queue === 'HP_PeB') {
                array_push($command, '-o', 'Option1=True');
            }
        }
        $command[] = $path;
        return $command;
    }
}
