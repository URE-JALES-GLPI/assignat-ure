<?php
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/batch.class.php';

Session::checkRight(PluginAssignatureProfile::RIGHT_VIEW, READ);
$userId = (int) Session::getLoginUserID();
$batchId = max(0, (int) ($_GET['id'] ?? $_POST['batch_id'] ?? 0));
$selectedIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['ids'] ?? $_POST['ids'] ?? [])))));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $redirectUrl = null;
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $batchId = PluginAssignatureBatch::create(
                $selectedIds, $userId, (int) ($_POST['technician_profiles_id'] ?? 0),
                (string) ($_POST['method'] ?? ''), (string) ($_POST['signer_name'] ?? ''),
                (string) ($_POST['document_type'] ?? ''), (string) ($_POST['signer_document'] ?? ''),
                (string) ($_POST['signature_png'] ?? '')
            );
            $job = PluginAssignatureBatch::printTerm($batchId, $userId);
            assignature_flash((string) ($_POST['method'] ?? '') === 'paper'
                ? 'Termo enviado à impressora (1 cópia). ' . $job . ' Após a assinatura à mão, envie o arquivo.'
                : 'Assinatura registrada e termo enviado à impressora (2 cópias). ' . $job);
        } elseif ($action === 'upload') {
            PluginAssignatureBatch::upload(
                $batchId, $userId, (string) ($_POST['signer_name'] ?? ''),
                (string) ($_POST['document_type'] ?? ''), (string) ($_POST['signer_document'] ?? ''),
                (array) ($_FILES['signed_file'] ?? [])
            );
            assignature_flash('Termo assinado registrado. Todos os pedidos do lote foram concluídos.');
        } elseif ($action === 'cancel') {
            PluginAssignatureBatch::cancel($batchId, $userId);
            assignature_flash('Termo cancelado. Os pedidos voltaram a ficar disponíveis.');
            $redirectUrl = assignature_url('/front/index.php?status=pending');
        } elseif ($action === 'convert_to_digital') {
            $batch = PluginAssignatureBatch::get($batchId);
            if ($batch === null || (string) $batch['method'] !== 'paper'
                || (string) $batch['status'] !== 'awaiting_upload') {
                throw new RuntimeException('Este termo não está aguardando assinatura no papel.');
            }
            $ids = array_map('intval', array_column(PluginAssignatureBatch::requests($batchId), 'id'));
            if ($ids === []) {
                throw new RuntimeException('Nenhum pedido disponível neste termo.');
            }
            PluginAssignatureBatch::cancel($batchId, $userId);
            assignature_flash('O termo impresso foi cancelado. Continue com a assinatura na tela.');
            $redirectUrl = assignature_url('/front/batch.php?' . http_build_query([
                'ids' => $ids, 'method' => 'digital',
            ]));
        } elseif ($action === 'print') {
            $job = PluginAssignatureBatch::printTerm($batchId, $userId);
            assignature_flash('Termo enviado para ' . PluginAssignatureBatch::printerQueue() . '. ' . $job);
        } else {
            throw new RuntimeException('Ação inválida.');
        }
        if ($redirectUrl === null) {
            $updatedBatch = PluginAssignatureBatch::get($batchId);
            $redirectUrl = $updatedBatch !== null && PluginAssignatureBatch::canManage($updatedBatch, $userId)
                ? assignature_url('/front/batch.php?id=' . $batchId)
                : assignature_url('/front/index.php');
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if ($batchId > 0 && (string) ($_POST['action'] ?? '') === 'create') {
            $message = ((string) ($_POST['method'] ?? '') === 'digital'
                ? 'Assinatura registrada, mas a impressão falhou: '
                : 'Termo criado, mas a impressão falhou: ') . $message . ' Abra o termo para tentar novamente.';
        }
        assignature_flash($message, false);
        $redirectUrl = $batchId > 0
            ? assignature_url('/front/batch.php?id=' . $batchId)
            : assignature_url('/front/index.php?status=pending');
    }
    Html::redirect($redirectUrl);
}

$batch = $batchId > 0 ? PluginAssignatureBatch::get($batchId) : null;
if ($batchId > 0 && ($batch === null || !PluginAssignatureBatch::canManage($batch, $userId))) {
    assignature_abort('Lote não encontrado ou sem acesso.');
}
if ($batch === null && $selectedIds === []) {
    Html::redirect(assignature_url('/front/index.php?status=pending'));
}
$requests = $batch !== null ? PluginAssignatureBatch::requests($batchId) : [];
if ($batch === null) {
    if (count($selectedIds) > 100) {
        assignature_abort('Selecione no máximo 100 pedidos.', 400);
    }
    foreach ($selectedIds as $id) {
        $request = PluginAssignatureRequest::get($id);
        if ($request === null || (string) $request['status'] !== 'pending' || (int) $request['is_active'] !== 1
            || (int) ($request['batches_id'] ?? 0) !== 0
            || !PluginAssignatureScope::canSign($userId, (int) $request['sources_id'])) {
            assignature_abort('Um dos pedidos não está disponível para assinatura.', 409);
        }
        $requests[] = $request;
    }
}
$termRows = $batch !== null ? PluginAssignatureBatch::termRows($requests) : [];
$termContext = $batch !== null ? PluginAssignatureBatch::termContext($requests) : [];
$sourceIds = array_values(array_unique(array_map('intval', array_column($requests, 'sources_id'))));
$technicians = $batch === null ? PluginAssignatureTechnician::availableForSources($sourceIds) : [];
$technicianName = $batch !== null ? PluginAssignatureBatch::technicianName($batch) : '';
$statusText = $batch === null ? 'Nova assinatura' : ((string) $batch['status'] === 'done' ? 'Assinado' : 'Aguardando arquivo');
header('Cache-Control: no-store');
Html::header('Assignat-ure', $_SERVER['PHP_SELF'], 'tools', 'PluginAssignatureApp');
echo "<script>document.title = 'Assignat-ure';</script>";
?>
<main class="as-page as-batch-page">
    <header class="as-page-head as-print-hide"><div class="as-head-brand"><img src="<?= assignature_h(assignature_logo_url()) ?>" alt="" class="as-head-logo"><div><p class="as-eyebrow">ASSINATURA EM LOTE</p><h1><?= $batch === null ? 'Como deseja assinar?' : 'Lote #' . $batchId ?></h1><p><?= count($requests) ?> pedido(s) · <?= assignature_h($statusText) ?></p></div></div><a class="as-button as-button-light" href="<?= assignature_h(assignature_url('/front/index.php')) ?>">Voltar</a></header>

    <?php if ($batch === null): ?>
        <section class="as-panel as-batch-summary"><h2>Pedidos selecionados</h2><div class="as-batch-items"><?php foreach ($requests as $request): ?><div><span class="as-source-chip"><?= assignature_h(PluginAssignatureSource::get((int) $request['sources_id'])['name'] ?? '') ?></span><strong><?= assignature_h($request['code']) ?> · <?= assignature_h($request['title']) ?></strong></div><?php endforeach; ?></div></section>
        <div class="as-choice-grid" data-sign-choices>
            <button type="button" class="as-choice" data-choose-method="digital"><i class="ti ti-pencil"></i><strong>Assinar agora</strong><span>O retirante informa os dados e assina uma vez na tela para todos os pedidos.</span><em>Começar <i class="ti ti-arrow-right"></i></em></button>
            <button type="button" class="as-choice" data-choose-method="paper"><i class="ti ti-printer"></i><strong>Assinar depois</strong><span>Escolha o técnico. O termo será enviado à impressora; a pessoa assina à mão e você envia o arquivo depois.</span><em>Preparar impressão <i class="ti ti-arrow-right"></i></em></button>
        </div>
        <section class="as-panel as-flow" data-sign-flow hidden>
            <div class="as-section-title"><h2 data-flow-title>Assinar agora</h2><button type="button" class="as-text-button" data-back-choices>Trocar opção</button></div>
            <form method="post" class="as-form" data-batch-form data-sign-form>
                <?= assignature_csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="method" data-method-value><input type="hidden" name="signature_png" data-signature-value>
                <?php foreach ($selectedIds as $id): ?><input type="hidden" name="ids[]" value="<?= $id ?>"><?php endforeach; ?>
                <div data-step="0"><p class="as-step-label">1 de 5 · Técnico responsável</p><h3>Quem acompanha a retirada?</h3><label>Assinatura cadastrada do técnico<select name="technician_profiles_id" required><option value="">Selecione o técnico</option><?php foreach ($technicians as $technician): ?><option value="<?= (int) $technician['id'] ?>"><?= assignature_h($technician['full_name']) ?></option><?php endforeach; ?></select></label><?php foreach ($technicians as $technician): ?><div class="as-technician-choice" data-technician-detail="<?= (int) $technician['id'] ?>" hidden><strong><?= assignature_h($technician['full_name']) ?></strong><span><?= assignature_h(PluginAssignatureTechnician::maskedDocument($technician)) ?></span><img src="data:image/png;base64,<?= assignature_h($technician['signature_png']) ?>" alt="Assinatura cadastrada"></div><?php endforeach; ?><?php if ($technicians === []): ?><p class="as-form-error">Nenhum técnico com assinatura está cadastrado para todos os plugins deste lote. Peça a um administrador que faça o cadastro.</p><?php endif; ?><p class="as-hint">O nome, documento e assinatura cadastrados do técnico serão incluídos no termo.</p></div>
                <div data-step="1" hidden><p class="as-step-label">2 de 5 · Retirante</p><h3>Nome completo de quem retira</h3><label>Nome completo<input name="signer_name" data-uppercase maxlength="255" autocomplete="name" placeholder="NOME E SOBRENOME" required></label></div>
                <div data-step="2" hidden>
                    <p class="as-step-label">3 de 5 · Identificação</p>
                    <h3>Qual documento será usado?</h3>
                    <div class="as-document-options" role="radiogroup" aria-label="Tipo de documento">
                        <label class="as-document-choice">
                            <input type="radio" name="document_type" value="CPF" required>
                            <span class="as-document-choice-icon"><i class="ti ti-id" aria-hidden="true"></i></span>
                            <span class="as-document-choice-copy"><strong>CPF</strong><small>Cadastro de Pessoa Física</small></span>
                            <span class="as-document-choice-check" aria-hidden="true"><i class="ti ti-check"></i></span>
                        </label>
                        <label class="as-document-choice">
                            <input type="radio" name="document_type" value="RG" required>
                            <span class="as-document-choice-icon"><i class="ti ti-id-badge" aria-hidden="true"></i></span>
                            <span class="as-document-choice-copy"><strong>RG</strong><small>Registro Geral</small></span>
                            <span class="as-document-choice-check" aria-hidden="true"><i class="ti ti-check"></i></span>
                        </label>
                    </div>
                </div>
                <div data-step="3" hidden><p class="as-step-label">4 de 5 · Documento</p><h3 data-document-heading>Digite o documento</h3><label>Número do documento<input name="signer_document" data-document-input inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00" required></label><div class="as-keypad" data-keypad><?php foreach (['1','2','3','4','5','6','7','8','9','X','0','⌫'] as $key): ?><button type="button" data-key="<?= assignature_h($key) ?>"><?= assignature_h($key) ?></button><?php endforeach; ?></div><p class="as-hint">CPF é conferido pelos dígitos verificadores. O RG varia conforme o estado.</p></div>
                <div data-step="4" hidden><p class="as-step-label">5 de 5 · Assinatura</p><h3>Assine com o dedo, mouse ou caneta</h3><p class="as-hint">Esta assinatura será vinculada a todos os <?= count($requests) ?> pedidos acima.</p><div class="as-signature-field"><span>Assinatura de quem retira</span><canvas data-signature-canvas aria-label="Área para desenhar a assinatura"></canvas><button type="button" class="as-text-button" data-signature-clear>Limpar assinatura</button></div><label class="as-checkbox"><input type="checkbox" name="accepted" value="1" required> Confirmei a identidade e os dados de todos os pedidos</label></div>
                <p class="as-form-error" data-signature-error hidden></p>
                <div class="as-flow-actions"><button type="button" class="as-button as-button-light" data-step-back hidden>Voltar</button><button type="button" class="as-button" data-step-next>Continuar</button><button type="submit" class="as-button" data-final-submit hidden>Concluir <?= count($requests) ?> assinatura(s)</button></div>
            </form>
        </section>
    <?php else: ?>
        <section class="as-panel as-paper-term" id="termo">
            <div class="as-term-heading"><img src="<?= assignature_h(assignature_logo_url()) ?>" alt="" class="as-term-logo"><div><p class="as-eyebrow">[URE] ASSIGNAT-URE</p><h2>Termo de entrega de equipamentos</h2><p>Lote #<?= $batchId ?> · <?= assignature_h(PluginAssignatureBatch::formatDate((string) $batch['created_at'], true)) ?></p></div></div>
            <p class="as-term-intro">Este termo registra a entrega dos equipamentos abaixo após os procedimentos informados pelos plugins de origem. A pessoa responsável pela retirada deve conferir os itens e as condições registradas antes de assinar.</p>
            <p class="as-term-declaration">Eu, <strong><?= assignature_h($technicianName) ?></strong>, técnico(a) responsável, declaro que os equipamentos listados foram disponibilizados ao responsável identificado neste termo, com os resultados e observações registrados no atendimento.</p>
            <div class="as-term-facts"><div><small>Data da entrega</small><strong><?= assignature_h(PluginAssignatureBatch::formatDate((string) $batch['created_at'])) ?></strong></div><div><small>Plugin(s) de origem</small><strong><?= assignature_h($termContext['sources'] ?: '—') ?></strong></div><div><small>Técnico responsável</small><strong><?= assignature_h($technicianName . ' · ' . $batch['technician_document_type'] . ' ' . PluginAssignatureBatch::technicianDocument($batch)) ?></strong></div><div><small>Responsável pela retirada</small><strong><?= assignature_h($batch['signer_name'] ?: '________________________________') ?></strong></div><div><small>Quadro / atendimento</small><strong><?= assignature_h($termContext['boards'] ?: 'Não informado') ?></strong></div><div><small>Itens neste termo</small><strong><?= count($termRows) ?> equipamento(s) · <?= count($requests) ?> pedido(s)</strong></div></div>
            <h3 class="as-term-section-title">Equipamentos entregues</h3>
            <table class="as-term-table"><thead><tr><th>#</th><th>Equipamento / pedido</th><th>Status final</th><th>Serviço / observações</th></tr></thead><tbody><?php foreach ($termRows as $index => $row): ?><tr><td><?= $index + 1 ?></td><td><strong><?= assignature_h($row['name']) ?></strong><small>#<?= assignature_h($row['code']) ?></small></td><td><strong><?= assignature_h($row['status']) ?></strong></td><td><?= nl2br(assignature_h($row['report'] !== '' ? $row['report'] : '—')) ?></td></tr><?php endforeach; ?></tbody></table>
            <h3 class="as-term-section-title">Assinaturas</h3>
            <div class="as-term-signatures"><div class="as-term-signature-card"><div class="as-term-signature-ink"><?php if ((string) ($batch['technician_signature_png'] ?? '') !== ''): ?><img src="data:image/png;base64,<?= assignature_h($batch['technician_signature_png']) ?>" alt="Assinatura do técnico"><?php endif; ?></div><strong>Responsável pela entrega (técnico)</strong><small>Nome: <?= assignature_h($technicianName) ?></small><small>Documento: <?= assignature_h($batch['technician_document_type'] . ' ' . PluginAssignatureBatch::technicianDocument($batch)) ?></small></div><div class="as-term-signature-card"><div class="as-term-signature-ink"><?php if ((string) $batch['method'] === 'digital'): ?><img src="data:image/png;base64,<?= assignature_h($batch['signature_png']) ?>" alt="Assinatura de quem retira"><?php endif; ?></div><strong>Responsável pelo recebimento</strong><small>Nome: <?= assignature_h($batch['signer_name'] ?: '____________________________') ?></small><small>Documento: <?= (string) $batch['method'] === 'digital' ? assignature_h($batch['document_type'] . ' ' . PluginAssignatureBatch::decryptedDocument($batch)) : '____________________________' ?></small><?php if ((string) $batch['method'] === 'digital'): ?><small>Assinado em <?= assignature_h(PluginAssignatureBatch::formatDate((string) $batch['completed_at'], true)) ?></small><?php endif; ?></div></div>
            <p class="as-term-foot">[URE] Assignat-ure · Termo #<?= $batchId ?> · <?= (string) $batch['status'] === 'done' ? 'Assinatura registrada' : 'Aguardando assinatura no papel' ?></p>
        </section>
        <div class="as-batch-actions as-print-hide">
             <?php if (((string) $batch['method'] === 'paper' && (string) $batch['status'] === 'awaiting_upload') || ((string) $batch['method'] === 'digital' && (string) $batch['status'] === 'done')): ?><form method="post"><?= assignature_csrf_field() ?><input type="hidden" name="action" value="print"><input type="hidden" name="batch_id" value="<?= $batchId ?>"><button type="submit" class="as-button"><i class="ti ti-printer"></i> <?= empty($batch['printed_at']) ? 'Enviar' : 'Reimprimir' ?> para <?= assignature_h(PluginAssignatureBatch::printerQueue()) ?> (<?= (string) $batch['method'] === 'digital' ? '2 cópias' : '1 cópia' ?>)</button></form><?php endif; ?>
            <?php if ((string) $batch['status'] === 'done' && (string) $batch['method'] === 'paper'): ?><a class="as-button as-button-light" href="<?= assignature_h(assignature_url('/front/file.php?id=' . $batchId)) ?>"><i class="ti ti-download"></i> Ver arquivo assinado</a><?php endif; ?>
        </div>
        <?php if ((string) $batch['method'] === 'paper' && (string) $batch['status'] === 'awaiting_upload'
            && ((int) $batch['created_by_users_id'] === $userId || PluginAssignatureApp::canAdmin())): ?>
            <section class="as-panel as-switch-signing as-print-hide">
                <div><h2>Prefere assinar na tela?</h2><p class="as-hint">O termo impresso será cancelado e os <?= count($requests) ?> pedido(s) seguirão para uma nova assinatura digital.</p></div>
                <form method="post" onsubmit="return confirm('Cancelar o termo em papel e mudar todos os pedidos deste lote para Assinar agora?')">
                    <?= assignature_csrf_field() ?><input type="hidden" name="action" value="convert_to_digital"><input type="hidden" name="batch_id" value="<?= $batchId ?>">
                    <button class="as-button as-button-light" type="submit"><i class="ti ti-pencil"></i> Mudar para assinar agora</button>
                </form>
            </section>
        <?php endif; ?>
         <?php if ((string) $batch['method'] === 'paper' && (string) $batch['status'] === 'awaiting_upload' && !empty($batch['printed_at'])): ?>
            <section class="as-panel as-upload-panel as-print-hide"><h2>Depois da assinatura no papel</h2><p class="as-hint">Digitalize ou fotografe o termo assinado e envie aqui. Apenas o arquivo é obrigatório; os dados da pessoa são opcionais. Os pedidos serão concluídos após o arquivo ser salvo.</p><form method="post" enctype="multipart/form-data" class="as-form" data-paper-upload><?= assignature_csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="batch_id" value="<?= $batchId ?>"><div class="as-form-pair"><label>Nome de quem assinou (opcional)<input name="signer_name" data-uppercase maxlength="255" value="<?= assignature_h($batch['signer_name']) ?>"></label><label>Tipo de documento (opcional)<select name="document_type" data-document-type><option value="">Selecione</option><option value="CPF" <?= $batch['document_type'] === 'CPF' ? 'selected' : '' ?>>CPF</option><option value="RG" <?= $batch['document_type'] === 'RG' ? 'selected' : '' ?>>RG</option></select></label></div><label>Número do documento (opcional)<input name="signer_document" data-document-input inputmode="numeric" autocomplete="off" value="<?= assignature_h(PluginAssignatureBatch::decryptedDocument($batch)) ?>"></label><label>Termo assinado (PDF, PNG ou JPG · até 10 MB)<input type="file" name="signed_file" accept="application/pdf,image/png,image/jpeg" required></label><button class="as-button" type="submit">Enviar arquivo e concluir <?= count($requests) ?> pedido(s)</button></form>
            <form method="post" class="as-cancel-form" onsubmit="return confirm('Cancelar o termo e liberar os pedidos para uma nova assinatura?')"><?= assignature_csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="batch_id" value="<?= $batchId ?>"><button class="as-text-button">Cancelar termo</button></form></section>
         <?php elseif ((string) $batch['method'] === 'paper' && (string) $batch['status'] === 'awaiting_upload'): ?><section class="as-panel as-upload-panel as-print-hide"><h2>Impressão pendente</h2><p class="as-hint">Envie o termo para a impressora configurada. Depois que a pessoa assinar à mão, o envio do arquivo será liberado.</p><form method="post" class="as-cancel-form" onsubmit="return confirm('Cancelar o termo e liberar os pedidos para uma nova assinatura?')"><?= assignature_csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="batch_id" value="<?= $batchId ?>"><button class="as-text-button">Cancelar termo</button></form></section><?php endif; ?>
        <?php if ((string) $batch['status'] === 'done'): ?><section class="as-panel as-batch-results as-print-hide"><h2>Pedidos concluídos</h2><div class="as-batch-items"><?php foreach ($requests as $request): ?><div><a href="<?= assignature_h(assignature_url('/front/request.php?id=' . (int) $request['id'])) ?>"><?= assignature_h($request['code']) ?> · <?= assignature_h($request['title']) ?></a><span><?= $request['callback_state'] === 'confirmed' ? 'Confirmado na origem' : 'Confirmação pendente' ?></span></div><?php endforeach; ?></div></section><?php endif; ?>
    <?php endif; ?>
</main>
<?php Html::footer(); ?>
