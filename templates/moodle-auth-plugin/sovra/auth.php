<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Ingreso a Moodle con una credencial verificable de la billetera (Sovra ID, OID4VP).
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * Plugin de autenticación: agrega «Ingresar con la SuperApp» al login.
 *
 * No maneja contraseñas: el ingreso lo completa auth/sovra/finish.php con complete_user_login, después de
 * que Sovra verificó la presentación y el plugin encontró exactamente una cuenta con ese documento. Las cuentas
 * pueden tener cualquier método de los aceptados en la configuración (por defecto manual, email y sovra), así
 * que las cuentas manual o email siguen pudiendo entrar también con su contraseña; las de método sovra, no.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_plugin_sovra extends auth_plugin_base {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->authtype = 'sovra';
        $this->config = get_config('auth_sovra');
    }

    /**
     * Nunca por usuario y contraseña.
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    public function user_login($username, $password) {
        return false;
    }

    /**
     * Las cuentas de este método no tienen contraseña local.
     *
     * @return bool
     */
    public function prevent_local_passwords() {
        return true;
    }

    /**
     * No es un método interno de Moodle.
     *
     * @return bool
     */
    public function is_internal() {
        return false;
    }

    /**
     * No se cambia contraseña desde Moodle.
     *
     * @return bool
     */
    public function can_change_password() {
        return false;
    }

    /**
     * El botón de la pantalla de ingreso, sólo con la configuración completa.
     *
     * @param string $wantsurl
     * @return array
     */
    public function loginpage_idp_list($wantsurl) {
        if (!\auth_sovra\config::ready()) {
            return [];
        }
        return [[
            // Con sesskey: sólo un clic desde este sitio abre una verificación en Sovra.
            'url' => new moodle_url('/auth/sovra/login.php', ['sesskey' => sesskey()]),
            'iconurl' => new moodle_url('/auth/sovra/pix/superapp.svg'),
            'name' => get_string('loginbutton', 'auth_sovra'),
        ]];
    }
}
