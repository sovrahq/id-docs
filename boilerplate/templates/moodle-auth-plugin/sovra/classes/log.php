<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * Registro de lo que el plugin habla con Sovra y de cada intento de ingreso.
 *
 * Es lo que el administrador mira cuando algo no anda («¿llegan los avisos?», «¿por qué no entró?»).
 * **Nunca** guarda el número ni el tipo de documento, el DID del ciudadano, los claims ni el cuerpo de los
 * avisos: sólo qué pasó, cuándo, con qué verificación y, si se resolvió o entró una cuenta, su id. De las
 * llamadas a la API guarda el código de error y el `details` que devuelve Sovra, que no traen datos personales.
 * Escribir acá nunca hace fallar el flujo; si no se puede, queda en el log de errores de PHP.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log {

    /** @var string Tabla. */
    const TABLE = 'auth_sovra_log';

    /** @var string Llamada de Moodle a la API de Sovra. */
    const API = 'api';

    /** @var string Aviso de Sovra al webhook de Moodle. */
    const WEBHOOK = 'webhook';

    /** @var string Resultado de un intento de ingreso. */
    const LOGIN = 'login';

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
     * @param string|null $verificationid
     * @param string|null $detail
     * @return void
     */
    public static function api(string $method, string $path, int $httpstatus, string $result, int $duration,
            ?string $verificationid = null, ?string $detail = null): void {
        self::write([
            'kind' => self::API,
            'action' => $method . ' ' . $path,
            'httpstatus' => $httpstatus,
            'result' => $result,
            'verificationid' => $verificationid,
            'duration' => $duration,
            'detail' => $detail,
        ]);
    }

    /**
     * Registra un aviso del webhook.
     *
     * @param string $event El tipo de evento, o '' si no se pudo leer.
     * @param int $httpstatus Lo que respondió Moodle.
     * @param string $result
     * @param array $payload El sobre decodificado (vacío si no se pudo leer o no estaba firmado).
     * @param string|null $detail Sin datos personales (por ejemplo, la clase de una excepción).
     * @return void
     */
    public static function webhook(string $event, int $httpstatus, string $result, array $payload = [],
            ?string $detail = null): void {
        self::write([
            'kind' => self::WEBHOOK,
            'action' => $event !== '' ? $event : '—',
            'httpstatus' => $httpstatus,
            'result' => $result,
            'verificationid' => is_string($payload['data']['verification_id'] ?? null) ? $payload['data']['verification_id'] : null,
            'eventid' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
            'workspaceid' => is_string($payload['workspace_id'] ?? null) ? $payload['workspace_id'] : null,
            'detail' => $detail,
        ]);
    }

    /**
     * Registra el veredicto de un intento de ingreso.
     *
     * @param string $action De dónde salió: webhook, poll, finish, status o resolve.
     * @param string $result Un código de resolver o de login_session.
     * @param string|null $verificationid
     * @param int|null $userid La cuenta, si se resolvió una (aunque después no llegue a entrar).
     * @param string|null $detail Sin datos personales.
     * @return void
     */
    public static function login(string $action, string $result, ?string $verificationid, ?int $userid = null,
            ?string $detail = null): void {
        self::write([
            'kind' => self::LOGIN,
            'action' => $action,
            'result' => $result,
            'verificationid' => $verificationid,
            'userid' => $userid,
            'detail' => $detail,
        ]);
    }

    /**
     * Para lo que puede llegar sin autenticar: ¿pasó un minuto desde la última vez que se registró?
     *
     * Si pasó, marca este momento y devuelve true. Así un endpoint público escribe como mucho una fila por minuto.
     *
     * @param string $key Ajuste del plugin donde se guarda el último momento (lastrejected, lastbadsession…).
     * @return bool
     */
    public static function once_per_minute(string $key): bool {
        if ((int) get_config(config::PLUGIN, $key) >= time() - MINSECS) {
            return false;
        }
        set_config($key, time(), config::PLUGIN);
        return true;
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

        $limits = ['action' => 100, 'result' => 64, 'verificationid' => 36, 'eventid' => 64, 'workspaceid' => 64,
            'detail' => 1000];
        foreach ($limits as $field => $length) {
            if (isset($row[$field])) {
                $row[$field] = \core_text::substr((string) $row[$field], 0, $length);
            }
        }
        $row['httpstatus'] = $row['httpstatus'] ?? 0;
        $row['timecreated'] = time();
        try {
            $DB->insert_record(self::TABLE, (object) $row);
        } catch (\Throwable $e) {
            // El registro es para diagnosticar: si no se puede escribir, el ingreso sigue igual. debugging() no se
            // ve en producción, así que también va al log de errores de PHP (sin el contenido de la fila).
            error_log('auth_sovra: no se pudo escribir el registro: ' . get_class($e) . ': ' . $e->getMessage());
            debugging('auth_sovra: no se pudo escribir el registro: ' . get_class($e), DEBUG_NORMAL);
        }
    }
}
