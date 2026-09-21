@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 800px; margin: 0 auto; margin-top: 20px;">
    <div class="monitor-header">
        <span><i class="fas fa-home"></i> Inicio - Restaurante El Lobito</span>
    </div>
    <div style="padding: 40px 30px; text-align: center;">
        <div style="font-size: 50px; color: var(--color-primary); margin-bottom: 15px;">
            <i class="fas fa-utensils"></i>
        </div>
        <h2 style="color: var(--color-dark); margin-bottom: 10px;">¡Bienvenido, {{ auth()->user()->nombre }}!</h2>
        <p style="color: var(--text-muted); font-size: 15px; margin-bottom: 30px;">Selecciona una opción en el menú lateral para comenzar a operar en tu turno.</p>
        
        <div style="display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;">
            @if (in_array(auth()->user()->rol->nombre ?? 'MESERO', ['ADMIN', 'GERENTE', 'MESERO']))
                <a href="#" style="text-decoration: none; background: var(--color-primary); color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-desktop"></i> Ir al Monitor</a>
            @endif
            
            @if (in_array(auth()->user()->rol->nombre ?? 'MESERO', ['ADMIN', 'GERENTE', 'CAJERO']))
                <a href="{{ route('caja.apertura') }}" style="text-decoration: none; background: var(--color-success); color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-store"></i> Abrir Caja</a>
            @endif
        </div>
    </div>
</div>
@endsection