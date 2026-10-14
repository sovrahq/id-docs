<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Firma de los webhooks de Sovra: X-Sovra-Signature: sha256=<HMAC-SHA256 hex del cuerpo crudo>.
 *
 * El cuerpo se firma byte por byte, tal como llegó: decodificar y volver a codificar el JSON cambia
 * el orden y los espacios, y la firma deja de coincidir.
 *
 * Referencia: docs/guides/credentials/05-webhooks.md
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hmac {

    /**
     * Valor esperado del header para un cuerpo.
     *
     * @param string $rawbody
     * @param string $secret
     * @return string sha256=...
     */
    public static function sign(string $rawbody, string $secret): string {
        return 'sha256=' . hash_hmac('sha256', $rawbody, $secret);
    }

    /**
     * ¿La firma recibida corresponde al cuerpo? Comparación de tiempo constante.
     *
     * @param string $rawbody
     * @param string|null $header El valor de X-Sovra-Signature.
     * @param string $secret
     * @return bool
     */
    public static function is_valid(string $rawbody, ?string $header, string $secret): bool {
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }
        return hash_equals(self::sign($rawbody, $secret), strtolower(trim($header)));
    }
}
