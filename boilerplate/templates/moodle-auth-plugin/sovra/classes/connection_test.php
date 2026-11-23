<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * El botón «Probar la conexión»: abre una verificación con la consulta real y la deja vencer.
 *
 * Prueba la URL, la API key, la red y que la API acepte la consulta DCQL tal como la arma el plugin.
 * No toca ninguna sesión de ingreso.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection_test {

    /**
     * Corre la prueba.
     *
     * @param api_client|null $api
     * @return array{0: bool, 1: string} [salió bien, mensaje]
     */
    public static function run(?api_client $api = null): array {
        if ($names = config::undecryptable()) {
            return [false, get_string('secret_undecryptable', 'auth_sovra', implode(', ', $names))];
        }
        try {
            $api = $api ?? api_client::from_config();
            $api->create_verification(login_session::dcql());
        } catch (api_exception $e) {
            return [false, get_string('test_error', 'auth_sovra', s($e->summary()))];
        }
        $message = get_string('test_ok', 'auth_sovra', s(config::masked(config::api_key())));
        if (!config::allowed_issuers()) {
            return [false, $message . ' ' . get_string('test_issuers_missing', 'auth_sovra')];
        }
        return [true, $message];
    }
}
