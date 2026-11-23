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
 * GET), bajo un lock por sesión y sólo si sigue pendiente: un segundo aviso no lo cambia ni se registra.
 * Completar el ingreso borra la fila: un solo uso. Moodle serializa las peticiones de una misma sesión
 * (lock de sesión), así que dos «finish» simultáneos del mismo navegador no pueden pasar los dos.
 *
 * Abrir una verificación cuesta una llamada a Sovra: sólo con sesskey (lo controla login.php), reutilizando
 * el código vigente del navegador y con un tope de códigos nuevos por navegador.
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

    /** @var string Resultado de FAILED cuando la API no deja seguir (API key revocada, verificación que no existe). */
    const UNAVAILABLE = 'unavailable';

    /** @var int Segundos mínimos entre consultas por GET de una misma sesión. */
    const POLL_INTERVAL = 3;

    /** @var int Segundos para completar el ingreso después del veredicto. */
    const FINISH_WINDOW = 5 * MINSECS;

    /** @var int Tolerancia de reloj con Sovra al vencer. */
    const CLOCK_SKEW = MINSECS;

    /** @var int Cuántas sesiones de ingreso recuerda un navegador (pestañas abiertas). */
    const MAX_TOKENS = 5;

    /** @var int Cuántos códigos nuevos puede pedir un navegador dentro de START_WINDOW. */
    const MAX_STARTS = 5;

    /** @var int Ventana del tope de códigos nuevos. */
    const START_WINDOW = 10 * MINSECS;

    /** @var int Vigencia mínima que le tiene que quedar a un código para volver a mostrarlo. */
    const REUSE_MIN_LEFT = 2 * MINSECS;

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
        global $DB, $SESSION;

        // Cuenta también los intentos que fallan: el tope es sobre las llamadas a Sovra.
        $SESSION->auth_sovra_starts = array_merge(self::recent_starts(), [time()]);
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

        // Por referencia: el QR queda corto aunque crezca la consulta.
        $uri = (string) ($data['authorization_request_uri_ref'] ?? $data['authorization_request_uri']);
        self::remember($record->token, $uri);
        return [$record, $uri];
    }

    /**
     * El código vigente de este navegador, para volver a mostrarlo sin abrir otra verificación (recargar la página).
     *
     * @return array{0: \stdClass, 1: string}|null [la sesión, la URI] o null si no hay uno que sirva.
     */
    public static function reusable(): ?array {
        global $SESSION;
        $tokens = $SESSION->auth_sovra_tokens ?? [];
        $token = $tokens ? end($tokens) : '';
        $uri = $SESSION->auth_sovra_uris[$token] ?? null;
        $record = $uri ? self::for_browser($token) : null;
        if (!$record || $record->status !== self::PENDING || $record->timeexpires - time() < self::REUSE_MIN_LEFT) {
            return null;
        }
        return [$record, $uri];
    }

    /**
     * ¿Puede este navegador pedir otro código? MAX_STARTS por START_WINDOW.
     *
     * @return bool
     */
    public static function can_start(): bool {
        return count(self::recent_starts()) < self::MAX_STARTS;
    }

    /**
     * Lo que contesta status.php a la página de espera. Todo estado final trae `url`.
     *
     * @param string $token
     * @param bool $sesskeyok
     * @return array{state: string, url?: string}
     */
    public static function poll(string $token, bool $sesskeyok): array {
        $record = $sesskeyok ? self::for_browser($token) : null;
        if (!$record) {
            self::log_bad_session('status');
            return ['state' => 'gone', 'url' => self::result_url('generic')];
        }
        $record = self::refresh($record);
        switch ($record->status) {
            case self::PENDING:
                return ['state' => 'pending'];
            case self::EXPIRED:
                return ['state' => 'expired'];
            case self::RESOLVED:
                if ($record->result === resolver::OK) {
                    return ['state' => 'ok', 'url' => (new \moodle_url('/auth/sovra/finish.php',
                        ['token' => $token, 'sesskey' => sesskey()]))->out(false)];
                }
                return ['state' => 'done', 'url' => self::result_url($record->result)];
            default:
                // FAILED: el error de Sovra no se le muestra a la persona.
                return ['state' => 'done', 'url' => self::result_url($record->result === self::UNAVAILABLE
                    ? self::UNAVAILABLE : 'failed')];
        }
    }

    /**
     * Lo que hace finish.php antes de iniciar la sesión: valida, consume y registra.
     *
     * @param string $token
     * @param bool $sesskeyok
     * @return array{0: string, 1: \stdClass|null} [resultado para la persona, cuenta si es OK]
     */
    public static function finish(string $token, bool $sesskeyok): array {
        $record = $sesskeyok ? self::for_browser($token) : null;
        if (!$record) {
            self::log_bad_session('finish');
            return ['generic', null];
        }
        [$result, $user] = self::consume($record);
        log::login('finish', $result, $record->verificationid, $user ? (int) $user->id : null);
        if ($result !== resolver::OK) {
            return [in_array($result, ['notready', 'gone'], true) ? 'generic' : $result, null];
        }
        return [resolver::OK, $user];
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
            // Ya quedó en el registro. Un 401/403/404 no se arregla solo: se cierra el intento en lugar de
            // dejar a la persona esperando hasta el vencimiento. Lo demás (red, 5xx) se reintenta.
            if (in_array($e->httpstatus, [401, 403, 404], true)) {
                self::settle($record, self::FAILED, self::UNAVAILABLE, null, 'poll', $e->summary());
            }
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
     * @return string|null El resultado aplicado, o null si no había nada que aplicar (o lo aplicó otro antes).
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
            return self::settle($record, self::EXPIRED, null, null, $source) ? self::EXPIRED : null;
        }
        switch ($status) {
            case 'completed':
                [$result, $userid] = resolver::resolve(self::claims_of($presentation));
                return self::settle($record, self::RESOLVED, $result, $userid, $source) ? $result : null;
            case 'failed':
                $code = $error !== null && $error !== '' ? $error : 'failed';
                return self::settle($record, self::FAILED, $code, null, $source) ? $code : null;
            case 'expired':
                return self::settle($record, self::EXPIRED, null, null, $source) ? self::EXPIRED : null;
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
        unset($SESSION->auth_sovra_uris[$record->token]);

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
     * El webhook y la consulta pueden llegar a la vez: el lock por sesión hace que escriba y registre uno solo.
     *
     * @param \stdClass $record
     * @param string $status
     * @param string|null $result
     * @param int|null $userid
     * @param string $source
     * @param string|null $detail Para el registro (sin datos personales).
     * @return bool Si lo escribió esta llamada.
     */
    private static function settle(\stdClass $record, string $status, ?string $result, ?int $userid, string $source,
            ?string $detail = null): bool {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('auth_sovra')->get_lock('session_' . $record->id, 5);
        if (!$lock) {
            // Lo tiene otra petición, que está escribiendo el veredicto.
            return false;
        }
        try {
            if ($DB->get_field(self::TABLE, 'status', ['id' => $record->id]) !== self::PENDING) {
                return false;
            }
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
        } finally {
            $lock->release();
        }
        log::login($source, $result ?? $status, $record->verificationid, $userid, $detail);
        return true;
    }

    /**
     * La página que muestra un resultado a la persona.
     *
     * @param string $result
     * @return string
     */
    private static function result_url(string $result): string {
        return (new \moodle_url('/auth/sovra/login.php', ['result' => $result]))->out(false);
    }

    /**
     * Registra una consulta o un «finish» sin sesión de ingreso válida (otro navegador, sesión de Moodle perdida).
     *
     * Como mucho una fila por minuto: no hace falta estar logueado para llegar acá.
     *
     * @param string $action status o finish.
     * @return void
     */
    private static function log_bad_session(string $action): void {
        if (log::once_per_minute('lastbadsession')) {
            log::login($action, 'badsession', null);
        }
    }

    /**
     * Los inicios de este navegador dentro de START_WINDOW.
     *
     * @return int[]
     */
    private static function recent_starts(): array {
        global $SESSION;
        $cutoff = time() - self::START_WINDOW;
        return array_values(array_filter($SESSION->auth_sovra_starts ?? [], fn($time) => $time > $cutoff));
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
     * Recuerda el token, y la URI para volver a mostrar el código, en la sesión del navegador.
     *
     * @param string $token
     * @param string $uri
     * @return void
     */
    private static function remember(string $token, string $uri): void {
        global $SESSION;
        $tokens = $SESSION->auth_sovra_tokens ?? [];
        $tokens[] = $token;
        $SESSION->auth_sovra_tokens = array_slice($tokens, -self::MAX_TOKENS);
        $uris = ($SESSION->auth_sovra_uris ?? []) + [$token => $uri];
        $SESSION->auth_sovra_uris = array_intersect_key($uris, array_flip($SESSION->auth_sovra_tokens));
    }
}
