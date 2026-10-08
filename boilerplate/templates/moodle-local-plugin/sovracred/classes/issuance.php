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
 *       └────────(la oferta vence)───────────┘                         └──▶ revoked
 *
 * La oferta se crea recién cuando el estudiante la pide: vence a las 24 h y es de un solo uso,
 * así que generarla al completar el curso haría que la mayoría venza sin usarse.
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

    /** @var int Una oferta que vence antes de esto no se reutiliza: no le alcanza al estudiante. */
    const REUSE_MARGIN = 600;

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

        if ($DB->record_exists(self::TABLE, ['userid' => $userid, 'courseid' => $courseid])) {
            return null;
        }
        $now = time();
        $record = (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => self::AVAILABLE,
            'verificationcode' => self::new_verification_code(),
            'timecompleted' => $timecompleted,
            'statustime' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        try {
            $record->id = $DB->insert_record(self::TABLE, $record);
        } catch (\dml_write_exception $e) {
            // Otro proceso lo registró en paralelo: el índice único (userid, courseid) manda.
            return null;
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
        $coursename = format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]);
        $url = new \moodle_url('/local/sovracred/credenciales.php');
        $a = (object) ['course' => $coursename, 'url' => $url->out(false)];

        // El evento lo dispara el cron (o un docente): el texto va en el idioma del estudiante, no en el de quien lo disparó.
        $user = \core_user::get_user($issuance->userid, 'id, lang', MUST_EXIST);
        $previouslang = force_current_language($user->lang);

        $message = new \core\message\message();
        $message->component = 'local_sovracred';
        $message->name = 'credentialavailable';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $issuance->userid;
        $message->subject = get_string('notifysubject', 'local_sovracred', $a);
        $message->fullmessage = get_string('notifybody', 'local_sovracred', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = get_string('notifybodyhtml', 'local_sovracred', $a);
        $message->smallmessage = get_string('notifysmall', 'local_sovracred', $a);
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('mycredentials', 'local_sovracred');
        $message->courseid = $course->id;

        force_current_language($previouslang);
        message_send($message);
    }

    /**
     * El estudiante tocó «Recibir en mi cartera»: devuelve el derecho con una oferta vigente.
     *
     * Reutiliza la oferta si vence en más de REUSE_MARGIN. Si no, primero pregunta si la anterior ya se
     * canjeó (el aviso pudo no llegar) y sólo si no, crea otra.
     *
     * @param \stdClass $issuance
     * @param api_client|null $api
     * @return \stdClass El derecho actualizado.
     * @throws missing_claims_exception|api_exception
     */
    public static function request_offer(\stdClass $issuance, ?api_client $api = null): \stdClass {
        if (!in_array($issuance->status, [self::AVAILABLE, self::OFFERED], true)) {
            return $issuance;
        }
        if ($issuance->status === self::OFFERED && $issuance->offerexpires - time() > self::REUSE_MARGIN) {
            return $issuance;
        }

        try {
            if ($issuance->status === self::OFFERED && !empty($issuance->credentialid)) {
                $api = $api ?? api_client::from_config();
                $issuance = self::refresh($issuance, $api);
                if ($issuance->status !== self::AVAILABLE && $issuance->status !== self::OFFERED) {
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
            'statustime' => time(),
            'lasterror' => null,
        ]);
    }

    /**
     * Pregunta a la API el estado de la oferta y lo aplica. Para la reconciliación y para el reintento.
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
            if ($e->errorcode === 'credential_not_found') {
                // La oferta no existe en este workspace (¿se cambió de workspace?): se puede pedir otra.
                return self::update($issuance, self::offer_cleared() + [
                    'status' => self::AVAILABLE,
                    'statustime' => time(),
                    'lasterror' => $e->summary(),
                ]);
            }
            self::save_error($issuance, $e->summary());
            throw $e;
        }

        switch ($remote['status'] ?? '') {
            case 'issued':
                $issuedat = !empty($remote['issued_at']) ? strtotime($remote['issued_at']) : false;
                return self::update($issuance, [
                    'status' => self::ISSUED,
                    'holderdid' => $remote['holder_did'] ?? null,
                    'timeissued' => $issuedat ?: time(),
                    'statustime' => max((int) $issuance->statustime, $issuedat ?: time()),
                    'lasterror' => null,
                ]);
            case 'expired':
                if ($issuance->status !== self::OFFERED) {
                    return $issuance;
                }
                return self::update($issuance, self::offer_cleared() + [
                    'status' => self::AVAILABLE,
                    'statustime' => time(),
                    'lasterror' => null,
                ]);
            case 'suspended':
            case 'revoked':
                return self::update($issuance, ['status' => $remote['status'], 'lasterror' => null]);
            default:
                // pending: todavía no la canjeó.
                return $issuance;
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
