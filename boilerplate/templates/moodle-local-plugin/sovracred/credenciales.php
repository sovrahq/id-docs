<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * «Mis credenciales»: el estudiante ve sus cursos completados y recibe cada credencial en su cartera.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_sovracred\api_client;
use local_sovracred\api_exception;
use local_sovracred\issuance;
use local_sovracred\missing_claims_exception;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tcpdf/tcpdf_barcodes_2d.php');

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('guestsarenotallowed');
}

$action = optional_param('op', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

$url = new moodle_url('/local/sovracred/credenciales.php');
$PAGE->set_url($url);
// Contexto de sistema: el de usuario agrega la cabecera de perfil, que acá no aporta.
// Los datos son siempre los del propio usuario (todas las consultas filtran por $USER->id).
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mycredentials', 'local_sovracred'));
$PAGE->set_heading(get_string('mycredentials', 'local_sovracred'));

// «Recibir en mi cartera»: sólo sobre un derecho propio, y con la clave de sesión.
if ($action === 'recibir') {
    require_sesskey();
    $record = $DB->get_record(issuance::TABLE, ['id' => $id, 'userid' => $USER->id], '*', MUST_EXIST);
    try {
        issuance::request_offer($record);
    } catch (missing_claims_exception $e) {
        redirect($url, get_string('error_missingdata', 'local_sovracred'), null, \core\output\notification::NOTIFY_ERROR);
    } catch (api_exception $e) {
        redirect($url, get_string('error_api', 'local_sovracred'), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect(new moodle_url($url, ['id' => $id]));
}

$records = $DB->get_records(issuance::TABLE, ['userid' => $USER->id], 'timecompleted DESC');

// Si hay ofertas abiertas, preguntar si ya se canjearon: el estudiante escanea con el teléfono
// y esta página (abierta en la computadora) se actualiza sola.
$hasopenoffer = false;
if (\local_sovracred\config::api_ready()) {
    $api = null;
    foreach ($records as $record) {
        // Como mucho una consulta cada 10 s por oferta, aunque haya varias pestañas recargándose.
        if ($record->status !== issuance::OFFERED || $record->timechecked > time() - 10) {
            continue;
        }
        try {
            $api = $api ?? api_client::from_config();
            $records[$record->id] = issuance::refresh($record, $api);
        } catch (api_exception $e) {
            // Se muestra lo que hay; la tarea programada vuelve a intentar.
            continue;
        }
    }
}

$devicetype = core_useragent::get_device_type();
$isphone = in_array($devicetype, [core_useragent::DEVICETYPE_MOBILE, core_useragent::DEVICETYPE_TABLET], true);

echo $OUTPUT->header();

if (!$records) {
    echo $OUTPUT->notification(get_string('nocredentials', 'local_sovracred'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('mycredentials_intro', 'local_sovracred'), ['class' => 'lead']);

foreach ($records as $record) {
    $course = $DB->get_record('course', ['id' => $record->courseid], 'id, fullname');
    $coursename = $course ? format_string($course->fullname, true, ['context' => context_course::instance($course->id)]) : '—';

    $body = html_writer::tag('h3', $coursename, ['class' => 'h5 card-title']);
    $body .= html_writer::tag('p', get_string('completedon', 'local_sovracred',
        userdate($record->timecompleted, get_string('strftimedate', 'langconfig'))), ['class' => 'text-muted mb-2']);

    $offervalid = $record->status === issuance::OFFERED && $record->offerexpires > time()
        && strpos((string) $record->offeruri, issuance::OFFER_SCHEME) === 0;

    if ($record->status === issuance::AVAILABLE || ($record->status === issuance::OFFERED && !$offervalid)) {
        $body .= html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
        $body .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $body .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'op', 'value' => 'recibir']);
        $body .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $record->id]);
        $body .= html_writer::tag('button', get_string('receive', 'local_sovracred'),
            ['type' => 'submit', 'class' => 'btn btn-primary']);
        $body .= html_writer::end_tag('form');

    } else if ($offervalid) {
        $hasopenoffer = true;

        // El QR codifica la URI de la oferta tal cual, sin modificarla. Se genera acá, sin servicios externos.
        $qr = new TCPDF2DBarcode($record->offeruri, 'QRCODE,M');
        $svg = $qr->getBarcodeSVGcode(5, 5, 'black');
        $qrhtml = html_writer::empty_tag('img', [
            'src' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'alt' => get_string('qr_alt', 'local_sovracred'),
            'class' => 'img-fluid border p-2 bg-white',
            'style' => 'max-width: 280px; width: 100%;',
        ]);
        $qrblock = html_writer::div(
            html_writer::tag('p', get_string('scanqr', 'local_sovracred'), ['class' => 'mb-2']) . $qrhtml, 'mb-3');

        // Enlace directo openid-credential-offer://, para cuando la página está abierta en el mismo teléfono.
        // Se arma a mano: moodle_url no acepta esquemas propios.
        $linkblock = html_writer::div(
            '<a class="btn ' . ($isphone ? 'btn-primary btn-lg' : 'btn-outline-primary') . '" href="' . s($record->offeruri) . '">' .
                get_string('openwallet', 'local_sovracred') . '</a>' .
            html_writer::tag('p', get_string('openwallet_help', 'local_sovracred'), ['class' => 'small text-muted mt-1']),
            'mb-3');

        $body .= $isphone ? $linkblock . $qrblock : $qrblock . $linkblock;
        $body .= html_writer::tag('p', get_string('offerexpires', 'local_sovracred',
            userdate($record->offerexpires, get_string('strftimedatetimeshort', 'langconfig'))), ['class' => 'small text-muted']);

    } else if ($record->status === issuance::ISSUED) {
        $issuedtext = $record->timeissued
            ? get_string('status_issued_student', 'local_sovracred', userdate($record->timeissued, get_string('strftimedate', 'langconfig')))
            : get_string('status_issued_student_nodate', 'local_sovracred');
        $body .= $OUTPUT->notification($issuedtext, \core\output\notification::NOTIFY_SUCCESS, false);

    } else {
        $body .= $OUTPUT->notification(get_string('status_' . $record->status . '_student', 'local_sovracred'),
            \core\output\notification::NOTIFY_WARNING, false);
    }

    $body .= html_writer::tag('p', get_string('verificationcode', 'local_sovracred', $record->verificationcode),
        ['class' => 'small text-muted mb-0']);

    echo html_writer::div(html_writer::div($body, 'card-body'), 'card mb-3', ['id' => 'credencial-' . $record->id]);
}

if ($hasopenoffer) {
    // Mientras haya una oferta en pantalla, recargar cada 20 s para mostrar «Ya está en tu cartera» al escanear.
    $PAGE->requires->js_amd_inline("setTimeout(function() { window.location.reload(); }, 20000);");
}

echo $OUTPUT->footer();
