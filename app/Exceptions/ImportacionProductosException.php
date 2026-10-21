<?php

namespace App\Exceptions;

/**
 * Error controlado de la importación de productos por CSV.
 *
 * Se usa para problemas que el usuario puede corregir (archivo vacío,
 * encabezados incorrectos, demasiadas filas, etc.). El controlador lo
 * convierte en una respuesta 422 con un mensaje legible; no se muestra un
 * error interno ni se guarda nada.
 */
class ImportacionProductosException extends \RuntimeException {}
