<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * El derecho de un estudiante a la credencial de un curso, y su oferta en Sovra.
 *
 *   available ──(el estudiante la pide)──▶ offered ──(la canjea)──▶ issued ⇄ suspended
 *       ▲                                    │                         │
 *       └──────(Sovra informa: venció)───────┘                         └──▶ revoked
 *
 * «Vencida» no es un estado guardado: Sovra sigue informando `issued` después del `expires_at`
 * de la credencial. Se calcula al mostrarla, con is_expired().
 *
 * La oferta se crea recién cuando el estudiante la pide: vence a las 24 h y es de un solo uso,
 * así que generarla al completar el curso haría que la mayoría venza sin usarse.
 *
 * Invariante: un derecho tiene como mucho UNA oferta canjeable. No se crea otra hasta que Sovra
 * informa que la anterior venció; si no, la anterior podría canjearse sin que Moodle se entere.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issuance {

    /** @var string Tiene derecho y no hay oferta vigente. */
    const AVAILABLE = 'available';
    /** @var string Hay una oferta creada, todavía sin canjear. */
    const OFFERED = 'offered';
    /** @var string La credencial está en la cartera. */
    const ISSUED = 'issued';
    /** @var string Suspendida (reversible). */
    const SUSPENDED = 'suspended';
    /** @var string Revocada (terminal). */
    const REVOKED = 'revoked';

    /** @var string[] Todos los estados, en el orden en que se muestran. */
    const STATUSES = [self::AVAILABLE, self::OFFERED, self::ISSUED, self::SUSPENDED, self::REVOKED];

    /** @var string Prefijo obligatorio de una URI de oferta OID4VCI. */
    const OFFER_SCHEME = 'openid-credential-offer://';

    /** @var string Tabla. */
    const TABLE = 'local_sovracred_issuance';

    /**
     * Registra el derecho. Devuelve null si ya existía.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $timecompleted
     * @return \stdClass|null
     */
    public static function register(int $userid, int $courseid, int $timecompleted): ?\stdClass {
        global $DB;

        $key = ['userid' => $userid, 'courseid' => $courseid];
        if ($DB->record_exists(self::TABLE, $key)) {
            return null;
        }
        $now = time();
        $record = (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => self::AVAILABLE,
            'verificationcode' => self::new_verification_code(),
            'timecompleted' => $timecompleted,
            'statustime' => 0,
            'timechecked' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        try {
            $record->id = $DB->insert_record(self::TABLE, $record);
        } catch (\dml_write_exception $e) {
            if ($DB->record_exists(self::TABLE, $key)) {
                // Otro proceso lo registró en paralelo: el índice único (userid, courseid) manda.
                return null;
            }
            // Cualquier otro fallo de escritura no se disfraza de duplicado.
            throw $e;
        }
        return $record;
    }

    /**
     * Avisa al estudiante con una notificación de Moodle que enlaza a «Mis credenciales».
     *
     * @param \stdClass $issuance
     * @return void
     */
    public static function notify(\stdClass $issuance): void {
        $course = get_course($issuance->courseid);
        $url = new \moodle_url('/local/sovracred/credenciales.php');

        // El evento lo dispara el cron (o un docente): el texto va en el idioma del estudiante, no en el de quien lo disparó.
        $user = \core_user::get_user($issuance->userid, 'id, lang', MUST_EXIST);
        $previouslang = force_current_language($user->lang);

        $context = \context_course::instance($course->id);
        $html = (object) ['course' => format_string($course->fullname, true, ['context' => $context]), 'url' => $url->out()];
        $plain = (object) [
            'course' => html_entity_decode(strip_tags(format_string($course->fullname, true,
                ['context' => $context, 'escape' => false])), ENT_QUOTES, 'UTF-8'),
            'url' => $url->out(false),
        ];

        $message = new \core\message\message();
        $message->component = 'local_sovracred';
        $message->name = 'credentialavailable';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $issuance->userid;
        $message->subject = get_string('notifysubject', 'local_sovracred', $plain);
        $message->fullmessage = get_string('notifybody', 'local_sovracred', $plain);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = get_string('notifybodyhtml', 'local_sovracred', $html);
        $message->smallmessage = get_string('notifysmall', 'local_sovracred', $plain);
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('mycredentials', 'local_sovracred');
        $message->courseid = $course->id;

        force_current_language($previouslang);
        message_send($message);
    }

    /**
     * El estudiante tocó «Recibir en mi cartera»: devuelve el derecho con una oferta canjeable.
     *
     * Si ya hay una oferta, primero pregunta a Sovra su estado: si se canjeó (el aviso pudo no llegar)
     * la marca emitida; si sigue pendiente la reutiliza; sólo si venció crea otra.
     *
     * @param \stdClass $issuance
     * @param api_client|null $api
     * @return \stdClass El derecho actualizado.
     * @throws missing_claims_exception|api_exception
     */
    public static function request_offer(\stdClass $issuance, ?api_client $api = null): \stdClass {
        global $DB;

        // Dos pestañas o un doble clic no pueden crear dos ofertas: una a la vez por derecho.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_sovracred');
        $lock = $lockfactory->get_lock('issuance_' . $issuance->id, 15);
        if (!$lock) {
            throw new api_exception('busy');
        }
        try {
            $issuance = $DB->get_record(self::TABLE, ['id' => $issuance->id], '*', MUST_EXIST);
            return self::request_offer_locked($issuance, $api);
        } finally {
            $lock->release();
        }
    }

    /**
     * request_offer, ya con el lock tomado y el registro releído.
     *
     * @param \stdClass $issuance
     * @param api_client|null $api
     * @return \stdClass
     */
    private static function request_offer_locked(\stdClass $issuance, ?api_client $api): \stdClass {
        if (!in_array($issuance->status, [self::AVAILABLE, self::OFFERED], true)) {
            return $issuance;
        }

        try {
            if ($issuance->status === self::OFFERED && !empty($issuance->credentialid)) {
                $api = $api ?? api_client::from_config();
                $issuance = self::refresh($issuance, $api);
                if ($issuance->status !== self::AVAILABLE) {
                    // Emitida, o todavía canjeable: no se crea otra.
                    return $issuance;
                }
            }
        } catch (api_exception $e) {
            self::save_error($issuance, $e->summary());
            throw $e;
        }

        $claims = claims::build($issuance);
        $missing = claims::missing($claims);
        if ($missing) {
            self::save_error($issuance, 'missingclaims: ' . implode(', ', $missing));
            throw new missing_claims_exception($missing);
        }

        try {
            $api = $api ?? api_client::from_config();
            if (config::schema_id() === '') {
                throw new api_exception('notconfigured');
            }
            $offer = $api->create_offer(config::schema_id(), $claims);
            if (strpos($offer['offer_uri'], self::OFFER_SCHEME) !== 0) {
                // Se va a renderizar como href: nada que no sea una oferta OID4VCI.
                throw new api_exception('invalidresponse', 0, 'offer_uri');
            }
        } catch (api_exception $e) {
            self::save_error($issuance, $e->summary());
            throw $e;
        }

        $expires = !empty($offer['expires_at']) ? strtotime($offer['expires_at']) : false;
        return self::update($issuance, [
            'status' => self::OFFERED,
            'credentialid' => $offer['credential_id'],
            'offeruri' => $offer['offer_uri'],
            'offerexpires' => $expires ?: time() + DAYSECS,
            // Una oferta nueva todavía no tiene historia en Sovra: cualquier evento suyo la puede actualizar.
            'statustime' => 0,
            'timechecked' => time(),
            'lasterror' => null,
        ]);
    }

    /**
     * Pregunta a la API el estado de la credencial y lo aplica. Para la reconciliación, la página y el reintento.
     *
     * @param \stdClass $issuance
     * @param api_client $api
     * @return \stdClass
     * @throws api_exception
     */
    public static function refresh(\stdClass $issuance, api_client $api): \stdClass {
        if (empty($issuance->credentialid)) {
            return $issuance;
        }
        try {
            $remote = $api->get_credential($issuance->credentialid);
        } catch (api_exception $e) {
            if ($e->errorcode === 'credential_not_found' && $issuance->status === self::OFFERED) {
                // La oferta no existe en este workspace (¿se cambió de workspace?): se puede pedir otra.
                return self::update($issuance, self::offer_cleared() + [
                    'status' => self::AVAILABLE,
                    'statustime' => 0,
                    'timechecked' => time(),
                    'lasterror' => $e->summary(),
                ]);
            }
            self::update($issuance, ['lasterror' => $e->summary(), 'timechecked' => time()]);
            throw $e;
        }

        $checked = ['timechecked' => time()];
        switch ($remote['status'] ?? '') {
            case 'issued':
                $issuedat = !empty($remote['issued_at']) ? strtotime($remote['issued_at']) : false;
                $changes = $checked + ['status' => self::ISSUED, 'lasterror' => null] + self::missing_issue_data($issuance, $remote);
                if (empty($issuance->timeissued) && empty($changes['timeissued'])) {
                    $changes['timeissued'] = time();
                }
                if ($issuedat) {
                    $changes['statustime'] = max((int) $issuance->statustime, $issuedat);
                }
                return self::update($issuance, $changes);
            case 'expired':
                if ($issuance->status !== self::OFFERED) {
                    return self::update($issuance, $checked);
                }
                return self::update($issuance, $checked + self::offer_cleared() + [
                    'status' => self::AVAILABLE,
                    'statustime' => 0,
                    'lasterror' => null,
                ]);
            case 'suspended':
            case 'revoked':
                // Sin hora de Sovra para este cambio: no se mueve statustime. Si la revocación llegó antes
                // que la emisión, acá se completan los datos de la emisión que nunca se guardaron.
                return self::update($issuance, $checked + ['status' => $remote['status'], 'lasterror' => null]
                    + self::missing_issue_data($issuance, $remote));
            default:
                // pending: todavía no la canjeó. Se anota la consulta para que la reconciliación rote.
                return self::update($issuance, $checked);
        }
    }

    /**
     * Aplica cambios y los persiste.
     *
     * @param \stdClass $issuance
     * @param array $changes
     * @return \stdClass
     */
    public static function update(\stdClass $issuance, array $changes): \stdClass {
        global $DB;

        foreach ($changes as $field => $value) {
            $issuance->$field = $value;
        }
        $issuance->timemodified = time();
        $DB->update_record(self::TABLE, (object) (['id' => $issuance->id, 'timemodified' => $issuance->timemodified] + $changes));
        return $issuance;
    }

    /**
     * ¿La credencial está vencida? Sólo una emitida o suspendida puede estarlo.
     *
     * @param \stdClass $issuance
     * @param int|null $now
     * @return bool
     */
    public static function is_expired(\stdClass $issuance, ?int $now = null): bool {
        return in_array($issuance->status, [self::ISSUED, self::SUSPENDED], true)
            && !empty($issuance->credexpires) && (int) $issuance->credexpires <= ($now ?? time());
    }

    /**
     * Los datos de la emisión que el derecho todavía no tiene y que Sovra informa.
     *
     * Sirve tanto para el `data` de un webhook como para la respuesta de GET /credentials/{id}:
     * los dos usan `holder_did`, `issued_at` y `expires_at`.
     *
     * @param \stdClass $issuance
     * @param array $data
     * @return array Cambios a aplicar (vacío si no falta nada o Sovra no lo informa).
     */
    public static function missing_issue_data(\stdClass $issuance, array $data): array {
        $changes = [];
        if (empty($issuance->holderdid) && !empty($data['holder_did']) && is_string($data['holder_did'])) {
            $changes['holderdid'] = $data['holder_did'];
        }
        foreach (['timeissued' => 'issued_at', 'credexpires' => 'expires_at'] as $field => $key) {
            if (empty($issuance->$field) && !empty($data[$key]) && is_string($data[$key])) {
                $time = strtotime($data[$key]);
                if ($time) {
                    $changes[$field] = $time;
                }
            }
        }
        return $changes;
    }

    /**
     * Campos de una oferta, vaciados.
     *
     * @return array
     */
    private static function offer_cleared(): array {
        return ['credentialid' => null, 'offeruri' => null, 'offerexpires' => null];
    }

    /**
     * Guarda el último error, visible en el reporte del administrador.
     *
     * @param \stdClass $issuance
     * @param string $error
     * @return void
     */
    private static function save_error(\stdClass $issuance, string $error): void {
        self::update($issuance, ['lasterror' => $error]);
    }

    /**
     * Código de verificación propio de la credencial, legible: XXXX-XXXX-XXXX.
     *
     * Sin 0/O ni 1/I/L, para que se pueda dictar.
     *
     * @return string
     */
    private static function new_verification_code(): string {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return implode('-', str_split($code, 4));
    }
}
