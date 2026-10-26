<?php
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/batch.class.php';
require_once GLPI_ROOT . '/plugins/assignature/inc/technician.class.php';

if (!PluginAssignatureApp::canAdmin()) {
    Html::displayErrorAndDie('Sem permissão para administrar o Assignat-ure.');
}
global $DB;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'source_save') {
            $id = PluginAssignatureSource::save($_POST);
            try {
                $count = PluginAssignatureRequest::syncSource(PluginAssignatureSource::get($id));
                assignature_flash('Fonte salva. ' . $count . ' pedido(s) sincronizado(s).');
            } catch (Throwable $e) {
                assignature_flash('Fonte salva, mas a sincronização falhou: ' . $e->getMessage(), false);
            }
        } elseif ($action === 'source_sync') {
            $source = PluginAssignatureSource::get((int) ($_POST['source_id'] ?? 0));
            if ($source === null) {
                throw new RuntimeException('Fonte não encontrada.');
            }
            $count = PluginAssignatureRequest::syncSource($source);
            assignature_flash($count . ' pedido(s) sincronizado(s).');
        } elseif ($action === 'scope_save') {
            PluginAssignatureScope::save(
                (int) ($_POST['users_id'] ?? 0),
                (int) ($_POST['source_id'] ?? 0),
                !empty($_POST['see_pending']),
                !empty($_POST['see_done']),
                !empty($_POST['can_sign'])
            );
            assignature_flash('Acesso do usuário salvo.');
        } elseif ($action === 'callback_retry') {
            PluginAssignatureRequest::sendCallback((int) ($_POST['request_id'] ?? 0));
            assignature_flash('Confirmação enviada para a origem.');
        } elseif ($action === 'printer_save') {
            PluginAssignatureBatch::setPrinterQueue((string) ($_POST['printer_queue'] ?? ''));
            assignature_flash('Fila de impressão salva.');
        } elseif ($action === 'technician_save') {
            PluginAssignatureTechnician::save($_POST);
            assignature_flash('Assinatura do técnico salva.');
        } else {
            throw new RuntimeException('Ação desconhecida.');
        }
    } catch (Throwable $e) {
        assignature_flash($e->getMessage(), false);
    }
    Html::redirect(assignature_url('/front/admin.php'));
}

$sources = PluginAssignatureSource::all();
$technicians = PluginAssignatureTechnician::all();
$editTechnician = PluginAssignatureTechnician::get((int) ($_GET['technician'] ?? 0));
$editTechnicianSources = $editTechnician !== null
    ? PluginAssignatureTechnician::sourceIds((int) $editTechnician['id']) : [];
$editSource = PluginAssignatureSource::get((int) ($_GET['source'] ?? 0));
$editUserId = max(0, (int) ($_GET['user'] ?? 0));
$editScopeSourceId = max(0, (int) ($_GET['scope_source'] ?? 0));
$editScope = $editUserId > 0 && $editScopeSourceId > 0
    ? (PluginAssignatureScope::forUser($editUserId)[$editScopeSourceId] ?? null)
    : null;
$scopes = [];
foreach ($DB->request(['FROM' => PluginAssignatureScope::TABLE, 'ORDER' => 'users_id ASC']) as $row) {
    $scopes[] = $row;
}
$pendingCallbacks = [];
foreach ($DB->request([
    'SELECT' => ['id', 'code', 'callback_state', 'callback_error'],
    'FROM' => PluginAssignatureRequest::TABLE,
    'WHERE' => ['status' => 'done', 'callback_state' => ['pending', 'failed']],
    'LIMIT' => 50,
]) as $row) {
    $pendingCallbacks[] = $row;
}
Html::header('Assignat-ure', $_SERVER['PHP_SELF'], 'tools', 'PluginAssignatureApp');
echo "<script>document.title = 'Assignat-ure';</script>";
?>
<main class="as-page">
    <header class="as-page-head">
        <div class="as-head-brand"><img src="<?= assignature_h(assignature_logo_url()) ?>" alt="" class="as-head-logo"><div><p class="as-eyebrow">[URE] Assignat-ure</p><h1>Administração</h1><p>Conecte plugins, configure acessos e cadastre assinaturas dos técnicos.</p></div></div>
        <a class="as-button as-button-light" href="<?= assignature_h(assignature_url('/front/index.php')) ?>">Voltar às assinaturas</a>
    </header>

    <div class="as-admin-grid">
        <section class="as-panel">
            <div class="as-section-title"><h2>Fontes conectadas</h2><span><?= count($sources) ?></span></div>
            <?php if ($sources === []): ?><p class="as-empty-inline">Nenhuma fonte cadastrada. Comece pelo Tralla.</p><?php endif; ?>
            <?php foreach ($sources as $source): ?>
                <div class="as-source-row">
                    <div><strong><?= assignature_h($source['name']) ?></strong><small><?= assignature_h($source['slug']) ?> · <?= assignature_h($source['adapter']) ?></small><small><?= assignature_h($source['endpoint_url']) ?></small>
                        <?php if (!empty($source['last_sync'])): ?><small>Última tentativa: <?= assignature_h($source['last_sync']) ?></small><?php endif; ?>
                        <?php if ((string) ($source['last_error'] ?? '') !== ''): ?><small class="as-error"><?= assignature_h($source['last_error']) ?></small><?php endif; ?></div>
                    <div class="as-inline-actions"><a class="as-button as-button-light as-button-small" href="?source=<?= (int) $source['id'] ?>">Editar</a>
                        <form method="post"><input type="hidden" name="action" value="source_sync"><input type="hidden" name="source_id" value="<?= (int) $source['id'] ?>"><?= assignature_csrf_field() ?><button class="as-button as-button-small">Sincronizar</button></form></div>
                </div>
            <?php endforeach; ?>
            <form method="post" class="as-form as-separated">
                <?= assignature_csrf_field() ?><input type="hidden" name="action" value="source_save"><input type="hidden" name="id" value="<?= (int) ($editSource['id'] ?? 0) ?>">
                <h3><?= $editSource ? 'Editar fonte' : 'Adicionar fonte' ?></h3>
                <div class="as-form-pair"><label>Identificador<input name="slug" required pattern="[a-z][a-z0-9_]+" maxlength="64" placeholder="tralla" value="<?= assignature_h($editSource['slug'] ?? '') ?>"></label>
                    <label>Nome<input name="name" data-uppercase required maxlength="150" placeholder="TRALLA" value="<?= assignature_h($editSource['name'] ?? '') ?>"></label></div>
                <label>Integração<select name="adapter"><option value="tralla" <?= ($editSource['adapter'] ?? 'tralla') === 'tralla' ? 'selected' : '' ?>>Tralla</option><option value="generic" <?= ($editSource['adapter'] ?? '') === 'generic' ? 'selected' : '' ?>>Genérica (Protocolo/futuros)</option></select></label>
                <label>URL do endpoint<input type="url" name="endpoint_url" required maxlength="500" placeholder="https://glpi.exemplo.com/plugins/tralla/front/assignature.php" value="<?= assignature_h($editSource['endpoint_url'] ?? '') ?>"></label>
                <label>Token de acesso<input type="password" name="token" <?= $editSource ? '' : 'required' ?> autocomplete="new-password" placeholder="<?= $editSource ? 'Deixe vazio para manter o atual' : 'Cole o token exibido no plugin de origem' ?>"></label>
                <label class="as-checkbox"><input type="checkbox" name="is_active" value="1" <?= !$editSource || (int) $editSource['is_active'] === 1 ? 'checked' : '' ?>> Fonte ativa</label>
                <button class="as-button">Salvar fonte</button>
            </form>
        </section>

        <section class="as-panel">
            <div class="as-section-title"><h2>Acesso por usuário</h2></div>
            <p class="as-hint">O perfil do GLPI precisa ter a permissão “Visualizar o plugin”. Aqui você escolhe a fonte, os estados visíveis e quem pode coletar a assinatura.</p>
            <form method="post" class="as-form">
                <?= assignature_csrf_field() ?><input type="hidden" name="action" value="scope_save">
                <label>Usuário<?php User::dropdown(['name' => 'users_id', 'right' => 'all', 'display_emptychoice' => true, 'value' => $editUserId]); ?></label>
                <label>Fonte<select name="source_id" required><option value="">Selecione</option><?php foreach ($sources as $source): ?><option value="<?= (int) $source['id'] ?>" <?= $editScopeSourceId === (int) $source['id'] ? 'selected' : '' ?>><?= assignature_h($source['name']) ?></option><?php endforeach; ?></select></label>
                <div class="as-checks"><label><input type="checkbox" name="see_pending" value="1" <?= !empty($editScope['see_pending']) ? 'checked' : '' ?>> Ver pendentes</label><label><input type="checkbox" name="see_done" value="1" <?= !empty($editScope['see_done']) ? 'checked' : '' ?>> Ver concluídas</label><label><input type="checkbox" name="can_sign" value="1" <?= !empty($editScope['can_sign']) ? 'checked' : '' ?>> Pode assinar</label></div>
                <button class="as-button" <?= $sources === [] ? 'disabled' : '' ?>>Salvar acesso</button>
            </form>
            <?php if ($scopes !== []): ?>
                <div class="as-scopes"><h3>Acessos configurados</h3>
                <?php foreach ($scopes as $scope):
                    $user = new User();
                    $userName = $user->getFromDB((int) $scope['users_id']) ? $user->getFriendlyName() : '#' . $scope['users_id'];
                    $sourceName = '#' . $scope['sources_id'];
                    foreach ($sources as $source) { if ((int) $source['id'] === (int) $scope['sources_id']) { $sourceName = (string) $source['name']; break; } }
                    ?><div class="as-scope-row"><div><strong><?= assignature_h($userName) ?></strong><small><?= assignature_h($sourceName) ?></small></div><span><?= (int) $scope['see_pending'] ? 'Pendentes ' : '' ?><?= (int) $scope['see_done'] ? 'Concluídas ' : '' ?><?= (int) $scope['can_sign'] ? '· Assina' : '' ?></span><a class="as-button as-button-light as-button-small" href="?user=<?= (int) $scope['users_id'] ?>&amp;scope_source=<?= (int) $scope['sources_id'] ?>">Editar</a></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <section class="as-panel as-technician-settings">
        <div class="as-section-title"><h2>Assinaturas de técnicos e funcionários</h2><span><?= count($technicians) ?></span></div>
        <p class="as-hint">Cadastre a identificação e a assinatura uma vez. Selecione os plugins onde este técnico pode aparecer. Em lotes com mais de uma origem, somente técnicos liberados para todas elas serão exibidos.</p>
        <?php foreach ($technicians as $technician):
            $linked = PluginAssignatureTechnician::sourceIds((int) $technician['id']);
            $linkedNames = [];
            foreach ($sources as $source) { if (in_array((int) $source['id'], $linked, true)) { $linkedNames[] = (string) $source['name']; } }
            ?><div class="as-source-row"><div><strong><?= assignature_h($technician['full_name']) ?><?= (int) $technician['is_active'] === 1 ? '' : ' · Inativo' ?></strong><small><?= assignature_h($technician['document_type']) ?> · <?= assignature_h(implode(', ', $linkedNames)) ?></small></div><a class="as-button as-button-light as-button-small" href="?technician=<?= (int) $technician['id'] ?>#tecnico-form">Editar</a></div><?php endforeach; ?>
        <form method="post" class="as-form as-separated" id="tecnico-form" data-technician-form>
            <?= assignature_csrf_field() ?><input type="hidden" name="action" value="technician_save"><input type="hidden" name="id" value="<?= (int) ($editTechnician['id'] ?? 0) ?>"><input type="hidden" name="signature_png" data-technician-signature>
            <h3><?= $editTechnician !== null ? 'Editar técnico ou funcionário' : 'Cadastrar técnico ou funcionário' ?></h3>
            <label>Nome completo<input name="full_name" data-uppercase maxlength="255" required value="<?= assignature_h($editTechnician['full_name'] ?? '') ?>" placeholder="NOME E SOBRENOME"></label>
            <div class="as-form-pair"><label>Tipo de documento<select name="document_type" required><option value="">Selecione</option><option value="CPF" <?= ($editTechnician['document_type'] ?? '') === 'CPF' ? 'selected' : '' ?>>CPF</option><option value="RG" <?= ($editTechnician['document_type'] ?? '') === 'RG' ? 'selected' : '' ?>>RG</option></select></label><label>Número do documento<input name="document" data-document-input autocomplete="off" inputmode="numeric" <?= $editTechnician === null ? 'required' : '' ?> placeholder="<?= $editTechnician !== null ? 'Deixe vazio para manter o atual' : '000.000.000-00' ?>"></label></div>
            <fieldset class="as-source-fieldset"><legend>Plugins onde esta assinatura aparece</legend><div class="as-checks"><?php foreach ($sources as $source): ?><label><input type="checkbox" name="sources[]" value="<?= (int) $source['id'] ?>" <?= in_array((int) $source['id'], $editTechnicianSources, true) ? 'checked' : '' ?>> <?= assignature_h($source['name']) ?></label><?php endforeach; ?></div></fieldset>
            <div class="as-signature-field"><span>Assinatura do técnico</span><?php if ($editTechnician !== null): ?><p class="as-hint">Assinatura atual. Desenhe abaixo apenas para substituí-la.</p><div class="as-technician-preview"><img src="data:image/png;base64,<?= assignature_h($editTechnician['signature_png']) ?>" alt="Assinatura atual do técnico"></div><?php endif; ?><canvas data-technician-canvas aria-label="Área para desenhar a assinatura do técnico"></canvas><button type="button" class="as-text-button" data-technician-clear>Limpar desenho</button></div>
            <label class="as-checkbox"><input type="checkbox" name="is_active" value="1" <?= $editTechnician === null || (int) $editTechnician['is_active'] === 1 ? 'checked' : '' ?>> Cadastro ativo</label>
            <button class="as-button" <?= $sources === [] ? 'disabled' : '' ?>>Salvar assinatura</button>
        </form>
    </section>
    <section class="as-panel as-printer-settings"><h2>Impressão direta no servidor</h2><p class="as-hint">Fila CUPS da impressora HP no Ubuntu do GLPI. Ao confirmar “Assinar depois”, o termo em PDF é enviado para esta fila.</p><form method="post" class="as-form as-printer-form"><?= assignature_csrf_field() ?><input type="hidden" name="action" value="printer_save"><label>Nome da fila CUPS<input name="printer_queue" required maxlength="100" value="<?= assignature_h(PluginAssignatureBatch::printerQueue()) ?>" placeholder="HP_PeB"></label><button class="as-button" type="submit">Salvar impressora</button></form></section>

    <?php if ($pendingCallbacks !== []): ?><section class="as-panel as-callbacks"><h2>Confirmações aguardando a origem</h2>
        <?php foreach ($pendingCallbacks as $request): ?><div class="as-source-row"><div><strong><?= assignature_h($request['code']) ?></strong><small><?= assignature_h($request['callback_error'] ?: 'Aguardando envio') ?></small></div><form method="post"><?= assignature_csrf_field() ?><input type="hidden" name="action" value="callback_retry"><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><button class="as-button as-button-small">Tentar novamente</button></form></div><?php endforeach; ?>
    </section><?php endif; ?>
</main>
<?php Html::footer(); ?>
