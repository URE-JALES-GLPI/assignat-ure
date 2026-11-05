/**
 * [URE] Assignat-ure: página de assinatura por link (Google Apps Script).
 *
 * O GLPI fica em rede interna, então o Google nunca chama o GLPI: é o GLPI que
 * chama este script para criar pedidos e buscar as assinaturas.
 * Cada pedido é um arquivo <token>.json em uma pasta do Drive. Depois de assinado
 * vira <token>.signed.json e, quando o GLPI confirma o recebimento, é apagado.
 * O segredo fica nas propriedades do script, nunca neste arquivo.
 */
var FOLDER_NAME = 'Assignat-ure - assinaturas pendentes';
var MAX_SIGNATURE_LENGTH = 450000;
var MAX_LIST = 20;

/** Rode uma vez pelo editor: cria a pasta e o segredo e mostra o segredo no registro. */
function configurar() {
  var props = PropertiesService.getScriptProperties();
  if (!props.getProperty('FOLDER_ID')) {
    props.setProperty('FOLDER_ID', DriveApp.createFolder(FOLDER_NAME).getId());
  }
  if (!props.getProperty('SECRET')) {
    props.setProperty('SECRET', (Utilities.getUuid() + Utilities.getUuid()).replace(/-/g, ''));
  }
  Logger.log('Segredo para colar no Assignat-ure: ' + props.getProperty('SECRET'));
}

function doPost(e) {
  try {
    var body = JSON.parse(e.postData.contents);
    // Chamadas da página de assinatura: valem só para o pedido do token, sem segredo.
    if (body.action === 'term') {
      var state = readState_(String(body.token || ''));
      return json_(state.problem ? { ok: true, problem: state.problem } : { ok: true, term: state.data.term });
    }
    if (body.action === 'sign') {
      assinar(String(body.token || ''), body.form);
      return json_({ ok: true });
    }
    if (!secretOk_(body.secret)) {
      return json_({ ok: false, error: 'Segredo inválido.' });
    }
    switch (body.action) {
      case 'ping':
        folder_();
        return json_({ ok: true });
      case 'create':
        return json_(create_(body));
      case 'signed':
        return json_({ ok: true, signed: listSigned_() });
      case 'ack':
      case 'delete':
        return json_({ ok: true, removed: removeAll_(body.tokens) });
      default:
        return json_({ ok: false, error: 'Ação desconhecida.' });
    }
  } catch (error) {
    return json_({ ok: false, error: String(error && error.message || error) });
  }
}

function doGet(e) {
  var token = String(e && e.parameter && e.parameter.token || '');
  var state = readState_(token);
  var template = HtmlService.createTemplateFromFile('Assinar');
  template.problem = state.problem || '';
  template.term = state.data ? state.data.term : null;
  template.token = state.problem ? '' : token;
  return template.evaluate()
    .setTitle('Assinatura do termo de entrega')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1');
}

/** Registra a assinatura: usado pela página própria e pelos links antigos, via google.script.run. */
function assinar(token, form) {
  var lock = LockService.getScriptLock();
  lock.waitLock(15000);
  try {
    var state = readState_(token);
    if (state.problem) {
      throw new Error(state.problem);
    }
    var signed = validateForm_(form);
    signed.signed_at = new Date().toISOString();
    var file = state.file;
    file.setContent(JSON.stringify({ status: 'signed', token: token, signed: signed }));
    file.setName(token + '.signed.json');
    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

function create_(body) {
  var token = String(body.token || '');
  if (!validToken_(token) || !body.term || !Number(body.expires_at)) {
    throw new Error('Pedido inválido.');
  }
  var lock = LockService.getScriptLock();
  lock.waitLock(15000);
  try {
    // Reenvio do mesmo link: nunca sobrescreve o que já foi assinado.
    if (findFile_(token + '.signed.json')) {
      return { ok: true, already_signed: true };
    }
    var content = JSON.stringify({
      status: 'pending', token: token, expires_at: Number(body.expires_at), term: body.term
    });
    var file = findFile_(token + '.json');
    if (file) {
      file.setContent(content);
    } else {
      folder_().createFile(token + '.json', content, MimeType.PLAIN_TEXT);
    }
    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

function readState_(token) {
  if (!validToken_(token)) {
    return { problem: 'Este link não é válido.' };
  }
  if (findFile_(token + '.signed.json')) {
    return { problem: 'Este termo já foi assinado. Obrigado!' };
  }
  var file = findFile_(token + '.json');
  if (!file) {
    return { problem: 'Este link não está mais disponível. Fale com o técnico responsável.' };
  }
  var data = JSON.parse(file.getBlob().getDataAsString());
  if (Number(data.expires_at) * 1000 < Date.now()) {
    return { problem: 'Este link expirou. Peça ao técnico responsável um novo envio.' };
  }
  return { file: file, data: data };
}

function validateForm_(form) {
  form = form || {};
  var name = String(form.signer_name || '').trim().replace(/\s+/g, ' ').toUpperCase();
  if (name.split(' ').length < 2 || name.length > 255) {
    throw new Error('Informe nome e sobrenome de quem retira.');
  }
  var type = String(form.document_type || '');
  var raw = String(form.document || '').toUpperCase().replace(/[^0-9X]/g, '');
  if (type === 'CPF' ? !validCpf_(raw) : !(type === 'RG' && /^[0-9]{7,9}[0-9X]?$/.test(raw))) {
    throw new Error('Confira o documento informado.');
  }
  var signature = String(form.signature_png || '');
  if (signature.length > MAX_SIGNATURE_LENGTH || !/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/.test(signature)) {
    throw new Error('Desenhe a assinatura antes de concluir.');
  }
  if (form.accepted !== true) {
    throw new Error('Confirme que conferiu os dados do termo.');
  }
  return { signer_name: name, document_type: type, document: raw, signature_png: signature };
}

function validCpf_(cpf) {
  if (!/^[0-9]{11}$/.test(cpf) || /^(.)\1{10}$/.test(cpf)) {
    return false;
  }
  for (var length = 9; length <= 10; length++) {
    var sum = 0;
    for (var i = 0; i < length; i++) {
      sum += Number(cpf.charAt(i)) * (length + 1 - i);
    }
    var digit = (sum * 10) % 11;
    if ((digit === 10 ? 0 : digit) !== Number(cpf.charAt(length))) {
      return false;
    }
  }
  return true;
}

function listSigned_() {
  var signed = [];
  var files = folder_().searchFiles('title contains ".signed.json" and trashed = false');
  while (files.hasNext() && signed.length < MAX_LIST) {
    var data = JSON.parse(files.next().getBlob().getDataAsString());
    if (data.status === 'signed' && data.signed) {
      signed.push({
        token: data.token,
        signer_name: data.signed.signer_name,
        document_type: data.signed.document_type,
        document: data.signed.document,
        signature_png: data.signed.signature_png,
        signed_at: data.signed.signed_at
      });
    }
  }
  return signed;
}

function removeAll_(tokens) {
  var removed = 0;
  (Array.isArray(tokens) ? tokens : []).forEach(function (token) {
    if (!validToken_(String(token))) {
      return;
    }
    [token + '.json', token + '.signed.json'].forEach(function (name) {
      var file = findFile_(name);
      if (file) {
        // O Apps Script só manda para a lixeira: zera antes para não deixar CPF e assinatura lá.
        file.setContent('{}');
        file.setTrashed(true);
        removed++;
      }
    });
  });
  return removed;
}

function findFile_(name) {
  var files = folder_().getFilesByName(name);
  while (files.hasNext()) {
    var file = files.next();
    if (!file.isTrashed()) {
      return file;
    }
  }
  return null;
}

function folder_() {
  var id = PropertiesService.getScriptProperties().getProperty('FOLDER_ID');
  if (!id) {
    throw new Error('Rode a função configurar() no editor do Apps Script.');
  }
  return DriveApp.getFolderById(id);
}

function validToken_(token) {
  return /^[a-f0-9]{48}$/.test(token);
}

function secretOk_(secret) {
  var expected = PropertiesService.getScriptProperties().getProperty('SECRET');
  return !!expected && digest_(String(secret || '')) === digest_(expected);
}

function digest_(value) {
  return Utilities.base64Encode(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, value, Utilities.Charset.UTF_8));
}

function json_(value) {
  return ContentService.createTextOutput(JSON.stringify(value)).setMimeType(ContentService.MimeType.JSON);
}
