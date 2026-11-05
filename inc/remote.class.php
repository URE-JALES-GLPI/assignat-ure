<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/batch.class.php';

/**
 * Assinatura por link (Google Apps Script, ver appscript/README.md).
 * O GLPI é interno: é sempre ele que chama o script, nunca o contrário.
 */
class PluginAssignatureRemote
{
    private const SETTINGS = 'glpi_plugin_assignature_settings';
    public const LINK_DAYS = 7;
    /** Página de assinatura (docs/assinar): abre mesmo com conta Google conectada no navegador. */
    private const SIGN_PAGE = 'https://ure-jales-glpi.github.io/assignat-ure/assinar/';

    private static function settings(): array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::SETTINGS, 'LIMIT' => 1])->current();
        return is_array($row) ? $row : [];
    }

    public static function url(): string
    {
        return (string) (self::settings()['appscript_url'] ?? '');
    }

    private static function secret(): string
    {
        $encrypted = (string) (self::settings()['appscript_secret_encrypted'] ?? '');
        return $encrypted === '' ? '' : (string) (new GLPIKey())->decrypt($encrypted);
    }

    public static function configured(): bool
    {
        return self::url() !== '' && self::secret() !== '';
    }

    public static function validUrl(string $url): bool
    {
        return preg_match('~^https://script\.google\.com/(a/macros/[A-Za-z0-9.-]+/|macros/)s/[A-Za-z0-9_-]+/exec$~D', $url) === 1;
    }

    /** Segredo em branco mantém o atual; URL em branco desliga a opção. */
    public static function saveSettings(string $url, string $secret): void
    {
        global $DB;
        $url = trim($url);
        if ($url !== '' && !self::validUrl($url)) {
            throw new RuntimeException('Use a URL do App da Web, que começa com https://script.google.com e termina em /exec.');
        }
        $values = ['appscript_url' => $url];
        if (trim($secret) !== '') {
            $values['appscript_secret_encrypted'] = (new GLPIKey())->encrypt(trim($secret));
        }
        $row = $DB->request(['SELECT' => ['id'], 'FROM' => self::SETTINGS, 'LIMIT' => 1])->current();
        if (is_array($row)) {
            $DB->update(self::SETTINGS, $values, ['id' => (int) $row['id']]);
        } else {
            $DB->insert(self::SETTINGS, $values);
        }
    }

    /** O token e o script vão depois do #: o navegador não envia essa parte ao GitHub. */
    private static function signLink(string $token): string
    {
        $script = preg_replace('~^https://script\.google\.com/|/exec$~D', '', self::url());
        return self::SIGN_PAGE . '#s=' . $script . '&t=' . rawurlencode($token);
    }

    public static function test(): void
    {
        self::call('ping');
    }

    private static function call(string $action, array $payload = []): array
    {
        if (!self::configured()) {
            throw new RuntimeException('Configure a URL e o segredo do Apps Script na administração.');
        }
        $handle = curl_init(self::url());
        if ($handle === false) {
            throw new RuntimeException('Não foi possível iniciar a conexão com o Google.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['secret' => self::secret(), 'action' => $action] + $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            // O Apps Script responde com um redirecionamento para o resultado.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false) {
            throw new RuntimeException('Conexão com o Apps Script falhou: ' . $error);
        }
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            throw new RuntimeException('O Apps Script não respondeu como esperado. Confira a URL e se ele foi publicado para "qualquer pessoa".');
        }
        if (empty($data['ok'])) {
            throw new RuntimeException('Apps Script: ' . mb_substr((string) ($data['error'] ?? 'erro desconhecido'), 0, 200));
        }
        return $data;
    }

    /** Mesmo conteúdo do termo impresso, sem documentos nem assinaturas. */
    private static function term(array $batch, array $requests): array
    {
        $context = PluginAssignatureBatch::termContext($requests);
        return [
            'batch_id' => (int) $batch['id'],
            'date' => PluginAssignatureBatch::formatDate((string) $batch['created_at'], true),
            'technician' => PluginAssignatureBatch::technicianName($batch),
            'sources' => (string) $context['sources'],
            'boards' => (string) $context['boards'],
            'rows' => array_map(static fn (array $row): array => [
                'code' => (string) $row['code'], 'name' => (string) $row['name'],
                'status' => (string) $row['status'], 'report' => (string) $row['report'],
            ], PluginAssignatureBatch::termRows($requests)),
        ];
    }

    /** Publica o termo no script e envia o link. Reenviar renova a validade e mantém o link. */
    public static function send(int $batchId): void
    {
        global $DB;
        $batch = PluginAssignatureBatch::get($batchId);
        if ($batch === null || (string) $batch['status'] !== 'awaiting_remote') {
            throw new RuntimeException('Este termo não está aguardando assinatura por e-mail.');
        }
        try {
            $term = self::term($batch, PluginAssignatureBatch::requests($batchId));
            $expires = time() + self::LINK_DAYS * 86400;
            $result = self::call('create', [
                'token' => (string) $batch['remote_token'], 'expires_at' => $expires, 'term' => $term,
            ]);
            if (!empty($result['already_signed'])) {
                return;
            }
            $link = self::signLink((string) $batch['remote_token']);
            self::mail([(string) $batch['signer_email']], [], 'Assinatura do termo de entrega',
                self::layout('Termo aguardando sua assinatura',
                    '<p>Você recebeu um termo de entrega de equipamentos para conferir e assinar.</p>'
                    . self::items($term['rows'])
                    . '<p style="margin:24px 0;text-align:center"><a href="' . assignature_h($link) . '" style="background:#f18900;border-radius:6px;color:#ffffff;display:inline-block;font-weight:bold;padding:12px 24px;text-decoration:none">Conferir e assinar</a></p>'
                    . '<p style="color:#6b778c;font-size:13px">O link vale até ' . assignature_h(date('d/m/Y H:i', $expires)) . ' e só pode ser usado uma vez. Se você não esperava este e-mail, ignore esta mensagem.</p>'),
                "Termo de entrega #{$batchId} aguardando sua assinatura.\nConferir e assinar: {$link}\nO link vale até " . date('d/m/Y H:i', $expires) . '.');
            $DB->update(PluginAssignatureBatch::TABLE, [
                'remote_expires_at' => date('Y-m-d H:i:s', $expires),
                'remote_term_sha256' => hash('sha256', json_encode($term, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'remote_error' => '',
            ], ['id' => $batchId]);
        } catch (Throwable $e) {
            $DB->update(PluginAssignatureBatch::TABLE, ['remote_error' => mb_substr($e->getMessage(), 0, 500)], ['id' => $batchId]);
            throw $e;
        }
    }

    /** Tira o pedido do Drive. Falha aqui não impede cancelar: o link expira sozinho. */
    public static function remove(string $token): void
    {
        try {
            self::call('delete', ['tokens' => [$token]]);
        } catch (Throwable $e) {
            error_log('[Assignat-ure] Apps Script: ' . $e->getMessage());
        }
    }

    /** Busca assinaturas, conclui os termos, libera os vencidos e envia comprovantes. */
    public static function sync(): int
    {
        global $DB;
        if (!self::configured()) {
            return 0;
        }
        $count = 0;
        $done = [];
        foreach ((array) (self::call('signed')['signed'] ?? []) as $row) {
            $token = is_array($row) ? (string) ($row['token'] ?? '') : '';
            if (!preg_match('/^[a-f0-9]{48}$/D', $token)) {
                continue;
            }
            $batch = $DB->request([
                'FROM' => PluginAssignatureBatch::TABLE, 'WHERE' => ['remote_token' => $token], 'LIMIT' => 1,
            ])->current();
            // Termo cancelado ou já concluído: só limpa o Drive.
            if (!is_array($batch) || (string) $batch['status'] !== 'awaiting_remote') {
                $done[] = $token;
                continue;
            }
            try {
                PluginAssignatureBatch::completeRemote($batch, $row);
                $done[] = $token;
                $count++;
            } catch (Throwable $e) {
                // Fica no Drive e aparece no termo até ser resolvido ou cancelado.
                $DB->update(PluginAssignatureBatch::TABLE, ['remote_error' => mb_substr($e->getMessage(), 0, 500)], ['id' => (int) $batch['id']]);
            }
        }
        if ($done !== []) {
            self::call('ack', ['tokens' => $done]);
        }
        foreach ($DB->request([
            'FROM' => PluginAssignatureBatch::TABLE,
            'WHERE' => ['status' => 'awaiting_remote', 'remote_expires_at' => ['<', assignature_now()]],
        ]) as $batch) {
            PluginAssignatureBatch::release((int) $batch['id']);
            self::remove((string) $batch['remote_token']);
            $count++;
        }
        foreach ($DB->request([
            'FROM' => PluginAssignatureBatch::TABLE,
            'WHERE' => [
                'status' => 'done', 'signer_email' => ['!=', ''], 'remote_receipt_sent_at' => null,
                'completed_at' => ['>', date('Y-m-d H:i:s', time() - 7 * 86400)],
            ],
            'LIMIT' => 20,
        ]) as $batch) {
            try {
                self::sendReceipt($batch);
                $count++;
            } catch (Throwable $e) {
                $DB->update(PluginAssignatureBatch::TABLE, ['remote_error' => mb_substr('Comprovante não enviado: ' . $e->getMessage(), 0, 500)], ['id' => (int) $batch['id']]);
            }
        }
        return $count;
    }

    private static function sendReceipt(array $batch): void
    {
        global $DB;
        $batchId = (int) $batch['id'];
        $requests = PluginAssignatureBatch::requests($batchId);
        $signature = base64_decode((string) $batch['signature_png'], true);
        if ($signature === false) {
            throw new RuntimeException('Assinatura inválida.');
        }
        $term = PluginAssignatureBatch::termPdf($batch, $requests);
        $signedAt = PluginAssignatureBatch::formatDate((string) $batch['completed_at'], true);
        $hash = (string) $batch['remote_term_sha256'];
        $cc = [];
        $creator = new User();
        if ($creator->getFromDB((int) $batch['created_by_users_id'])) {
            $cc[] = trim((string) $creator->getDefaultEmail());
        }
        $fact = static fn (string $label, string $value): string => '<tr><td style="color:#6b778c;padding:3px 12px 3px 0;vertical-align:top">'
            . assignature_h($label) . '</td><td style="padding:3px 0">' . assignature_h($value) . '</td></tr>';
        self::mail([(string) $batch['signer_email']], array_values(array_filter($cc)),
            'Termo de entrega assinado',
            self::layout('Termo assinado',
                '<p>O termo de entrega foi assinado. A cópia completa está em anexo, em PDF.</p>'
                . '<table cellpadding="0" cellspacing="0" style="font-size:14px;margin:12px 0">'
                . $fact('Termo:', '#' . $batchId)
                . $fact('Assinado por:', (string) $batch['signer_name'])
                . $fact('E-mail do link:', (string) $batch['signer_email'])
                . $fact('Data e hora:', $signedAt)
                . $fact('Técnico:', PluginAssignatureBatch::technicianName($batch))
                . '</table>'
                . self::items(PluginAssignatureBatch::termRows($requests))
                . '<p style="margin:16px 0 4px"><strong>Assinatura</strong></p>'
                . '<img src="cid:assinatura" alt="Assinatura" width="320" style="border:1px solid #dfe1e6;border-radius:6px;display:block;max-width:100%">'
                . '<p style="color:#6b778c;font-size:12px;overflow-wrap:anywhere">Código de verificação do termo (SHA-256): ' . assignature_h($hash) . '</p>'),
            "Termo de entrega #{$batchId} assinado por {$batch['signer_name']} em {$signedAt}.\nCódigo de verificação (SHA-256): {$hash}\nA cópia completa está no PDF em anexo.",
            ['content' => $term['content'], 'name' => 'termo-assinado-' . $batchId . '.pdf'],
            $signature);
        $DB->update(PluginAssignatureBatch::TABLE, [
            'remote_receipt_sent_at' => assignature_now(), 'remote_error' => '',
        ], ['id' => $batchId]);
    }

    private static function items(array $rows): string
    {
        $html = '<p style="margin:16px 0 4px"><strong>Equipamentos</strong></p><ul style="margin:0;padding-left:20px">';
        foreach ($rows as $row) {
            $html .= '<li>' . assignature_h($row['name']) . ' <span style="color:#6b778c">#' . assignature_h($row['code']) . '</span></li>';
        }
        return $html . '</ul>';
    }

    /** Padrão de e-mail da URE: caixa branca de 600px, faixa laranja e rodapé escuro. */
    private static function layout(string $title, string $content): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" style="background:#ebeff2;font-family:Arial,sans-serif"><tr><td align="center" style="padding:24px 12px">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;color:#172b4d;font-size:14px;line-height:1.5;max-width:600px;text-align:left">'
            . '<tr><td style="border-bottom:4px solid #f18900;padding:20px 24px"><div style="color:#6b778c;font-size:11px;font-weight:bold">[URE] ASSIGNAT-URE</div><div style="font-size:20px;font-weight:bold">' . assignature_h($title) . '</div></td></tr>'
            . '<tr><td style="padding:20px 24px">' . $content . '</td></tr>'
            . '<tr><td style="background:#454d55;border-radius:0 0 8px 8px;color:#ffffff;font-size:12px;padding:12px 24px">Unidade Regional de Ensino de Jales</td></tr>'
            . '</table></td></tr></table>';
    }

    private static function mail(array $to, array $cc, string $subject, string $html, string $text,
        ?array $attachment = null, ?string $signature = null): void
    {
        global $CFG_GLPI;
        $from = trim((string) ($CFG_GLPI['from_email'] ?? '')) ?: trim((string) ($CFG_GLPI['admin_email'] ?? ''));
        if ($from === '') {
            throw new RuntimeException('Configure o e-mail remetente nas notificações do GLPI.');
        }
        $mailer = new GLPIMailer();
        $email = $mailer->getEmail();
        $email->from(new \Symfony\Component\Mime\Address($from, 'URE Assignat-ure'));
        $email->to(...$to);
        if ($cc !== []) {
            $email->cc(...$cc);
        }
        $email->subject('[URE Jales] ' . $subject);
        $email->html($html);
        $email->text($text);
        if ($attachment !== null) {
            $email->attach($attachment['content'], $attachment['name'], 'application/pdf');
        }
        if ($signature !== null) {
            $email->embed($signature, 'assinatura', 'image/png');
        }
        if (!$mailer->send()) {
            throw new RuntimeException('O e-mail não foi enviado: ' . (string) $mailer->getError());
        }
    }
}
