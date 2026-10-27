<?php
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/batch.class.php';
require_once GLPI_ROOT . '/plugins/assignature/inc/technician.class.php';

if (!PluginAssignatureApp::canAdmin()) {
    assignature_abort('Sem permissão para administrar o Assignat-ure.', 403);
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
        } elseif ($action === 'scope_save_multi') {
            $rows = [];
            foreach ((array) ($_POST['sources'] ?? []) as $sid => $on) {
                $sid = (int) $sid;
                if ($sid <= 0 || empty($on)) {
                    continue;
                }
                $rows[$sid] = [
                    'pending' => !empty($_POST['see_pending'][$sid]),
                    'done' => !empty($_POST['see_done'][$sid]),
                    'sign' => !empty($_POST['can_sign'][$sid]),
                ];
            }
            $kept = PluginAssignatureScope::replaceForUser((int) ($_POST['users_id'] ?? 0), $rows);
            assignature_flash($kept > 0
                ? 'Acessos do usuário salvos (' . $kept . ' fonte(s)).'
                : 'Nenhuma fonte liberada: o usuário ficou sem acesso aos pedidos.');
            Html::redirect(assignature_url('/front/admin.php?user=' . (int) ($_POST['users_id'] ?? 0)));
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
            <p class="as-hint">O perfil do GLPI precisa ter a permissão “Visualizar o plugin”. Aqui você escolhe as fontes que o usuário pode ver (uma ou várias), os estados visíveis e quem pode coletar a assinatura.</p>
            <?php $userScopes = $editUserId > 0 ? PluginAssignatureScope::forUser($editUserId) : []; ?>
            <style>
                .as-scope-hint{margin:2px 0 0}
                .as-scope-pick{background:#fafbff;border:1px solid var(--as-line,#e4e9f1);border-radius:12px;display:grid;gap:11px;padding:14px 16px;transition:background .15s,border-color .15s}
                .as-scope-pick:not(.is-off){background:#f3f8ff;border-color:#bcd3f6}
                .as-scope-pick .as-scope-pick-name{align-items:center;cursor:pointer;display:flex;gap:10px;margin:0}
                .as-scope-pick .as-scope-pick-name span{color:var(--as-ink,#17223b);font-size:14px;font-weight:800;letter-spacing:.02em}
                .as-scope-pick .as-scope-pick-flags{display:flex;flex-wrap:wrap;gap:10px 22px;padding-left:28px}
                .as-scope-pick .as-scope-pick-flags label{align-items:center;color:#354968;cursor:pointer;display:inline-flex;font-size:13px;font-weight:600;gap:8px;margin:0}
                .as-scope-pick input[type=checkbox]{flex:none;height:17px;margin:0;width:17px}
                .as-scope-pick.is-off .as-scope-pick-flags{opacity:.55}
                #as-scope-form>button.as-button{margin-top:4px}
            </style>
            <form method="post" class="as-form" id="as-scope-form">
                <?= assignature_csrf_field() ?><input type="hidden" name="action" value="scope_save_multi">
                <label>Usuário<?php User::dropdown(['name' => 'users_id', 'right' => 'all', 'display_emptychoice' => true, 'value' => $editUserId]); ?></label>
                <p class="as-hint as-scope-hint"><?= $editUserId > 0 ? 'Marque todas as fontes que este usuário pode ver. Desmarcar uma fonte remove o acesso dele a ela.' : 'Escolha o usuário para marcar as fontes que ele pode ver.' ?></p>
                <?php foreach ($sources as $source): $sid = (int) $source['id']; $sc = $userScopes[$sid] ?? null; ?>
                    <div class="as-scope-pick <?= $sc !== null ? '' : 'is-off' ?>">
                        <label class="as-scope-pick-name"><input type="checkbox" name="sources[<?= $sid ?>]" value="1" data-source-toggle <?= $sc !== null ? 'checked' : '' ?> <?= $editUserId > 0 ? '' : 'disabled' ?>> <span><?= assignature_h($source['name']) ?></span></label>
                        <div class="as-scope-pick-flags">
                            <label><input type="checkbox" name="see_pending[<?= $sid ?>]" value="1" data-flag="pending" <?= !empty($sc['see_pending']) ? 'checked' : '' ?> <?= $editUserId > 0 ? '' : 'disabled' ?>> Ver pendentes</label>
                            <label><input type="checkbox" name="see_done[<?= $sid ?>]" value="1" data-flag="done" <?= !empty($sc['see_done']) ? 'checked' : '' ?> <?= $editUserId > 0 ? '' : 'disabled' ?>> Ver concluídas</label>
                            <label><input type="checkbox" name="can_sign[<?= $sid ?>]" value="1" data-flag="sign" <?= !empty($sc['can_sign']) ? 'checked' : '' ?> <?= $editUserId > 0 ? '' : 'disabled' ?>> Pode assinar</label>
                        </div>
                    </div>
                <?php endforeach; ?>
                <button class="as-button" <?= ($sources === [] || $editUserId <= 0) ? 'disabled' : '' ?>>Salvar acessos</button>
            </form>
            <script>
            (function () {
                var f = document.getElementById('as-scope-form');
                if (!f) { return; }
                var sel = f.querySelector('[name="users_id"]');
                var cur = <?= (int) $editUserId ?>;
                if (sel) {
                    var go = function () {
                        var v = parseInt(sel.value, 10) || 0;
                        if (v !== cur) { window.location.href = '?user=' + v + '#as-scope-form'; }
                    };
                    if (window.jQuery) { window.jQuery(sel).on('change', go); } else { sel.addEventListener('change', go); }
                }
                f.querySelectorAll('.as-scope-pick').forEach(function (box) {
                    var tg = box.querySelector('[data-source-toggle]');
                    var fl = function (n) { return box.querySelector('[data-flag="' + n + '"]'); };
                    if (!tg) { return; }
                    var refresh = function () { box.classList.toggle('is-off', !tg.checked); };
                    tg.addEventListener('change', function () {
                        if (tg.checked && !fl('pending').checked && !fl('done').checked && !fl('sign').checked) {
                            fl('pending').checked = true; fl('done').checked = true;
                        }
                        if (!tg.checked) { ['pending', 'done', 'sign'].forEach(function (n) { fl(n).checked = false; }); }
                        refresh();
                    });
                    box.querySelectorAll('[data-flag]').forEach(function (c) {
                        c.addEventListener('change', function () {
                            if (c.checked) { tg.checked = true; }
                            if (c.getAttribute('data-flag') === 'sign' && c.checked) { fl('pending').checked = true; }
                            if (!fl('pending').checked && !fl('done').checked) { tg.checked = false; fl('sign').checked = false; }
                            refresh();
                        });
                    });
                });
            })();
            </script>
            <?php if ($scopes !== []): ?>
                <div class="as-scopes"><h3>Acessos configurados</h3>
                <?php
                $byUser = [];
                foreach ($scopes as $scope) { $byUser[(int) $scope['users_id']][] = $scope; }
                foreach ($byUser as $uid => $list):
                    $user = new User();
                    $userName = $user->getFromDB($uid) ? $user->getFriendlyName() : '#' . $uid;
                    $lines = [];
                    foreach ($list as $scope) {
                        $sourceName = '#' . $scope['sources_id'];
                        foreach ($sources as $source) { if ((int) $source['id'] === (int) $scope['sources_id']) { $sourceName = (string) $source['name']; break; } }
                        $flags = array_filter([
                            (int) $scope['see_pending'] ? 'Pendentes' : '',
                            (int) $scope['see_done'] ? 'Concluídas' : '',
                            (int) $scope['can_sign'] ? 'Assina' : '',
                        ]);
                        $lines[] = assignature_h($sourceName) . ' — ' . assignature_h(implode(', ', $flags));
                    }
                    ?><div class="as-scope-row"><div><strong><?= assignature_h($userName) ?></strong><small><?= implode('<br>', $lines) ?></small></div><a class="as-button as-button-light as-button-small" href="?user=<?= (int) $uid ?>#as-scope-form">Editar</a></div><?php endforeach; ?>
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
