<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

function assignature_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Nome de exibição de um usuário do GLPI: primeiro nome e depois sobrenome; sem nenhum dos dois, o login. */
function assignature_user_name(User $user): string
{
    $name = trim(trim((string) ($user->fields['firstname'] ?? '')) . ' ' . trim((string) ($user->fields['realname'] ?? '')));

    return $name !== '' ? $name : trim((string) ($user->fields['name'] ?? ''));
}

function assignature_url(string $path = ''): string
{
    global $CFG_GLPI;
    return (string) $CFG_GLPI['root_doc'] . '/plugins/assignature' . $path;
}

function assignature_logo_url(): string
{
    return assignature_url('/logo.png?v=' . (int) @filemtime(dirname(__DIR__) . '/public/logo.png'));
}

function assignature_now(): string
{
    return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
}

function assignature_csrf_field(): string
{
    return Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
}

function assignature_flash(string $message, bool $success = true): void
{
    Session::addMessageAfterRedirect($message, $success, $success ? INFO : ERROR);
}

/** Exibe um erro dentro do GLPI sem depender de métodos removidos do Html. */
function assignature_abort(string $message, int $status = 404): never
{
    http_response_code($status);
    Html::header('Assignat-ure', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginAssignatureApp');
    echo '<main class="as-page"><section class="as-panel">'
        . '<h1>Não foi possível abrir esta página</h1>'
        . '<p>' . assignature_h($message) . '</p>'
        . '<a class="as-button as-button-light" href="'
        . assignature_h(assignature_url('/front/index.php?status=pending'))
        . '">Voltar às assinaturas</a>'
        . '</section></main>';
    Html::footer();
    exit;
}
