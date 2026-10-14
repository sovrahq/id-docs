<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Lectura de la configuración del plugin, con los secretos descifrados.
 *
 * La API key y el secreto del webhook se guardan cifrados (admin_setting_encryptedpassword).
 * También se aceptan en claro si el sitio los fija en config.php con
 * $CFG->forced_plugin_settings['local_sovracred'], que es lo habitual en despliegues automatizados.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {

    /** @var string Emisión para todos los cursos. */
    const SCOPE_ALL = 'all';

    /** @var string Emisión sólo para los cursos listados. */
    const SCOPE_LIST = 'list';

    /**
     * URL base de la API, sin barra final.
     *
     * @return string
     */
    public static function api_url(): string {
        return rtrim(trim((string) get_config('local_sovracred', 'apiurl')), '/');
    }

    /**
     * API key del workspace (sovra_sk_...).
     *
     * @return string
     */
    public static function api_key(): string {
        return self::secret('apikey');
    }

    /**
     * Secreto HMAC del webhook.
     *
     * @return string
     */
    public static function webhook_secret(): string {
        return self::secret('webhooksecret');
    }

    /**
     * El schema_id (slug) del esquema de la credencial.
     *
     * @return string
     */
    public static function schema_id(): string {
        return trim((string) get_config('local_sovracred', 'schemaid'));
    }

    /**
     * Campo del perfil del que sale el número de documento: 'idnumber' o 'profile_field_<shortname>'.
     *
     * @return string
     */
    public static function document_field(): string {
        $field = (string) get_config('local_sovracred', 'documentfield');
        return $field === '' ? 'idnumber' : $field;
    }

    /**
     * ¿La API está configurada lo suficiente como para crear ofertas?
     *
     * @return bool
     */
    public static function api_ready(): bool {
        return self::api_url() !== '' && self::api_key() !== '' && self::schema_id() !== '';
    }

    /**
     * ¿Este curso emite credenciales?
     *
     * @param int $courseid
     * @return bool
     */
    public static function course_enabled(int $courseid): bool {
        if ($courseid == SITEID) {
            return false;
        }
        if (get_config('local_sovracred', 'coursescope') !== self::SCOPE_LIST) {
            return true;
        }
        $ids = array_filter(array_map('intval', explode(',', (string) get_config('local_sovracred', 'courseids'))));
        return in_array($courseid, $ids, true);
    }

    /**
     * URL pública del webhook, para pegar en la consola de Sovra.
     *
     * @return \moodle_url
     */
    public static function webhook_url(): \moodle_url {
        return new \moodle_url('/local/sovracred/webhook.php');
    }

    /**
     * Lee un secreto, descifrándolo si está cifrado.
     *
     * @param string $name
     * @return string
     */
    private static function secret(string $name): string {
        $value = trim((string) get_config('local_sovracred', $name));
        if ($value === '' || !preg_match('~^(sodium|openssl-aes-256-ctr):~', $value)) {
            // Vacío, o fijado en claro desde config.php.
            return $value;
        }
        try {
            return \core\encryption::decrypt($value);
        } catch (\moodle_exception $e) {
            // La clave de cifrado del sitio cambió (por ejemplo, se movió el dataroot sin su carpeta secret/).
            // Nunca se loguea el valor: sólo que no se pudo descifrar.
            debugging("local_sovracred: no se pudo descifrar '{$name}'; volvé a guardarlo en la configuración.");
            return '';
        }
    }
}
