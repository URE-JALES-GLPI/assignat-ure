<?php
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/batch.class.php';

Session::checkRight(PluginAssignatureProfile::RIGHT_VIEW, READ);
$userId = (int) Session::getLoginUserID();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0 && isset($_GET['card'])) {
    $tralla = PluginAssignatureSource::bySlug('tralla');
    $cardId = (int) $_GET['card'];
    if ($tralla !== null && $cardId > 0
        && (PluginAssignatureScope::canSee($userId, (int) $tralla['id'], 'pending')
            || PluginAssignatureScope::canSee($userId, (int) $tralla['id'], 'done'))) {
        $found = PluginAssignatureRequest::byExternal((int) $tralla['id'], (string) $cardId);
        if ($found === null) {
            try {
                PluginAssignatureRequest::syncSource($tralla);
            } catch (Throwable $e) {
                assignature_flash($e->getMessage(), false);
            }
            $found = PluginAssignatureRequest::byExternal((int) $tralla['id'], (string) $cardId);
        }
        $id = (int) ($found['id'] ?? 0);
    }
}

$request = PluginAssignatureRequest::get($id);
if ($request === null || !PluginAssignatureScope::canSee($userId, (int) $request['sources_id'], (string) $request['status'])) {
    Html::displayErrorAndDie('Assinatura não encontrada ou sem acesso.');
}
$source = PluginAssignatureSource::get((int) $request['sources_id']);
if ($source === null) {
    Html::displayErrorAndDie('Fonte não encontrada.');
}

$canSign = (string) $request['status'] === 'pending'
    && (int) ($request['batches_id'] ?? 0) === 0
    && PluginAssignatureScope::canSign($userId, (int) $source['id']);
$batch = (int) ($request['batches_id'] ?? 0) > 0
    ? PluginAssignatureBatch::get((int) $request['batches_id']) : null;
$payload = json_decode((string) ($request['payload_json'] ?? ''), true);
$payload = is_array($payload) ? $payload : [];
$machines = is_array($payload['machines'] ?? null) ? $payload['machines'] : [];
$signedBy = '';
if ((int) $request['signed_by_users_id'] > 0) {
    $user = new User();
    if ($user->getFromDB((int) $request['signed_by_users_id'])) {
        $signedBy = (string) $user->getFriendlyName();
    }
}
header('Cache-Control: no-store');
Html::header('Assinatura ' . $request['code'] . ' — Assignat-ure', $_SERVER['PHP_SELF'], 'tools', 'PluginAssignatureApp');
?>
<main class="as-page as-detail-page">
    <header class="as-page-head"><div class="as-head-brand"><img src="<?= assignature_h(assignature_logo_url()) ?>" alt="" class="as-head-logo"><div><p class="as-eyebrow"><?= assignature_h($source['name']) ?> · <?= assignature_h($request['code']) ?></p><h1><?= assignature_h($request['title']) ?></h1><p><?= assignature_h($request['summary']) ?></p></div></div><a class="as-button as-button-light as-print-hide" href="<?= assignature_h(assignature_url('/front/index.php')) ?>">Voltar</a></header>
    <div class="as-detail-grid">
        <section class="as-panel"><div class="as-section-title"><h2>Pedido</h2><span class="as-status <?= $request['status'] === 'done' ? 'is-done' : 'is-pending' ?>"><?= $request['status'] === 'done' ? 'Assinado' : 'Pendente' ?></span></div>
            <?php if ((string) ($payload['description'] ?? '') !== ''): ?><p><?= nl2br(assignature_h($payload['description'])) ?></p><?php endif; ?>
            <dl class="as-facts"><div><dt>Origem</dt><dd><?= assignature_h($source['name']) ?></dd></div><div><dt>Referência</dt><dd><?= assignature_h($request['code']) ?></dd></div>
            <?php if (isset($payload['board']['name'])): ?><div><dt>Quadro</dt><dd><?= assignature_h($payload['board']['name']) ?></dd></div><?php endif; ?>
            <?php if (isset($payload['technician']['name'])): ?><div><dt>Técnico</dt><dd><?= assignature_h($payload['technician']['name']) ?></dd></div><?php endif; ?></dl>
            <?php if ($machines !== []): ?><h3>Máquinas</h3><div class="as-machines"><?php foreach ($machines as $machine): if (!is_array($machine)) { continue; } $label = trim((string) ($machine['label'] ?? '')); ?><div><strong><?= assignature_h($label !== '' ? $label : ($machine['model_name'] ?? 'Máquina')) ?></strong><span><?= assignature_h($machine['serial'] ?? '') ?></span><small><?= assignature_h($machine['status_label'] ?? '') ?></small></div><?php endforeach; ?></div><?php endif; ?>
        </section>

        <?php if ($request['status'] === 'done'): ?>
            <section class="as-panel as-receipt"><h2>Comprovante de assinatura</h2><p>Assinado por <strong><?= assignature_h($request['signer_name']) ?></strong> em <?= assignature_h($request['signed_at']) ?>.</p>
                <?php if ($batch !== null): ?><p>Técnico: <?= assignature_h(PluginAssignatureBatch::technicianName($batch)) ?></p><p>Documento de quem retirou: <?= assignature_h($batch['document_type']) ?> <?= assignature_h(PluginAssignatureBatch::decryptedDocument($batch)) ?></p><?php elseif ($request['signer_document'] !== ''): ?><p>Documento: <?= assignature_h($request['signer_document']) ?></p><?php endif; ?>
                <?php if ($batch !== null && (string) ($batch['technician_signature_png'] ?? '') !== ''): ?><p>Assinatura do técnico responsável:</p><div class="as-signed-image"><img src="data:image/png;base64,<?= assignature_h($batch['technician_signature_png']) ?>" alt="Assinatura de <?= assignature_h(PluginAssignatureBatch::technicianName($batch)) ?>"></div><?php endif; ?>
                <?php if ($batch !== null && (string) $batch['method'] === 'paper'): ?><p><i class="ti ti-file-check"></i> Assinado em papel e arquivo registrado no Assignat-ure.</p><a class="as-button as-button-light" href="<?= assignature_h(assignature_url('/front/file.php?id=' . (int) $batch['id'])) ?>">Ver arquivo assinado</a><?php else: ?><div class="as-signed-image"><img src="data:image/png;base64,<?= assignature_h($batch['signature_png'] ?? $request['signature_png']) ?>" alt="Assinatura de <?= assignature_h($request['signer_name']) ?>"></div><?php endif; ?>
                <p class="as-hint">Registrado por <?= assignature_h($signedBy ?: 'usuário GLPI #' . $request['signed_by_users_id']) ?> · comprovante #<?= (int) $request['id'] ?></p>
                <?php if ($request['callback_state'] !== 'confirmed'): ?><p class="as-warning as-print-hide">Assinatura salva. A confirmação na origem está pendente; o sistema tentará novamente.</p><?php endif; ?>
                <?php if ($batch !== null): ?><a class="as-button as-print-hide" href="<?= assignature_h(assignature_url('/front/batch.php?id=' . (int) $batch['id'])) ?>">Ver termo completo</a><?php else: ?><button type="button" class="as-button as-print-hide" onclick="window.print()">Imprimir comprovante</button><?php endif; ?>
            </section>
        <?php elseif ($canSign): ?>
            <section class="as-panel"><h2>Assinar retirada</h2><p class="as-hint">Confira os dados do pedido. Você poderá assinar agora ou preparar o termo para assinatura em papel.</p><a class="as-button as-button-wide" href="<?= assignature_h(assignature_url('/front/batch.php?ids%5B%5D=' . (int) $request['id'])) ?>">Escolher como assinar <i class="ti ti-arrow-right"></i></a></section>
        <?php elseif ($batch !== null && (string) $batch['status'] === 'awaiting_upload'): ?>
            <section class="as-panel"><h2>Aguardando arquivo assinado</h2><p>Este pedido faz parte do termo #<?= (int) $batch['id'] ?>. Após enviar o arquivo, a retirada será concluída automaticamente.</p><a class="as-button" href="<?= assignature_h(assignature_url('/front/batch.php?id=' . (int) $batch['id'])) ?>">Abrir termo</a></section>
        <?php else: ?>
            <section class="as-panel"><h2>Aguardando assinatura</h2><p>Você pode visualizar este pedido, mas não tem permissão para coletar a assinatura.</p></section>
        <?php endif; ?>
    </div>
</main>
<?php Html::footer(); ?>
