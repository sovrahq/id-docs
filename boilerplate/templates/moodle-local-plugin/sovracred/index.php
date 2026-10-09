<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Reporte para el administrador: derechos por estado, errores, prueba de conexión y reintentos.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_sovracred\api_client;
use local_sovracred\api_exception;
use local_sovracred\config;
use local_sovracred\issuance;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_sovracred_report');

$status = optional_param('status', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$action = optional_param('op', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$perpage = 50;

$baseurl = new moodle_url('/local/sovracred/index.php', array_filter(['status' => $status, 'page' => $page]));

if ($action !== '') {
    require_sesskey();
    $type = \core\output\notification::NOTIFY_SUCCESS;

    if ($action === 'test') {
        // Una oferta sin claims: con un esquema que tiene campos obligatorios, la API responde
        // missing_claims sin crear nada, y eso prueba a la vez la llave, el esquema y la red.
        try {
            api_client::from_config()->create_offer(config::schema_id(), []);
            $message = get_string('test_created', 'local_sovracred');
            $type = \core\output\notification::NOTIFY_WARNING;
        } catch (api_exception $e) {
            if ($e->errorcode === 'missing_claims') {
                $message = get_string('test_ok', 'local_sovracred');
            } else if (get_string_manager()->string_exists('test_' . $e->errorcode, 'local_sovracred')) {
                $message = get_string('test_' . $e->errorcode, 'local_sovracred');
                $type = \core\output\notification::NOTIFY_ERROR;
            } else {
                $message = get_string('test_other', 'local_sovracred', s($e->summary()));
                $type = \core\output\notification::NOTIFY_ERROR;
            }
        }
        redirect($baseurl, $message, null, $type);
    }

    $record = $DB->get_record(issuance::TABLE, ['id' => $id], '*', MUST_EXIST);
    if ($action === 'refresh') {
        try {
            $record = issuance::refresh($record, api_client::from_config());
            $message = get_string('refreshed', 'local_sovracred', get_string('status_' . $record->status, 'local_sovracred'));
        } catch (api_exception $e) {
            $message = s($e->summary());
            $type = \core\output\notification::NOTIFY_ERROR;
        }
    } else if ($action === 'notify') {
        issuance::notify($record);
        $message = get_string('notified', 'local_sovracred');
    } else {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    redirect($baseurl, $message, null, $type);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report', 'local_sovracred'));

// Estado de la configuración.
$checks = [];
$checks[] = [config::api_url() !== '' && config::api_key() !== '' && config::schema_id() !== '', get_string('check_api', 'local_sovracred')];
$checks[] = [config::webhook_secret() !== '', get_string('check_webhook', 'local_sovracred', config::webhook_url()->out(false))];
$items = '';
foreach ($checks as [$ok, $text]) {
    $items .= html_writer::tag('li', ($ok ? '✅ ' : '⚠️ ') . $text);
}
$lastrejected = (int) get_config('local_sovracred', 'lastrejected');
if ($lastrejected) {
    $items .= html_writer::tag('li', '⚠️ ' . get_string('check_lastrejected', 'local_sovracred', userdate($lastrejected)));
}
echo html_writer::tag('ul', $items, ['class' => 'list-unstyled']);

echo $OUTPUT->single_button(new moodle_url($baseurl, ['op' => 'test', 'sesskey' => sesskey()]),
    get_string('testconnection', 'local_sovracred'), 'post');

// Conteo por estado, que también filtra.
$counts = $DB->get_records_sql_menu("SELECT status, COUNT(1) FROM {local_sovracred_issuance} GROUP BY status");
$links = [html_writer::link(new moodle_url('/local/sovracred/index.php'), get_string('all') . ' (' . array_sum($counts) . ')',
    ['class' => $status === '' ? 'fw-bold font-weight-bold' : ''])];
foreach (issuance::STATUSES as $s) {
    $links[] = html_writer::link(new moodle_url('/local/sovracred/index.php', ['status' => $s]),
        get_string('status_' . $s, 'local_sovracred') . ' (' . ($counts[$s] ?? 0) . ')',
        ['class' => $status === $s ? 'fw-bold font-weight-bold' : '']);
}
echo html_writer::tag('p', implode(' · ', $links), ['class' => 'mt-3']);

$where = $status !== '' ? 'WHERE i.status = :status' : '';
$params = ['status' => $status];
$total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_sovracred_issuance} i $where", $params);
$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$rows = $DB->get_records_sql("
        SELECT i.*, c.fullname AS coursename, $userfields
          FROM {local_sovracred_issuance} i
          JOIN {user} u ON u.id = i.userid
          JOIN {course} c ON c.id = i.courseid
        $where
      ORDER BY i.timemodified DESC", $params, $page * $perpage, $perpage);

$table = new html_table();
$table->head = [
    get_string('user'), get_string('course'), get_string('status'), get_string('completedon_short', 'local_sovracred'),
    get_string('offerexpires_short', 'local_sovracred'), get_string('lasterror', 'local_sovracred'), get_string('actions'),
];
foreach ($rows as $row) {
    $actions = [];
    if (!empty($row->credentialid)) {
        $actions[] = html_writer::link(new moodle_url($baseurl, ['op' => 'refresh', 'id' => $row->id, 'sesskey' => sesskey()]),
            get_string('refresh', 'local_sovracred'));
    }
    if (in_array($row->status, [issuance::AVAILABLE, issuance::OFFERED], true)) {
        $actions[] = html_writer::link(new moodle_url($baseurl, ['op' => 'notify', 'id' => $row->id, 'sesskey' => sesskey()]),
            get_string('notify', 'local_sovracred'));
    }
    $table->data[] = [
        fullname($row),
        format_string($row->coursename),
        get_string('status_' . $row->status, 'local_sovracred'),
        $row->timecompleted ? userdate($row->timecompleted, get_string('strftimedatetimeshort', 'langconfig')) : '',
        $row->status === issuance::OFFERED && $row->offerexpires
            ? userdate($row->offerexpires, get_string('strftimedatetimeshort', 'langconfig')) : '',
        s((string) $row->lasterror),
        implode(' · ', $actions),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
