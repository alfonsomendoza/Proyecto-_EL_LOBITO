@php
    use Illuminate\Support\Facades\Schema;

    $cfgPm = app(\App\Services\ConfiguracionService::class);
    $rolPm = strtoupper((string) (auth()->user()->rol->nombre ?? ''));
    $puedeExcepcionPm = in_array($rolPm, ['ADMIN', 'GERENTE'], true);
    $sujetoRetencionPm = isset($sujetoRetencion) ? (bool) $sujetoRetencion : false;
    $urlCuentasPm = isset($urlCuentas) ? $urlCuentas : null;
@endphp

<script>
(function () {
    'use strict';

    // Configuración fiscal global (misma fuente que el cobro y la prefactura).
    const DESCUENTO_FISCAL_HABILITADO = {{ $cfgPm->descuentoFiscalHabilitado() ? 'true' : 'false' }};
    const PROPINA_FISCAL_HABILITADA = {{ $cfgPm->propinaFiscalHabilitada() ? 'true' : 'false' }};
    const IVA_HABILITADO = {{ $cfgPm->ivaHabilitado() ? 'true' : 'false' }};
    const RET_DGI_HABILITADO = {{ $cfgPm->retencionDgiHabilitada() ? 'true' : 'false' }};
    const RET_ALC_HABILITADO = {{ $cfgPm->retencionAlcaldiaHabilitada() ? 'true' : 'false' }};
    const SUJETO_RETENCION = @js($sujetoRetencionPm);
    const PUEDE_EXCEPCION_FISCAL = {{ $puedeExcepcionPm ? 'true' : 'false' }};
    const URL_CUENTAS = @js($urlCuentasPm);

    function escaparHtml(texto) {
        if (texto === null || texto === undefined) return '';
        return String(texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Genera y abre la prefactura (SOLO LECTURA) en una pestaña nueva.
     *
     * Es la función ÚNICA de "generar la prefactura": la usan la venta
     * principal, las cuentas específicas, el módulo mesas/cuentas y el módulo
     * Cobros. No registra cobro, no emite factura y no modifica la cuenta, la
     * venta ni la mesa. La ruta es GET de solo lectura
     * (VentaController::prefactura).
     */
    window.abrirPrefacturaUrl = function (cuentaId, params) {
        let url = '{{ url("/pos/cuentas") }}' + '/' + encodeURIComponent(cuentaId) + '/prefactura';
        if (params && Object.keys(params).length) {
            const qs = new URLSearchParams(params).toString();
            if (qs) {
                url += '?' + qs;
            }
        }

        // Se abre en pestaña nueva. Si el navegador bloquea la ventana
        // emergente (window.open devuelve null), se navega en la pestaña
        // actual para que la prefactura SIEMPRE se muestre y pueda imprimirse.
        let ventana = null;
        try {
            ventana = window.open(url, '_blank');
        } catch (e) {
            ventana = null;
        }

        if (ventana) {
            try { ventana.opener = null; } catch (e) { /* sin acceso al opener */ }
            return;
        }

        window.location.href = url;
    };

    /**
     * Abre el modal de configuración fiscal de la prefactura.
     *
     * Es la función ÚNICA de "abrir el modal": la usan la venta principal,
     * las cuentas específicas, el módulo mesas/cuentas y el módulo Cobros.
     * Primero se muestra el modal (sin generar documento), se cargan los
     * valores globales y las excepciones permitidas, se permite modificar los
     * conceptos y actualizar el cálculo estimado, y solo al confirmar con
     * "Abrir e imprimir" se genera la prefactura. Cancelar no genera nada.
     *
     * @param {number|null} cuentaId  Id de la cuenta. Cuando es null se muestra
     *                                un selector para elegir entre las cuentas
     *                                abiertas de la mesa.
     * @param {number}   consumo      Importe de consumo de la cuenta (estimado).
     */
    window.abrirPrefacturaModal = function (cuentaId, consumo = 0) {
        const modoSeleccion = cuentaId === null;

        // Estado de los conceptos fiscales. Se inicializan desde la
        // configuración global. Solo ADMIN y GERENTE pueden modificarlos por
        // operación; CAJERO usa siempre la configuración global. DGI y
        // Alcaldía solo pueden aplicarse cuando el cliente esté sujeto a
        // retenciones.
        let prefDescuentoActivo = DESCUENTO_FISCAL_HABILITADO;
        let prefPropinaActivo = PROPINA_FISCAL_HABILITADA;
        let prefIvaActivo = IVA_HABILITADO;
        let prefRetDgiActivo = RET_DGI_HABILITADO && SUJETO_RETENCION;
        let prefRetAlcActivo = RET_ALC_HABILITADO && SUJETO_RETENCION;

        const consumoFijo = Number(consumo || 0);

        const obtenerConsumo = function () {
            if (modoSeleccion) {
                const sel = document.getElementById('cuenta-prefactura');
                return sel ? Number(sel.options[sel.selectedIndex]?.dataset?.total || 0) : 0;
            }
            return consumoFijo;
        };

        const actualizarResumen = function () {
            const cons = obtenerConsumo();
            const modoD = document.getElementById('pref-desc-modo').value;
            const modoP = document.getElementById('pref-prop-modo').value;
            const inpD = document.getElementById('pref-desc');
            const inpP = document.getElementById('pref-prop');
            const res = document.getElementById('pref-resumen');

            const descTexto = (inpD && inpD.value !== null ? inpD.value : '').trim();
            const propTexto = (inpP && inpP.value !== null ? inpP.value : '').trim();
            let desc = 0, prop = 0;

            const vd = descTexto === ''
                ? 0
                : (modoD === 'porcentaje' ? numeroPorcentaje(descTexto, 2) : numeroEstricto(descTexto, 2));
            if (vd === null) {
                res.innerHTML = '<span style="color:#b91c1c;">Descuento inválido: usa solo números, sin letras ni notación científica, con hasta 2 decimales.</span>';
                return;
            }
            if (modoD === 'porcentaje' && vd > 100) {
                res.innerHTML = '<span style="color:#b91c1c;">El porcentaje de descuento debe estar entre 0 y 100.</span>';
                return;
            }
            if (vd >= 0) {
                desc = modoD === 'porcentaje' ? (cons * vd / 100) : vd;
            }

            const vp = propTexto === ''
                ? 0
                : (modoP === 'porcentaje' ? numeroPorcentaje(propTexto, 2) : numeroEstricto(propTexto, 2));
            if (vp === null) {
                res.innerHTML = '<span style="color:#b91c1c;">Propina inválida: usa solo números, sin letras ni notación científica, con hasta 2 decimales.</span>';
                return;
            }
            if (vp >= 0) {
                prop = modoP === 'porcentaje' ? ((cons - desc) * vp / 100) : vp;
            }

            // Respeta los toggles activos: si un concepto está desactivado,
            // no se aplica ni se muestra.
            const descAplicado = prefDescuentoActivo ? desc : 0;
            const propAplicada = prefPropinaActivo ? prop : 0;
            const base = Math.max(0, cons - descAplicado);
            const iva = prefIvaActivo ? (cons * 0.15) : 0;
            const retDgi = prefRetDgiActivo ? (cons * 0.02) : 0;
            const retAlc = prefRetAlcActivo ? (cons * 0.01) : 0;
            const totalRet = retDgi + retAlc;
            const totalEst = base + propAplicada + iva - totalRet;

            res.innerHTML = `Consumo: C$ ${cons.toFixed(2)}${prefIvaActivo ? `<br>IVA (15%): C$ ${iva.toFixed(2)}` : ''}${descAplicado > 0 ? `<br>Descuento: C$ ${descAplicado.toFixed(2)}` : ''}<br>Base: C$ ${base.toFixed(2)}${propAplicada > 0 ? `<br>Propina: C$ ${propAplicada.toFixed(2)}` : ''}${totalRet > 0 ? `<br>Ret. DGI (2%): C$ ${retDgi.toFixed(2)}<br>Ret. Alcaldía (1%): C$ ${retAlc.toFixed(2)}` : ''}<br><strong>Total estimado: C$ ${totalEst.toFixed(2)}</strong><br><em style="color:#666;">Estimado, no confirmado</em>`;
        };

        const buildHtml = function (opcionesSelector) {
            const selectorHtml = modoSeleccion
                ? `<label style="display:block;margin-bottom:4px;">Cuenta</label>
                   <select id="cuenta-prefactura" class="swal2-select" style="width:100%;margin:4px 0;">${opcionesSelector || ''}</select>`
                : `<div><strong>Cuenta:</strong> ${cuentaId}</div>`;

            const toggleHtml = function (concepto, activo, etiqueta) {
                const puedeCambiar = PUEDE_EXCEPCION_FISCAL;
                const esRetencion = (concepto === 'dgi' || concepto === 'alc');
                const sujeto = SUJETO_RETENCION;
                const desactivadoRet = (!sujeto && esRetencion);
                const desactivadoRol = (!puedeCambiar && !activo);
                const disabled = desactivadoRet || desactivadoRol;
                const style = `min-height:32px;padding:2px 8px;border:1px solid ${activo ? '#47c363' : '#ccc'};background:${activo ? '#e7f7ed' : '#f5f5f5'};color:${activo ? '#1e7e46' : '#999'};border-radius:4px;cursor:${(activo || puedeCambiar) && !disabled ? 'pointer' : 'not-allowed'};font-size:11px;font-weight:700;${disabled ? 'opacity:0.5;' : ''}`;
                const title = desactivadoRet ? 'Cliente no sujeto a retenciones' : (desactivadoRol ? 'Solo ADMIN/GERENTE pueden activar conceptos deshabilitados' : '');
                return `<button type="button" class="pref-fiscal-toggle" data-concepto="${concepto}" data-activo="${activo ? '1' : '0'}" style="${style}" ${disabled ? 'disabled' : ''} title="${title}">${etiqueta}</button>`;
            };

            return `<div style="text-align:left;font-size:12px;">
                ${selectorHtml}
                <div style="border-top:1px dashed #aaa;margin-top:8px;padding-top:8px;">
                    <label style="display:block;"><strong>Descuento</strong></label>
                    <select id="pref-desc-modo" class="swal2-select" style="margin:4px 0;width:100%;">
                        <option value="porcentaje">Porcentaje (%)</option>
                        <option value="importe">Importe fijo (C$)</option>
                    </select>
                    <input id="pref-desc" class="swal2-input" style="margin:4px 0;width:100%;" placeholder="Descuento (%)" autocomplete="off">
                    <label style="display:block;margin-top:6px;"><strong>Propina</strong></label>
                    <select id="pref-prop-modo" class="swal2-select" style="margin:4px 0;width:100%;">
                        <option value="porcentaje">Porcentaje (%)</option>
                        <option value="importe">Importe fijo (C$)</option>
                    </select>
                    <input id="pref-prop" class="swal2-input" style="margin:4px 0;width:100%;" placeholder="Propina (%)" autocomplete="off">
                    <div id="pref-resumen" style="margin-top:8px;font-size:12px;color:#333;"></div>
                </div>
                {{-- Controles fiscales individuales por concepto (excepciones por operación) --}}
                <div style="border-top:1px dashed #aaa;margin-top:8px;padding-top:8px;">
                    <label style="display:block;"><strong>Conceptos fiscales</strong></label>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px;">
                        ${toggleHtml('descuento', prefDescuentoActivo, 'Descuento')}
                        ${toggleHtml('propina', prefPropinaActivo, 'Propina')}
                        ${toggleHtml('iva', prefIvaActivo, 'IVA 15%')}
                        ${toggleHtml('dgi', prefRetDgiActivo, 'Ret. DGI 2%')}
                        ${toggleHtml('alc', prefRetAlcActivo, 'Ret. Alcaldía 1%')}
                    </div>
                    <div style="font-size:10px;color:#666;margin-top:4px;">ADMIN/GERENTE pueden activar/desactivar por operación. DGI/Alcaldía requieren cliente sujeto a retenciones.</div>
                </div>
                <p style="font-size:11px;color:#666;margin-top:6px;">Documento de consulta: NO es factura fiscal. Valores temporales, sin persistencia.</p>
            </div>`;
        };

        const mostrar = function (opcionesSelector) {
            Swal.fire({
                title: 'Imprimir prefactura',
                html: buildHtml(opcionesSelector),
                showCancelButton: true,
                confirmButtonText: 'Abrir e imprimir',
                cancelButtonText: 'Cancelar',
                didOpen: function () {
                    const selCuenta = document.getElementById('cuenta-prefactura');
                    const modoD = document.getElementById('pref-desc-modo');
                    const modoP = document.getElementById('pref-prop-modo');
                    const inpD = document.getElementById('pref-desc');
                    const inpP = document.getElementById('pref-prop');
                    const res = document.getElementById('pref-resumen');

                    if (modoD) { modoD.addEventListener('change', function () { if (inpD) inpD.value = ''; actualizarResumen(); }); }
                    if (modoP) { modoP.addEventListener('change', function () { if (inpP) inpP.value = ''; actualizarResumen(); }); }
                    if (inpD) { inpD.addEventListener('input', actualizarResumen); }
                    if (inpP) { inpP.addEventListener('input', actualizarResumen); }
                    if (selCuenta) { selCuenta.addEventListener('change', actualizarResumen); }

                    document.querySelectorAll('.pref-fiscal-toggle').forEach(function (boton) {
                        const concepto = boton.dataset.concepto;
                        const esRetencion = (concepto === 'dgi' || concepto === 'alc');
                        const sujeto = SUJETO_RETENCION;
                        const puedeCambiar = PUEDE_EXCEPCION_FISCAL;
                        const estaActivo = boton.dataset.activo === '1';

                        if (!sujeto && esRetencion) {
                            boton.disabled = true;
                            boton.style.cursor = 'not-allowed';
                            boton.style.opacity = '0.5';
                            boton.title = 'Cliente no sujeto a retenciones';
                        } else if (!puedeCambiar && !estaActivo) {
                            boton.disabled = true;
                            boton.style.cursor = 'not-allowed';
                            boton.style.opacity = '0.5';
                            boton.title = 'Solo ADMIN/GERENTE pueden activar conceptos deshabilitados';
                        }

                        boton.addEventListener('click', function () {
                            if (boton.disabled) return;
                            const estabaActivo = boton.dataset.activo === '1';
                            boton.dataset.activo = estabaActivo ? '0' : '1';
                            const ahoraActivo = boton.dataset.activo === '1';
                            if (ahoraActivo) {
                                boton.style.background = '#e7f7ed';
                                boton.style.color = '#1e7e46';
                                boton.style.borderColor = '#47c363';
                            } else {
                                boton.style.background = '#f5f5f5';
                                boton.style.color = '#999';
                                boton.style.borderColor = '#ccc';
                            }
                            if (concepto === 'descuento') prefDescuentoActivo = ahoraActivo;
                            if (concepto === 'propina') prefPropinaActivo = ahoraActivo;
                            if (concepto === 'iva') prefIvaActivo = ahoraActivo;
                            if (concepto === 'dgi') prefRetDgiActivo = ahoraActivo;
                            if (concepto === 'alc') prefRetAlcActivo = ahoraActivo;
                            actualizarResumen();
                        });
                    });

                    actualizarResumen();
                },
                preConfirm: function () {
                    const modoD = document.getElementById('pref-desc-modo').value;
                    const modoP = document.getElementById('pref-prop-modo').value;
                    const inpD = document.getElementById('pref-desc').value;
                    const inpP = document.getElementById('pref-prop').value;
                    const params = {};

                    if (inpD !== '') {
                        const vd = modoD === 'porcentaje' ? numeroPorcentaje(inpD, 2) : numeroEstricto(inpD, 2);
                        if (vd === null) {
                            return Swal.showValidationMessage('El descuento debe ser un número válido (sin letras, sin notación científica y con hasta 2 decimales).');
                        }
                        if (modoD === 'porcentaje' && (vd < 0 || vd > 100)) {
                            return Swal.showValidationMessage('El porcentaje de descuento debe estar entre 0 y 100.');
                        }
                        if (modoD === 'porcentaje') {
                            params.descuento_porcentaje = vd;
                        } else {
                            params.descuento = vd;
                        }
                    }
                    if (inpP !== '') {
                        const vp = modoP === 'porcentaje' ? numeroPorcentaje(inpP, 2) : numeroEstricto(inpP, 2);
                        if (vp === null) {
                            return Swal.showValidationMessage('La propina debe ser un número válido (sin letras, sin notación científica y con hasta 2 decimales).');
                        }
                        if (modoP === 'porcentaje') {
                            params.propina_porcentaje = vp;
                        } else {
                            params.propina = vp;
                        }
                    }

                    // Enviar estado de los conceptos fiscales para la prefactura.
                    params.iva_habilitado = prefIvaActivo ? '1' : '0';
                    params.retencion_dgi_habilitada = prefRetDgiActivo ? '1' : '0';
                    params.retencion_alcaldia_habilitada = prefRetAlcActivo ? '1' : '0';
                    params.descuento_activo = prefDescuentoActivo ? '1' : '0';
                    params.propina_activo = prefPropinaActivo ? '1' : '0';

                    const finalCuenta = modoSeleccion
                        ? Number(document.getElementById('cuenta-prefactura').value)
                        : cuentaId;
                    return { cuentaId: finalCuenta, params };
                }
            }).then(function (resultado) {
                if (!resultado.isConfirmed || !resultado.value) {
                    return;
                }
                window.abrirPrefacturaUrl(resultado.value.cuentaId, resultado.value.params);
            });
        };

        if (modoSeleccion) {
            if (!URL_CUENTAS) {
                Swal.fire('Prefactura', 'No se pudo cargar la lista de cuentas.', 'warning');
                return;
            }
            fetch(URL_CUENTAS)
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    const abiertas = (data.cuentas || []).filter(function (cuenta) { return cuenta.estado === 'ABIERTA'; });
                    if (!abiertas.length) {
                        Swal.fire('Prefactura', 'No hay cuentas abiertas para imprimir prefactura.', 'warning');
                        return;
                    }
                    const opciones = abiertas.map(function (cuenta) {
                        return `<option value="${cuenta.id}" data-total="${cuenta.total || 0}">${escaparHtml(cuenta.nombre)} — C$ ${Number(cuenta.total || 0).toFixed(2)}</option>`;
                    }).join('');
                    mostrar(opciones);
                })
                .catch(function (error) {
                    Swal.fire('Prefactura', error.message, 'warning');
                });
        } else {
            mostrar();
        }
    };
})();
</script>
