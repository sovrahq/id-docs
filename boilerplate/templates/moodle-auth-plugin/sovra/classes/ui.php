<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * Qué se le muestra a la persona según el resultado. Nunca detalles técnicos.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ui {

    /**
     * El HTML del resultado de un intento: aviso, y enlaces para seguir.
     *
     * @param string $result Un código de resolver, de login_session o un error de Sovra.
     * @return string
     */
    public static function result(string $result): string {
        global $OUTPUT;

        $retry = \html_writer::link(new \moodle_url('/auth/sovra/login.php'), get_string('retry', 'auth_sovra'),
            ['class' => 'btn btn-primary me-2 mr-2']);
        $back = \html_writer::link(new \moodle_url('/login/index.php'), get_string('back', 'auth_sovra'),
            ['class' => 'btn btn-secondary']);

        if ($result === resolver::NOT_FOUND) {
            $signup = config::signup_url();
            $html = $OUTPUT->heading(get_string('result_notfound', 'auth_sovra', s(config::platform_name())), 3);
            $html .= \html_writer::tag('p', get_string($signup ? 'result_notfound_signup' : 'result_notfound_nosignup', 'auth_sovra'));
            $links = $signup ? \html_writer::link($signup, get_string('signup', 'auth_sovra'),
                ['class' => 'btn btn-primary me-2 mr-2']) : '';
            return $html . \html_writer::div($links . $back);
        }

        $type = 'warning';
        $showretry = true;
        switch ($result) {
            case resolver::DUPLICATE:
            case resolver::SUSPENDED:
            case resolver::AUTH_NOT_ALLOWED:
            case resolver::ADMIN:
            case resolver::MISCONFIGURED:
                $key = 'result_' . $result;
                $showretry = false;
                break;
            case resolver::ISSUER_MISSING:
            case resolver::ISSUER_NOT_ALLOWED:
            case resolver::TYPE_NOT_ALLOWED:
                $key = 'result_issuernotallowed';
                break;
            case resolver::NO_DOCUMENT:
                $key = 'result_nodocument';
                break;
            case login_session::EXPIRED:
                $key = 'result_expired';
                break;
            case 'generic':
                $key = 'result_generic';
                break;
            default:
                // Un error de Sovra (credential_revoked, holder_key_mismatch…): la credencial no pasó.
                $key = 'result_failed';
        }
        return $OUTPUT->notification(get_string($key, 'auth_sovra'), $type, false)
            . \html_writer::div(($showretry ? $retry : '') . $back);
    }
}
