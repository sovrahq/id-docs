<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * Lectura de la configuración del plugin, con los secretos descifrados.
 *
 * La API key y el secreto del webhook se guardan cifrados (admin_setting_encryptedpassword).
 * También se aceptan en claro si el sitio los fija en config.php con
 * $CFG->forced_plugin_settings['auth_sovra'], que es lo habitual en despliegues automatizados.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {

    /** @var string Plugin. */
    const PLUGIN = 'auth_sovra';

    /** @var string Métodos de autenticación aceptados por defecto. */
    const DEFAULT_AUTHS = 'manual,email,sovra';

    /**
     * URL base de la API, sin barra final.
     *
     * @return string
     */
    public static function api_url(): string {
        return rtrim(trim((string) get_config(self::PLUGIN, 'apiurl')), '/');
    }

    /**
     * API key del workspace verificador (sovra_sk_...).
     *
     * @return string
     */
    public static function api_key(): string {
        return self::secret('apikey');
    }

    /**
     * Secreto HMAC del webhook. Vacío: el webhook responde 503 y el resultado se consulta por GET.
     *
     * @return string
     */
    public static function webhook_secret(): string {
        return self::secret('webhooksecret');
    }

    /**
     * El workspace_id de Sovra (UUID). Opcional: si está, se ignoran los avisos de otros workspaces.
     *
     * @return string
     */
    public static function workspace_id(): string {
        return trim((string) get_config(self::PLUGIN, 'workspaceid'));
    }

    /**
     * DIDs de los emisores aceptados: uno por línea (también se aceptan comas y espacios).
     *
     * @return string[]
     */
    public static function allowed_issuers(): array {
        return self::list_setting('allowedissuers');
    }

    /**
     * Tipos de credencial (vct) aceptados. Vacío: no se exige un tipo.
     *
     * @return string[]
     */
    public static function credential_types(): array {
        return self::list_setting('credentialtypes');
    }

    /**
     * Claim con el número de documento.
     *
     * @return string
     */
    public static function claim_document_number(): string {
        $claim = trim((string) get_config(self::PLUGIN, 'claimdocnumber'));
        return $claim === '' ? 'num_doc' : $claim;
    }

    /**
     * Claim con el tipo de documento.
     *
     * @return string
     */
    public static function claim_document_type(): string {
        $claim = trim((string) get_config(self::PLUGIN, 'claimdoctype'));
        return $claim === '' ? 'tipo_doc' : $claim;
    }

    /**
     * Campo de la cuenta con el número de documento: 'idnumber' o 'profile_field_<shortname>'.
     *
     * @return string
     */
    public static function document_field(): string {
        $field = (string) get_config(self::PLUGIN, 'documentfield');
        return $field === '' ? 'idnumber' : $field;
    }

    /**
     * ¿Se compara también el tipo de documento?
     *
     * @return bool
     */
    public static function match_document_type(): bool {
        return (bool) get_config(self::PLUGIN, 'matchdoctype') && self::document_type_field() !== '';
    }

    /**
     * Campo de perfil con el tipo de documento: 'profile_field_<shortname>', o vacío.
     *
     * @return string
     */
    public static function document_type_field(): string {
        return (string) get_config(self::PLUGIN, 'documenttypefield');
    }

    /**
     * Tabla de equivalencias de tipos: valor normalizado de la credencial → valores normalizados de Moodle.
     *
     * Una línea por equivalencia: «Cédula de ciudadanía = CC». Un valor de la credencial puede aparecer en
     * varias líneas. Las líneas sin «=» o vacías se ignoran.
     *
     * @return array<string, string[]>
     */
    public static function document_type_map(): array {
        $map = [];
        $lines = preg_split('~\R~u', (string) get_config(self::PLUGIN, 'doctypemap'));
        foreach ($lines as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            [$from, $to] = array_map('trim', explode('=', $line, 2));
            if ($from === '' || $to === '') {
                continue;
            }
            $map[resolver::normalize_text($from)][] = resolver::normalize_text($to);
        }
        return $map;
    }

    /**
     * Métodos de autenticación de las cuentas que pueden entrar con la SuperApp.
     *
     * @return string[]
     */
    public static function allowed_auths(): array {
        $value = get_config(self::PLUGIN, 'allowedauths');
        if ($value === false) {
            $value = self::DEFAULT_AUTHS;
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    /**
     * ¿Pueden entrar con la SuperApp los administradores del sitio?
     *
     * @return bool
     */
    public static function allow_admins(): bool {
        return (bool) get_config(self::PLUGIN, 'allowadmins');
    }

    /**
     * Adónde se manda a quien no tiene cuenta. Null si no hay registro posible.
     *
     * @return \moodle_url|null
     */
    public static function signup_url(): ?\moodle_url {
        global $CFG;
        $url = trim((string) get_config(self::PLUGIN, 'signupurl'));
        if ($url !== '') {
            return new \moodle_url($url);
        }
        if (!empty($CFG->registerauth)) {
            return new \moodle_url('/login/signup.php');
        }
        return null;
    }

    /**
     * Nombre de la plataforma en los avisos («No tienes cuenta en Atenea»).
     * Vacío: el nombre corto del sitio.
     *
     * @return string Texto plano, sin escapar.
     */
    public static function platform_name(): string {
        global $SITE;
        $name = trim((string) get_config(self::PLUGIN, 'platformname'));
        if ($name === '') {
            $name = trim(format_string($SITE->shortname ?: $SITE->fullname, true, ['context' => \context_system::instance(),
                'escape' => false]));
        }
        return $name;
    }

    /**
     * ¿Está todo lo necesario para ofrecer el ingreso? URL, API key y al menos un emisor permitido.
     *
     * @return bool
     */
    public static function ready(): bool {
        return self::api_url() !== '' && self::api_key() !== '' && self::allowed_issuers() !== [];
    }

    /**
     * Los secretos guardados que no se pueden descifrar (por ejemplo, después de migrar el sitio con otra clave).
     *
     * Sin esto, el plugin los trata como vacíos y el botón desaparece sin explicación.
     *
     * @return string[] apikey y/o webhooksecret.
     */
    public static function undecryptable(): array {
        return array_values(array_filter(['apikey', 'webhooksecret'],
            fn($name) => trim((string) get_config(self::PLUGIN, $name)) !== '' && self::secret($name) === ''));
    }

    /**
     * URL pública del webhook, para pegar en la consola de Sovra.
     *
     * @return \moodle_url
     */
    public static function webhook_url(): \moodle_url {
        return new \moodle_url('/auth/sovra/webhook.php');
    }

    /**
     * Un secreto enmascarado para mostrar en pantalla.
     *
     * @param string $value
     * @return string
     */
    public static function masked(string $value): string {
        if ($value === '') {
            return '';
        }
        $prefix = preg_match('~^(sovra_sk_|whsec_)~', $value, $m) ? $m[1] : '';
        return $prefix . '…' . \core_text::substr($value, -4);
    }

    /**
     * Una configuración de lista: líneas, comas o espacios.
     *
     * @param string $name
     * @return string[]
     */
    private static function list_setting(string $name): array {
        $items = preg_split('~[\s,]+~u', trim((string) get_config(self::PLUGIN, $name)));
        return array_values(array_unique(array_filter($items, fn($item) => $item !== '')));
    }

    /**
     * Lee un secreto, descifrándolo si está cifrado.
     *
     * @param string $name
     * @return string
     */
    private static function secret(string $name): string {
        $value = trim((string) get_config(self::PLUGIN, $name));
        if ($value === '' || !preg_match('~^(sodium|openssl-aes-256-ctr):~', $value)) {
            // Vacío, o fijado en claro desde config.php.
            return $value;
        }
        try {
            return \core\encryption::decrypt($value);
        } catch (\moodle_exception $e) {
            // Nunca se loguea el valor: sólo que no se pudo descifrar. debugging() no se ve en producción:
            // también va al log de PHP, y logs.php y la prueba de conexión lo avisan (undecryptable()).
            error_log("auth_sovra: no se pudo descifrar '{$name}'; hay que volver a guardarlo en la configuración.");
            debugging("auth_sovra: no se pudo descifrar '{$name}'; volvé a guardarlo en la configuración.");
            return '';
        }
    }
}
