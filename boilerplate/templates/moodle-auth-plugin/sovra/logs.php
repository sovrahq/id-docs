<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Registro del ingreso con la SuperApp y prueba de conexión, para el administrador.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('auth_sovra_logs');

$test = optional_param('test', 0, PARAM_BOOL);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('logs', 'auth_sovra'));

// Un secreto que no se puede descifrar deja el ingreso apagado sin otro aviso.
if ($undecryptable = \auth_sovra\config::undecryptable()) {
    echo $OUTPUT->notification(get_string('secret_undecryptable', 'auth_sovra', implode(', ', $undecryptable)), 'error', false);
}
// Un campo de documento que la persona puede editar permite apropiarse del documento de otra.
foreach (\auth_sovra\config::document_field_warnings() as $warning) {
    echo $OUTPUT->notification(s($warning), 'warning', false);
}

if ($test && confirm_sesskey()) {
    [$ok, $message] = \auth_sovra\connection_test::run();
    echo $OUTPUT->notification($message, $ok ? 'success' : 'error', false);
}

echo $OUTPUT->single_button(new moodle_url('/auth/sovra/logs.php', ['test' => 1, 'sesskey' => sesskey()]),
    get_string('test_button', 'auth_sovra'), 'post');
echo html_writer::tag('p', get_string('logs_intro', 'auth_sovra'), ['class' => 'mt-3']);

$records = $DB->get_records(\auth_sovra\log::TABLE, null, 'timecreated DESC, id DESC', '*', 0, 300);
if (!$records) {
    echo $OUTPUT->notification(get_string('logs_empty', 'auth_sovra'), 'info', false);
} else {
    $table = new html_table();
    $table->head = [
        get_string('logs_time', 'auth_sovra'), get_string('logs_kind', 'auth_sovra'), get_string('logs_action', 'auth_sovra'),
        get_string('logs_http', 'auth_sovra'), get_string('logs_result', 'auth_sovra'),
        get_string('logs_verification', 'auth_sovra'), get_string('logs_user', 'auth_sovra'),
    ];
    foreach ($records as $r) {
        $user = $r->userid ? html_writer::link(new moodle_url('/user/profile.php', ['id' => $r->userid]), $r->userid) : '';
        $result = s($r->result) . ($r->detail ? html_writer::div(s($r->detail), 'small text-muted') : '');
        $table->data[] = [userdate($r->timecreated, get_string('strftimedatetimeshort', 'langconfig')), s($r->kind),
            s($r->action), $r->httpstatus ?: '', $result, s((string) $r->verificationid), $user];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
