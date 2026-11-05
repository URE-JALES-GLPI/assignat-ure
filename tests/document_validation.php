<?php
/** Execute com: php tests/document_validation.php */
define('GLPI_ROOT', __DIR__);
class CommonDBTM {}
require_once dirname(__DIR__) . '/inc/remote.class.php';

function expectDocumentError(string $type, string $value): void
{
    try {
        PluginAssignatureBatch::document($type, $value);
    } catch (RuntimeException $e) {
        return;
    }
    throw new RuntimeException('Documento inválido aceito: ' . $type . ' ' . $value);
}

if (PluginAssignatureBatch::document('CPF', '52998224725') !== '529.982.247-25') {
    throw new RuntimeException('Máscara de CPF incorreta.');
}
if (PluginAssignatureBatch::document('RG', '123456789') !== '12.345.678-9') {
    throw new RuntimeException('Máscara de RG incorreta.');
}
expectDocumentError('CPF', '11111111111');
expectDocumentError('CPF', '52998224724');
expectDocumentError('RG', '123');
expectDocumentError('RG', '1234X6789');
echo "Validação de CPF e RG: OK\n";

$singlePaper = PluginAssignatureBatch::printCommand('HP_PeB', true, 1, '/tmp/termo.pdf');
$duplexPaper = PluginAssignatureBatch::printCommand('HP_PeB', true, 2, '/tmp/termo.pdf');
$duplexDigital = PluginAssignatureBatch::printCommand('HP_PeB', false, 2, '/tmp/termo.pdf');
if ($singlePaper[4] !== '1' || in_array('sides=two-sided-long-edge', $singlePaper, true)
    || $duplexPaper[4] !== '1' || !in_array('sides=two-sided-long-edge', $duplexPaper, true)
    || !in_array('Duplex=DuplexNoTumble', $duplexPaper, true)
    || !in_array('Option1=True', $duplexPaper, true)
    || $duplexDigital[4] !== '2' || !in_array('sides=two-sided-long-edge', $duplexDigital, true)) {
    throw new RuntimeException('Opções de cópias e frente e verso incorretas.');
}
if (!in_array('Resolution=600dpi', $singlePaper, true) || !in_array('Resolution=600dpi', $duplexDigital, true)) {
    throw new RuntimeException('A impressão deve sair em 600 dpi.');
}
echo "Impressão: 1 cópia no papel, 2 no digital, frente e verso em múltiplas páginas: OK\n";

foreach (['https://script.google.com/macros/s/AKfy_c-1/exec', 'https://script.google.com/a/macros/ure.sp.gov.br/s/AKfy/exec'] as $url) {
    if (!PluginAssignatureRemote::validUrl($url)) {
        throw new RuntimeException('URL do Apps Script recusada: ' . $url);
    }
}
foreach (['http://script.google.com/macros/s/AK/exec', 'https://script.google.com/macros/s/AK/dev', 'https://script.google.com.evil.test/macros/s/AK/exec', ''] as $url) {
    if (PluginAssignatureRemote::validUrl($url)) {
        throw new RuntimeException('URL inválida aceita: ' . $url);
    }
}
echo "URL do Apps Script: OK\n";
