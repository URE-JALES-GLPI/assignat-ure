<?php
if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}
require_once __DIR__ . '/helpers.php';

class PluginAssignatureProfile extends CommonDBTM
{
    public static string $rightname = 'profile';
    public const RIGHT_VIEW = 'plugin_assignature_view';
    public const RIGHT_ADMIN = 'plugin_assignature_admin';

    public static function install(): void
    {
        global $DB;
        $installerProfile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles']) as $profile) {
            $isSuperAdmin = strcasecmp((string) $profile['name'], 'Super-Admin') === 0;
            $isInstallerAdmin = (int) $profile['id'] === $installerProfile
                && Session::haveRight('config', UPDATE);
            $default = ($isSuperAdmin || $isInstallerAdmin) ? READ : 0;
            self::setIfMissing((int) $profile['id'], self::RIGHT_VIEW, $default);
            self::setIfMissing((int) $profile['id'], self::RIGHT_ADMIN, $default);
        }
        self::changeProfile();
    }

    public static function uninstall(): void
    {
        global $DB;
        $DB->delete(ProfileRight::getTable(), ['name' => [self::RIGHT_VIEW, self::RIGHT_ADMIN]]);
    }

    private static function setIfMissing(int $profileId, string $name, int $rights): void
    {
        if (!countElementsInTable(ProfileRight::getTable(), ['profiles_id' => $profileId, 'name' => $name])) {
            (new ProfileRight())->add(['profiles_id' => $profileId, 'name' => $name, 'rights' => $rights]);
        }
    }

    public static function getRight(int $profileId, string $name): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['rights'], 'FROM' => ProfileRight::getTable(),
            'WHERE' => ['profiles_id' => $profileId, 'name' => $name], 'LIMIT' => 1,
        ])->current();
        return is_array($row) ? (int) $row['rights'] : 0;
    }

    public static function setRight(int $profileId, string $name, int $rights): void
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['id'], 'FROM' => ProfileRight::getTable(),
            'WHERE' => ['profiles_id' => $profileId, 'name' => $name], 'LIMIT' => 1,
        ])->current();
        if (is_array($row)) {
            $DB->update(ProfileRight::getTable(), ['rights' => $rights], ['id' => (int) $row['id']]);
        } else {
            (new ProfileRight())->add(['profiles_id' => $profileId, 'name' => $name, 'rights' => $rights]);
        }
    }

    public static function changeProfile(): void
    {
        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profileId <= 0) {
            return;
        }
        foreach ([self::RIGHT_VIEW, self::RIGHT_ADMIN] as $name) {
            $_SESSION['glpiactiveprofile'][$name] = self::getRight($profileId, $name);
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return $item instanceof Profile ? 'Assignat-ure' : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Profile) || !$item->canView()) {
            return false;
        }
        $id = (int) $item->getID();
        $canEdit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        echo '<form method="post" action="' . assignature_h(assignature_url('/front/profile.form.php')) . '">';
        echo assignature_csrf_field();
        echo '<input type="hidden" name="profiles_id" value="' . $id . '">';
        echo '<div class="spaced"><table class="tab_cadre_fixehov"><tr class="headerRow"><th colspan="2">Assignat-ure — permissões do perfil</th></tr>';
        foreach ([self::RIGHT_VIEW => 'Visualizar o plugin', self::RIGHT_ADMIN => 'Administrar fontes e acessos'] as $right => $label) {
            $value = self::getRight($id, $right) > 0 ? 1 : 0;
            echo '<tr class="tab_bg_1"><td width="50%">' . assignature_h($label) . '</td><td>';
            if ($canEdit) {
                Dropdown::showFromArray($right, [0 => 'Não', 1 => 'Sim'], ['value' => $value]);
            } else {
                echo $value ? 'Sim' : 'Não';
            }
            echo '</td></tr>';
        }
        if ($canEdit) {
            echo '<tr><td colspan="2" class="center"><button class="btn btn-primary" name="save" value="1">Salvar permissões</button></td></tr>';
        }
        echo '</table></div></form>';
        return true;
    }
}
