<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Procesa las entregas del webhook de Sovra.
 *
 * Qué responder importa tanto como qué hacer: un 4xx hace que Sovra descarte el evento sin reintentar.
 * Por eso sólo la firma inválida da 401; todo lo demás con firma válida da 200, y un secreto sin
 * configurar da 503 para que Sovra reintente cuando la configuración esté completa.
 *
 * El webhook es opcional: si no llega (el firewall del sitio lo bloquea), la tarea de reconciliación
 * confirma igual las emisiones.
 *
 * Cada aviso queda en el registro (log) con lo que se hizo con él. Los que no traen una firma válida,
 * como mucho uno por minuto: el endpoint es público y no tiene que poder llenar la base.
 *
 * Referencia: docs/guides/credentials/06-webhooks.md
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webhook {

    /** @var string[] Evento → estado al que lleva el derecho. */
    const TRANSITIONS = [
        'credential.issued' => issuance::ISSUED,
        'credential.revoked' => issuance::REVOKED,
        'credential.suspended' => issuance::SUSPENDED,
        'credential.unsuspended' => issuance::ISSUED,
    ];

    /**
     * Procesa una entrega y devuelve el código HTTP a responder.
     *
     * @param string $rawbody El cuerpo tal como llegó.
     * @param string|null $signature El header X-Sovra-Signature.
     * @param string $secret El secreto del webhook.
     * @return int 200, 401, 500 o 503
     */
    public static function handle(string $rawbody, ?string $signature, string $secret): int {
        global $DB;

        if ($secret === '') {
            if ((int) get_config('local_sovracred', 'lastunconfigured') < time() - MINSECS) {
                set_config('lastunconfigured', time(), 'local_sovracred');
                log::webhook('', 503, 'notconfigured');
            }
            return 503;
        }
        if (!hmac::is_valid($rawbody, $signature, $secret)) {
            // Para el reporte: un secreto mal copiado se ve acá. No se guarda el cuerpo: trae datos personales.
            // Como mucho una escritura por minuto: el endpoint es público y esto no tiene que ser disparable a voluntad.
            if ((int) get_config('local_sovracred', 'lastrejected') < time() - MINSECS) {
                set_config('lastrejected', time(), 'local_sovracred');
                log::webhook('', 401, 'badsignature');
            }
            return 401;
        }

        $payload = json_decode($rawbody, true);
        if (!is_array($payload) || empty($payload['id']) || empty($payload['event']) || !is_string($payload['id'])) {
            // Firmado por Sovra pero ilegible: reintentarlo no lo arregla.
            log::webhook('', 200, 'unreadable', is_array($payload) ? $payload : []);
            return 200;
        }
        $eventid = \core_text::substr($payload['id'], 0, 64);
        $event = \core_text::substr((string) $payload['event'], 0, 64);

        if ($DB->record_exists('local_sovracred_webhook', ['eventid' => $eventid])) {
            log::webhook($event, 200, 'duplicate', $payload);
            return 200;
        }

        // La API key y el secreto del webhook salen de la consola por separado: si son de workspaces distintos,
        // los avisos llegan firmados pero hablan de credenciales que este Moodle no emitió.
        $workspaceid = config::workspace_id();
        if ($workspaceid !== '' && strcasecmp($workspaceid, (string) ($payload['workspace_id'] ?? '')) !== 0) {
            log::webhook($event, 200, 'otherworkspace', $payload);
            return 200;
        }

        // Marcar la entrega y aplicarla en una sola transacción: si el proceso muere en el medio,
        // no queda un id marcado como procesado sin haberse aplicado.
        $transaction = null;
        try {
            $transaction = $DB->start_delegated_transaction();
            $DB->insert_record('local_sovracred_webhook', (object) [
                'eventid' => $eventid,
                'event' => $event,
                'timereceived' => time(),
            ]);
            $result = self::apply($event, $payload);
            $transaction->allow_commit();
            log::webhook($event, 200, $result, $payload);
            return 200;
        } catch (\Throwable $e) {
            if ($transaction && !$transaction->is_disposed()) {
                try {
                    $transaction->rollback($e);
                } catch (\Throwable $rethrown) {
                    // rollback() vuelve a lanzar la excepción: ya la tenemos.
                    unset($rethrown);
                }
            }
            // ¿Era la misma entrega procesándose en paralelo? Entonces el índice único ya la tomó y está aplicada.
            if ($DB->record_exists('local_sovracred_webhook', ['eventid' => $eventid])) {
                log::webhook($event, 200, 'duplicate', $payload);
                return 200;
            }
            // Cualquier otro fallo: 500 para que Sovra reintente. Un 4xx lo descartaría para siempre,
            // y una excepción sin atrapar acá la convierte Moodle en un 404.
            debugging('local_sovracred: no se pudo aplicar la entrega ' . $eventid . ': ' . get_class($e), DEBUG_NORMAL);
            log::webhook($event, 500, 'failed', $payload, get_class($e));
            return 500;
        }
    }

    /**
     * Aplica el evento al derecho correspondiente, si existe y si el evento no es más viejo que su estado.
     *
     * @param string $event
     * @param array $payload
     * @return string Para el registro: applied, stale, unknowncredential o unhandledevent.
     */
    private static function apply(string $event, array $payload): string {
        global $DB;

        if (!isset(self::TRANSITIONS[$event]) || empty($payload['data']['credential_id'])) {
            return 'unhandledevent';
        }
        $data = $payload['data'];
        $issuance = $DB->get_record(issuance::TABLE, ['credentialid' => (string) $data['credential_id']]);
        if (!$issuance) {
            // Una credencial que emitió otro sistema con el mismo workspace.
            return 'unknowncredential';
        }

        // Los eventos se entregan en paralelo y con reintentos: un revoked puede llegar antes que un issued demorado.
        // statustime sólo guarda horas del reloj de Sovra (timestamp de eventos, issued_at): nunca el de Moodle.
        $eventtime = !empty($payload['timestamp']) ? (int) strtotime($payload['timestamp']) : 0;
        if (!$eventtime) {
            // Sin hora legible: se aplica, pero sin mover la marca.
            $eventtime = (int) $issuance->statustime;
        }
        if ($eventtime < (int) $issuance->statustime) {
            // No pisa el estado, pero un issued demorado detrás de un revoked trae datos que no hay de otro modo.
            $missing = issuance::missing_issue_data($issuance, $data);
            if ($missing) {
                issuance::update($issuance, $missing);
            }
            return 'stale';
        }

        $changes = ['status' => self::TRANSITIONS[$event], 'statustime' => $eventtime, 'lasterror' => null];
        if (self::TRANSITIONS[$event] === issuance::ISSUED && empty($issuance->timeissued)) {
            // Un unsuspended que llega antes que el issued demorado.
            $changes['timeissued'] = $eventtime ?: time();
        }
        if ($event === 'credential.issued') {
            $issuedat = !empty($data['issued_at']) ? strtotime($data['issued_at']) : false;
            $changes['holderdid'] = $data['holder_did'] ?? null;
            $changes['timeissued'] = $issuedat ?: $eventtime;
            $expiresat = !empty($data['expires_at']) ? strtotime($data['expires_at']) : false;
            if ($expiresat) {
                $changes['credexpires'] = $expiresat;
            }
        } else {
            // revoked, suspended y unsuspended traen el holder_did: sirve si el issued todavía no llegó.
            $changes += issuance::missing_issue_data($issuance, $data);
            // El SD-JWT firmado llega sólo en este evento ($data['credential']) y Sovra no lo guarda.
            // Este plugin no lo necesita y no lo conserva; si tu institución sí, guardalo acá.
        }
        issuance::update($issuance, $changes);
        return 'applied';
    }
}
