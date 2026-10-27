<?php
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/profile.class.php';

Session::checkRight('profile', UPDATE);
$id = (int) ($_POST['profiles_id'] ?? 0);
$profile = new Profile();
if ($id <= 0 || !$profile->getFromDB($id)) {
    assignature_abort('Perfil inválido.');
}

if (isset($_POST['save'])) {
    $admin = !empty($_POST[PluginAssignatureProfile::RIGHT_ADMIN]);
    $view = $admin || !empty($_POST[PluginAssignatureProfile::RIGHT_VIEW]);
    PluginAssignatureProfile::setRight($id, PluginAssignatureProfile::RIGHT_VIEW, $view ? READ : 0);
    PluginAssignatureProfile::setRight($id, PluginAssignatureProfile::RIGHT_ADMIN, $admin ? READ : 0);
    if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === $id) {
        PluginAssignatureProfile::changeProfile();
    }
    assignature_flash('Permissões do Assignat-ure atualizadas.');
}
Html::redirect((string) $CFG_GLPI['root_doc'] . '/front/profile.form.php?id=' . $id);
