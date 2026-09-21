@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 600px; margin: 0 auto;">
    <div class="monitor-header">
        <span><i class="fas fa-box-open"></i> Iniciar Turno y Fondo de Caja</span>
    </div>
    <div style="padding: 25px;">
        <form id="formApertura">
            @csrf
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold; color:var(--color-dark);">Hora de Apertura:</label>
                <input type="datetime-local" name="fecha" id="hora_apertura_input" value="{{ now()->format('Y-m-d\TH:i') }}" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;" required>
            </div>
            <div style="margin-bottom: 15px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold; color:var(--color-dark);">Operador (Sesión Activa):</label>
                <input type="text" name="cajero" value="{{ auth()->user()->nombre ?? 'Admin' }}" readonly style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; background-color: #e9ecef; font-weight: bold;" required>
            </div>
            <div style="margin-bottom: 25px;">
                <label style="display:block; margin-bottom:5px; font-weight:bold; color:var(--color-dark);">Turno:</label>
                <select name="turno" id="turno_input" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px;" required>
                    <option value="Mañana">Mañana</option>
                    <option value="Tarde">Tarde</option>
                    <option value="Noche">Noche</option>
                </select>
            </div>

            <h4 style="color: var(--color-primary); border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">Conteo de Efectivo (Fondo)</h4>
            
            @php $denominaciones = [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.5]; @endphp
            @foreach($denominaciones as $den)
                @php $formato = $den < 1 ? '0.50' : number_format($den, 2); @endphp
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; align-items:center;">
                    <label style="width:100px;">C$ {{ $formato }}:</label>
                    <input type="number" data-den="{{ $den }}" name="apertura_b[{{ $den }}]" value="0" class="calc-apertura" min="0" style="flex:1; padding:8px; border:1px solid #ddd; border-radius:4px;">
                </div>
            @endforeach

            <div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-left: 4px solid var(--color-primary); font-size: 16px; font-weight: bold;">
                Fondo Total: C$ <span id="lbl_total_apertura">0.00</span>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" style="flex: 2; background-color: var(--color-primary); color: white; border: none; padding: 12px; border-radius: 4px; cursor: pointer; font-weight: bold;">Abrir Caja</button>
                <button type="button" id="btnRecuperarApertura" style="flex: 1; background-color: #6c757d; color: white; border: none; padding: 12px; border-radius: 4px; cursor: pointer;">Ver Actual</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('.calc-apertura');
    const lblTotal = document.getElementById('lbl_total_apertura');
    const form = document.getElementById('formApertura');

    // Botón Ver Actual corregido para que VS Code no marque error
    document.getElementById('btnRecuperarApertura').addEventListener('click', function() {
        window.open('{{ route("caja.ticket_apertura") }}', '_blank');
    });

    function calcularTotal() {
        let total = 0;
        inputs.forEach(input => {
            const cantidad = parseInt(input.value) || 0;
            const denominacion = parseFloat(input.getAttribute('data-den'));
            total += (cantidad * denominacion);
        });
        lblTotal.textContent = total.toFixed(2);
        return total;
    }

    inputs.forEach(input => input.addEventListener('input', calcularTotal));

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        let conteo = {};
        inputs.forEach(input => {
            const cantidad = parseInt(input.value) || 0;
            const denominacion = input.getAttribute('data-den');
            if (cantidad > 0) {
                conteo[denominacion] = cantidad;
            }
        });

        const totalMonto = calcularTotal();
        const formData = new FormData();
        formData.append('_token', document.querySelector('input[name="_token"]').value); 
        formData.append('fecha', document.getElementById('hora_apertura_input').value);
        formData.append('cajero', document.querySelector('input[name="cajero"]').value);
        formData.append('turno', document.getElementById('turno_input').value);
        formData.append('caja_id', 1);
        formData.append('monto_inicial', totalMonto);
        formData.append('conteo_json', JSON.stringify(conteo));

        Swal.fire({ title: 'Abriendo caja...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        fetch('{{ route("caja.abrir") }}', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.exito) {
                Swal.fire('Éxito', data.mensaje, 'success').then(() => {
                    window.open('{{ route("caja.ticket_apertura") }}?id=' + data.apertura_id, '_blank');
                    window.location.reload();
                });
            } else {
                Swal.fire('Error', data.error, 'error');
            }
        })
        .catch(error => {
            Swal.fire('Error', 'Fallo de conexión al procesar la apertura.', 'error');
        });
    });
});
</script>
@endsection