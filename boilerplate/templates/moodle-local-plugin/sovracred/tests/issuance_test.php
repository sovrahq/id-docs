<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «La oferta se crea cuando el estudiante la pide» y «Conexión con la API de Sovra».
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class issuance_test extends \advanced_testcase {
    use fixtures_trait;

    /** @var array Historial de peticiones HTTP. */
    private array $history = [];

    /** @var \GuzzleHttp\Handler\MockHandler */
    private $mock;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
        $this->setAdminUser();
        $this->redirectMessages();
        ['mock' => $this->mock] = $this->get_mocked_http_client($this->history);
    }

    /**
     * Derecho disponible de un estudiante.
     *
     * @param string $documento
     * @return \stdClass
     */
    private function available_issuance(string $documento = '1020304050'): \stdClass {
        global $DB;
        $course = $this->create_course_with_fields();
        $student = $this->create_student($documento);
        $this->complete_course($student, $course);
        return $DB->get_record(issuance::TABLE, ['userid' => $student->id], '*', MUST_EXIST);
    }

    public function test_no_se_crea_oferta_al_completar(): void {
        $this->available_issuance();
        $this->assertCount(0, $this->history);
    }

    public function test_primera_solicitud(): void {
        global $DB;
        $issuance = $this->available_issuance();
        $expires = time() + DAYSECS;
        $this->mock->append($this->offer_response('11111111-1111-1111-1111-111111111111', $expires));

        issuance::request_offer($issuance);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.example.test/api/v1/issuer/credential-offer', (string) $request->getUri());
        // Autenticación: Bearer y nada deprecado.
        $this->assertSame('Bearer sovra_sk_prueba', $request->getHeaderLine('Authorization'));
        $this->assertFalse($request->hasHeader('x-api-key'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('bogota-formacion', $body['schema_id']);
        $this->assertSame('1020304050', $body['claims']['documento']);

        $saved = $DB->get_record(issuance::TABLE, ['id' => $issuance->id]);
        $this->assertSame(issuance::OFFERED, $saved->status);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $saved->credentialid);
        $this->assertStringStartsWith('openid-credential-offer://', $saved->offeruri);
        $this->assertEquals($expires, $saved->offerexpires);
        $this->assertNull($saved->lasterror);
    }

    public function test_oferta_vigente_se_reutiliza(): void {
        $issuance = $this->available_issuance();
        $this->mock->append($this->offer_response('11111111-1111-1111-1111-111111111111', time() + DAYSECS));
        $issuance = issuance::request_offer($issuance);

        $again = issuance::request_offer($issuance);

        $this->assertCount(1, $this->history);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $again->credentialid);
    }

    public function test_oferta_por_vencer_consulta_y_crea_otra(): void {
        $issuance = $this->available_issuance();
        $this->mock->append($this->offer_response('11111111-1111-1111-1111-111111111111', time() + 300));
        $issuance = issuance::request_offer($issuance);

        $this->mock->append($this->json_response(200, ['id' => '11111111-1111-1111-1111-111111111111', 'status' => 'pending']));
        $this->mock->append($this->offer_response('22222222-2222-2222-2222-222222222222', time() + DAYSECS));
        $issuance = issuance::request_offer($issuance);

        $this->assertCount(3, $this->history);
        $this->assertSame('GET', $this->history[1]['request']->getMethod());
        $this->assertSame(issuance::OFFERED, $issuance->status);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $issuance->credentialid);
    }

    public function test_ya_emitida_aunque_no_llego_el_aviso(): void {
        $issuance = $this->available_issuance();
        $this->mock->append($this->offer_response('11111111-1111-1111-1111-111111111111', time() - 60));
        $issuance = issuance::request_offer($issuance);

        $this->mock->append($this->json_response(200, [
            'id' => '11111111-1111-1111-1111-111111111111', 'status' => 'issued',
            'holder_did' => 'did:sovra:0xabc', 'issued_at' => '2026-10-08T10:00:00Z',
        ]));
        $issuance = issuance::request_offer($issuance);

        $this->assertCount(2, $this->history);
        $this->assertSame(issuance::ISSUED, $issuance->status);
        $this->assertSame('did:sovra:0xabc', $issuance->holderdid);
    }

    public function test_falta_un_dato_obligatorio(): void {
        global $DB;
        $issuance = $this->available_issuance('');
        try {
            issuance::request_offer($issuance);
            $this->fail('Tenía que fallar por falta de documento');
        } catch (missing_claims_exception $e) {
            $this->assertSame(['documento'], $e->missing);
        }
        $this->assertCount(0, $this->history);
        $saved = $DB->get_record(issuance::TABLE, ['id' => $issuance->id]);
        $this->assertSame(issuance::AVAILABLE, $saved->status);
        $this->assertStringContainsString('documento', $saved->lasterror);
    }

    public function test_la_api_rechaza(): void {
        global $DB;
        $issuance = $this->available_issuance();
        $this->mock->append($this->json_response(401, ['error' => 'invalid_api_key']));
        try {
            issuance::request_offer($issuance);
            $this->fail('Tenía que fallar');
        } catch (api_exception $e) {
            $this->assertSame('invalid_api_key', $e->errorcode);
        }
        $saved = $DB->get_record(issuance::TABLE, ['id' => $issuance->id]);
        $this->assertSame(issuance::AVAILABLE, $saved->status);
        $this->assertStringContainsString('invalid_api_key', $saved->lasterror);
        // La API key nunca queda en el error.
        $this->assertStringNotContainsString('sovra_sk_prueba', $saved->lasterror);
    }

    public function test_la_api_no_responde(): void {
        global $DB;
        $issuance = $this->available_issuance();
        $this->mock->append(new \GuzzleHttp\Exception\ConnectException('timeout',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.example.test')));
        $this->expectException(api_exception::class);
        try {
            issuance::request_offer($issuance);
        } finally {
            $this->assertStringContainsString('network', $DB->get_field(issuance::TABLE, 'lasterror', ['id' => $issuance->id]));
        }
    }

    public function test_host_bloqueado(): void {
        set_config('curlsecurityblockedhosts', 'api.example.test');
        $issuance = $this->available_issuance();
        try {
            issuance::request_offer($issuance);
            $this->fail('Tenía que fallar');
        } catch (api_exception $e) {
            $this->assertSame('hostblocked', $e->errorcode);
        }
        $this->assertCount(0, $this->history);
    }

    public function test_claims_vacios_viajan_como_objeto(): void {
        // La API responde 500 a "claims": [] — la prueba de conexión manda un objeto vacío.
        $this->mock->append($this->json_response(422, ['error' => 'missing_claims', 'details' => ['holderName']]));
        try {
            api_client::from_config()->create_offer('bogota-formacion', []);
        } catch (api_exception $e) {
            $this->assertSame('missing_claims', $e->errorcode);
        }
        $this->assertSame('{"schema_id":"bogota-formacion","claims":{}}', (string) $this->history[0]['request']->getBody());
    }
}
