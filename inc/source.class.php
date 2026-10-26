<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/helpers.php';

class PluginAssignatureSource
{
    public const TABLE = 'glpi_plugin_assignature_sources';

    public static function all(bool $activeOnly = false): array
    {
        global $DB;
        $query = ['FROM' => self::TABLE, 'ORDER' => 'name ASC'];
        if ($activeOnly) {
            $query['WHERE'] = ['is_active' => 1];
        }
        $sources = [];
        foreach ($DB->request($query) as $row) {
            $sources[] = $row;
        }
        return $sources;
    }

    public static function get(int $id): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return is_array($row) ? $row : null;
    }

    public static function bySlug(string $slug): ?array
    {
        global $DB;
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['slug' => $slug], 'LIMIT' => 1])->current();
        return is_array($row) ? $row : null;
    }

    public static function save(array $data): int
    {
        global $DB;
        $id = (int) ($data['id'] ?? 0);
        $existing = $id > 0 ? self::get($id) : null;
        if ($id > 0 && $existing === null) {
            throw new RuntimeException('Fonte não encontrada.');
        }
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $name = mb_strtoupper(trim((string) ($data['name'] ?? '')), 'UTF-8');
        $adapter = (string) ($data['adapter'] ?? 'generic');
        $url = trim((string) ($data['endpoint_url'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $slug) || $name === '' || mb_strlen($name) > 150) {
            throw new RuntimeException('Informe um identificador e nome válidos.');
        }
        if (!in_array($adapter, ['tralla', 'generic'], true)) {
            throw new RuntimeException('Adaptador inválido.');
        }
        if ($adapter === 'tralla' && $slug !== 'tralla') {
            throw new RuntimeException('Use o identificador tralla para a integração com o Tralla.');
        }
        self::validateUrl($url);
        if ($adapter === 'tralla' && !str_ends_with($url, '/front/assignature.php')) {
            throw new RuntimeException('A URL do Tralla deve terminar em /front/assignature.php.');
        }
        $other = self::bySlug($slug);
        if ($other !== null && (int) $other['id'] !== $id) {
            throw new RuntimeException('Este identificador já está cadastrado.');
        }
        $newToken = trim((string) ($data['token'] ?? ''));
        if ($newToken === '' && $existing === null) {
            throw new RuntimeException('Informe o token de acesso da fonte.');
        }
        $encrypted = (string) ($existing['token_encrypted'] ?? '');
        if ($newToken !== '') {
            $encrypted = (string) (new GLPIKey())->encrypt($newToken);
            if ($encrypted === '') {
                throw new RuntimeException('Não foi possível proteger o token com a chave do GLPI.');
            }
        }
        $values = [
            'slug' => $slug,
            'name' => $name,
            'adapter' => $adapter,
            'endpoint_url' => $url,
            'token_encrypted' => $encrypted,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'date_mod' => assignature_now(),
        ];
        if ($existing !== null) {
            $DB->update(self::TABLE, $values, ['id' => $id]);
            return $id;
        }
        $values['date_creation'] = assignature_now();
        $DB->insert(self::TABLE, $values);
        return (int) $DB->insertId();
    }

    public static function token(array $source): string
    {
        return (string) (new GLPIKey())->decrypt((string) $source['token_encrypted']);
    }

    public static function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Use uma URL HTTP(S) completa do endpoint, sem parâmetros.');
        }
    }

    public static function api(array $source, string $method, array $parameters): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('A extensão cURL do PHP é necessária para sincronizar fontes.');
        }
        $url = (string) $source['endpoint_url'];
        if ($method === 'POST' && (string) $source['adapter'] === 'tralla') {
            $url = preg_replace('~/assignature\.php$~', '/assignature_callback.php', $url);
            if ($url === (string) $source['endpoint_url']) {
                throw new RuntimeException('URL do Tralla deve terminar em /front/assignature.php.');
            }
        } elseif ($method === 'GET') {
            $url .= '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }
        $token = self::token($source);
        if ($token === '') {
            throw new RuntimeException('Token da fonte não pôde ser lido.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir conexão com a fonte.');
        }
        $headerName = (string) $source['adapter'] === 'tralla' ? 'X-Tralla-Token' : 'X-Assignature-Token';
        $headers = ['Accept: application/json', $headerName . ': ' . $token];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($parameters, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || strlen((string) $body) > 2_000_000) {
            throw new RuntimeException('Falha na fonte (HTTP ' . $status . '): ' . ($error ?: 'resposta inválida') . '.');
        }
        $json = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($json) ? (string) ($json['error'] ?? 'resposta inválida') : 'resposta inválida';
            throw new RuntimeException('Falha na fonte (HTTP ' . $status . '): ' . mb_substr($message, 0, 200));
        }
        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            throw new RuntimeException('A fonte não retornou uma resposta JSON válida.');
        }
        return (array) ($json['data'] ?? []);
    }
}
