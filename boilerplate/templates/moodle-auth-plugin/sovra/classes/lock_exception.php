<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * No se pudo tomar el lock de una sesión de ingreso que sigue pendiente: el veredicto no se escribió.
 *
 * El webhook la convierte en 500 para que Sovra reintente; la consulta por GET reintenta en la próxima vuelta.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lock_exception extends \Exception {
}
