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
 * @covers     \local_sovracred\task\reconcile
 * @covers     \local_sovracred\issuance::refresh
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
        ]));
        $current = $this->run_task();
        $this->assertSame(issuance::ISSUED, $current->status);
        $this->assertSame('did:sovra:0xabc', $current->holderdid);
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
}
