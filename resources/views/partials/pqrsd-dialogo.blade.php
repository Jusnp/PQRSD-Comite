<dialog class="pqrsd-modal" id="pqrsd-dialogo" aria-modal="true" aria-labelledby="pqrsd-dialogo-titulo" aria-describedby="pqrsd-dialogo-texto">
    <div class="pqrsd-modal-contenido">
        <h2 id="pqrsd-dialogo-titulo"></h2>
        <p id="pqrsd-dialogo-texto"></p>
        <input class="form-input" id="pqrsd-dialogo-valor" type="text" readonly hidden autocomplete="off" aria-label="Texto para copiar">
        <div class="pqrsd-modal-acciones">
            <button class="btn btn-secondary" id="pqrsd-dialogo-cancelar" type="button">Cancelar</button>
            <button class="btn btn-red" id="pqrsd-dialogo-aceptar" type="button">Confirmar</button>
        </div>
    </div>
</dialog>
<div id="pqrsd-aviso" role="status" hidden></div>
<script src="{{ asset('js/pqrsd-dialogo.js') }}?v={{ filemtime(public_path('js/pqrsd-dialogo.js')) }}"></script>
