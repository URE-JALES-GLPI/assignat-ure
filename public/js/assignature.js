(function () {
    'use strict';
    var form = document.querySelector('[data-sign-form]');
    if (!form) { return; }
    var canvas = form.querySelector('[data-signature-canvas]');
    var clear = form.querySelector('[data-signature-clear]');
    var value = form.querySelector('[data-signature-value]');
    var error = form.querySelector('[data-signature-error]');
    if (!canvas || !value) { return; }

    var ratio = Math.max(1, Math.min(window.devicePixelRatio || 1, 3));
    var width = Math.max(300, Math.round(canvas.getBoundingClientRect().width));
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(180 * ratio);
    var ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = 2.6;
    ctx.strokeStyle = '#17223b';
    var drawing = false;
    var signed = false;

    function point(event) {
        var rect = canvas.getBoundingClientRect();
        return {
            x: (event.clientX - rect.left) * (width / rect.width),
            y: (event.clientY - rect.top) * (180 / rect.height)
        };
    }
    canvas.addEventListener('pointerdown', function (event) {
        event.preventDefault();
        canvas.setPointerCapture(event.pointerId);
        var p = point(event);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        ctx.lineTo(p.x + 0.1, p.y + 0.1);
        ctx.stroke();
        drawing = true;
        signed = true;
        error.hidden = true;
    });
    canvas.addEventListener('pointermove', function (event) {
        if (!drawing) { return; }
        event.preventDefault();
        var p = point(event);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    });
    function stop() { drawing = false; }
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    if (clear) {
        clear.addEventListener('click', function () {
            ctx.clearRect(0, 0, width, 180);
            signed = false;
            value.value = '';
        });
    }
    form.addEventListener('submit', function (event) {
        if (!signed) {
            event.preventDefault();
            error.textContent = 'Desenhe a assinatura antes de concluir.';
            error.hidden = false;
            canvas.focus();
            return;
        }
        value.value = canvas.toDataURL('image/png');
    });
}());
