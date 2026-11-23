<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Estado de una sesión de ingreso, para la página que espera. Sólo responde al navegador que la abrió.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- La consulta la hace quien todavía no inició sesión.
define('AJAX_SCRIPT', true);
// Sólo lee $SESSION: no bloquea la sesión mientras la página pregunta cada pocos segundos.
define('READ_ONLY_SESSION', true);

require(__DIR__ . '/../../config.php');

use auth_sovra\login_session;

$token = required_param('token', PARAM_ALPHANUM);
// confirm_sesskey() sin argumento corta con una excepción si falta el parámetro: acá es un «gone» más.
$sesskey = optional_param('sesskey', '', PARAM_RAW);

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Todo estado final trae la URL a la que tiene que ir la página.
echo json_encode(login_session::poll($token, $sesskey !== '' && confirm_sesskey($sesskey)));
