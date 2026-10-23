<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

function assignature_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function assignature_url(string $path = ''): string
{
    global $CFG_GLPI;
    return (string) $CFG_GLPI['root_doc'] . '/plugins/assignature' . $path;
}

function assignature_now(): string
{
    return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
}

function assignature_csrf_field(): string
{
    return Html::hidden('_glpi_csrf_token', ['value' => '']);
}

function assignature_flash(string $message, bool $success = true): void
{
    Session::addMessageAfterRedirect($message, $success, $success ? INFO : ERROR);
}
