<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Registro de lo que el plugin habla con Sovra: cada llamada a la API y cada aviso del webhook.
 *
 * Es lo que el administrador mira cuando algo no anda («¿llegan los avisos?», «¿qué contestó la API?»),
 * y lo único que hay: la consola de Sovra no tiene historial de entregas.
 *
 * No guarda claims ni cuerpos, que traen datos personales: sólo qué pasó, cuándo y con qué credencial.
 * Escribir acá nunca hace fallar el flujo.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log {

    /** @var string Tabla. */
    const TABLE = 'local_sovracred_log';

    /** @var string Llamada de Moodle a la API de Sovra. */
    const API = 'api';

    /** @var string Aviso de Sovra al webhook de Moodle. */
    const WEBHOOK = 'webhook';

    /** @var string[] Resultados de un aviso del webhook. */
    const WEBHOOK_RESULTS = [
        'applied', 'stale', 'duplicate', 'unknowncredential', 'unhandledevent', 'otherworkspace',
        'unreadable', 'badsignature', 'notconfigured', 'failed',
    ];

    /** @var int Cuánto se guarda el registro. */
    const RETENTION = 30 * DAYSECS;

    /**
     * Registra una llamada a la API.
     *
     * @param string $method
     * @param string $path
     * @param int $httpstatus 0 si no hubo respuesta.
     * @param string $result 'ok' o el código de error.
     * @param int $duration Milisegundos.
     * @param string|null $credentialid
     * @param string|null $detail
     * @return void
     */
    public static function api(string $method, string $path, int $httpstatus, string $result, int $duration,
            ?string $credentialid = null, ?string $detail = null): void {
        self::write([
            'kind' => self::API,
            'action' => $method . ' ' . $path,
            'httpstatus' => $httpstatus,
            'result' => $result,
            'credentialid' => $credentialid,
            'duration' => $duration,
            'detail' => $detail,
        ]);
    }

    /**
     * Registra un aviso del webhook.
     *
     * @param string $event El tipo de evento, o '' si no se pudo leer.
     * @param int $httpstatus Lo que respondió Moodle.
     * @param string $result Uno de WEBHOOK_RESULTS.
     * @param array $payload El sobre decodificado (vacío si no se pudo leer o no estaba firmado).
     * @param string|null $detail
     * @return void
     */
    public static function webhook(string $event, int $httpstatus, string $result, array $payload = [],
            ?string $detail = null): void {
        self::write([
            'kind' => self::WEBHOOK,
            'action' => $event !== '' ? $event : '—',
            'httpstatus' => $httpstatus,
            'result' => $result,
            'credentialid' => is_string($payload['data']['credential_id'] ?? null) ? $payload['data']['credential_id'] : null,
            'eventid' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
            'workspaceid' => is_string($payload['workspace_id'] ?? null) ? $payload['workspace_id'] : null,
            'detail' => $detail,
        ]);
    }

    /**
     * El último aviso del webhook firmado correctamente.
     *
     * @return \stdClass|null
     */
    public static function last_webhook(): ?\stdClass {
        global $DB;
        $records = $DB->get_records_select(self::TABLE, "kind = :kind AND result NOT IN ('badsignature', 'notconfigured')",
            ['kind' => self::WEBHOOK], 'timecreated DESC, id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Borra lo que pasó la retención.
     *
     * @return void
     */
    public static function prune(): void {
        global $DB;
        $DB->delete_records_select(self::TABLE, 'timecreated < :cutoff', ['cutoff' => time() - self::RETENTION]);
    }

    /**
     * Inserta una fila, recortando cada campo a su largo.
     *
     * @param array $row
     * @return void
     */
    private static function write(array $row): void {
        global $DB;

        $limits = ['action' => 100, 'result' => 64, 'credentialid' => 36, 'eventid' => 64, 'workspaceid' => 64, 'detail' => 1000];
        foreach ($limits as $field => $length) {
            if (isset($row[$field])) {
                $row[$field] = \core_text::substr((string) $row[$field], 0, $length);
            }
        }
        $row['timecreated'] = time();
        try {
            $DB->insert_record(self::TABLE, (object) $row);
        } catch (\Throwable $e) {
            // El registro es para diagnosticar: si no se puede escribir, la emisión sigue igual.
            debugging('local_sovracred: no se pudo escribir el registro: ' . get_class($e), DEBUG_NORMAL);
        }
    }
}
