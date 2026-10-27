<?php
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/batch.class.php';

Session::checkRight(PluginAssignatureProfile::RIGHT_VIEW, READ);
$userId = (int) Session::getLoginUserID();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
// Links vindos dos plugins de origem: ?card=ID (Tralla) ou ?source=slug&ref=ID (Protocolo e outros).
$linkSlug = isset($_GET['card']) ? 'tralla' : strtolower(trim((string) ($_GET['source'] ?? '')));
$linkRef = isset($_GET['card']) ? (string) (int) $_GET['card'] : trim((string) ($_GET['ref'] ?? ''));
if ($id <= 0 && $linkSlug !== '' && $linkRef !== '' && $linkRef !== '0' && mb_strlen($linkRef) <= 100) {
    $linkSource = PluginAssignatureSource::bySlug($linkSlug);
    if ($linkSource !== null
        && (PluginAssignatureScope::canSee($userId, (int) $linkSource['id'], 'pending')
            || PluginAssignatureScope::canSee($userId, (int) $linkSource['id'], 'done'))) {
        $found = PluginAssignatureRequest::byExternal((int) $linkSource['id'], $linkRef);
        if ($found === null) {
            try {
                PluginAssignatureRequest::syncSource($linkSource);
            } catch (Throwable $e) {
                assignature_flash($e->getMessage(), false);
            }
            $found = PluginAssignatureRequest::byExternal((int) $linkSource['id'], $linkRef);
        }
        $id = (int) ($found['id'] ?? 0);
    }
}

// Seleção vinda do Tralla: ?cards=10,11,15 (ids externos) abre só os cards marcados.
// Na navegação interna a seleção segue em ?sel=ids internos.
$selectionIds = [];
$selectionLoaded = false;
$cardsParam = trim((string) ($_GET['cards'] ?? ''));
if ($id <= 0 && $cardsParam !== '') {
    $selectionLoaded = true;
    $trallaSource = PluginAssignatureSource::bySlug('tralla');
    if ($trallaSource !== null && PluginAssignatureScope::canSee($userId, (int) $trallaSource['id'], 'pending')) {
        $refs = array_slice(array_values(array_unique(array_filter(array_map(
            static fn(string $ref): string => (string) (int) trim($ref),
            explode(',', $cardsParam)
        ), static fn(string $ref): bool => $ref !== '0'))), 0, 100);
        $synced = false;
        foreach ($refs as $ref) {
            $found = PluginAssignatureRequest::byExternal((int) $trallaSource['id'], $ref);
            if ($found === null && !$synced) {
                $synced = true;
                try {
                    PluginAssignatureRequest::syncSource($trallaSource);
                } catch (Throwable $e) {
                    assignature_flash($e->getMessage(), false);
                }
                $found = PluginAssignatureRequest::byExternal((int) $trallaSource['id'], $ref);
            }
            if ($found !== null && (string) $found['status'] === 'pending' && (int) $found['is_active'] === 1) {
                $selectionIds[] = (int) $found['id'];
            }
        }
    }
    $id = $selectionIds[0] ?? 0;
    if ($id <= 0) {
        assignature_abort('Nenhum dos cards selecionados está pendente de assinatura no Assignat-ure.');
    }
} elseif ($id > 0 && trim((string) ($_GET['sel'] ?? '')) !== '') {
    $selectionLoaded = true;
    $selectionIds = array_slice(array_values(array_unique(array_filter(array_map(
        'intval', explode(',', (string) $_GET['sel'])
    )))), 0, 100);
}
$selectedRequests = [];
foreach ($selectionIds as $selectedId) {
    $selected = PluginAssignatureRequest::get($selectedId);
    if ($selected !== null && (string) $selected['status'] === 'pending' && (int) $selected['is_active'] === 1
        && PluginAssignatureScope::canSee($userId, (int) $selected['sources_id'], 'pending')) {
        $selectedRequests[(int) $selected['id']] = $selected;
    }
}
if ($selectionLoaded && $selectedRequests !== [] && !isset($selectedRequests[$id])) {
    $id = (int) array_key_first($selectedRequests);
}

$request = PluginAssignatureRequest::get($id);
if ($request === null || !PluginAssignatureScope::canSee($userId, (int) $request['sources_id'], (string) $request['status'])) {
    assignature_abort('Assinatura não encontrada ou sem acesso.');
}
$source = PluginAssignatureSource::get((int) $request['sources_id']);
if ($source === null) {
    assignature_abort('Fonte não encontrada.');
}

$canSign = (string) $request['status'] === 'pending'
    && (int) ($request['batches_id'] ?? 0) === 0
    && PluginAssignatureScope::canSign($userId, (int) $source['id']);
$batch = (int) ($request['batches_id'] ?? 0) > 0
    ? PluginAssignatureBatch::get((int) $request['batches_id']) : null;
$payload = json_decode((string) ($request['payload_json'] ?? ''), true);
$payload = is_array($payload) ? $payload : [];
// Tralla: a lista de retiradas não traz as máquinas; busca o card completo na origem.
if ((string) $source['adapter'] === 'tralla' && (string) $request['status'] === 'pending'
    && (int) $request['is_active'] === 1 && (int) $source['is_active'] === 1) {
    try {
        $detail = PluginAssignatureSource::api($source, 'GET', ['card' => (string) $request['external_id']]);
        if (is_array($detail) && is_array($detail['machines'] ?? null)) {
            $payload = array_merge($payload, $detail);
            global $DB;
            $DB->update(PluginAssignatureRequest::TABLE, [
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ], ['id' => (int) $request['id']]);
        }
    } catch (Throwable $e) {
        // Sem conexão com o Tralla: mostra o que já estava salvo.
    }
}
$machines = is_array($payload['machines'] ?? null) ? $payload['machines'] : [];
$entityId = (int) ($payload['entities_id'] ?? $payload['entity_id'] ?? ($payload['entity']['id'] ?? 0));
$entityName = trim((string) ($payload['entity']['name'] ?? $request['title']));
$sameEntity = [];
$isSelection = count($selectedRequests) > 1;
if ($isSelection) {
    $sameEntity = array_values($selectedRequests);
} elseif ((string) $request['status'] === 'pending') {
    foreach (PluginAssignatureRequest::visible($userId, 'pending', (int) $request['sources_id']) as $candidate) {
        if ((int) $candidate['is_active'] !== 1 || (int) ($candidate['batches_id'] ?? 0) !== 0
            || !PluginAssignatureScope::canSign($userId, (int) $candidate['sources_id'])) {
            continue;
        }
        $candidatePayload = json_decode((string) ($candidate['payload_json'] ?? ''), true);
        $candidatePayload = is_array($candidatePayload) ? $candidatePayload : [];
        $candidateEntityId = (int) ($candidatePayload['entities_id'] ?? $candidatePayload['entity_id'] ?? ($candidatePayload['entity']['id'] ?? 0));
        $candidateName = trim((string) ($candidatePayload['entity']['name'] ?? $candidate['title']));
        if ($entityId > 0 && $candidateEntityId > 0 ? $entityId === $candidateEntityId
            : mb_strtolower($entityName, 'UTF-8') === mb_strtolower($candidateName, 'UTF-8')) {
            $sameEntity[] = $candidate;
        }
    }
}
$signedBy = '';
if ((int) $request['signed_by_users_id'] > 0) {
    $user = new User();
    if ($user->getFromDB((int) $request['signed_by_users_id'])) {
        $signedBy = (string) $user->getFriendlyName();
    }
}
header('Cache-Control: no-store');
Html::header('Assignat-ure', $_SERVER['PHP_SELF'], 'tools', 'PluginAssignatureApp');
echo "<script>document.title = 'Assignat-ure';</script>";
?>
<main class="as-page as-detail-page">
    <header class="as-page-head"><div class="as-head-brand"><img src="<?= assignature_h(assignature_logo_url()) ?>" alt="" class="as-head-logo"><div><p class="as-eyebrow"><?= assignature_h($source['name']) ?> · <?= assignature_h($request['code']) ?></p><h1><?= assignature_h($request['title']) ?></h1><p><?= assignature_h($request['summary']) ?></p></div></div><a class="as-button as-button-light as-print-hide" href="<?= assignature_h(assignature_url('/front/index.php')) ?>">Voltar</a></header>
    <div class="as-request-layout<?= count($sameEntity) > 1 ? ' has-sidebar' : '' ?>">
    <?php if (count($sameEntity) > 1): ?>
        <aside class="as-panel as-entity-sidebar as-print-hide" aria-label="Assinaturas pendentes da mesma entidade">
            <?php if ($isSelection): ?><h2>Selecionados</h2><p class="as-hint"><?= count($sameEntity) ?> pedidos marcados no Tralla para assinar juntos</p><?php else: ?><h2>Mesma entidade</h2><p class="as-hint"><?= count($sameEntity) ?> pedidos pendentes de <?= assignature_h($entityName) ?></p><?php endif; ?>
            <nav class="as-entity-list"><?php foreach ($sameEntity as $candidate): ?>
                <a class="as-entity-link<?= (int) $candidate['id'] === $id ? ' is-current' : '' ?>" href="<?= assignature_h(assignature_url('/front/request.php?id=' . (int) $candidate['id'] . ($isSelection ? '&sel=' . implode(',', array_keys($selectedRequests)) : ''))) ?>" <?= (int) $candidate['id'] === $id ? 'aria-current="page"' : '' ?>>
                    <strong><?= assignature_h($candidate['code']) ?></strong><span><?= assignature_h($candidate['title']) ?></span>
                </a>
            <?php endforeach; ?></nav>
            <?php if (count($sameEntity) <= 100): ?>
                <form method="get" action="<?= assignature_h(assignature_url('/front/batch.php')) ?>">
                    <?php $batchCount = 0; foreach ($sameEntity as $candidate): if ((int) ($candidate['batches_id'] ?? 0) !== 0 || !PluginAssignatureScope::canSign($userId, (int) $candidate['sources_id'])) { continue; } $batchCount++; ?><input type="hidden" name="ids[]" value="<?= (int) $candidate['id'] ?>"><?php endforeach; ?>
                    <?php if ($batchCount > 0): ?><button class="as-button as-button-wide" type="submit"><i class="ti ti-checks"></i> <?= $isSelection ? 'Assinar os ' . $batchCount . ' selecionados' : 'Assinar todos juntos' ?></button><?php else: ?><p class="as-hint">Você não tem permissão para assinar estes pedidos.</p><?php endif; ?>
                </form>
            <?php else: ?><p class="as-hint">Para assinar em lote, selecione até 100 pedidos na lista de pendentes.</p><?php endif; ?>
        </aside>
    <?php endif; ?>
    <div class="as-detail-grid">
        <section class="as-panel"><div class="as-section-title"><h2>Pedido</h2><span class="as-status <?= $request['status'] === 'done' ? 'is-done' : 'is-pending' ?>"><?= $request['status'] === 'done' ? 'Assinado' : 'Pendente' ?></span></div>
            <?php if ((string) ($payload['description'] ?? '') !== ''): ?><p><?= nl2br(assignature_h($payload['description'])) ?></p><?php endif; ?>
            <dl class="as-facts"><div><dt>Origem</dt><dd><?= assignature_h($source['name']) ?></dd></div><div><dt>Referência</dt><dd><?= assignature_h($request['code']) ?></dd></div>
            <?php if (isset($payload['board']['name'])): ?><div><dt>Quadro</dt><dd><?= assignature_h($payload['board']['name']) ?></dd></div><?php endif; ?>
            <?php if (isset($payload['technician']['name'])): ?><div><dt>Técnico</dt><dd><?= assignature_h($payload['technician']['name']) ?></dd></div><?php endif; ?></dl>
            <?php if (isset($payload['list']['name']) && (string) $payload['list']['name'] !== ''): ?><dl class="as-facts as-facts-tight"><div><dt>Lista no Tralla</dt><dd><?= assignature_h($payload['list']['name']) ?></dd></div><?php if (!empty($payload['date_approved'])): ?><div><dt>Aprovado em</dt><dd><?= assignature_h(date('d/m/Y H:i', strtotime((string) $payload['date_approved']))) ?></dd></div><?php endif; ?></dl><?php endif; ?>
            <?php if ($machines !== []): ?>
                <h3 class="as-machines-title">Máquinas do card <span><?= count($machines) ?></span></h3>
                <div class="as-machines as-machines-detail">
                <?php foreach ($machines as $index => $machine): if (!is_array($machine)) { continue; }
                    $label = trim((string) ($machine['label'] ?? ''));
                    $model = trim((string) ($machine['model_name'] ?? ''));
                    $serial = trim((string) ($machine['serial'] ?? ''));
                    $report = trim((string) ($machine['report'] ?? ''));
                    $status = (string) ($machine['status'] ?? '');
                    $statusClass = $status === 'done' ? 'is-done' : ($status === 'not_repairable' ? 'is-broken' : 'is-other'); ?>
                    <article class="as-machine">
                        <header>
                            <div>
                                <strong><?= assignature_h($label !== '' ? $label : ($model !== '' ? $model : 'Máquina ' . ($index + 1))) ?></strong>
                                <small><?= assignature_h(implode(' · ', array_filter([$label !== '' ? $model : '', $serial !== '' ? 'Série/patrimônio: ' . $serial : 'Sem número de série']))) ?></small>
                            </div>
                            <?php if ((string) ($machine['status_label'] ?? '') !== ''): ?><span class="as-machine-status <?= $statusClass ?>"><?= assignature_h($machine['status_label']) ?></span><?php endif; ?>
                        </header>
                        <?php if ($report !== ''): ?><div class="as-machine-report"><span>Relatório técnico</span><p><?= nl2br(assignature_h($report)) ?></p></div><?php endif; ?>
                        <?php if (!empty($machine['service_done']) || !empty($machine['needs_inventory'])): ?><div class="as-machine-flags"><?php if (!empty($machine['service_done'])): ?><span><i class="ti ti-check"></i> Serviço realizado</span><?php endif; ?><?php if (!empty($machine['needs_inventory'])): ?><span class="is-warn"><i class="ti ti-clipboard-list"></i> Precisa de inventário</span><?php endif; ?></div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php elseif ((string) $source['adapter'] === 'tralla'): ?>
                <p class="as-hint">Não foi possível carregar as máquinas deste card no Tralla agora.</p>
            <?php endif; ?>
        </section>

        <?php if ($request['status'] === 'done'): ?>
            <section class="as-panel as-receipt"><h2>Comprovante de assinatura</h2><p><?= trim((string) $request['signer_name']) !== '' ? 'Assinado por <strong>' . assignature_h($request['signer_name']) . '</strong>' : 'Assinatura registrada no termo enviado' ?> em <?= assignature_h($request['signed_at']) ?>.</p>
                <?php if ($batch !== null): ?><p>Técnico: <?= assignature_h(PluginAssignatureBatch::technicianName($batch)) ?></p><?php if ((string) $batch['document_type'] !== '' && PluginAssignatureBatch::decryptedDocument($batch) !== ''): ?><p>Documento de quem retirou: <?= assignature_h($batch['document_type']) ?> <?= assignature_h(PluginAssignatureBatch::decryptedDocument($batch)) ?></p><?php endif; ?><?php elseif ($request['signer_document'] !== ''): ?><p>Documento: <?= assignature_h($request['signer_document']) ?></p><?php endif; ?>
                <?php if ($batch !== null && (string) ($batch['technician_signature_png'] ?? '') !== ''): ?><p>Assinatura do técnico responsável:</p><div class="as-signed-image"><img src="data:image/png;base64,<?= assignature_h($batch['technician_signature_png']) ?>" alt="Assinatura de <?= assignature_h(PluginAssignatureBatch::technicianName($batch)) ?>"></div><?php endif; ?>
                <?php if ($batch !== null && (string) $batch['method'] === 'paper'): ?><p><i class="ti ti-file-check"></i> Assinado em papel e arquivo registrado no Assignat-ure.</p><a class="as-button as-button-light" href="<?= assignature_h(assignature_url('/front/file.php?id=' . (int) $batch['id'])) ?>">Ver arquivo assinado</a><?php else: ?><div class="as-signed-image"><img src="data:image/png;base64,<?= assignature_h($batch['signature_png'] ?? $request['signature_png']) ?>" alt="Assinatura de <?= assignature_h($request['signer_name']) ?>"></div><?php endif; ?>
                <p class="as-hint">Registrado por <?= assignature_h($signedBy ?: 'usuário GLPI #' . $request['signed_by_users_id']) ?> · comprovante #<?= (int) $request['id'] ?></p>
                <?php if ($request['callback_state'] !== 'confirmed'): ?><p class="as-warning as-print-hide">Assinatura salva. A confirmação na origem está pendente; o sistema tentará novamente.</p><?php endif; ?>
                <?php if ($batch !== null): ?><a class="as-button as-print-hide" href="<?= assignature_h(assignature_url('/front/batch.php?id=' . (int) $batch['id'])) ?>">Ver termo completo</a><?php else: ?><button type="button" class="as-button as-print-hide" onclick="window.print()">Imprimir comprovante</button><?php endif; ?>
            </section>
        <?php elseif ($canSign): ?>
            <section class="as-panel"><h2>Assinar retirada</h2><p class="as-hint">Confira os dados do pedido. Você poderá assinar agora ou preparar o termo para assinatura em papel.</p><a class="as-button as-button-wide" href="<?= assignature_h(assignature_url('/front/batch.php?ids%5B%5D=' . (int) $request['id'])) ?>">Escolher como assinar <i class="ti ti-arrow-right"></i></a></section>
        <?php elseif ($batch !== null && (string) $batch['status'] === 'awaiting_upload'): ?>
            <section class="as-panel"><h2>Aguardando arquivo assinado</h2><p>Este pedido faz parte do termo #<?= (int) $batch['id'] ?>. Após enviar o arquivo, a retirada será concluída automaticamente.</p><div class="as-inline-actions"><a class="as-button" href="<?= assignature_h(assignature_url('/front/batch.php?id=' . (int) $batch['id'])) ?>">Abrir termo</a><?php if ((int) $batch['created_by_users_id'] === $userId || PluginAssignatureApp::canAdmin()): ?><form method="post" action="<?= assignature_h(assignature_url('/front/batch.php')) ?>" onsubmit="return confirm('Cancelar o termo em papel e mudar todos os pedidos deste lote para Assinar agora?')"><?= assignature_csrf_field() ?><input type="hidden" name="action" value="convert_to_digital"><input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>"><button class="as-button as-button-light" type="submit">Mudar para assinar agora</button></form><?php endif; ?></div></section>
        <?php else: ?>
            <section class="as-panel"><h2>Aguardando assinatura</h2><p>Você pode visualizar este pedido, mas não tem permissão para coletar a assinatura.</p></section>
        <?php endif; ?>
    </div>
    </div>
</main>
<?php Html::footer(); ?>
