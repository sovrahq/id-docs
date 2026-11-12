<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Registro para el administrador: cada llamada a la API de Sovra y cada aviso del webhook.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_sovracred\log;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_sovracred_logs');

$kind = optional_param('kind', '', PARAM_ALPHA);
$errors = optional_param('errors', 0, PARAM_BOOL);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

if (!in_array($kind, ['', log::API, log::WEBHOOK], true)) {
    $kind = '';
}
$baseurl = new moodle_url('/local/sovracred/logs.php', array_filter(['kind' => $kind, 'errors' => $errors]));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('logs', 'local_sovracred'));
echo html_writer::tag('p', get_string('logs_intro', 'local_sovracred', (object) [
    'days' => log::RETENTION / DAYSECS,
    'reporturl' => (new moodle_url('/local/sovracred/index.php'))->out(false),
]));

// Filtros: tipo y sólo errores.
$filters = [];
foreach (['' => get_string('all'), log::API => get_string('kind_api', 'local_sovracred'),
        log::WEBHOOK => get_string('kind_webhook', 'local_sovracred')] as $value => $label) {
    $filters[] = html_writer::link(new moodle_url('/local/sovracred/logs.php', array_filter(['kind' => $value, 'errors' => $errors])),
        $label, ['class' => $kind === $value ? 'fw-bold font-weight-bold' : '']);
}
$filters[] = html_writer::link(new moodle_url('/local/sovracred/logs.php', array_filter(['kind' => $kind, 'errors' => !$errors])),
    get_string($errors ? 'logs_showall' : 'logs_onlyerrors', 'local_sovracred'));
echo html_writer::tag('p', implode(' · ', $filters));

// Un error es una llamada que no dio ok, o un aviso que no se aplicó por un problema de este lado.
$conditions = [];
$params = [];
if ($kind !== '') {
    $conditions[] = 'l.kind = :kind';
    $params['kind'] = $kind;
}
if ($errors) {
    $conditions[] = "((l.kind = 'api' AND l.result <> 'ok') OR " .
        "(l.kind = 'webhook' AND l.result IN ('badsignature', 'notconfigured', 'otherworkspace', 'failed', 'unreadable')))";
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_sovracred_log} l $where", $params);
$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
// El estudiante y el curso, si la credencial es la oferta vigente de un derecho.
$rows = $DB->get_records_sql("
        SELECT l.*, i.id AS issuanceid, c.fullname AS coursename, $userfields
          FROM {local_sovracred_log} l
     LEFT JOIN {local_sovracred_issuance} i ON i.credentialid = l.credentialid
     LEFT JOIN {user} u ON u.id = i.userid
     LEFT JOIN {course} c ON c.id = i.courseid
        $where
      ORDER BY l.timecreated DESC, l.id DESC", $params, $page * $perpage, $perpage);

if (!$rows) {
    echo $OUTPUT->notification(get_string('logs_empty', 'local_sovracred'), 'info', false);
}

$table = new html_table();
$table->head = [
    get_string('date'), get_string('logs_kind', 'local_sovracred'), get_string('logs_action', 'local_sovracred'),
    get_string('logs_http', 'local_sovracred'), get_string('logs_result', 'local_sovracred'),
    get_string('logs_credential', 'local_sovracred'), get_string('logs_detail', 'local_sovracred'),
];
foreach ($rows as $row) {
    if ($row->kind === log::API) {
        $result = $row->result === 'ok' ? '✅ OK' : '❌ ' . html_writer::tag('code', s($row->result));
        $direction = get_string('kind_api_short', 'local_sovracred');
    } else {
        $ok = in_array($row->result, ['applied', 'stale', 'duplicate'], true);
        $neutral = in_array($row->result, ['unknowncredential', 'unhandledevent'], true);
        $result = ($ok ? '✅ ' : ($neutral ? 'ℹ️ ' : '❌ ')) . get_string('result_' . $row->result, 'local_sovracred');
        $direction = get_string('kind_webhook_short', 'local_sovracred');
    }

    $credential = '';
    if ($row->credentialid) {
        $credential = html_writer::tag('code', s($row->credentialid), ['class' => 'small']);
        if ($row->issuanceid) {
            $credential .= html_writer::div(s(fullname($row)) . ' · ' . format_string($row->coursename), 'small text-muted');
        }
    }

    $detail = [];
    if ($row->duration !== null) {
        $detail[] = get_string('logs_duration', 'local_sovracred', (int) $row->duration);
    }
    if ($row->eventid) {
        $detail[] = get_string('logs_eventid', 'local_sovracred', html_writer::tag('code', s($row->eventid)));
    }
    if ($row->workspaceid) {
        $detail[] = get_string('logs_workspace', 'local_sovracred', html_writer::tag('code', s($row->workspaceid)));
    }
    if ($row->detail !== null && $row->detail !== '') {
        $detail[] = s($row->detail);
    }

    $table->data[] = [
        userdate($row->timecreated, get_string('strftimedatetimeaccurate', 'langconfig')),
        $direction,
        html_writer::tag('code', s($row->action)),
        $row->httpstatus ?: '—',
        $result,
        $credential,
        html_writer::div(implode('<br>', $detail), 'small'),
    ];
}
if ($rows) {
    echo html_writer::table($table);
}
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
