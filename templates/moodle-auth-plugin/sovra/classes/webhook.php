<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * Procesa las entregas del webhook de Sovra: presentation.verified y presentation.failed.
 *
 * Qué responder importa: un 4xx hace que Sovra descarte el evento para siempre, así que nunca se responde 4xx.
 * Un secreto sin configurar, ilegible o que no coincide con la firma da 503: Sovra reintenta durante ~1,5 h y
 * firma los reintentos con el secreto vigente, así que corregir el secreto recupera los eventos. Un error al
 * procesar da 500 (también reintenta). Todo lo demás con firma válida da 200.
 *
 * El webhook es opcional: la página de ingreso consulta la verificación por GET mientras espera, así que no
 * lo hace más rápido; lo que aporta es que el veredicto no dependa de que la API responda a cada consulta.
 * Los dos caminos escriben el mismo veredicto con login_session::apply(), y el que llega segundo no cambia
 * nada; si el segundo es el webhook, su entrega queda anotada como alreadysettled.
 *
 * Referencia: apps/docs/src/content/docs/webhooks.mdx
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
     * @return int 200, 500 o 503
     */
    public static function handle(string $rawbody, ?string $signature, string $secret): int {
        if ($secret === '') {
            if (log::once_per_minute('lastunconfigured')) {
                log::webhook('', 503, config::undecryptable() ? 'undecryptable' : 'notconfigured');
            }
            return 503;
        }
        if (!hmac::is_valid($rawbody, $signature, $secret)) {
            // 503 y no 401: con un secreto mal copiado, un 4xx perdería cada evento. No se procesa nada.
            // Como mucho una escritura por minuto: el endpoint es público. No se guarda el cuerpo.
            if (log::once_per_minute('lastrejected')) {
                log::webhook('', 503, 'badsignature');
            }
            return 503;
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
        try {
            $result = login_session::apply($verificationid, self::EVENTS[$event], $data, $error, 'webhook');
        } catch (\Throwable $e) {
            // La base, el lock… Con 500 Sovra reintenta. Acá todavía se sabe qué evento y qué verificación eran.
            // Sólo la clase y el mensaje: el debuginfo de un error de la base puede traer el documento.
            $detail = get_class($e) . ': ' . $e->getMessage();
            error_log('auth_sovra webhook: ' . $detail);
            log::webhook($event, 500, 'exception', $payload, $detail);
            return 500;
        }
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
