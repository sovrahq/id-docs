<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * Procesa las entregas del webhook de Sovra: presentation.verified y presentation.failed.
 *
 * Qué responder importa: un 4xx hace que Sovra descarte el evento sin reintentar. Por eso sólo la firma
 * inválida da 401; todo lo demás con firma válida da 200, y un secreto sin configurar da 503.
 *
 * El webhook es opcional: la página de ingreso consulta la verificación por GET mientras espera. Si llega,
 * el ingreso es más rápido. Los dos caminos escriben el mismo veredicto con la misma función, y el que llega
 * segundo no cambia nada.
 *
 * Referencia: docs/guides/credentials/06-webhooks.md
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webhook {

    /** @var string[] Evento → estado de la verificación. */
    const EVENTS = [
        'presentation.verified' => 'completed',
        'presentation.failed' => 'failed',
    ];

    /**
     * Procesa una entrega y devuelve el código HTTP a responder.
     *
     * @param string $rawbody El cuerpo tal como llegó.
     * @param string|null $signature El header X-Sovra-Signature.
     * @param string $secret El secreto del webhook.
     * @return int 200, 401 o 503
     */
    public static function handle(string $rawbody, ?string $signature, string $secret): int {
        if ($secret === '') {
            if ((int) get_config(config::PLUGIN, 'lastunconfigured') < time() - MINSECS) {
                set_config('lastunconfigured', time(), config::PLUGIN);
                log::webhook('', 503, 'notconfigured');
            }
            return 503;
        }
        if (!hmac::is_valid($rawbody, $signature, $secret)) {
            // Como mucho una escritura por minuto: el endpoint es público. No se guarda el cuerpo.
            if ((int) get_config(config::PLUGIN, 'lastrejected') < time() - MINSECS) {
                set_config('lastrejected', time(), config::PLUGIN);
                log::webhook('', 401, 'badsignature');
            }
            return 401;
        }

        $payload = json_decode($rawbody, true);
        if (!is_array($payload) || !is_string($payload['event'] ?? null) || !is_array($payload['data'] ?? null)) {
            log::webhook('', 200, 'unreadable');
            return 200;
        }
        $event = \core_text::substr($payload['event'], 0, 64);
        if (!isset(self::EVENTS[$event])) {
            // Otros eventos del workspace (credential.*, document.*): no son de este plugin.
            log::webhook($event, 200, 'unhandledevent', $payload);
            return 200;
        }

        $workspace = config::workspace_id();
        if ($workspace !== '' && ($payload['workspace_id'] ?? null) !== $workspace) {
            log::webhook($event, 200, 'otherworkspace', $payload);
            return 200;
        }

        $data = $payload['data'];
        $verificationid = is_string($data['verification_id'] ?? null) ? $data['verification_id'] : '';
        if ($verificationid === '' || !self::is_ours($verificationid)) {
            log::webhook($event, 200, 'unknownverification', $payload);
            return 200;
        }

        $error = is_string($data['error'] ?? null) ? $data['error'] : null;
        $result = login_session::apply($verificationid, self::EVENTS[$event], $data, $error, 'webhook');
        log::webhook($event, 200, $result === null ? 'alreadysettled' : 'applied', $payload);
        return 200;
    }

    /**
     * ¿La verificación la abrió este Moodle?
     *
     * @param string $verificationid
     * @return bool
     */
    private static function is_ours(string $verificationid): bool {
        global $DB;
        return $DB->record_exists(login_session::TABLE, ['verificationid' => $verificationid]);
    }
}
