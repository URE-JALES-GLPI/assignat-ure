<?php
include('../../../inc/includes.php');
require_once GLPI_ROOT . '/plugins/assignature/inc/request.class.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!isset($_POST['sign']) || empty($_POST['accepted'])) {
            throw new RuntimeException('Confirme que os dados foram conferidos.');
        }
        PluginAssignatureRequest::sign(
            $id,
            $userId,
            (string) ($_POST['signer_name'] ?? ''),
            (string) ($_POST['signer_document'] ?? ''),
            (string) ($_POST['signature_png'] ?? '')
        );
        assignature_flash('Assinatura registrada. O comprovante está disponível.');
    } catch (Throwable $e) {
        assignature_flash($e->getMessage(), false);
    }
    Html::redirect(assignature_url('/front/request.php?id=' . $id));
}

$canSign = (string) $request['status'] === 'pending'
    && PluginAssignatureScope::canSign($userId, (int) $source['id']);
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
    <header class="as-page-head"><div><p class="as-eyebrow"><?= assignature_h($source['name']) ?> · <?= assignature_h($request['code']) ?></p><h1><?= assignature_h($request['title']) ?></h1><p><?= assignature_h($request['summary']) ?></p></div><a class="as-button as-button-light as-print-hide" href="<?= assignature_h(assignature_url('/front/index.php')) ?>">Voltar</a></header>
    <div class="as-detail-grid">
        <section class="as-panel"><div class="as-section-title"><h2>Pedido</h2><span class="as-status <?= $request['status'] === 'done' ? 'is-done' : 'is-pending' ?>"><?= $request['status'] === 'done' ? 'Concluída' : 'Pendente' ?></span></div>
            <?php if ((string) ($payload['description'] ?? '') !== ''): ?><p><?= nl2br(assignature_h($payload['description'])) ?></p><?php endif; ?>
            <dl class="as-facts"><div><dt>Origem</dt><dd><?= assignature_h($source['name']) ?></dd></div><div><dt>Referência</dt><dd><?= assignature_h($request['code']) ?></dd></div>
            <?php if (isset($payload['board']['name'])): ?><div><dt>Quadro</dt><dd><?= assignature_h($payload['board']['name']) ?></dd></div><?php endif; ?>
            <?php if (isset($payload['technician']['name'])): ?><div><dt>Técnico</dt><dd><?= assignature_h($payload['technician']['name']) ?></dd></div><?php endif; ?></dl>
            <?php if ($machines !== []): ?><h3>Máquinas</h3><div class="as-machines"><?php foreach ($machines as $machine): if (!is_array($machine)) { continue; } $label = trim((string) ($machine['label'] ?? '')); ?><div><strong><?= assignature_h($label !== '' ? $label : ($machine['model_name'] ?? 'Máquina')) ?></strong><span><?= assignature_h($machine['serial'] ?? '') ?></span><small><?= assignature_h($machine['status_label'] ?? '') ?></small></div><?php endforeach; ?></div><?php endif; ?>
        </section>

        <?php if ($request['status'] === 'done'): ?>
            <section class="as-panel as-receipt"><h2>Comprovante de assinatura</h2><p>Assinado por <strong><?= assignature_h($request['signer_name']) ?></strong> em <?= assignature_h($request['signed_at']) ?>.</p>
                <?php if ($request['signer_document'] !== ''): ?><p>Documento: <?= assignature_h($request['signer_document']) ?></p><?php endif; ?>
                <div class="as-signed-image"><img src="data:image/png;base64,<?= assignature_h($request['signature_png']) ?>" alt="Assinatura de <?= assignature_h($request['signer_name']) ?>"></div>
                <p class="as-hint">Registrado por <?= assignature_h($signedBy ?: 'usuário GLPI #' . $request['signed_by_users_id']) ?> · comprovante #<?= (int) $request['id'] ?></p>
                <?php if ($request['callback_state'] !== 'confirmed'): ?><p class="as-warning as-print-hide">Assinatura salva. A confirmação na origem está pendente; o sistema tentará novamente.</p><?php endif; ?>
                <button type="button" class="as-button as-print-hide" onclick="window.print()">Imprimir comprovante</button>
            </section>
        <?php elseif ($canSign): ?>
            <section class="as-panel"><h2>Assinar retirada</h2><p class="as-hint">Confira os dados e peça à pessoa responsável para assinar no quadro abaixo.</p>
                <form method="post" class="as-form" id="as-sign-form" data-sign-form>
                    <?= assignature_csrf_field() ?><input type="hidden" name="id" value="<?= (int) $request['id'] ?>"><input type="hidden" name="sign" value="1"><input type="hidden" name="signature_png" data-signature-value>
                    <label>Nome de quem assina<input name="signer_name" required maxlength="255" autocomplete="name"></label>
                    <label>Documento (opcional)<input name="signer_document" maxlength="100"></label>
                    <div class="as-signature-field"><span>Assinatura</span><canvas data-signature-canvas aria-label="Área para desenhar a assinatura"></canvas><button type="button" class="as-text-button" data-signature-clear>Limpar assinatura</button></div>
                    <label class="as-checkbox"><input type="checkbox" name="accepted" value="1" required> Confirmei a identidade e os dados deste pedido</label>
                    <p class="as-form-error" data-signature-error hidden></p><button class="as-button as-button-wide">Concluir assinatura</button>
                </form>
            </section>
        <?php else: ?>
            <section class="as-panel"><h2>Aguardando assinatura</h2><p>Você pode visualizar este pedido, mas não tem permissão para coletar a assinatura.</p></section>
        <?php endif; ?>
    </div>
</main>
<?php Html::footer(); ?>
