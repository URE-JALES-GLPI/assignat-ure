(function () {
    'use strict';

    var selection = document.querySelector('[data-batch-select]');
    if (selection) {
        var checks = Array.prototype.slice.call(document.querySelectorAll('[data-batch-check]'));
        var button = selection.querySelector('[data-batch-button]');
        var count = selection.querySelector('[data-selected-count]');
        function updateSelection() {
            var total = checks.filter(function (check) { return check.checked; }).length;
            count.textContent = String(total);
            button.disabled = total === 0;
        }
        checks.forEach(function (check) { check.addEventListener('change', updateSelection); });
        var all = selection.querySelector('[data-select-all]');
        all.addEventListener('click', function () {
            var shouldSelect = checks.some(function (check) { return !check.checked; });
            checks.forEach(function (check, index) { check.checked = shouldSelect && index < 100; });
            all.textContent = shouldSelect ? 'Limpar seleção' : 'Selecionar todos';
            updateSelection();
        });
        selection.addEventListener('submit', function (event) {
            if (checks.filter(function (check) { return check.checked; }).length > 100) {
                event.preventDefault();
                alert('Selecione no máximo 100 pedidos por lote.');
            }
        });
    }

    function digits(value) { return value.replace(/\D/g, ''); }
    function validCpf(value) {
        var raw = digits(value);
        if (raw.length !== 11 || /^(.)\1+$/.test(raw)) { return false; }
        for (var len = 9; len <= 10; len++) {
            var sum = 0;
            for (var i = 0; i < len; i++) { sum += Number(raw[i]) * (len + 1 - i); }
            var digit = (sum * 10) % 11;
            if (digit === 10) { digit = 0; }
            if (Number(raw[len]) !== digit) { return false; }
        }
        return true;
    }
    function maskCpf(value) {
        var raw = digits(value).slice(0, 11);
        return raw.slice(0, 3) + (raw.length > 3 ? '.' + raw.slice(3, 6) : '')
            + (raw.length > 6 ? '.' + raw.slice(6, 9) : '')
            + (raw.length > 9 ? '-' + raw.slice(9, 11) : '');
    }
    function maskRg(value) {
        var raw = value.toUpperCase().replace(/[^0-9X]/g, '').slice(0, 10);
        if (raw.length <= 2) { return raw; }
        if (raw.length <= 5) { return raw.slice(0, 2) + '.' + raw.slice(2); }
        if (raw.length <= 8) { return raw.slice(0, 2) + '.' + raw.slice(2, 5) + '.' + raw.slice(5); }
        if (raw.length === 9) { return raw.slice(0, 2) + '.' + raw.slice(2, 5) + '.' + raw.slice(5, 8) + '-' + raw.slice(8); }
        return raw.slice(0, 3) + '.' + raw.slice(3, 6) + '.' + raw.slice(6, 9) + '-' + raw.slice(9);
    }
    function bindDocument(form) {
        var input = form.querySelector('[data-document-input]');
        if (!input) { return function () { return true; }; }
        function type() {
            var selected = form.querySelector('input[name="document_type"]:checked');
            var select = form.querySelector('select[name="document_type"]');
            return selected ? selected.value : (select ? select.value : 'CPF');
        }
        function refresh() {
            var isCpf = type() === 'CPF';
            input.value = isCpf ? maskCpf(input.value) : maskRg(input.value);
            input.setAttribute('inputmode', isCpf ? 'numeric' : 'text');
            input.setAttribute('maxlength', isCpf ? '14' : '13');
            input.setAttribute('placeholder', isCpf ? '000.000.000-00' : '00.000.000-0');
            var xKey = form.querySelector('[data-key="X"]');
            if (xKey) { xKey.hidden = isCpf; }
        }
        input.addEventListener('input', refresh);
        form.querySelectorAll('[name="document_type"]').forEach(function (field) { field.addEventListener('change', function () { input.value = ''; refresh(); }); });
        form.querySelectorAll('[data-key]').forEach(function (key) {
            key.addEventListener('click', function () {
                var char = key.getAttribute('data-key');
                input.value = char === '⌫' ? input.value.slice(0, -1) : input.value + char;
                refresh();
                input.focus();
            });
        });
        refresh();
        return function () {
            if (type() === 'CPF') { return validCpf(input.value); }
            return /^[0-9]{7,9}[0-9X]?$/.test(input.value.toUpperCase().replace(/[^0-9X]/g, ''));
        };
    }
    var upload = document.querySelector('[data-paper-upload]');
    if (upload) {
        var validUploadDoc = bindDocument(upload);
        upload.addEventListener('submit', function (event) {
            if (!validUploadDoc()) {
                event.preventDefault();
                alert('Confira o número do documento antes de enviar.');
            }
        });
    }

    var form = document.querySelector('[data-batch-form]');
    if (!form) { return; }
    var flow = document.querySelector('[data-sign-flow]');
    var choices = document.querySelector('[data-sign-choices]');
    var steps = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
    var dots = Array.prototype.slice.call(form.querySelectorAll('[data-step-dot]'));
    var methodInput = form.querySelector('[data-method-value]');
    var next = form.querySelector('[data-step-next]');
    var back = form.querySelector('[data-step-back]');
    var submit = form.querySelector('[data-final-submit]');
    var error = form.querySelector('[data-signature-error]');
    var signer = form.querySelector('[name="signer_name"]');
    var documentInput = form.querySelector('[data-document-input]');
    var validDocument = bindDocument(form);
    var currentStep = 0;
    var signed = false;
    var canvasReady = false;
    var canvas = form.querySelector('[data-signature-canvas]');
    var signatureValue = form.querySelector('[data-signature-value]');
    var canvasContext, canvasWidth, drawing = false;

    function showError(message) {
        error.textContent = message;
        error.hidden = false;
    }
    function initCanvas() {
        if (canvasReady) { return; }
        canvasWidth = Math.max(300, Math.round(canvas.getBoundingClientRect().width));
        var ratio = Math.max(1, Math.min(window.devicePixelRatio || 1, 3));
        canvas.width = Math.round(canvasWidth * ratio);
        canvas.height = Math.round(180 * ratio);
        canvasContext = canvas.getContext('2d');
        canvasContext.scale(ratio, ratio);
        canvasContext.lineCap = 'round';
        canvasContext.lineJoin = 'round';
        canvasContext.lineWidth = 2.6;
        canvasContext.strokeStyle = '#17223b';
        canvasReady = true;
    }
    function point(event) {
        var rect = canvas.getBoundingClientRect();
        return { x: (event.clientX - rect.left) * canvasWidth / rect.width,
            y: (event.clientY - rect.top) * 180 / rect.height };
    }
    canvas.addEventListener('pointerdown', function (event) {
        if (methodInput.value !== 'digital') { return; }
        event.preventDefault();
        canvas.setPointerCapture(event.pointerId);
        var p = point(event);
        canvasContext.beginPath();
        canvasContext.moveTo(p.x, p.y);
        canvasContext.lineTo(p.x + 0.1, p.y + 0.1);
        canvasContext.stroke();
        drawing = true;
        signed = true;
        error.hidden = true;
    });
    canvas.addEventListener('pointermove', function (event) {
        if (!drawing) { return; }
        event.preventDefault();
        var p = point(event);
        canvasContext.lineTo(p.x, p.y);
        canvasContext.stroke();
    });
    function stop() { drawing = false; }
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    form.querySelector('[data-signature-clear]').addEventListener('click', function () {
        if (canvasReady) { canvasContext.clearRect(0, 0, canvasWidth, 180); }
        signed = false;
        signatureValue.value = '';
    });

    function showStep(index) {
        currentStep = index;
        steps.forEach(function (step, i) { step.hidden = i !== index; });
        dots.forEach(function (dot, i) { dot.classList.toggle('is-active', i === index); dot.classList.toggle('is-done', i < index); });
        back.hidden = index === 0;
        next.hidden = index === 4;
        submit.hidden = index !== 4;
        error.hidden = true;
        if (index === 4) { initCanvas(); }
    }
    function choose(method) {
        methodInput.value = method;
        choices.hidden = true;
        flow.hidden = false;
        flow.querySelector('[data-flow-title]').textContent = method === 'digital' ? 'Assinar agora' : 'Assinar depois';
        form.querySelector('[data-paper-person]').hidden = method !== 'paper';
        form.querySelector('[data-steps]').hidden = method !== 'digital';
        signer.required = method === 'digital';
        documentInput.required = method === 'digital';
        form.querySelectorAll('[name="document_type"]').forEach(function (field) { field.required = method === 'digital'; });
        form.querySelector('[name="accepted"]').required = method === 'digital';
        showStep(0);
        if (method === 'paper') {
            next.hidden = true;
            submit.hidden = false;
            submit.textContent = 'Gerar termo para impressão';
        } else {
            submit.textContent = 'Concluir assinatura do lote';
        }
        flow.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    document.querySelectorAll('[data-choose-method]').forEach(function (button) {
        button.addEventListener('click', function () { choose(button.getAttribute('data-choose-method')); });
    });
    document.querySelector('[data-back-choices]').addEventListener('click', function () {
        flow.hidden = true;
        choices.hidden = false;
        methodInput.value = '';
    });
    form.querySelector('[data-registered-signer]').addEventListener('change', function (event) {
        signer.value = event.target.value;
    });
    next.addEventListener('click', function () {
        var field = steps[currentStep].querySelector('input[required], select[required]');
        if (currentStep === 2) {
            field = steps[currentStep].querySelector('input[name="document_type"]:checked');
            if (!field) { showError('Escolha CPF ou RG.'); return; }
        } else if (field && !field.reportValidity()) { return; }
        if (currentStep === 3 && !validDocument()) {
            showError('Confira o documento. O CPF precisa ser válido.');
            return;
        }
        showStep(currentStep + 1);
    });
    back.addEventListener('click', function () { showStep(Math.max(0, currentStep - 1)); });
    form.addEventListener('submit', function (event) {
        if (methodInput.value === 'paper') { return; }
        if (!signer.value.trim() || !validDocument()) {
            event.preventDefault();
            showError('Confira o nome completo e o documento.');
            return;
        }
        if (!signed) {
            event.preventDefault();
            showError('Desenhe a assinatura antes de concluir.');
            return;
        }
        signatureValue.value = canvas.toDataURL('image/png');
        submit.disabled = true;
        submit.textContent = 'Registrando assinaturas...';
    });
}());
