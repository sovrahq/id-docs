<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * Una sesión de ingreso: la verificación OID4VP que pidió un navegador, y su veredicto.
 *
 * Atada al navegador por un token aleatorio que sólo conoce su $SESSION: sólo ese navegador puede consultar
 * el resultado y completar el ingreso. El veredicto lo escribe quien llegue primero (webhook o consulta por
 * GET) con una actualización condicionada a que siga pendiente, así que un segundo aviso no lo cambia.
 * Completar el ingreso borra la fila: un solo uso. Moodle serializa las peticiones de una misma sesión
 * (lock de sesión), así que dos «finish» simultáneos del mismo navegador no pueden pasar los dos.
 *
 * La fila nunca guarda documento, tipo ni DID del ciudadano: sólo el id de la cuenta resuelta.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class login_session {

    /** @var string Tabla. */
    const TABLE = 'auth_sovra_session';

    /** @var string Esperando la presentación. */
    const PENDING = 'pending';
    /** @var string Presentación verificada; `result` dice si hay cuenta (ok) o por qué no. */
    const RESOLVED = 'resolved';
    /** @var string Sovra rechazó la presentación; `result` trae su error. */
    const FAILED = 'failed';
    /** @var string Venció sin presentación. */
    const EXPIRED = 'expired';

    /** @var int Segundos mínimos entre consultas por GET de una misma sesión. */
    const POLL_INTERVAL = 3;

    /** @var int Segundos para completar el ingreso después del veredicto. */
    const FINISH_WINDOW = 5 * MINSECS;

    /** @var int Tolerancia de reloj con Sovra al vencer. */
    const CLOCK_SKEW = MINSECS;

    /** @var int Cuántas sesiones de ingreso recuerda un navegador (pestañas abiertas). */
    const MAX_TOKENS = 5;

    /** @var string Etiqueta de la consulta DCQL. */
    const DCQL_ID = 'identidad';

    /**
     * La consulta DCQL: emisor, tipo de credencial y documento. Nada más.
     *
     * @return array
     */
    public static function dcql(): array {
        $paths = ['iss', 'vct'];
        if (config::match_document_type()) {
            $paths[] = config::claim_document_type();
        }
        $paths[] = config::claim_document_number();

        $credential = [
            'id' => self::DCQL_ID,
            'format' => 'vc+sd-jwt',
            'claims' => array_map(fn($path) => ['path' => [$path]], array_values(array_unique($paths))),
        ];
        // Sólo lo evalúa la billetera, para ofrecer la credencial correcta. En el servidor se compara el vct.
        if ($types = config::credential_types()) {
            $credential['meta'] = ['vct_values' => $types];
        }
        return ['credentials' => [$credential]];
    }

    /**
     * Abre una verificación y la ata a este navegador.
     *
     * @param api_client|null $api
     * @return array{0: \stdClass, 1: string} [la sesión, la URI openid4vp:// para el QR y el enlace]
     * @throws api_exception
     */
    public static function start(?api_client $api = null): array {
        global $DB;

        $api = $api ?? api_client::from_config();
        $data = $api->create_verification(self::dcql());

        $now = time();
        $expires = strtotime((string) ($data['expires_at'] ?? '')) ?: $now + 10 * MINSECS;
        $record = (object) [
            'token' => bin2hex(random_bytes(32)),
            'verificationid' => \core_text::substr($data['session_id'], 0, 36),
            'status' => self::PENDING,
            'timecreated' => $now,
            'timeexpires' => $expires,
            'timelastpoll' => $now,
        ];
        $record->id = $DB->insert_record(self::TABLE, $record);
        self::remember($record->token);

        // Por referencia: el QR queda corto aunque crezca la consulta.
        $uri = (string) ($data['authorization_request_uri_ref'] ?? $data['authorization_request_uri']);
        return [$record, $uri];
    }

    /**
     * La sesión de ingreso de este navegador con ese token, o null.
     *
     * @param string $token
     * @return \stdClass|null
     */
    public static function for_browser(string $token): ?\stdClass {
        global $DB, $SESSION;
        $tokens = $SESSION->auth_sovra_tokens ?? [];
        if ($token === '' || !in_array($token, $tokens, true)) {
            return null;
        }
        return $DB->get_record(self::TABLE, ['token' => $token]) ?: null;
    }

    /**
     * Pone al día una sesión pendiente: la vence o, cada POLL_INTERVAL, le pregunta a Sovra.
     *
     * Es la red de contención del webhook: con él caído o bloqueado, el ingreso funciona igual.
     *
     * @param \stdClass $record
     * @param api_client|null $api
     * @return \stdClass La sesión actualizada.
     */
    public static function refresh(\stdClass $record, ?api_client $api = null): \stdClass {
        global $DB;
        if ($record->status !== self::PENDING) {
            return $record;
        }
        $now = time();
        if ($now > $record->timeexpires + self::CLOCK_SKEW) {
            self::settle($record, self::EXPIRED, null, null, 'poll');
            return self::reload($record);
        }
        if ($now - $record->timelastpoll < self::POLL_INTERVAL) {
            return $record;
        }
        $DB->set_field(self::TABLE, 'timelastpoll', $now, ['id' => $record->id]);
        try {
            $api = $api ?? api_client::from_config();
            $data = $api->get_verification($record->verificationid);
        } catch (api_exception $e) {
            // Ya quedó en el registro; se reintenta en la próxima consulta.
            return self::reload($record);
        }
        self::apply($record->verificationid, (string) ($data['status'] ?? ''), $data['presentation_data'] ?? null,
            $data['error'] ?? null, 'poll');
        return self::reload($record);
    }

    /**
     * Aplica el resultado de Sovra a la sesión de ingreso de esa verificación, si sigue pendiente.
     *
     * @param string $verificationid
     * @param string $status completed, failed, expired o pending.
     * @param array|null $presentation presentation_data (GET) o data (webhook): con `credentials`.
     * @param string|null $error El error de Sovra, si falló.
     * @param string $source poll o webhook, para el registro.
     * @return string|null El resultado aplicado, o null si no había nada que aplicar.
     */
    public static function apply(string $verificationid, string $status, ?array $presentation, ?string $error,
            string $source): ?string {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['verificationid' => $verificationid]);
        if (!$record || $record->status !== self::PENDING) {
            return null;
        }
        if (time() > $record->timeexpires + self::CLOCK_SKEW) {
            // Un resultado tardío no habilita a entrar.
            self::settle($record, self::EXPIRED, null, null, $source);
            return self::EXPIRED;
        }
        switch ($status) {
            case 'completed':
                [$result, $userid] = resolver::resolve(self::claims_of($presentation));
                self::settle($record, self::RESOLVED, $result, $userid, $source);
                return $result;
            case 'failed':
                $code = $error !== null && $error !== '' ? $error : 'failed';
                self::settle($record, self::FAILED, $code, null, $source);
                return $code;
            case 'expired':
                self::settle($record, self::EXPIRED, null, null, $source);
                return self::EXPIRED;
            default:
                return null;
        }
    }

    /**
     * Completa el ingreso: valida la sesión, la borra y devuelve la cuenta. Un solo uso.
     *
     * @param \stdClass $record Obtenida con for_browser().
     * @return array{0: string, 1: \stdClass|null} [resultado, cuenta si es OK]
     */
    public static function consume(\stdClass $record): array {
        global $DB, $SESSION;

        // Siempre desde la base: el objeto que llega puede ser de antes de un consumo anterior.
        $record = $DB->get_record(self::TABLE, ['id' => $record->id]);
        if (!$record) {
            return ['gone', null];
        }
        if ($record->status !== self::RESOLVED || $record->result !== resolver::OK || empty($record->userid)) {
            return ['notready', null];
        }
        // Se borra antes de cualquier otra cosa: pase lo que pase, esta verificación no sirve otra vez.
        $DB->delete_records(self::TABLE, ['id' => $record->id]);
        $SESSION->auth_sovra_tokens = array_values(array_diff($SESSION->auth_sovra_tokens ?? [], [$record->token]));

        if (time() > (int) $record->timecompleted + self::FINISH_WINDOW) {
            return [self::EXPIRED, null];
        }
        // La cuenta puede haber cambiado desde la verificación (suspendida, otro método…).
        $user = $DB->get_record('user', ['id' => $record->userid]);
        if (!$user) {
            return [resolver::NOT_FOUND, null];
        }
        if ($reason = resolver::cannot_login($user)) {
            return [$reason, null];
        }
        return [resolver::OK, $user];
    }

    /**
     * Borra las sesiones vencidas hace más de una hora.
     *
     * @return void
     */
    public static function prune(): void {
        global $DB;
        $DB->delete_records_select(self::TABLE, 'timeexpires < :cutoff', ['cutoff' => time() - HOURSECS]);
    }

    /**
     * Los claims de nuestra credencial en la presentación (la entrada con nuestro id, o la primera).
     *
     * @param array|null $presentation
     * @return array
     */
    private static function claims_of(?array $presentation): array {
        $credentials = is_array($presentation['credentials'] ?? null) ? $presentation['credentials'] : [];
        $chosen = null;
        foreach ($credentials as $credential) {
            if (is_array($credential) && ($credential['id'] ?? null) === self::DCQL_ID) {
                $chosen = $credential;
                break;
            }
        }
        $chosen = $chosen ?? (is_array($credentials[0] ?? null) ? $credentials[0] : []);
        return is_array($chosen['claims'] ?? null) ? $chosen['claims'] : [];
    }

    /**
     * Escribe el veredicto sólo si la sesión sigue pendiente, y lo registra.
     *
     * @param \stdClass $record
     * @param string $status
     * @param string|null $result
     * @param int|null $userid
     * @param string $source
     * @return void
     */
    private static function settle(\stdClass $record, string $status, ?string $result, ?int $userid, string $source): void {
        global $DB;
        $DB->execute('UPDATE {' . self::TABLE . '}
                         SET status = :status, result = :result, userid = :userid, timecompleted = :now
                       WHERE id = :id AND status = :pending', [
            'status' => $status,
            'result' => $result !== null ? \core_text::substr($result, 0, 64) : null,
            'userid' => $userid,
            'now' => time(),
            'id' => $record->id,
            'pending' => self::PENDING,
        ]);
        log::login($source, $result ?? $status, $record->verificationid, $userid);
    }

    /**
     * Relee la sesión.
     *
     * @param \stdClass $record
     * @return \stdClass
     */
    private static function reload(\stdClass $record): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $record->id]) ?: $record;
    }

    /**
     * Recuerda el token en la sesión del navegador.
     *
     * @param string $token
     * @return void
     */
    private static function remember(string $token): void {
        global $SESSION;
        $tokens = $SESSION->auth_sovra_tokens ?? [];
        $tokens[] = $token;
        $SESSION->auth_sovra_tokens = array_slice($tokens, -self::MAX_TOKENS);
    }
}
