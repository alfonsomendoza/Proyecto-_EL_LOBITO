<?php

namespace App\Exceptions;

/**
 * Se lanza dentro de la transacción del cobro cuando existe al menos un
 * producto válido de cocina que aún NO está marcado como TERMINADO (LISTO).
 *
 * Lleva la lista de líneas que bloquean el cobro para que el backend pueda
 * responder 422 con el detalle para el cajero, sin exponer datos sensibles.
 */
class CocinaPendienteException extends \RuntimeException
{
    /**
     * @param  array<int, array{producto: string, cantidad: int, estado: string, etiqueta: string, comanda: ?int}>  $productosPendientes
     */
    public function __construct(string $mensaje, public readonly array $productosPendientes)
    {
        parent::__construct($mensaje);
    }
}
