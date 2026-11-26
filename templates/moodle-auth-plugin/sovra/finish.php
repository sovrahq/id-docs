<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Completa el ingreso: sólo desde el navegador que abrió la verificación, una sola vez.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Es la página que inicia la sesión.
require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/login/lib.php');

use auth_sovra\login_session;
use auth_sovra\resolver;

$token = required_param('token', PARAM_ALPHANUM);
$sesskey = optional_param('sesskey', '', PARAM_RAW);

$PAGE->set_url(new moodle_url('/auth/sovra/finish.php'));
$PAGE->set_context(context_system::instance());

if (!is_enabled_auth('sovra')) {
    redirect(new moodle_url('/auth/sovra/login.php'));
}
if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/'));
}

// Valida sesskey y token, consume la sesión de ingreso (un solo uso) y lo registra.
[$result, $user] = login_session::finish($token, $sesskey !== '' && confirm_sesskey($sesskey));
if ($result !== resolver::OK) {
    redirect(new moodle_url('/auth/sovra/login.php', ['result' => $result]));
}

$user = get_complete_user_data('id', $user->id);
complete_user_login($user);
redirect(core_login_get_return_url());
