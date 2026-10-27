(function () {
    const dialogo = document.getElementById('pqrsd-dialogo');
    if (!dialogo) {
        return;
    }

    const titulo = document.getElementById('pqrsd-dialogo-titulo');
    const texto = document.getElementById('pqrsd-dialogo-texto');
    const valor = document.getElementById('pqrsd-dialogo-valor');
    const cancelar = document.getElementById('pqrsd-dialogo-cancelar');
    const aceptar = document.getElementById('pqrsd-dialogo-aceptar');
    const aviso = document.getElementById('pqrsd-aviso');
    let origen = null;
    let formulario = null;
    let ocupado = false;

    function claseBoton(variante) {
        aceptar.className = 'btn ' + (variante === 'peligro' ? 'btn-red' : 'btn-green');
    }

    function abrir(opciones) {
        if (ocupado || dialogo.open) {
            return;
        }

        origen = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        titulo.textContent = opciones.titulo || 'Aviso';
        texto.textContent = opciones.mensaje || '';
        if (opciones.valor) {
            valor.hidden = false;
            valor.value = opciones.valor;
        } else {
            valor.hidden = true;
            valor.value = '';
        }

        formulario = opciones.formulario || null;
        const soloCierre = formulario === null;
        cancelar.hidden = soloCierre;
        aceptar.disabled = false;
        cancelar.disabled = false;
        aceptar.removeAttribute('aria-busy');
        aceptar.textContent = opciones.aceptar || (soloCierre ? 'Entendido' : 'Confirmar');
        claseBoton(opciones.variante || (soloCierre ? 'info' : 'peligro'));
        dialogo.showModal();
        (soloCierre ? aceptar : cancelar).focus();
    }

    function cerrar() {
        if (dialogo.open) {
            dialogo.close();
        }
    }

    dialogo.addEventListener('close', () => {
        const volver = origen;
        formulario = null;
        ocupado = false;
        aceptar.disabled = false;
        cancelar.disabled = false;
        aceptar.removeAttribute('aria-busy');
        if (volver && document.contains(volver)) {
            volver.focus();
        }
    });

    cancelar.addEventListener('click', () => {
        if (!ocupado) {
            cerrar();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !dialogo.open || ocupado) {
            return;
        }
        event.preventDefault();
        cerrar();
    });

    aceptar.addEventListener('click', () => {
        if (!formulario) {
            cerrar();
            return;
        }
        if (ocupado) {
            return;
        }

        ocupado = true;
        aceptar.disabled = true;
        cancelar.disabled = true;
        aceptar.textContent = 'Procesando…';
        aceptar.setAttribute('aria-busy', 'true');
        formulario.dataset.pqrsdConfirmado = '1';
        formulario.submit();
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-pqrsd-confirmar')) {
            return;
        }
        if (form.dataset.pqrsdConfirmado === '1') {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        abrir({
            titulo: form.dataset.titulo || '',
            mensaje: form.dataset.mensaje || '',
            aceptar: form.dataset.aceptar || '',
            variante: form.dataset.variante || 'peligro',
            formulario: form,
        });
    }, true);

    window.pqrsdAviso = function (opciones) {
        abrir(opciones || {});
    };

    window.pqrsdEstado = function (mensaje) {
        if (!aviso) {
            return;
        }
        aviso.className = 'alert alert-success';
        aviso.hidden = false;
        aviso.textContent = mensaje;
    };
})();
