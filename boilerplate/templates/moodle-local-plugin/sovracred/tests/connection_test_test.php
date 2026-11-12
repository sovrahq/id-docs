<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Prueba de conexión».
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connection_test_test extends \advanced_testcase {
    use fixtures_trait;

    /** @var array Historial de peticiones HTTP. */
    private array $history = [];

    /** @var \GuzzleHttp\Handler\MockHandler */
    private $mock;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
        ['mock' => $this->mock] = $this->get_mocked_http_client($this->history);
    }

    /**
     * Una fila de GET /schemas.
     *
     * @param string $schemaid
     * @param int $version
     * @param array $claims key → obligatorio
     * @param array $extra
     * @return array
     */
    private function schema(string $schemaid, int $version, array $claims, array $extra = []): array {
        $defs = [];
        foreach ($claims as $key => $required) {
            $defs[] = ['key' => $key, 'type' => 'string', 'required' => $required, 'disclosable' => true];
        }
        return $extra + [
            'id' => '0b9f2a64-3c1e-4d8a-9f27-5e6a1c3d7b9' . $version, 'schema_id' => $schemaid, 'version' => $version,
            'kind' => 'credential', 'format' => 'vc+sd-jwt', 'registration_status' => 'registered', 'claims' => $defs,
        ];
    }

    /**
     * Los claims del esquema de prueba v2, que coinciden con los del plugin.
     *
     * @return array
     */
    private function v2_claims(): array {
        return array_fill_keys(claims::REQUIRED, true) + ['programa' => false];
    }

    /**
     * Los tipos de resultado.
     *
     * @param array $results
     * @return string[]
     */
    private function types(array $results): array {
        return array_column($results, 0);
    }

    public function test_conexion_correcta_sin_crear_oferta(): void {
        $this->mock->append($this->json_response(200, ['schemas' => [
            $this->schema('bogota_formacion', 1, ['holderName' => true, 'horas' => true]),
            $this->schema('bogota_formacion', 2, $this->v2_claims()),
        ]]));

        $results = connection_test::run();

        $this->assertSame([connection_test::OK], $this->types($results));
        $this->assertStringContainsString('2', $results[0][1]);
        $this->assertCount(1, $this->history);
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
    }

    public function test_slug_equivocado_lista_los_que_existen(): void {
        // Guion en vez de guion bajo: el error con el que se encontró la primera institución.
        set_config('schemaid', 'bogota-formacion', 'local_sovracred');
        $this->mock->append($this->json_response(200, ['schemas' => [
            $this->schema('bogota_formacion', 2, $this->v2_claims()),
            $this->schema('hoja_de_vida', 1, ['nombre' => true], ['kind' => 'document']),
        ]]));

        [[$type, $message]] = connection_test::run();

        $this->assertSame(connection_test::ERROR, $type);
        $this->assertStringContainsString('<code>bogota_formacion</code>', $message);
        $this->assertStringNotContainsString('hoja_de_vida', $message);
    }

    public function test_claims_que_no_coinciden(): void {
        $claims = $this->v2_claims();
        unset($claims['tipoDocumento']);
        $claims['horas'] = true;
        $claims['programa'] = true;
        $this->mock->append($this->json_response(200, ['schemas' => [$this->schema('bogota_formacion', 3, $claims)]]));

        $results = connection_test::run();

        $this->assertSame([connection_test::ERROR, connection_test::WARNING, connection_test::WARNING], $this->types($results));
        $this->assertStringContainsString('<code>horas</code>', $results[0][1]);
        $this->assertStringContainsString('<code>programa</code>', $results[1][1]);
        $this->assertStringContainsString('<code>tipoDocumento</code>', $results[2][1]);
    }

    public function test_esquema_registrandose(): void {
        $this->mock->append($this->json_response(200, ['schemas' => [
            $this->schema('bogota_formacion', 1, $this->v2_claims(), ['registration_status' => 'pending']),
        ]]));

        [[$type, $message]] = connection_test::run();

        $this->assertSame(connection_test::ERROR, $type);
        $this->assertSame(get_string('test_schema_registration_pending', 'local_sovracred'), $message);
    }

    public function test_api_key_invalida(): void {
        $this->mock->append($this->json_response(401, ['error' => 'invalid_api_key']));

        [[$type, $message]] = connection_test::run();

        $this->assertSame(connection_test::ERROR, $type);
        $this->assertSame(get_string('test_invalid_api_key', 'local_sovracred'), $message);
    }

    public function test_sin_configurar(): void {
        set_config('apikey', '', 'local_sovracred');

        [[$type, $message]] = connection_test::run();

        $this->assertSame(connection_test::ERROR, $type);
        $this->assertSame(get_string('test_notconfigured', 'local_sovracred'), $message);
        $this->assertCount(0, $this->history);
    }
}
