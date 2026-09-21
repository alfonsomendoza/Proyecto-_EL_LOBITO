'use strict';

document.addEventListener('DOMContentLoaded', () => {

    // 1. Calculadora en tiempo real (C$ locales + desglose de USD convertidos a C$)
    const vincularCalculadora = (claseInputs, idEtiqueta) => {
        const inputs = document.querySelectorAll(claseInputs);
        const etiqueta = document.getElementById(idEtiqueta);

        const recalcularTotales = () => {
            let total = 0;

            inputs.forEach(campo => {
                const coincidencia = campo.name.match(/(?:apertura_b|b_)\[?([\d\.]+)\]?/);
                if (coincidencia) {
                    const valorDenominacion = parseFloat(coincidencia[1]);
                    const cantidad = parseInt(campo.value) || 0;
                    total += (valorDenominacion * cantidad);
                }
            });

            if (idEtiqueta === 'lbl_total_cierre') {
                let totalDolares = 0;
                const inputsDolares = document.querySelectorAll('input[name^="dolar_b"]');

                inputsDolares.forEach(campo => {
                    const match = campo.name.match(/\[([\d\.]+)\]/);
                    if (match) {
                        const valorDolar = parseFloat(match[1]);
                        const cantidad = parseInt(campo.value) || 0;
                        totalDolares += (valorDolar * cantidad);
                    }
                });

                const inputTasa = document.getElementById('tasa_cambio');
                const tasa = inputTasa ? (parseFloat(inputTasa.value) || 0) : 0;

                total += (totalDolares * tasa);
            }

            if (etiqueta) etiqueta.innerText = total.toFixed(2);
        };

        inputs.forEach(input => {
            input.addEventListener('input', recalcularTotales);
        });

        if (idEtiqueta === 'lbl_total_cierre') {
            const inputsDolares = document.querySelectorAll('input[name^="dolar_b"]');
            inputsDolares.forEach(input => input.addEventListener('input', recalcularTotales));

            const inputTasa = document.getElementById('tasa_cambio');
            if (inputTasa) inputTasa.addEventListener('input', recalcularTotales);
        }
    };

    vincularCalculadora('.calc-apertura', 'lbl_total_apertura');
    vincularCalculadora('.calc-cierre', 'lbl_total_cierre');

    // 2. Auto-completar fecha y hora local
    const campoHora = document.getElementById('hora_apertura_input');
    if (campoHora) {
        const ahora = new Date();
        ahora.setMinutes(ahora.getMinutes() - ahora.getTimezoneOffset());
        campoHora.value = ahora.toISOString().slice(0, 16);
    }

    // 3. Procesamiento AJAX para todos los formularios
    const manejarEnvio = (idFormulario, validar) => {
        const formulario = document.getElementById(idFormulario);
        if (!formulario) return;

        formulario.addEventListener('submit', function (evento) {
            evento.preventDefault();

            // Validación previa (client-side) antes de siquiera intentar el envío,
            // para que el cajero vea el problema (ej. "falta dinero") de inmediato
            // en vez de solo después de darle a "Registrar Pago".
            if (typeof validar === 'function') {
                const mensajeError = validar();
                if (mensajeError) {
                    Swal.fire({ icon: 'warning', title: 'Revisa los datos', text: mensajeError });
                    return;
                }
            }

            const boton = formulario.querySelector('button[type="submit"]');
            const textoOriginal = boton.innerText;
            boton.disabled = true;
            boton.innerText = 'Procesando...';

            const datosFormulario = new FormData(formulario);

            fetch(formulario.action, { method: 'POST', body: datosFormulario })
                .then(respuesta => {
                    if (!respuesta.ok) throw new Error('Error en el servidor: ' + respuesta.status);
                    return respuesta.json();
                })
                .then(datos => {
                    if (datos.exito) {
                        Swal.fire({
                            icon: 'success',
                            title: '¡Operación Exitosa!',
                            text: datos.mensaje || 'Datos procesados correctamente.',
                        });

                        if (idFormulario === 'formApertura') {
                            window.open('ticket_apertura.php', '_blank', 'width=400,height=600');
                        }

                        if (idFormulario === 'formVentas' && typeof actualizarCamposMetodoPago === 'function') {
                            // El reset() del formulario no dispara 'change' en el <select>,
                            // así que sincronizamos manualmente los campos condicionales
                            // (Monto Recibido / Tasa de Cambio / preview de Cambio).
                            actualizarCamposMetodoPago();
                        }

                        if (datos.estado) {
                            const etiquetasBanco = { BAC: 'BAC', BANPRO: 'BANPRO', LAFISE: 'LAFISE', FICOHSA: 'FICOHSA' };
                            const etiquetasEstado = { CUADRA: 'CUADRADO', SOBRANTE: 'SOBRANTE', FALTANTE: 'FALTANTE' };
                            let htmlTarjetas = '';
                            const armarLineasBanco = (porBanco, titulo) => {
                                if (!porBanco) return '';
                                const entradas = Object.entries(porBanco).filter(([, v]) => parseFloat(v) > 0);
                                if (entradas.length === 0) return '';
                                let html = `<br><b>${titulo}:</b><br>`;
                                entradas.forEach(([banco, monto]) => {
                                    html += `&nbsp;&nbsp;${etiquetasBanco[banco] || banco}: C$ ${parseFloat(monto).toFixed(2)}<br>`;
                                });
                                return html;
                            };
                            htmlTarjetas += armarLineasBanco(datos.ventas_tarjeta_debito_por_banco, 'Tarjeta Débito');
                            htmlTarjetas += armarLineasBanco(datos.ventas_tarjeta_credito_por_banco, 'Tarjeta Crédito');

                            const diferenciaAbs = Math.abs(parseFloat(datos.diferencia)).toFixed(2);
                            const signo = parseFloat(datos.diferencia) > 0 ? '+' : (parseFloat(datos.diferencia) < 0 ? '-' : '');

                            Swal.fire({
                                icon: datos.estado === 'CUADRA' ? 'success' : 'warning',
                                title: 'Cierre de Caja Completado',
                                html: `Esperado (efectivo): C$ ${datos.esperado}<br>Físico Contado: C$ ${datos.fisico}<br>Diferencia: ${signo}C$ ${diferenciaAbs} (<b>${etiquetasEstado[datos.estado] || datos.estado}</b>)<br>Estado: <b>${datos.estado}</b><br>
                                       Cambios entregados: C$ ${parseFloat(datos.total_cambios_entregados || 0).toFixed(2)}
                                       ${htmlTarjetas}`
                            });
                        }

                        formulario.reset();

                        const lblApertura = document.getElementById('lbl_total_apertura');
                        const lblCierre = document.getElementById('lbl_total_cierre');
                        if (lblApertura) lblApertura.innerText = '0.00';
                        if (lblCierre) lblCierre.innerText = '0.00';

                        if (campoHora) {
                            const ahora = new Date();
                            ahora.setMinutes(ahora.getMinutes() - ahora.getTimezoneOffset());
                            campoHora.value = ahora.toISOString().slice(0, 16);
                        }
                    } else {
                        Swal.fire({ icon: 'error', title: 'Atención', text: datos.error });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({ icon: 'error', title: 'Error de red', text: 'No se pudo conectar al servidor.' });
                })
                .finally(() => {
                    boton.disabled = false;
                    boton.innerText = textoOriginal;
                });
        });
    };

    // 5. Lógica limpia del panel de Administración
    const formAdmin = document.getElementById('formBuscarTurno');
    if (formAdmin) {
        formAdmin.addEventListener('submit', function (evento) {
            evento.preventDefault();
            const datosFormulario = new FormData(formAdmin);

            fetch('obtener_turno_admin.php', { method: 'POST', body: datosFormulario })
                .then(res => res.json())
                .then(datos => {
                    if (datos.exito) {
                        const info = datos.datos;
                        // El backend ahora devuelve TODOS los turnos de ese cajero en esa
                        // fecha (antes solo devolvía el más reciente y escondía los gastos
                        // de turnos anteriores del mismo día). Si por alguna razón llega una
                        // respuesta antigua sin "turnos", la armamos con los datos sueltos.
                        const turnos = info.turnos || [{ apertura: info.apertura, gastos: info.gastos, ventas: info.ventas || [], cierre: info.cierre }];
                        const resumen = info.resumen || null;

                        document.getElementById('panel_acciones').style.display = 'block';

                        // Los botones fijos de acción (anular/reimprimir) del HTML apuntan
                        // siempre al ÚLTIMO turno del día, que es el que normalmente se
                        // sigue operando. El resto de turnos tiene sus propios botones
                        // dentro de cada tarjeta generada abajo.
                        const ultimoTurno = turnos[turnos.length - 1];
                        const tieneCierre = ultimoTurno.cierre !== null;

                        const btnAnularApertura = document.getElementById('btnAnularApertura');
                        const btnAnularCierre = document.getElementById('btnAnularCierre');
                        const btnReimprimirApertura = document.getElementById('btnReimprimirApertura');
                        const btnReimprimirCierre = document.getElementById('btnReimprimirCierre');

                        btnAnularApertura.style.display = tieneCierre ? 'none' : 'inline-block';
                        btnAnularCierre.style.display = tieneCierre ? 'inline-block' : 'none';

                        if (btnReimprimirCierre) {
                            btnReimprimirCierre.style.display = tieneCierre ? 'inline-block' : 'none';
                            btnReimprimirCierre.onclick = () => {
                                window.open('ticket_cierre.php?id=' + ultimoTurno.apertura.id, '_blank', 'width=400,height=600');
                            };
                        }

                        btnAnularApertura.onclick = () => window.anularApertura(ultimoTurno.apertura.id);
                        btnAnularCierre.onclick = () => window.anularCierre(ultimoTurno.apertura.id);

                        if (btnReimprimirApertura) {
                            btnReimprimirApertura.onclick = () => {
                                window.open('ticket_apertura.php?id=' + ultimoTurno.apertura.id, '_blank', 'width=400,height=600');
                            };
                        }

                        // Resumen consolidado del día (todos los turnos sumados)
                        let divCierre = document.getElementById('resumen_cierre_admin');
                        if (!divCierre) {
                            divCierre = document.createElement('div');
                            divCierre.id = 'resumen_cierre_admin';
                            const panelAcciones = document.getElementById('panel_acciones');
                            const tituloGastos = panelAcciones.querySelectorAll('h3')[1];
                            panelAcciones.insertBefore(divCierre, tituloGastos);
                        }

                        if (resumen) {
                            const etiquetasBanco = { BAC: 'BAC', BANPRO: 'BANPRO', LAFISE: 'LAFISE', FICOHSA: 'FICOHSA' };
                            const filasBanco = (porBanco) => {
                                if (!porBanco) return '';
                                return Object.entries(porBanco)
                                    .filter(([, v]) => parseFloat(v) > 0)
                                    .map(([banco, monto]) => `&nbsp;&nbsp;${etiquetasBanco[banco] || banco}: C$ ${parseFloat(monto).toFixed(2)}<br>`)
                                    .join('');
                            };

                            divCierre.innerHTML = `
                            <h3 style="color: var(--azul-oscuro); font-size: 14px; margin-top: 15px; margin-bottom: 5px;">
                                Resumen del Día (${resumen.cantidad_turnos} turno${resumen.cantidad_turnos > 1 ? 's' : ''})
                                <button type="button" id="btnImprimirResumenDia" style="float:right; background-color:#17a2b8; color:#fff; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:11px;">Imprimir Resumen</button>
                            </h3>
                            <div style="background-color: #e9ecef; padding: 10px; border-radius: 4px; font-size: 12px; margin-bottom: 15px; border-left: 4px solid var(--azul-claro);">
                                <b>Total Fondo de Apertura:</b> C$ ${(resumen.total_monto_apertura || 0).toFixed(2)}<br>
                                <br>
                                <b>Ventas en Efectivo C$:</b> C$ ${(resumen.total_ventas_efectivo_cordoba || 0).toFixed(2)}<br>
                                <b>Ventas en Efectivo US$:</b> C$ ${(resumen.total_ventas_efectivo_dolar || 0).toFixed(2)} (cordobizado)<br>
                                <b>Ventas Tarjeta Débito:</b> C$ ${(resumen.total_tarjeta_debito || 0).toFixed(2)}<br>
                                ${filasBanco(resumen.tarjeta_debito_por_banco)}
                                <b>Ventas Tarjeta Crédito:</b> C$ ${(resumen.total_tarjeta_credito || 0).toFixed(2)}<br>
                                ${filasBanco(resumen.tarjeta_credito_por_banco)}
                                <b>Total Ventas del Sistema:</b> C$ ${resumen.total_ventas.toFixed(2)}<br>
                                <b>Total Ventas Anuladas:</b> C$ ${(resumen.total_ventas_anuladas || 0).toFixed(2)} (${resumen.cantidad_ventas_anuladas || 0})<br>
                                <br>
                                <b>Total Gastos Activos:</b> C$ ${resumen.total_gastos_activos.toFixed(2)}<br>
                                <b>Total Gastos Anulados:</b> C$ ${resumen.total_gastos_anulados.toFixed(2)} (${resumen.cantidad_gastos_anulados})<br>
                                <b>Total Cambios Entregados:</b> C$ ${(resumen.total_cambios_entregados || 0).toFixed(2)}<br>
                                <hr style="margin:8px 0;">
                                <b style="font-size:13px;">Efectivo Esperado del Día:</b> <span style="font-size:13px; font-weight:bold;">C$ ${(resumen.efectivo_esperado_del_dia || 0).toFixed(2)}</span>
                                <div style="font-size:10px; color:#666; margin-top:2px;">(Apertura + Ventas Efectivo C$ + Efectivo US$ cordobizado − Gastos Activos − Cambios Entregados)</div>
                            </div>
                        `;

                            const btnImprimirResumenDia = document.getElementById('btnImprimirResumenDia');
                            if (btnImprimirResumenDia) {
                                btnImprimirResumenDia.addEventListener('click', () => {
                                    const fecha = document.getElementById('admin_fecha').value;
                                    const cajero = document.getElementById('admin_cajero').value;
                                    window.open(
                                        `ticket_resumen_dia.php?fecha=${encodeURIComponent(fecha)}&cajero=${encodeURIComponent(cajero)}`,
                                        '_blank', 'width=400,height=600'
                                    );
                                });
                            }
                        } else {
                            divCierre.innerHTML = '';
                        }

                        // Una tarjeta por cada turno del día, con su propio detalle de
                        // gastos, ventas y cierre (si lo tiene).
                        const contenedorGastos = document.getElementById('lista_gastos_admin');
                        contenedorGastos.innerHTML = '';

                        turnos.forEach(turno => {
                            const tarjeta = document.createElement('div');
                            tarjeta.style.marginBottom = '18px';
                            tarjeta.style.paddingBottom = '12px';
                            tarjeta.style.borderBottom = '2px solid #ccc';

                            let htmlTarjeta = `<div style="font-weight:bold; margin-bottom:6px;">
                                Turno #${turno.apertura.id} — ${turno.apertura.fecha} — Estado: ${turno.apertura.estado}
                                <button type="button" onclick="window.open('ticket_gastos.php?id=${turno.apertura.id}', '_blank', 'width=400,height=600')" style="float:right; background-color:#6c757d; color:#fff; border:none; padding:4px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Imprimir Gastos</button>
                            </div>`;

                            // Datos de la apertura del turno: cajero, turno de trabajo y con
                            // cuánto dinero se abrió la caja, para poder darse cuenta de todo
                            // de un vistazo sin tener que reimprimir el ticket.
                            htmlTarjeta += `<div style="background-color:#eef4fa; padding:8px; border-radius:4px; font-size:11px; margin-bottom:8px;">
                                <b>Cajero:</b> ${turno.apertura.cajero} &nbsp;|&nbsp; <b>Turno:</b> ${turno.apertura.turno || '-'}<br>
                                <b>Fondo de Apertura:</b> C$ ${parseFloat(turno.apertura.monto_inicial).toFixed(2)}
                            </div>`;

                            // Historial COMPLETO de cierres de este turno (antes solo se mostraba
                            // el vigente y el/los anteriores quedaban invisibles, aunque seguían
                            // guardados en la base de datos). Cada uno con su propio botón de
                            // reimprimir y, si sigue vigente, de anular.
                            const historialCierres = turno.historial_cierres || (turno.cierre ? [turno.cierre] : []);
                            if (historialCierres.length > 0) {
                                htmlTarjeta += '<div style="font-weight:bold; margin-top:6px; margin-bottom:4px; font-size:12px; color: var(--azul-claro);">Cierres registrados en este turno</div>';
                                historialCierres.forEach((cierre, indice) => {
                                    const numero = indice + 1;
                                    const anulado = cierre.estado === 'ANULADO';
                                    htmlTarjeta += `<div style="background-color:${anulado ? '#f8d7da' : '#f1f5f9'}; padding:8px; border-radius:4px; font-size:11px; margin-bottom:6px; ${anulado ? 'text-decoration: line-through; color:#721c24;' : ''}">
                                        <b>Cierre #${numero}</b> (${cierre.fecha}) — Esperado C$ ${parseFloat(cierre.total_esperado).toFixed(2)} |
                                        Contado C$ ${parseFloat(cierre.total_contado).toFixed(2)} |
                                        Diferencia C$ ${parseFloat(cierre.diferencia).toFixed(2)} |
                                        Estado: <strong>${cierre.estado}</strong>
                                        <div style="margin-top:4px; text-decoration:none;">
                                            <button type="button" onclick="window.open('ticket_cierre.php?cierre_id=${cierre.id}', '_blank', 'width=400,height=600')" style="background-color:#17a2b8; color:#fff; border:none; padding:4px 8px; border-radius:4px; cursor:pointer; font-size:11px; margin-right:6px;">Reimprimir Cierre ${numero}</button>
                                            ${anulado ? '' : `<button type="button" onclick="anularCierrePorId(${cierre.id})" style="background-color:#dc3545; color:#fff; border:none; padding:4px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Anular Cierre ${numero}</button>`}
                                        </div>
                                    </div>`;
                                });
                            }

                            if (!turno.gastos || turno.gastos.length === 0) {
                                htmlTarjeta += '<p style="text-align:center; color:#666; margin:0; font-size:12px;">Sin gastos en este turno.</p>';
                            } else {
                                htmlTarjeta += '<table style="width:100%; border-collapse: collapse;">';
                                htmlTarjeta += '<tr style="border-bottom: 1px solid #ccc; text-align: left;"><th>Concepto</th><th>Monto</th><th>Acción</th></tr>';

                                turno.gastos.forEach(gasto => {
                                    const esAnulado = (gasto.estado === 'ANULADO');
                                    htmlTarjeta += `<tr style="border-bottom: 1px solid #eee; ${esAnulado ? 'text-decoration: line-through; color: #dc3545;' : ''}" id="fila_gasto_${gasto.id}">
                                        <td style="padding: 5px;">${gasto.concepto}</td>
                                        <td style="padding: 5px;">C$ ${parseFloat(gasto.monto).toFixed(2)}</td>
                                        <td style="padding: 5px;">
                                            ${esAnulado
                                                ? '<strong style="color: #dc3545; font-size: 11px;">ANULADO</strong>'
                                                : `<button type="button" onclick="anularGasto(${gasto.id})" style="background-color: #dc3545; color: #ffffff; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-weight: bold;">Anular</button>`
                                            }
                                        </td>
                                    </tr>`;
                                });
                                htmlTarjeta += '</table>';
                            }

                            // Ventas del turno (antes se recibían del backend pero nunca se
                            // pintaban en el panel admin). Misma lógica que gastos: se puede
                            // anular una venta por error.
                            htmlTarjeta += '<div style="font-weight:bold; margin-top:10px; margin-bottom:4px; font-size:12px; color: var(--azul-claro);">Ventas registradas</div>';
                            if (!turno.ventas || turno.ventas.length === 0) {
                                htmlTarjeta += '<p style="text-align:center; color:#666; margin:0; font-size:12px;">Sin ventas en este turno.</p>';
                            } else {
                                const etiquetasMetodo = {
                                    'EFECTIVO_CORDOBA': 'Efectivo C$',
                                    'EFECTIVO_DOLAR': 'Efectivo US$',
                                    'TARJETA_DEBITO': 'Tarjeta Débito',
                                    'TARJETA_CREDITO': 'Tarjeta Crédito'
                                };
                                htmlTarjeta += '<table style="width:100%; border-collapse: collapse;">';
                                htmlTarjeta += '<tr style="border-bottom: 1px solid #ccc; text-align: left;"><th>Descripción</th><th>Monto</th><th>Método</th><th>Cambio</th><th>Acción</th></tr>';

                                turno.ventas.forEach(venta => {
                                    const esAnulada = (venta.estado === 'ANULADA');
                                    let metodoTexto = etiquetasMetodo[venta.metodo_pago] || 'Efectivo C$';
                                    if (venta.banco) metodoTexto += ` (${venta.banco})`;
                                    const cambioTexto = (venta.cambio !== null && venta.cambio !== undefined && parseFloat(venta.cambio) > 0)
                                        ? `C$ ${parseFloat(venta.cambio).toFixed(2)}`
                                        : '—';
                                    htmlTarjeta += `<tr style="border-bottom: 1px solid #eee; ${esAnulada ? 'text-decoration: line-through; color: #dc3545;' : ''}" id="fila_venta_${venta.id}">
                                        <td style="padding: 5px;">${venta.descripcion}</td>
                                        <td style="padding: 5px;">C$ ${parseFloat(venta.monto).toFixed(2)}</td>
                                        <td style="padding: 5px;">${metodoTexto}</td>
                                        <td style="padding: 5px;">${cambioTexto}</td>
                                        <td style="padding: 5px;">
                                            ${esAnulada
                                                ? '<strong style="color: #dc3545; font-size: 11px;">ANULADA</strong>'
                                                : `<button type="button" onclick="anularVenta(${venta.id})" style="background-color: #dc3545; color: #ffffff; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-weight: bold;">Anular</button>`
                                            }
                                        </td>
                                    </tr>`;
                                });
                                htmlTarjeta += '</table>';
                            }

                            tarjeta.innerHTML = htmlTarjeta;
                            contenedorGastos.appendChild(tarjeta);
                        });

                        Swal.fire({ icon: 'success', title: 'Turno(s) encontrado(s)', text: `${turnos.length} turno(s) para ese día`, timer: 1500, showConfirmButton: false });
                    } else {
                        document.getElementById('panel_acciones').style.display = 'none';
                        Swal.fire({ icon: 'warning', title: 'Atención', text: datos.error });
                    }
                })
                .catch(() => {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo conectar al servidor.' });
                });
        });
    }

    // 6. Lógica dinámica para múltiples gastos con capacidad de eliminar filas previas
    const contenedorGastosForm = document.getElementById('contenedor-gastos');
    const btnAgregarGastoUI = document.getElementById('btnAgregarGastoUI');

    const activarBotonEliminar = (fila) => {
        const btnEliminar = fila.querySelector('.btn-eliminar-gasto');
        if (btnEliminar) {
            btnEliminar.addEventListener('click', () => {
                const totalFilas = contenedorGastosForm.querySelectorAll('.fila-gasto').length;
                if (totalFilas > 1) {
                    fila.remove();
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Atención',
                        text: 'Debe mantener al menos una fila de gasto o dejar el monto en cero.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            });
        }
    };

    const filaInicial = contenedorGastosForm ? contenedorGastosForm.querySelector('.fila-gasto') : null;
    if (filaInicial) {
        activarBotonEliminar(filaInicial);
    }

    if (btnAgregarGastoUI && contenedorGastosForm) {
        btnAgregarGastoUI.addEventListener('click', () => {
            const primeraFila = contenedorGastosForm.querySelector('.fila-gasto');
            const nuevaFila = primeraFila.cloneNode(true);

            nuevaFila.querySelector('input[type="text"]').value = '';
            nuevaFila.querySelector('input[type="number"]').value = '0';

            // La primera fila trae su botón "X" con disabled="disabled" a propósito
            // (para no poder quedarse sin ninguna fila). Al clonarla, ese atributo
            // se copiaba también, así que las filas nuevas quedaban con el botón
            // "X" deshabilitado y no se podían borrar. Lo habilitamos aquí.
            const btnEliminarClon = nuevaFila.querySelector('.btn-eliminar-gasto');
            if (btnEliminarClon) btnEliminarClon.disabled = false;

            activarBotonEliminar(nuevaFila);

            contenedorGastosForm.appendChild(nuevaFila);
        });
    }

    // -----------------------------------------------------------------
    // Método de pago en Ventas: mostrar/ocultar "Monto Recibido", "Tasa de
    // Cambio" y "Banco" según corresponda; calcular cambio o "falta dinero"
    // en vivo (ANTES de darle a "Registrar Pago", no después); mostrar el
    // monto a cobrar en el POS cuando es tarjeta.
    // -----------------------------------------------------------------
    const selectMetodoPago = document.getElementById('metodo_pago_venta');
    const filaMontoRecibido = document.getElementById('fila_monto_recibido');
    const filaTasaCambioVenta = document.getElementById('fila_tasa_cambio_venta');
    const filaCambioPreview = document.getElementById('fila_cambio_preview');
    const filaFaltaPreview = document.getElementById('fila_falta_preview');
    const filaBancoVenta = document.getElementById('fila_banco_venta');
    const filaMontoCobrarTarjeta = document.getElementById('fila_monto_cobrar_tarjeta');
    const lblMontoRecibido = document.getElementById('lbl_monto_recibido');
    const inputMontoVenta = document.getElementById('monto_venta_input');
    const inputMontoRecibido = document.getElementById('monto_recibido_input');
    const inputTasaCambioVenta = document.getElementById('tasa_cambio_venta_input');
    const selectBancoVenta = document.getElementById('banco_venta_input');
    const lblCambioPreview = document.getElementById('lbl_cambio_preview');
    const lblFaltaPreview = document.getElementById('lbl_falta_preview');
    const lblMontoCobrarTarjeta = document.getElementById('lbl_monto_cobrar_tarjeta');

    const actualizarCamposMetodoPago = () => {
        if (!selectMetodoPago) return;
        const metodo = selectMetodoPago.value;
        const esEfectivo = (metodo === 'EFECTIVO_CORDOBA' || metodo === 'EFECTIVO_DOLAR');
        const esDolar = (metodo === 'EFECTIVO_DOLAR');
        const esTarjeta = (metodo === 'TARJETA_DEBITO' || metodo === 'TARJETA_CREDITO');

        filaMontoRecibido.style.display = esEfectivo ? 'flex' : 'none';
        filaTasaCambioVenta.style.display = esDolar ? 'flex' : 'none';
        lblMontoRecibido.textContent = esDolar ? 'Monto Recibido (US$):' : 'Monto Recibido (C$):';

        filaBancoVenta.style.display = esTarjeta ? 'flex' : 'none';
        filaMontoCobrarTarjeta.style.display = esTarjeta ? 'block' : 'none';

        if (esEfectivo) {
            inputMontoRecibido.setAttribute('required', 'required');
        } else {
            inputMontoRecibido.removeAttribute('required');
        }
        if (esTarjeta) {
            selectBancoVenta.setAttribute('required', 'required');
        } else {
            selectBancoVenta.removeAttribute('required');
        }

        actualizarPreview();
    };

    /** Calcula en vivo, ANTES de enviar el formulario, si falta dinero o cuánto cambio hay que dar. */
    const actualizarPreview = () => {
        if (!selectMetodoPago) return;
        const metodo = selectMetodoPago.value;
        const montoVenta = parseFloat(inputMontoVenta.value) || 0;

        if (metodo === 'TARJETA_DEBITO' || metodo === 'TARJETA_CREDITO') {
            lblMontoCobrarTarjeta.textContent = montoVenta.toFixed(2);
            filaCambioPreview.style.display = 'none';
            filaFaltaPreview.style.display = 'none';
            return;
        }

        const montoRecibido = parseFloat(inputMontoRecibido.value) || 0;
        let recibidoEnCordobas = montoRecibido;
        if (metodo === 'EFECTIVO_DOLAR') {
            const tasa = parseFloat(inputTasaCambioVenta.value) || 0;
            recibidoEnCordobas = montoRecibido * tasa;
        }

        const diferencia = recibidoEnCordobas - montoVenta;
        if (diferencia < 0) {
            filaCambioPreview.style.display = 'none';
            filaFaltaPreview.style.display = 'block';
            lblFaltaPreview.textContent = Math.abs(diferencia).toFixed(2);
        } else {
            filaFaltaPreview.style.display = 'none';
            filaCambioPreview.style.display = 'block';
            lblCambioPreview.textContent = diferencia.toFixed(2);
        }
    };

    if (selectMetodoPago) {
        selectMetodoPago.addEventListener('change', actualizarCamposMetodoPago);
        [inputMontoVenta, inputMontoRecibido, inputTasaCambioVenta].forEach(campo => {
            if (campo) campo.addEventListener('input', actualizarPreview);
        });
        actualizarCamposMetodoPago(); // estado inicial
    }

    /** Validación previa al envío de la venta: bloquea el submit si falta dinero o falta el banco. */
    const validarVenta = () => {
        if (!selectMetodoPago) return null;
        const metodo = selectMetodoPago.value;
        const montoVenta = parseFloat(inputMontoVenta.value) || 0;

        if (metodo === 'TARJETA_DEBITO' || metodo === 'TARJETA_CREDITO') {
            if (!selectBancoVenta.value) return 'Selecciona el banco de la tarjeta.';
            return null;
        }

        const montoRecibido = parseFloat(inputMontoRecibido.value) || 0;
        let recibidoEnCordobas = montoRecibido;
        if (metodo === 'EFECTIVO_DOLAR') {
            const tasa = parseFloat(inputTasaCambioVenta.value) || 0;
            if (tasa <= 0) return 'Indica la tasa de cambio.';
            recibidoEnCordobas = montoRecibido * tasa;
        }

        if (recibidoEnCordobas < montoVenta) {
            const faltante = (montoVenta - recibidoEnCordobas).toFixed(2);
            return `Falta dinero: faltan C$ ${faltante} para completar el pago.`;
        }
        return null;
    };

    manejarEnvio('formApertura');
    manejarEnvio('formGastos');
    manejarEnvio('formCierre');
    manejarEnvio('formVentas', validarVenta);

    // 4. Recuperar estado de la apertura actual
    const btnRecuperar = document.getElementById('btnRecuperarApertura');
    if (btnRecuperar) {
        btnRecuperar.addEventListener('click', () => {
            fetch('obtener_apertura.php')
                .then(res => {
                    if (!res.ok) throw new Error('Error al conectar');
                    return res.json();
                })
                .then(datos => {
                    if (datos.exito) {
                        Swal.fire({
                            icon: 'info',
                            title: 'Apertura Activa',
                            html: `
                            <b>Cajero:</b> ${datos.datos.cajero}<br>
                            <b>Fecha:</b> ${datos.datos.fecha}<br>
                            <b>Fondo Inicial:</b> C$ ${parseFloat(datos.datos.monto_inicial).toFixed(2)}
                        `
                        });
                    } else {
                        Swal.fire({ icon: 'warning', title: 'Sin datos', text: datos.error });
                    }
                })
                .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Fallo de conexión.' }));
        });
    }

    // Botón "Imprimir Gastos del Turno" (módulo de Registrar Gasto)
    const btnImprimirGastos = document.getElementById('btnImprimirGastos');
    if (btnImprimirGastos) {
        btnImprimirGastos.addEventListener('click', () => {
            window.open('ticket_gastos.php', '_blank', 'width=400,height=600');
        });
    }

    // Funciones globales de anulación
    window.anularGasto = function (idGasto) {
        Swal.fire({
            title: '¿Anular este gasto?',
            text: "El registro quedará marcado como ANULADO y afectará el cuadre.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, anular gasto',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                const datos = new FormData();
                datos.append('accion', 'anular_gasto');
                datos.append('id', idGasto);

                fetch('procesar_anulacion.php', { method: 'POST', body: datos })
                    .then(res => res.json())
                    .then(respuesta => {
                        if (respuesta.exito) {
                            Swal.fire('¡Anulado!', respuesta.mensaje, 'success');
                            const fila = document.getElementById('fila_gasto_' + idGasto);
                            if (fila) {
                                fila.style.textDecoration = 'line-through';
                                fila.style.color = '#dc3545';
                                const boton = fila.querySelector('button');
                                if (boton) boton.remove();
                            }
                        } else {
                            Swal.fire('Error', respuesta.error, 'error');
                        }
                    })
                    .catch(() => {
                        Swal.fire('Error', 'Fallo de conexión con el servidor.', 'error');
                    });
            }
        });
    };

    window.anularVenta = function (idVenta) {
        Swal.fire({
            title: '¿Anular esta venta?',
            text: "El registro quedará marcado como ANULADA y no se contará en el cuadre de caja.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, anular venta',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                const datos = new FormData();
                datos.append('accion', 'anular_venta');
                datos.append('id', idVenta);

                fetch('procesar_anulacion.php', { method: 'POST', body: datos })
                    .then(res => res.json())
                    .then(respuesta => {
                        if (respuesta.exito) {
                            Swal.fire('¡Anulada!', respuesta.mensaje, 'success');
                            const fila = document.getElementById('fila_venta_' + idVenta);
                            if (fila) {
                                fila.style.textDecoration = 'line-through';
                                fila.style.color = '#dc3545';
                                const boton = fila.querySelector('button');
                                if (boton) boton.remove();
                            }
                        } else {
                            Swal.fire('Error', respuesta.error, 'error');
                        }
                    })
                    .catch(() => {
                        Swal.fire('Error', 'Fallo de conexión con el servidor.', 'error');
                    });
            }
        });
    };

    window.anularCierrePorId = function (idCierre) {
        Swal.fire({
            title: '¿Anular este cierre?',
            text: "Este cierre puntual del historial quedará marcado como ANULADO. Si era el vigente, la caja de ese turno vuelve a estar ABIERTA.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, anular este cierre',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                const datos = new FormData();
                datos.append('accion', 'anular_cierre_id');
                datos.append('id', idCierre);

                fetch('procesar_anulacion.php', { method: 'POST', body: datos })
                    .then(res => res.json())
                    .then(respuesta => {
                        if (respuesta.exito) {
                            Swal.fire('¡Anulado!', respuesta.mensaje, 'success').then(() => {
                                const btnBuscar = document.querySelector('#formBuscarTurno button[type="submit"]');
                                if (btnBuscar) btnBuscar.click();
                            });
                        } else {
                            Swal.fire('Error', respuesta.error, 'error');
                        }
                    })
                    .catch(() => {
                        Swal.fire('Error', 'Fallo de conexión con el servidor.', 'error');
                    });
            }
        });
    };

    window.anularApertura = function (idApertura) {
        Swal.fire({
            title: '¿Anular este turno por completo?',
            text: "La caja quedará inhabilitada. No debe tener cierres vinculados.",
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, anular turno'
        }).then((result) => {
            if (result.isConfirmed) {
                const datos = new FormData();
                datos.append('accion', 'anular_apertura');
                datos.append('id', idApertura);

                fetch('procesar_anulacion.php', { method: 'POST', body: datos })
                    .then(res => res.json())
                    .then(respuesta => {
                        if (respuesta.exito) {
                            Swal.fire('Anulada', respuesta.mensaje, 'success')
                                .then(() => document.getElementById('formBuscarTurno').dispatchEvent(new Event('submit')));
                        } else { Swal.fire('Error', respuesta.error, 'error'); }
                    });
            }
        });
    };

    window.anularCierre = function (idApertura) {
        Swal.fire({
            title: '¿Anular el cierre de caja?',
            text: "La caja volverá al estado ABIERTA para permitir un nuevo cuadre.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, reactivar turno'
        }).then((result) => {
            if (result.isConfirmed) {
                const datos = new FormData();
                datos.append('accion', 'anular_cierre');
                datos.append('id', idApertura);

                fetch('procesar_anulacion.php', { method: 'POST', body: datos })
                    .then(res => res.json())
                    .then(respuesta => {
                        if (respuesta.exito) {
                            Swal.fire('Cierre anulado', respuesta.mensaje, 'success')
                                .then(() => document.getElementById('formBuscarTurno').dispatchEvent(new Event('submit')));
                        } else { Swal.fire('Error', respuesta.error, 'error'); }
                    });
            }
        });
    };
});
