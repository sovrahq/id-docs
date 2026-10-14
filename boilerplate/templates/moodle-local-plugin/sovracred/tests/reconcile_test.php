<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Reconciliación sin depender del webhook».
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reconcile_test extends \advanced_testcase {
    use fixtures_trait;

    /** @var \GuzzleHttp\Handler\MockHandler */
    private $mock;

    /** @var \stdClass */
    private \stdClass $issuance;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
        $this->setAdminUser();
        $this->redirectMessages();
        ['mock' => $this->mock] = $this->get_mocked_http_client();

        $course = $this->create_course_with_fields();
        $student = $this->create_student();
        $this->complete_course($student, $course);
        $record = $DB->get_record(issuance::TABLE, ['userid' => $student->id], '*', MUST_EXIST);
        $this->issuance = issuance::update($record, [
            'status' => issuance::OFFERED, 'credentialid' => '11111111-1111-1111-1111-111111111111',
            'offeruri' => 'openid-credential-offer://?x', 'offerexpires' => time() + DAYSECS,
        ]);
    }

    /**
     * Corre la tarea y devuelve el derecho actualizado.
     *
     * @return \stdClass
     */
    private function run_task(): \stdClass {
        global $DB;
        ob_start();
        (new task\reconcile())->execute();
        ob_end_clean();
        return $DB->get_record(issuance::TABLE, ['id' => $this->issuance->id]);
    }

    public function test_oferta_canjeada_sin_aviso(): void {
        $this->mock->append($this->json_response(200, [
            'id' => $this->issuance->credentialid, 'status' => 'issued',
            'holder_did' => 'did:sovra:0xabc', 'issued_at' => '2026-10-08T10:00:00Z',
            'expires_at' => '2027-10-08T10:00:00Z',
        ]));
        $current = $this->run_task();
        $this->assertSame(issuance::ISSUED, $current->status);
        $this->assertSame('did:sovra:0xabc', $current->holderdid);
        $this->assertEquals(strtotime('2027-10-08T10:00:00Z'), $current->credexpires);
    }

    public function test_datos_de_la_emision_incompletos(): void {
        // Revocada antes de que llegara el issued: sin DID, sin fecha de emisión ni de vencimiento.
        $this->issuance = issuance::update($this->issuance, [
            'status' => issuance::SUSPENDED, 'holderdid' => null, 'timeissued' => null, 'credexpires' => null,
            'timechecked' => 0,
        ]);
        $this->mock->append($this->json_response(200, [
            'id' => $this->issuance->credentialid, 'status' => 'revoked',
            'holder_did' => 'did:sovra:0xabc', 'issued_at' => '2026-10-08T10:00:00Z',
            'expires_at' => '2027-10-08T10:00:00Z',
        ]));
        $current = $this->run_task();
        $this->assertSame(issuance::REVOKED, $current->status);
        $this->assertSame('did:sovra:0xabc', $current->holderdid);
        $this->assertEquals(strtotime('2026-10-08T10:00:00Z'), $current->timeissued);
        $this->assertEquals(strtotime('2027-10-08T10:00:00Z'), $current->credexpires);
    }

    public function test_oferta_vencida_sin_canjear(): void {
        $this->mock->append($this->json_response(200, ['id' => $this->issuance->credentialid, 'status' => 'expired']));
        $current = $this->run_task();
        $this->assertSame(issuance::AVAILABLE, $current->status);
        $this->assertNull($current->credentialid);
        $this->assertNull($current->offeruri);
    }

    public function test_oferta_todavia_pendiente(): void {
        $this->mock->append($this->json_response(200, ['id' => $this->issuance->credentialid, 'status' => 'pending']));
        $this->assertSame(issuance::OFFERED, $this->run_task()->status);
    }

    public function test_api_inaccesible(): void {
        $this->mock->append($this->json_response(503, ['error' => 'unavailable']));
        $current = $this->run_task();
        $this->assertSame(issuance::OFFERED, $current->status);
        $this->assertSame($this->issuance->credentialid, $current->credentialid);

        // La próxima corrida lo vuelve a intentar.
        $this->mock->append($this->json_response(200, ['id' => $this->issuance->credentialid, 'status' => 'issued',
            'holder_did' => 'did:sovra:0xabc', 'issued_at' => '2026-10-08T10:00:00Z']));
        $this->assertSame(issuance::ISSUED, $this->run_task()->status);
    }

    public function test_con_mas_ofertas_que_el_lote_rota(): void {
        global $DB;
        // Una segunda oferta abierta, y lotes de a una: dos corridas tienen que consultar las dos.
        $student = $this->create_student('555');
        $this->complete_course($student, $this->create_course_with_fields());
        $second = $DB->get_record(issuance::TABLE, ['userid' => $student->id], '*', MUST_EXIST);
        issuance::update($second, ['status' => issuance::OFFERED, 'credentialid' => '22222222-2222-2222-2222-222222222222',
            'offeruri' => 'openid-credential-offer://?y', 'offerexpires' => time() + DAYSECS]);

        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $api = api_client::from_config();
        $task = new task\reconcile();
        foreach ([1, 2] as $round) {
            $mock->append($this->json_response(200, ['status' => 'pending']));
            ob_start();
            $task->run($api, 1, 0);
            ob_end_clean();
            $this->waitForSecond();
        }
        $queried = array_map(fn($h) => basename($h['request']->getUri()->getPath()), $history);
        sort($queried);
        $this->assertSame(['11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222'], $queried);
    }

    public function test_revocacion_de_una_emitida_sin_webhook(): void {
        global $DB;
        issuance::update($this->issuance, ['status' => issuance::ISSUED, 'timeissued' => time() - 2 * DAYSECS,
            'timechecked' => time() - 2 * DAYSECS]);
        $this->mock->append($this->json_response(200, ['id' => $this->issuance->credentialid, 'status' => 'revoked']));
        $this->assertSame(issuance::REVOKED, $this->run_task()->status);
    }

    public function test_emitida_consultada_hace_poco_no_se_vuelve_a_consultar(): void {
        issuance::update($this->issuance, ['status' => issuance::ISSUED, 'timechecked' => time() - HOURSECS]);
        // Sin respuestas en la cola: si consultara, el cliente simulado fallaría y quedaría un error.
        $current = $this->run_task();
        $this->assertSame(issuance::ISSUED, $current->status);
        $this->assertNull($current->lasterror);
    }

    public function test_limpia_entregas_viejas_del_webhook(): void {
        global $DB;
        $DB->insert_record('local_sovracred_webhook', (object) ['eventid' => 'viejo', 'event' => 'x', 'timereceived' => time() - 40 * DAYSECS]);
        $DB->insert_record('local_sovracred_webhook', (object) ['eventid' => 'nuevo', 'event' => 'x', 'timereceived' => time()]);
        $this->mock->append($this->json_response(200, ['status' => 'pending']));
        $this->run_task();
        $this->assertFalse($DB->record_exists('local_sovracred_webhook', ['eventid' => 'viejo']));
        $this->assertTrue($DB->record_exists('local_sovracred_webhook', ['eventid' => 'nuevo']));
    }
}
