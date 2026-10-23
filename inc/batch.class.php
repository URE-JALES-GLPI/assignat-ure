<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/request.class.php';

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

    public static function availableUsers(): array
    {
        global $DB;
        $users = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users',
            'WHERE' => ['is_active' => 1], 'ORDER' => 'realname ASC', 'LIMIT' => 2000,
        ]) as $row) {
            $name = trim((string) $row['firstname'] . ' ' . (string) $row['realname']);
            $users[(int) $row['id']] = $name !== '' ? $name : (string) $row['name'];
        }
        return $users;
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
        self::technician($technicianId);
        $signerName = trim($signerName);
        if (mb_strlen($signerName) > 255 || ($method === 'digital' && $signerName === '')) {
            throw new RuntimeException('Informe o nome completo de quem retira.');
        }
        $formatted = $document !== '' || $method === 'digital'
            ? self::document($documentType, $document) : '';
        $png = $method === 'digital' ? self::signaturePng($image) : '';
        $prepared = self::prepare($ids, $userId);
        $now = assignature_now();
        $DB->beginTransaction();
        try {
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
                'technician_users_id' => $technicianId, 'signer_name' => $signerName,
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
            if (!PluginAssignatureScope::canSee($userId, (int) $request['sources_id'], (string) $request['status'])) {
                return false;
            }
        }
        return true;
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

    public static function printPaper(int $batchId, int $userId): string
    {
        $batch = self::get($batchId);
        if ($batch === null || (string) $batch['method'] !== 'paper'
            || (string) $batch['status'] !== 'awaiting_upload' || !self::canManage($batch, $userId)) {
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
        if (!class_exists('TCPDF') || !function_exists('proc_open') || !is_executable('/usr/bin/lp')) {
            throw new RuntimeException('O servidor precisa do TCPDF e do comando /usr/bin/lp para imprimir diretamente.');
        }
        $html = '<h1>TERMO DE RETIRADA DE EQUIPAMENTOS</h1>';
        $html .= '<p><b>Assignat-ure · Lote #' . $batchId . '</b><br>Criado em ' . assignature_h($batch['created_at']) . '</p>';
        $html .= '<p>Declaro o recebimento dos equipamentos relacionados abaixo, nas condições registradas nos pedidos de origem.</p>';
        $html .= '<table cellpadding="7" border="1"><tr><td width="50%"><b>Técnico responsável</b><br>' . assignature_h(self::technician((int) $batch['technician_users_id'])) . '</td><td width="50%"><b>Pessoa que retira</b><br>' . assignature_h($batch['signer_name'] ?: '________________________________') . '</td></tr>';
        $html .= '<tr><td><b>Documento (CPF/RG)</b><br>____________________________</td><td><b>Data da retirada</b><br>____ / ____ / ______</td></tr></table><br>';
        $html .= '<table cellpadding="6" border="1"><tr style="background-color:#e9f0fa"><th width="16%"><b>Pedido</b></th><th width="24%"><b>Origem</b></th><th width="60%"><b>Equipamento / descrição</b></th></tr>';
        foreach (self::requests($batchId) as $request) {
            $source = PluginAssignatureSource::get((int) $request['sources_id']);
            $payload = json_decode((string) ($request['payload_json'] ?? ''), true);
            $machines = is_array($payload['machines'] ?? null) ? $payload['machines'] : [];
            $labels = [];
            foreach ($machines as $machine) {
                if (is_array($machine)) {
                    $labels[] = trim((string) ($machine['label'] ?? $machine['model_name'] ?? ''));
                }
            }
            $description = trim(implode(', ', array_filter($labels)) . ' — ' . (string) $request['title'], " —");
            $html .= '<tr><td>' . assignature_h($request['code']) . '</td><td>'
                . assignature_h($source['name'] ?? '') . '</td><td>' . assignature_h($description) . '</td></tr>';
        }
        $html .= '</table><br><br><br><br><p align="center">________________________________________________</p>';
        $html .= '<p align="center"><b>Assinatura de quem retira</b></p>';
        $html .= '<p style="font-size:9px">Termo #' . $batchId . ' gerado pelo Assignat-ure. Após a assinatura, digitalize e envie o arquivo no mesmo lote.</p>';

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Assignat-ure');
        $pdf->SetTitle('Termo de retirada #' . $batchId);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(17, 18, 17);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->writeHTML($html, true, false, true, false, '');
        $temp = tempnam(GLPI_PLUGIN_DOC_DIR . '/assignature', 'print-');
        if ($temp === false) {
            throw new RuntimeException('Não foi possível preparar o arquivo de impressão.');
        }
        try {
            $content = $pdf->Output('termo.pdf', 'S');
            if (!is_string($content) || !str_starts_with($content, '%PDF-')
                || file_put_contents($temp, $content) === false) {
                throw new RuntimeException('Não foi possível gerar o PDF para impressão.');
            }
            $pipes = [];
            $process = proc_open(['/usr/bin/lp', '-d', $queue, '-o', 'media=A4', $temp],
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
            return trim((string) $output);
        } finally {
            @unlink($temp);
        }
    }
}
