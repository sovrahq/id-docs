<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * De los claims de una presentación verificada a una cuenta de Moodle, o a un motivo para no dejar entrar.
 *
 * Lo usan el webhook y la consulta por GET, así que los dos llegan al mismo veredicto. No guarda nada:
 * el documento, el tipo y el emisor se usan acá y se descartan.
 *
 * El orden de los controles importa: primero el emisor y el tipo de credencial (una credencial ajena no
 * debería ni disparar una búsqueda), después el documento, después la cuenta.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolver {

    /** @var string Exactamente una cuenta, y puede entrar. */
    const OK = 'ok';
    /** @var string La presentación no trae `iss`. */
    const ISSUER_MISSING = 'issuermissing';
    /** @var string El emisor no está en la lista (o la lista está vacía). */
    const ISSUER_NOT_ALLOWED = 'issuernotallowed';
    /** @var string El `vct` no está entre los tipos aceptados. */
    const TYPE_NOT_ALLOWED = 'typenotallowed';
    /** @var string La presentación no trae número de documento (o tipo, si se compara). */
    const NO_DOCUMENT = 'nodocument';
    /** @var string Ninguna cuenta con ese documento. */
    const NOT_FOUND = 'notfound';
    /** @var string Más de una cuenta con ese documento. */
    const DUPLICATE = 'duplicate';
    /** @var string La cuenta está suspendida. */
    const SUSPENDED = 'suspended';
    /** @var string La cuenta no confirmó su email: con contraseña el núcleo tampoco la deja entrar. */
    const UNCONFIRMED = 'unconfirmed';
    /** @var string La cuenta usa un método de autenticación que no se acepta o está deshabilitado (incluido nologin). */
    const AUTH_NOT_ALLOWED = 'authnotallowed';
    /** @var string La cuenta es administradora del sitio y no se permite. */
    const ADMIN = 'admin';
    /** @var string El campo de documento configurado no existe. */
    const MISCONFIGURED = 'misconfigured';

    /**
     * Resuelve una presentación.
     *
     * @param array $claims Los claims de la presentación, por path unido con puntos (iss, vct, num_doc…).
     * @return array{0: string, 1: int|null} [resultado, userid si es OK]
     */
    public static function resolve(array $claims): array {
        // 1. Emisor. Falla cerrada: sin lista, nadie entra.
        $iss = $claims['iss'] ?? null;
        if (!is_string($iss) || trim($iss) === '') {
            return [self::ISSUER_MISSING, null];
        }
        if (!in_array(self::issuer_did($iss), config::allowed_issuers(), true)) {
            return [self::ISSUER_NOT_ALLOWED, null];
        }

        // 2. Tipo de credencial, si se configuró.
        $types = config::credential_types();
        if ($types && !in_array($claims['vct'] ?? null, $types, true)) {
            return [self::TYPE_NOT_ALLOWED, null];
        }

        // 3. Documento.
        $number = self::normalize_document((string) self::scalar($claims[config::claim_document_number()] ?? ''));
        if ($number === '') {
            return [self::NO_DOCUMENT, null];
        }
        $matchtype = config::match_document_type();
        $type = $matchtype ? self::normalize_text((string) self::scalar($claims[config::claim_document_type()] ?? '')) : '';
        if ($matchtype && $type === '') {
            return [self::NO_DOCUMENT, null];
        }

        // 4. Cuentas.
        try {
            $candidates = self::find_by_document($number);
        } catch (\coding_exception $e) {
            // A la persona, un aviso genérico; al administrador, la causa (no tiene datos personales).
            log::login('resolve', self::MISCONFIGURED, null, null, $e->getMessage());
            return [self::MISCONFIGURED, null];
        }
        if ($matchtype) {
            $accepted = config::document_type_map()[$type] ?? [$type];
            $candidates = array_filter($candidates, fn($user) => in_array(self::user_document_type((int) $user->id),
                $accepted, true));
        }
        if (!$candidates) {
            return [self::NOT_FOUND, null];
        }
        if (count($candidates) > 1) {
            return [self::DUPLICATE, null];
        }
        $user = reset($candidates);
        $reason = self::cannot_login($user);
        return $reason === null ? [self::OK, (int) $user->id] : [$reason, null];
    }

    /**
     * ¿Por qué esta cuenta no puede entrar con la SuperApp? Null si puede.
     *
     * Se vuelve a correr al completar el ingreso: la cuenta puede haber cambiado desde la verificación.
     *
     * @param \stdClass $user Con id, auth, confirmed, suspended y deleted.
     * @return string|null
     */
    public static function cannot_login(\stdClass $user): ?string {
        if (!empty($user->deleted)) {
            return self::NOT_FOUND;
        }
        if (!empty($user->suspended)) {
            return self::SUSPENDED;
        }
        // complete_user_login no lo revisa: sin esto, la SuperApp saltearía la confirmación del email.
        if (empty($user->confirmed)) {
            return self::UNCONFIRMED;
        }
        // Un método deshabilitado es la forma vieja de suspender: con contraseña tampoco entra.
        if ($user->auth === 'nologin' || !is_enabled_auth($user->auth)
                || !in_array($user->auth, config::allowed_auths(), true)) {
            return self::AUTH_NOT_ALLOWED;
        }
        if (!config::allow_admins() && is_siteadmin($user->id)) {
            return self::ADMIN;
        }
        return null;
    }

    /**
     * El DID del emisor a partir del `iss`.
     *
     * En Sovra el `iss` es la URL del emisor y su ruta es el DID del workspace
     * (https://api.sovra.io/did:sovra:0x…); es lo que usa Sovra para elegir la llave con la que verifica
     * la firma. Si el `iss` ya es un DID, se usa tal cual.
     *
     * @param string $iss
     * @return string
     */
    public static function issuer_did(string $iss): string {
        $iss = trim($iss);
        if (strpos($iss, 'did:') === 0) {
            return $iss;
        }
        $path = (string) parse_url($iss, PHP_URL_PATH);
        return ltrim($path, '/');
    }

    /**
     * Qué trajo una presentación rechazada, para que el administrador pueda corregir la configuración.
     *
     * El DID del emisor, el `vct` y los nombres de los claims: ninguno es un dato personal. Nunca los valores
     * del documento.
     *
     * @param array $claims
     * @return string
     */
    public static function describe(array $claims): string {
        $iss = is_string($claims['iss'] ?? null) ? self::issuer_did($claims['iss']) : '-';
        $vct = is_string($claims['vct'] ?? null) ? $claims['vct'] : '-';
        return "iss={$iss} vct={$vct} claims=" . implode(',', array_map('strval', array_keys($claims)));
    }

    /**
     * Número de documento comparable: sin puntos, espacios en blanco ni guiones, en mayúsculas.
     *
     * Es el lado de la credencial. El de Moodle lo normaliza el SQL de find_by_document, que sólo quita el
     * espacio común (no tabuladores ni espacios duros).
     *
     * @param string $value
     * @return string
     */
    public static function normalize_document(string $value): string {
        return \core_text::strtoupper(preg_replace('~[\s.\-]+~u', '', $value));
    }

    /**
     * Texto comparable para el tipo de documento: sin tildes, en minúsculas y con los espacios colapsados.
     *
     * @param string $value
     * @return string
     */
    public static function normalize_text(string $value): string {
        $value = \core_text::strtolower(\core_text::specialtoascii(trim($value)));
        return preg_replace('~\s+~u', ' ', $value);
    }

    /**
     * Las cuentas de este sitio, no borradas, cuyo campo de documento normalizado es $number.
     *
     * Trae también las suspendidas, para poder decir «suspendida» en vez de «no existe».
     *
     * @param string $number Ya normalizado.
     * @return \stdClass[] id, auth, confirmed, suspended, deleted
     */
    public static function find_by_document(string $number): array {
        global $DB, $CFG;

        $field = config::document_field();
        $params = ['number' => $number, 'mnethostid' => $CFG->mnet_localhost_id];
        if ($field === 'idnumber') {
            $join = '';
            $column = 'u.idnumber';
        } else if (strpos($field, 'profile_field_') === 0) {
            $shortname = substr($field, strlen('profile_field_'));
            $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]);
            if (!$fieldid) {
                throw new \coding_exception('auth_sovra: el campo de perfil configurado no existe');
            }
            $join = 'JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = :fieldid';
            $params['fieldid'] = $fieldid;
            $column = $DB->sql_compare_text('d.data', 255);
        } else {
            throw new \coding_exception('auth_sovra: campo de documento inválido');
        }

        // REPLACE y UPPER existen en PostgreSQL, MySQL/MariaDB, SQL Server y Oracle.
        $normalized = "REPLACE(REPLACE(REPLACE(UPPER($column), '.', ''), ' ', ''), '-', '')";
        $sql = "SELECT u.id, u.auth, u.confirmed, u.suspended, u.deleted
                  FROM {user} u
                  $join
                 WHERE u.deleted = 0 AND u.mnethostid = :mnethostid AND $normalized = :number
              ORDER BY u.id";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * El tipo de documento de una cuenta, normalizado.
     *
     * @param int $userid
     * @return string
     */
    private static function user_document_type(int $userid): string {
        global $DB;
        $field = config::document_type_field();
        if (strpos($field, 'profile_field_') !== 0) {
            return '';
        }
        $sql = "SELECT d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE d.userid = :userid AND f.shortname = :shortname";
        $value = $DB->get_field_sql($sql, ['userid' => $userid, 'shortname' => substr($field, strlen('profile_field_'))]);
        return self::normalize_text((string) $value);
    }

    /**
     * Un claim escalar como string; arrays y objetos no son documentos.
     *
     * @param mixed $value
     * @return string
     */
    private static function scalar($value): string {
        return is_scalar($value) ? (string) $value : '';
    }
}
