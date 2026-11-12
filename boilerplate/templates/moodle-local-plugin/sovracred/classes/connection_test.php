<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * El botón «Probar conexión»: API key, esquema y claims, sin crear ninguna oferta.
 *
 * Lee GET /api/v1/issuer/schemas, que prueba la llave y la red, y de paso permite decir qué esquemas sí
 * existen en el workspace (el error más común es el slug) y si los claims del esquema coinciden con los
 * que arma classes/claims.php (el segundo más común: el esquema cambió y el plugin no).
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection_test {

    /** @var string */
    const OK = 'success';

    /** @var string */
    const WARNING = 'warning';

    /** @var string */
    const ERROR = 'error';

    /**
     * Corre la prueba.
     *
     * @param api_client|null $api Las pruebas pasan uno propio; por defecto, el de la configuración.
     * @return array[] Lista de [tipo, mensaje en HTML], tipo OK, WARNING o ERROR.
     */
    public static function run(?api_client $api = null): array {
        $schemaid = config::schema_id();
        try {
            $api = $api ?? api_client::from_config();
            if ($schemaid === '') {
                throw new api_exception('notconfigured');
            }
            $schemas = $api->list_schemas();
        } catch (api_exception $e) {
            return [[self::ERROR, self::api_error($e)]];
        }

        // Una entrada por versión: la emisión usa la más alta.
        $schema = null;
        $slugs = [];
        foreach ($schemas as $row) {
            if (($row['kind'] ?? 'credential') === 'credential' && isset($row['schema_id'])) {
                $slugs[$row['schema_id']] = true;
            }
            if (($row['schema_id'] ?? null) === $schemaid && (!$schema || ($row['version'] ?? 0) > ($schema['version'] ?? 0))) {
                $schema = $row;
            }
        }

        if (!$schema) {
            $a = (object) ['schema' => s($schemaid), 'available' => self::code_list(array_keys($slugs))];
            return [[self::ERROR, get_string($slugs ? 'test_schema_not_found_list' : 'test_schema_none', 'local_sovracred', $a)]];
        }
        if (($schema['kind'] ?? 'credential') !== 'credential') {
            return [[self::ERROR, get_string('test_wrong_schema_kind', 'local_sovracred')]];
        }
        $registration = $schema['registration_status'] ?? 'registered';
        if ($registration === 'pending') {
            return [[self::ERROR, get_string('test_schema_registration_pending', 'local_sovracred')]];
        }
        if ($registration === 'failed') {
            return [[self::ERROR, get_string('test_schema_registration_failed', 'local_sovracred',
                s((string) ($schema['registration_error'] ?? '')))]];
        }

        return self::compare_claims($schema);
    }

    /**
     * Compara los claims del esquema con los que arma el plugin.
     *
     * @param array $schema
     * @return array[]
     */
    private static function compare_claims(array $schema): array {
        $schemakeys = [];
        $schemarequired = [];
        foreach ($schema['claims'] ?? [] as $claim) {
            if (isset($claim['key'])) {
                $schemakeys[] = $claim['key'];
                if (!empty($claim['required'])) {
                    $schemarequired[] = $claim['key'];
                }
            }
        }
        $pluginkeys = array_merge(claims::REQUIRED, claims::OPTIONAL);

        $results = [];
        // Lo que el esquema exige y el plugin no manda: la API rechaza todas las ofertas con missing_claims.
        $missing = array_values(array_diff($schemarequired, $pluginkeys));
        if ($missing) {
            $results[] = [self::ERROR, get_string('test_claims_missing', 'local_sovracred', self::code_list($missing))];
        }
        // Lo que el esquema exige y el plugin manda sólo a veces: falla para algunos estudiantes.
        $sometimes = array_values(array_intersect($schemarequired, claims::OPTIONAL));
        if ($sometimes) {
            $results[] = [self::WARNING, get_string('test_claims_optional', 'local_sovracred', self::code_list($sometimes))];
        }
        // Lo que el plugin manda y el esquema no declara: se descarta en silencio al firmar.
        $unknown = array_values(array_diff($pluginkeys, $schemakeys));
        if ($unknown) {
            $results[] = [self::WARNING, get_string('test_claims_unknown', 'local_sovracred', self::code_list($unknown))];
        }

        $a = (object) ['schema' => s($schema['schema_id']), 'version' => (int) ($schema['version'] ?? 1)];
        if (!$missing) {
            array_unshift($results, [self::OK, get_string($results ? 'test_ok_schema' : 'test_ok', 'local_sovracred', $a)]);
        }
        return $results;
    }

    /**
     * El mensaje de un error de la API.
     *
     * @param api_exception $e
     * @return string
     */
    private static function api_error(api_exception $e): string {
        if (get_string_manager()->string_exists('test_' . $e->errorcode, 'local_sovracred')) {
            return get_string('test_' . $e->errorcode, 'local_sovracred');
        }
        return get_string('test_other', 'local_sovracred', s($e->summary()));
    }

    /**
     * Lista de keys como código, separadas por comas.
     *
     * @param string[] $keys
     * @return string
     */
    private static function code_list(array $keys): string {
        return implode(', ', array_map(fn($key) => '<code>' . s($key) . '</code>', $keys));
    }
}
