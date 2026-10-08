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
 * Referencia: docs/guides/credentials/05-webhooks.md
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
     * @return int 200, 401 o 503
     */
    public static function handle(string $rawbody, ?string $signature, string $secret): int {
        global $DB;

        if ($secret === '') {
            return 503;
        }
        if (!hmac::is_valid($rawbody, $signature, $secret)) {
            // Para el reporte: un secreto mal copiado se ve acá. No se guarda el cuerpo: trae datos personales.
            set_config('lastrejected', time(), 'local_sovracred');
            return 401;
        }

        $payload = json_decode($rawbody, true);
        if (!is_array($payload) || empty($payload['id']) || empty($payload['event']) || !is_string($payload['id'])) {
            // Firmado por Sovra pero ilegible: reintentarlo no lo arregla.
            return 200;
        }
        $eventid = \core_text::substr($payload['id'], 0, 64);
        $event = \core_text::substr((string) $payload['event'], 0, 64);

        if ($DB->record_exists('local_sovracred_webhook', ['eventid' => $eventid])) {
            return 200;
        }

        try {
            $rowid = $DB->insert_record('local_sovracred_webhook', (object) [
                'eventid' => $eventid,
                'event' => $event,
                'timereceived' => time(),
            ]);
        } catch (\dml_write_exception $e) {
            // Misma entrega procesándose en paralelo: el índice único sobre eventid ya la tomó.
            return 200;
        }

        try {
            self::apply($event, $payload);
        } catch (\Throwable $e) {
            // Liberar el id para que el reintento de Sovra (que llega por el 500) se procese.
            $DB->delete_records('local_sovracred_webhook', ['id' => $rowid]);
            throw $e;
        }
        return 200;
    }

    /**
     * Aplica el evento al derecho correspondiente, si existe y si el evento no es más viejo que su estado.
     *
     * @param string $event
     * @param array $payload
     * @return void
     */
    private static function apply(string $event, array $payload): void {
        global $DB;

        if (!isset(self::TRANSITIONS[$event]) || empty($payload['data']['credential_id'])) {
            return;
        }
        $data = $payload['data'];
        $issuance = $DB->get_record(issuance::TABLE, ['credentialid' => (string) $data['credential_id']]);
        if (!$issuance) {
            // Una credencial que emitió otro sistema con el mismo workspace.
            return;
        }

        // Los eventos se entregan en paralelo y con reintentos: un revoked puede llegar antes que un issued demorado.
        $eventtime = !empty($payload['timestamp']) ? (int) strtotime($payload['timestamp']) : time();
        if ($eventtime < (int) $issuance->statustime) {
            return;
        }

        $changes = ['status' => self::TRANSITIONS[$event], 'statustime' => $eventtime, 'lasterror' => null];
        if ($event === 'credential.issued') {
            $issuedat = !empty($data['issued_at']) ? strtotime($data['issued_at']) : false;
            $changes['holderdid'] = $data['holder_did'] ?? null;
            $changes['timeissued'] = $issuedat ?: $eventtime;
            // El SD-JWT firmado llega sólo en este evento ($data['credential']) y Sovra no lo guarda.
            // Este plugin no lo necesita y no lo conserva; si tu institución sí, guardalo acá.
        }
        issuance::update($issuance, $changes);
    }
}
