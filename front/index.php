<?php
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/request.class.php';

Session::checkRight(PluginAssignatureProfile::RIGHT_VIEW, READ);
$userId = (int) Session::getLoginUserID();
$status = (string) ($_GET['status'] ?? 'pending');
if (!in_array($status, ['', 'pending', 'done'], true)) {
    $status = 'pending';
}
$sourceId = max(0, (int) ($_GET['source'] ?? 0));
PluginAssignatureRequest::syncDueSources($userId);
$allRequests = PluginAssignatureRequest::visible($userId, '', $sourceId);
$fingerprint = PluginAssignatureRequest::fingerprint($allRequests);
$requests = $status === '' ? $allRequests : array_values(array_filter(
    $allRequests,
    static fn (array $request): bool => (string) $request['status'] === $status
));
$sources = PluginAssignatureSource::all(true);
$hasSelectable = false;
foreach ($requests as $request) {
    if ((string) $request['status'] === 'pending' && (int) ($request['batches_id'] ?? 0) === 0
        && PluginAssignatureScope::canSign($userId, (int) $request['sources_id'])) {
        $hasSelectable = true;
        break;
    }
}
Html::header('Assinaturas — Assignat-ure', $_SERVER['PHP_SELF'], 'tools', 'PluginAssignatureApp');
?>
<main class="as-page" data-request-watch data-fingerprint="<?= assignature_h($fingerprint) ?>" data-source="<?= $sourceId ?>">
    <header class="as-page-head"><div class="as-head-brand"><img src="<?= assignature_h(assignature_logo_url()) ?>" alt="" class="as-head-logo"><div><p class="as-eyebrow">[URE] Assignat-ure</p><h1>Assinaturas</h1><p>Pedidos de assinatura dos plugins conectados.</p></div></div>
        <?php if (PluginAssignatureApp::canAdmin()): ?><a class="as-button as-button-light" href="<?= assignature_h(assignature_url('/front/admin.php')) ?>">Administração</a><?php endif; ?>
    </header>
    <?php if (PluginAssignatureApp::canAdmin()): foreach ($sources as $source): if ((string) ($source['last_error'] ?? '') !== ''): ?>
        <div class="as-warning">Falha ao sincronizar <?= assignature_h($source['name']) ?>: <?= assignature_h($source['last_error']) ?>. Verifique a URL e o token em <a href="<?= assignature_h(assignature_url('/front/admin.php?source=' . (int) $source['id'])) ?>">Administração</a>.</div>
    <?php endif; endforeach; endif; ?>
    <div class="as-tabs"><a href="?status=&amp;source=<?= $sourceId ?>" class="<?= $status === '' ? 'is-active' : '' ?>">Todas</a><a href="?status=pending&amp;source=<?= $sourceId ?>" class="<?= $status === 'pending' ? 'is-active' : '' ?>">Pendentes</a><a href="?status=done&amp;source=<?= $sourceId ?>" class="<?= $status === 'done' ? 'is-active' : '' ?>">Concluídas</a></div>
    <form method="get" class="as-filter"><label>Fonte<select name="source" onchange="this.form.submit()"><option value="0">Todas as fontes</option><?php foreach ($sources as $source): if (!PluginAssignatureApp::canAdmin() && !PluginAssignatureScope::canSee($userId, (int) $source['id'], 'pending') && !PluginAssignatureScope::canSee($userId, (int) $source['id'], 'done')) { continue; } ?><option value="<?= (int) $source['id'] ?>" <?= $sourceId === (int) $source['id'] ? 'selected' : '' ?>><?= assignature_h($source['name']) ?></option><?php endforeach; ?></select></label><input type="hidden" name="status" value="<?= assignature_h($status) ?>"></form>
    <?php if ($requests === []): ?><div class="as-empty"><div class="as-empty-icon"><i class="ti ti-signature"></i></div><h2>Nenhuma assinatura para mostrar</h2><p>Quando houver pedidos nas fontes liberadas para você, eles aparecerão aqui.</p></div><?php else: ?>
        <?php if ($hasSelectable): ?><div class="as-batch-entry" data-batch-entry><button type="button" class="as-button as-button-light" data-batch-start><i class="ti ti-checkbox"></i> Assinar em lote</button></div><?php endif; ?>
        <form id="as-batch-select" method="get" action="<?= assignature_h(assignature_url('/front/batch.php')) ?>" class="as-batch-toolbar" data-batch-select hidden><div><strong>Selecione os cards</strong><span>A mesma pessoa vai assinar todos os escolhidos.</span></div><div class="as-inline-actions"><button type="button" class="as-text-button" data-select-all>Selecionar todos</button><button type="button" class="as-text-button" data-batch-cancel>Cancelar</button><button type="submit" class="as-button" data-batch-button disabled>Continuar com selecionados <span data-selected-count>0</span></button></div></form>
        <div class="as-request-grid">
        <?php foreach ($requests as $request): $selectable = (string) $request['status'] === 'pending' && (int) ($request['batches_id'] ?? 0) === 0 && PluginAssignatureScope::canSign($userId, (int) $request['sources_id']); ?><article class="as-request"><div class="as-request-top"><span class="as-source-chip"><?= assignature_h($request['source_name']) ?></span><span class="as-status <?= $request['status'] === 'done' ? 'is-done' : 'is-pending' ?>"><?= $request['status'] === 'done' ? 'Assinado' : ((int) ($request['batches_id'] ?? 0) > 0 ? 'Aguardando arquivo' : 'Pendente') ?></span></div><a class="as-request-link" href="<?= assignature_h(assignature_url('/front/request.php?id=' . (int) $request['id'])) ?>"><strong><?= assignature_h($request['title']) ?></strong><p><?= assignature_h($request['summary']) ?></p></a><div class="as-request-bottom"><span>#<?= assignature_h($request['code']) ?></span><?php if ($selectable): ?><a href="<?= assignature_h(assignature_url('/front/request.php?id=' . (int) $request['id'])) ?>" data-single-action>Assinar <i class="ti ti-arrow-right"></i></a><label class="as-select-request" data-batch-option hidden><input type="checkbox" name="ids[]" value="<?= (int) $request['id'] ?>" form="as-batch-select" data-batch-check><span data-check-label>Selecionar</span></label><?php else: ?><a href="<?= assignature_h(assignature_url('/front/request.php?id=' . (int) $request['id'])) ?>"><?= $request['status'] === 'done' ? 'Ver comprovante' : 'Visualizar' ?> <i class="ti ti-arrow-right"></i></a><?php endif; ?></div></article><?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php Html::footer(); ?>
