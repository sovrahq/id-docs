<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Ingreso con la SuperApp: QR + enlace openid4vp://, y espera del resultado.
 *
 * Con ?result=<código> muestra el resultado de un intento (por ejemplo «No tienes cuenta en <plataforma>»).
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Es la página para iniciar sesión.
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tcpdf/tcpdf_barcodes_2d.php');

use auth_sovra\config;
use auth_sovra\login_session;

$result = optional_param('result', '', PARAM_ALPHANUMEXT);

$PAGE->set_url(new moodle_url('/auth/sovra/login.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('login_title', 'auth_sovra'));

if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/'));
}

$enabled = is_enabled_auth('sovra') && config::ready();

if ($result !== '') {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('login_title', 'auth_sovra'), 2);
    echo \auth_sovra\ui::result($result);
    echo $OUTPUT->footer();
    exit;
}

if (!$enabled) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('login_title', 'auth_sovra'), 2);
    echo $OUTPUT->notification(get_string('login_unavailable', 'auth_sovra'), 'warning', false);
    echo html_writer::link(new moodle_url('/login/index.php'), get_string('back', 'auth_sovra'), ['class' => 'btn btn-secondary']);
    echo $OUTPUT->footer();
    exit;
}

try {
    [$session, $uri] = login_session::start();
} catch (\auth_sovra\api_exception $e) {
    // Ya quedó en el registro con el estado HTTP y el código. A la persona, sin detalles.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('login_title', 'auth_sovra'), 2);
    echo $OUTPUT->notification(get_string('login_error', 'auth_sovra'), 'error', false);
    echo html_writer::link($PAGE->url, get_string('retry', 'auth_sovra'), ['class' => 'btn btn-primary me-2 mr-2']);
    echo html_writer::link(new moodle_url('/login/index.php'), get_string('back', 'auth_sovra'), ['class' => 'btn btn-secondary']);
    echo $OUTPUT->footer();
    exit;
}

// El QR codifica la URI tal cual. Se genera acá, sin servicios externos.
$qr = new TCPDF2DBarcode($uri, 'QRCODE,M');
$svg = $qr->getBarcodeSVGcode(5, 5, 'black');
$qrhtml = html_writer::empty_tag('img', [
    'src' => 'data:image/svg+xml;base64,' . base64_encode($svg),
    'alt' => get_string('qr_alt', 'auth_sovra'),
    'style' => 'width: 260px; max-width: 100%; height: auto; background: #fff; padding: 8px;',
]);

$statusurl = new moodle_url('/auth/sovra/status.php', ['token' => $session->token, 'sesskey' => sesskey()]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('login_title', 'auth_sovra'), 2);
echo html_writer::start_div('auth_sovra-login', ['id' => 'auth-sovra-login']);
echo html_writer::tag('p', get_string('login_intro', 'auth_sovra'));
echo html_writer::div($qrhtml, 'text-center my-3', ['id' => 'auth-sovra-qr']);
echo html_writer::div(
    html_writer::link($uri, get_string('login_openwallet', 'auth_sovra'), ['class' => 'btn btn-primary', 'id' => 'auth-sovra-open'])
    . html_writer::tag('div', get_string('login_openwallet_hint', 'auth_sovra'), ['class' => 'small text-muted mt-1']),
    'text-center mb-3');
echo html_writer::tag('p', get_string('login_waiting', 'auth_sovra'), ['id' => 'auth-sovra-state', 'class' => 'text-center',
    'role' => 'status', 'aria-live' => 'polite']);
echo html_writer::tag('p', '', ['id' => 'auth-sovra-countdown', 'class' => 'text-center small text-muted']);
echo html_writer::div(
    html_writer::link($PAGE->url, get_string('login_newcode', 'auth_sovra'), ['class' => 'btn btn-secondary d-none',
        'id' => 'auth-sovra-new'])
    . ' ' . html_writer::link(new moodle_url('/login/index.php'), get_string('back', 'auth_sovra'), ['class' => 'btn btn-link']),
    'text-center');
echo html_writer::end_div();

// La billetera no vuelve a esta página: la página pregunta. Sin dependencias, para que ande en el web view de la SuperApp.
$params = json_encode([
    'statusurl' => $statusurl->out(false),
    'expires' => (int) $session->timeexpires,
    'now' => time(),
    'strexpires' => get_string('login_expiresin', 'auth_sovra', '{t}'),
    'strexpired' => get_string('login_expired', 'auth_sovra'),
    'strsuccess' => get_string('login_success', 'auth_sovra'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
echo html_writer::script(<<<JS
(function(p) {
    var offset = Date.now() / 1000 - p.now;
    var state = document.getElementById('auth-sovra-state');
    var countdown = document.getElementById('auth-sovra-countdown');
    var done = false;
    function expired() {
        done = true;
        state.textContent = p.strexpired;
        countdown.textContent = '';
        document.getElementById('auth-sovra-qr').classList.add('d-none');
        document.getElementById('auth-sovra-open').classList.add('d-none');
        document.getElementById('auth-sovra-new').classList.remove('d-none');
    }
    function tick() {
        if (done) { return; }
        var left = Math.max(0, Math.round(p.expires - (Date.now() / 1000 - offset)));
        var m = Math.floor(left / 60), s = left % 60;
        countdown.textContent = p.strexpires.replace('{t}', m + ':' + (s < 10 ? '0' : '') + s);
    }
    function poll() {
        if (done) { return; }
        fetch(p.statusurl, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.state === 'pending') { setTimeout(poll, 3000); return; }
                if (d.state === 'expired') { expired(); return; }
                done = true;
                if (d.state === 'ok') { state.textContent = p.strsuccess; }
                window.location.href = d.url;
            })
            .catch(function() { setTimeout(poll, 5000); });
    }
    tick();
    setInterval(tick, 1000);
    setTimeout(poll, 3000);
})($params);
JS
);
echo $OUTPUT->footer();
