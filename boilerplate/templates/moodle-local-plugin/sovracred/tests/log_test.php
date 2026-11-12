<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Registro de la API y del webhook».
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class log_test extends \advanced_testcase {
    use fixtures_trait;

    /** @var string */
    const SECRET = 'secreto-de-prueba';

    /** @var string */
    const CREDID = '11111111-1111-1111-1111-111111111111';

    /** @var string */
    const WORKSPACE = 'bfe85e0d-c2da-4ccd-bd99-d26827f7e45e';

    /** @var array Historial de peticiones HTTP. */
    private array $history = [];

    /** @var \GuzzleHttp\Handler\MockHandler */
    private $mock;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        // El webhook usa una transacción propia.
        $this->preventResetByRollback();
        $this->configure_plugin();
        $this->setAdminUser();
        $this->redirectMessages();
        ['mock' => $this->mock] = $this->get_mocked_http_client($this->history);

        $course = $this->create_completable_course();
        $student = $this->create_student();
        $this->complete_course($student, $course);
        $record = $DB->get_record(issuance::TABLE, ['userid' => $student->id], '*', MUST_EXIST);
        issuance::update($record, [
            'status' => issuance::OFFERED, 'credentialid' => self::CREDID,
            'offeruri' => 'openid-credential-offer://?x', 'offerexpires' => time() + DAYSECS,
        ]);
    }

    /**
     * Cuerpo de una entrega.
     *
     * @param string $id
     * @param string $credentialid
     * @param string $workspace
     * @return string
     */
    private function body(string $id = 'evt-1', string $credentialid = self::CREDID, string $workspace = self::WORKSPACE): string {
        return '{"event": "credential.issued", "id": "' . $id . '", "timestamp": "2026-11-12T10:05:00.123456Z", ' .
            '"workspace_id": "' . $workspace . '", "data": {"credential_id": "' . $credentialid . '", ' .
            '"holder_did": "did:sovra:0xabc", "issued_at": "2026-11-12T10:04:59Z", "credential": "eyJ...~"}}';
    }

    /**
     * Las entradas del registro, de la más vieja a la más nueva.
     *
     * @param string $kind
     * @return \stdClass[]
     */
    private function entries(string $kind): array {
        global $DB;
        return array_values($DB->get_records(log::TABLE, ['kind' => $kind], 'id ASC'));
    }

    public function test_llamadas_a_la_api(): void {
        $this->mock->append($this->json_response(200, ['id' => self::CREDID, 'status' => 'pending']));
        $this->mock->append($this->json_response(404, ['error' => 'credential_not_found']));

        $api = api_client::from_config();
        $api->get_credential(self::CREDID);
        try {
            $api->get_credential(self::CREDID);
            $this->fail('Tenía que fallar con credential_not_found');
        } catch (api_exception $e) {
            $this->assertSame('credential_not_found', $e->errorcode);
        }

        [$ok, $error] = $this->entries(log::API);
        $this->assertSame('GET /api/v1/issuer/credentials/' . self::CREDID, $ok->action);
        $this->assertEquals(200, $ok->httpstatus);
        $this->assertSame('ok', $ok->result);
        $this->assertSame(self::CREDID, $ok->credentialid);
        $this->assertNotNull($ok->duration);

        $this->assertEquals(404, $error->httpstatus);
        $this->assertSame('credential_not_found', $error->result);
    }

    public function test_oferta_registra_credential_id_sin_claims(): void {
        global $DB;
        $this->mock->append($this->offer_response('22222222-2222-2222-2222-222222222222', time() + DAYSECS));

        api_client::from_config()->create_offer('bogota_formacion', ['documento' => '1020304050']);

        [$entry] = $this->entries(log::API);
        $this->assertSame('POST /api/v1/issuer/credential-offer', $entry->action);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $entry->credentialid);
        // Nada de los claims queda en el registro.
        $this->assertEquals(0, $DB->count_records_select(log::TABLE, $DB->sql_like('detail', ':doc'), ['doc' => '%1020304050%']));
    }

    public function test_avisos_del_webhook(): void {
        $body = $this->body();
        $signature = hmac::sign($body, self::SECRET);
        webhook::handle($body, $signature, self::SECRET);
        webhook::handle($body, $signature, self::SECRET);
        $other = $this->body('evt-2', '99999999-9999-9999-9999-999999999999');
        webhook::handle($other, hmac::sign($other, self::SECRET), self::SECRET);

        [$applied, $duplicate, $unknown] = $this->entries(log::WEBHOOK);
        $this->assertSame('credential.issued', $applied->action);
        $this->assertSame('applied', $applied->result);
        $this->assertEquals(200, $applied->httpstatus);
        $this->assertSame('evt-1', $applied->eventid);
        $this->assertSame(self::CREDID, $applied->credentialid);
        $this->assertSame(self::WORKSPACE, $applied->workspaceid);
        $this->assertSame('duplicate', $duplicate->result);
        $this->assertSame('unknowncredential', $unknown->result);

        $this->assertSame('unknowncredential', log::last_webhook()->result);
    }

    public function test_firma_invalida_se_registra_una_vez_por_minuto(): void {
        $body = $this->body();
        webhook::handle($body, hmac::sign($body, 'otro-secreto'), self::SECRET);
        webhook::handle($body, null, self::SECRET);

        $entries = $this->entries(log::WEBHOOK);
        $this->assertCount(1, $entries);
        $this->assertSame('badsignature', $entries[0]->result);
        $this->assertEquals(401, $entries[0]->httpstatus);
        // Sin firma válida no se confía en nada del cuerpo.
        $this->assertNull($entries[0]->eventid);
        $this->assertNull(log::last_webhook());
    }

    public function test_aviso_de_otro_workspace(): void {
        global $DB;
        set_config('workspaceid', '00000000-0000-0000-0000-000000000000', 'local_sovracred');
        $body = $this->body();

        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));

        [$entry] = $this->entries(log::WEBHOOK);
        $this->assertSame('otherworkspace', $entry->result);
        $this->assertSame(self::WORKSPACE, $entry->workspaceid);
        $this->assertSame(issuance::OFFERED, $DB->get_field(issuance::TABLE, 'status', ['credentialid' => self::CREDID]));
    }

    public function test_aviso_del_workspace_configurado(): void {
        global $DB;
        set_config('workspaceid', strtoupper(self::WORKSPACE), 'local_sovracred');
        $body = $this->body();

        webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET);

        $this->assertSame(issuance::ISSUED, $DB->get_field(issuance::TABLE, 'status', ['credentialid' => self::CREDID]));
    }

    public function test_retencion(): void {
        global $DB;
        log::api('GET', '/viejo', 200, 'ok', 10);
        log::api('GET', '/nuevo', 200, 'ok', 10);
        $DB->set_field(log::TABLE, 'timecreated', time() - log::RETENTION - 1, ['action' => 'GET /viejo']);

        log::prune();

        $this->assertSame(['GET /nuevo'], array_column($this->entries(log::API), 'action'));
    }
}
