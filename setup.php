<?php
/** [URE] Assignat-ure — central de assinaturas para GLPI 12. */

require_once __DIR__ . '/inc/profile.class.php';
require_once __DIR__ . '/inc/app.class.php';

define('PLUGIN_ASSIGNATURE_VERSION', '0.4.0');

function plugin_init_assignature(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass('PluginAssignatureProfile', ['addtabon' => 'Profile']);
    Plugin::registerClass('PluginAssignatureApp');

    if (Session::haveRight(PluginAssignatureProfile::RIGHT_VIEW, READ)) {
        $PLUGIN_HOOKS['menu_toadd']['assignature'] = ['tools' => 'PluginAssignatureApp'];
    }
    $PLUGIN_HOOKS['change_profile']['assignature'] = ['PluginAssignatureProfile', 'changeProfile'];
    $PLUGIN_HOOKS['config_page']['assignature'] = 'front/admin.php';
    $PLUGIN_HOOKS['secured_fields']['assignature'] = [
        'glpi_plugin_assignature_sources.token_encrypted',
        'glpi_plugin_assignature_batches.document_encrypted',
        'glpi_plugin_assignature_batches.technician_document_encrypted',
        'glpi_plugin_assignature_technicians.document_encrypted',
    ];
    $versioned = static function (string $path): string {
        $mtime = @filemtime(__DIR__ . '/' . $path);
        return $path . '?v=' . ($mtime ?: PLUGIN_ASSIGNATURE_VERSION);
    };
    $PLUGIN_HOOKS['add_css']['assignature'] = [$versioned('public/css/assignature.css')];
    $PLUGIN_HOOKS['add_javascript']['assignature'] = [$versioned('public/js/assignature.js')];
}

function plugin_version_assignature(): array
{
    return [
        'name' => '[URE] Assignat-ure',
        'version' => PLUGIN_ASSIGNATURE_VERSION,
        'author' => 'URE',
        'license' => 'GPLv3+',
        'homepage' => '',
        'requirements' => [
            'glpi' => ['min' => '12.0.0', 'max' => '12.0.99'],
            'php' => [
                'min' => '8.2.0',
                'exts' => ['curl' => ['required' => true, 'function' => 'curl_init']],
            ],
        ],
    ];
}

function plugin_assignature_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, '12.0.0', '<') || version_compare(GLPI_VERSION, '12.0.99', '>')) {
        echo 'O Assignat-ure requer GLPI 12.0.x.';
        return false;
    }
    if (version_compare(PHP_VERSION, '8.2.0', '<') || !extension_loaded('curl')) {
        echo 'O Assignat-ure requer PHP 8.2+ com cURL.';
        return false;
    }
    return true;
}

function plugin_assignature_check_config(): bool
{
    return true;
}
