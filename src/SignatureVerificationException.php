<?php

declare(strict_types=1);

namespace Seatsyoo;

/**
 * La firma de un aviso de webhook no es válida: falta, está mal escrita, no coincide con el cuerpo o es
 * demasiado vieja. Responde con un 400 y no hagas nada con el aviso.
 */
class SignatureVerificationException extends \RuntimeException
{
}
