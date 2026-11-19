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
use auth_sovra\resolver;

$token = required_param('token', PARAM_ALPHANUM);

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!confirm_sesskey()) {
    echo json_encode(['state' => 'gone']);
    exit;
}

$record = login_session::for_browser($token);
if (!$record) {
    echo json_encode(['state' => 'gone', 'url' => (new moodle_url('/auth/sovra/login.php', ['result' => 'generic']))->out(false)]);
    exit;
}

$record = login_session::refresh($record);

switch ($record->status) {
    case login_session::PENDING:
        $response = ['state' => 'pending'];
        break;
    case login_session::EXPIRED:
        $response = ['state' => 'expired'];
        break;
    case login_session::RESOLVED:
        if ($record->result === resolver::OK) {
            $response = ['state' => 'ok', 'url' => (new moodle_url('/auth/sovra/finish.php',
                ['token' => $token, 'sesskey' => sesskey()]))->out(false)];
            break;
        }
        $response = ['state' => 'done', 'url' => (new moodle_url('/auth/sovra/login.php',
            ['result' => $record->result]))->out(false)];
        break;
    default:
        // FAILED: el error de Sovra no se le muestra a la persona.
        $response = ['state' => 'done', 'url' => (new moodle_url('/auth/sovra/login.php', ['result' => 'failed']))->out(false)];
}
echo json_encode($response);
