<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/profile.class.php';

class PluginAssignatureApp extends CommonDBTM
{
    public static string $rightname = PluginAssignatureProfile::RIGHT_VIEW;

    public static function getTypeName($nb = 0)
    {
        return 'Assignat-ure';
    }

    public static function getMenuName()
    {
        return 'Assignat-ure';
    }

    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        $menu['icon'] = 'ti ti-signature';
        $menu['title'] = 'Assignat-ure';
        $menu['page'] = assignature_url('/front/index.php');
        return $menu;
    }

    public static function canView(): bool
    {
        return Session::haveRight(PluginAssignatureProfile::RIGHT_VIEW, READ);
    }

    public static function canAdmin(): bool
    {
        return self::canView() && Session::haveRight(PluginAssignatureProfile::RIGHT_ADMIN, READ);
    }
}
