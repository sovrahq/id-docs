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
use local_sovracred\connection_test;
use local_sovracred\issuance;
use local_sovracred\log;

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
        foreach (connection_test::run() as [$testtype, $message]) {
            \core\notification::add($message, $testtype);
        }
        redirect($baseurl);
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

// La configuración tal como la ve el plugin, con los secretos enmascarados: lo primero que hay que mirar.
$notset = html_writer::tag('em', get_string('notset', 'local_sovracred'));
$code = fn(string $value) => $value !== '' ? html_writer::tag('code', s($value)) : $notset;
$summary = new html_table();
$summary->attributes['class'] = 'generaltable w-auto';
$summary->data = [
    [get_string('apiurl', 'local_sovracred'), $code(config::api_url())],
    [get_string('apikey', 'local_sovracred'), $code(config::masked(config::api_key()))],
    [get_string('workspaceid', 'local_sovracred'), $code(config::workspace_id())],
    [get_string('schemaid', 'local_sovracred'), $code(config::schema_id())],
    [get_string('webhooksecret', 'local_sovracred'), $code(config::masked(config::webhook_secret()))],
    [get_string('webhookurl', 'local_sovracred'), $code(config::webhook_url()->out(false))],
];
echo html_writer::table($summary);
echo html_writer::tag('p', html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'local_sovracred']),
    get_string('editsettings', 'local_sovracred')) . ' · ' .
    html_writer::link(new moodle_url('/local/sovracred/mapeo.php'), get_string('map', 'local_sovracred')) . ' · ' .
    html_writer::link(new moodle_url('/local/sovracred/logs.php'), get_string('logs', 'local_sovracred')));

// Estado de la configuración.
$apiok = config::api_url() !== '' && config::api_key() !== '' && config::schema_id() !== '';
$webhookok = config::webhook_secret() !== '';
$items = html_writer::tag('li', $apiok ? '✅ ' . get_string('check_api', 'local_sovracred')
    : '⚠️ ' . get_string('test_notconfigured', 'local_sovracred'));
$items .= html_writer::tag('li', $webhookok ? '✅ ' . get_string('check_webhook', 'local_sovracred')
    : 'ℹ️ ' . get_string('check_nowebhooksecret', 'local_sovracred'));
$lastwebhook = log::last_webhook();
if ($lastwebhook) {
    $items .= html_writer::tag('li', 'ℹ️ ' . get_string('check_lastwebhook', 'local_sovracred', (object) [
        'time' => userdate($lastwebhook->timecreated),
        'event' => s($lastwebhook->action),
        'result' => get_string('result_' . $lastwebhook->result, 'local_sovracred'),
    ]));
} else if (config::webhook_secret() !== '') {
    $items .= html_writer::tag('li', 'ℹ️ ' . get_string('check_nowebhook', 'local_sovracred'));
}
if ($lastwebhook && $lastwebhook->workspaceid && config::workspace_id() === '') {
    $items .= html_writer::tag('li', 'ℹ️ ' . get_string('check_workspacehint', 'local_sovracred', s($lastwebhook->workspaceid)));
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
        get_string('status_' . (issuance::is_expired($row) ? 'expired' : $row->status), 'local_sovracred'),
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
