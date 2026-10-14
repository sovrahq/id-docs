<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Recepción de avisos de Sovra (webhook)».
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webhook_test extends \advanced_testcase {
    use fixtures_trait;

    /** @var string */
    const SECRET = 'secreto-de-prueba';

    /** @var string */
    const CREDID = '11111111-1111-1111-1111-111111111111';

    /** @var \stdClass Derecho con una oferta creada. */
    private \stdClass $issuance;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        // El procesador usa una transacción propia.
        $this->preventResetByRollback();
        $this->configure_plugin();
        $this->setAdminUser();
        $this->redirectMessages();

        $course = $this->create_course_with_fields();
        $student = $this->create_student();
        $this->complete_course($student, $course);
        $record = $DB->get_record(issuance::TABLE, ['userid' => $student->id], '*', MUST_EXIST);
        $this->issuance = issuance::update($record, [
            'status' => issuance::OFFERED, 'credentialid' => self::CREDID,
            'offeruri' => 'openid-credential-offer://?x', 'offerexpires' => time() + DAYSECS,
            'statustime' => strtotime('2026-10-08T10:00:00Z'),
        ]);
    }

    /**
     * Cuerpo de una entrega, con el espaciado propio de Sovra (no el de json_encode).
     *
     * @param string $event
     * @param string $id
     * @param string $timestamp
     * @param string $credentialid
     * @return string
     */
    private function body(string $event, string $id = 'evt-1', string $timestamp = '2026-10-08T10:05:00.123456Z',
            string $credentialid = self::CREDID): string {
        return '{"event": "' . $event . '", "id": "' . $id . '", "timestamp": "' . $timestamp . '", ' .
            '"workspace_id": "ws", "data": {"credential_id": "' . $credentialid . '", ' .
            '"holder_did": "did:sovra:0xabc", "issued_at": "2026-10-08T10:04:59Z", "expires_at": "2027-10-08T10:04:59Z", ' .
            '"credential": "eyJ...~"}}';
    }

    /**
     * El derecho, releído de la base.
     *
     * @return \stdClass
     */
    private function current(): \stdClass {
        global $DB;
        return $DB->get_record(issuance::TABLE, ['id' => $this->issuance->id]);
    }

    public function test_credencial_emitida(): void {
        $body = $this->body('credential.issued');
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));

        $current = $this->current();
        $this->assertSame(issuance::ISSUED, $current->status);
        $this->assertSame('did:sovra:0xabc', $current->holderdid);
        $this->assertEquals(strtotime('2026-10-08T10:04:59Z'), $current->timeissued);
        $this->assertEquals(strtotime('2027-10-08T10:04:59Z'), $current->credexpires);
    }

    public function test_firma_sobre_el_cuerpo_crudo(): void {
        // Re-serializar el JSON cambia los bytes: una firma calculada sobre otra serialización no vale.
        $body = $this->body('credential.issued');
        $reencoded = json_encode(json_decode($body, true));
        $this->assertNotSame($body, $reencoded);
        $this->assertSame(401, webhook::handle($body, hmac::sign($reencoded, self::SECRET), self::SECRET));
    }

    public function test_firma_invalida(): void {
        $body = $this->body('credential.issued');
        $this->assertSame(401, webhook::handle($body, hmac::sign($body, 'otro-secreto'), self::SECRET));
        $this->assertSame(401, webhook::handle($body, null, self::SECRET));
        $this->assertSame(401, webhook::handle($body, 'sha256=', self::SECRET));

        $this->assertSame(issuance::OFFERED, $this->current()->status);
        $this->assertGreaterThan(0, (int) get_config('local_sovracred', 'lastrejected'));
    }

    public function test_entrega_repetida(): void {
        global $DB;
        $body = $this->body('credential.issued');
        $signature = hmac::sign($body, self::SECRET);
        $this->assertSame(200, webhook::handle($body, $signature, self::SECRET));

        // Si se reprocesara, volvería a escribir el estado: lo cambiamos a mano para detectarlo.
        $DB->set_field(issuance::TABLE, 'status', issuance::SUSPENDED, ['id' => $this->issuance->id]);
        $this->assertSame(200, webhook::handle($body, $signature, self::SECRET));

        $this->assertSame(issuance::SUSPENDED, $this->current()->status);
        $this->assertEquals(1, $DB->count_records('local_sovracred_webhook', ['eventid' => 'evt-1']));
    }

    public function test_credencial_ajena(): void {
        $body = $this->body('credential.issued', 'evt-2', '2026-10-08T10:05:00Z', '99999999-9999-9999-9999-999999999999');
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(issuance::OFFERED, $this->current()->status);
    }

    public function test_cambios_de_estado_posteriores(): void {
        $steps = [
            ['credential.issued', '2026-10-08T10:05:00Z', issuance::ISSUED],
            ['credential.suspended', '2026-10-08T11:00:00Z', issuance::SUSPENDED],
            ['credential.unsuspended', '2026-10-08T12:00:00Z', issuance::ISSUED],
            ['credential.revoked', '2026-10-08T13:00:00Z', issuance::REVOKED],
        ];
        foreach ($steps as $i => [$event, $timestamp, $expected]) {
            $body = $this->body($event, "evt-$i", $timestamp);
            $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
            $this->assertSame($expected, $this->current()->status, $event);
        }
    }

    public function test_evento_desordenado(): void {
        // Un revoked como los de Sovra: trae holder_did y status_at, no issued_at ni expires_at.
        $revoked = '{"event": "credential.revoked", "id": "evt-r", "timestamp": "2026-10-08T12:00:00.000000Z", ' .
            '"workspace_id": "ws", "data": {"credential_id": "' . self::CREDID . '", ' .
            '"holder_did": "did:sovra:0xabc", "schema_type": "VerifiableCredential", "status_at": "2026-10-08T12:00:00Z"}}';
        $this->assertSame(200, webhook::handle($revoked, hmac::sign($revoked, self::SECRET), self::SECRET));
        $current = $this->current();
        $this->assertSame(issuance::REVOKED, $current->status);
        $this->assertSame('did:sovra:0xabc', $current->holderdid);
        $this->assertEmpty($current->timeissued);

        // El issued demorado, con timestamp anterior, llega después.
        $issued = $this->body('credential.issued', 'evt-i', '2026-10-08T10:05:00Z');
        $this->assertSame(200, webhook::handle($issued, hmac::sign($issued, self::SECRET), self::SECRET));

        // Spec: «Emisión que llega después de la revocación».
        $current = $this->current();
        $this->assertSame(issuance::REVOKED, $current->status);
        $this->assertEquals(strtotime('2026-10-08T12:00:00Z'), $current->statustime);
        $this->assertEquals(strtotime('2026-10-08T10:04:59Z'), $current->timeissued);
        $this->assertEquals(strtotime('2027-10-08T10:04:59Z'), $current->credexpires);
    }

    public function test_fallo_al_aplicar_responde_500_y_libera_el_id(): void {
        global $DB;
        // Un holder_did más largo que la columna hace fallar la escritura dentro de la transacción.
        $body = str_replace('did:sovra:0xabc', 'did:sovra:' . str_repeat('a', 300), $this->body('credential.issued'));
        $this->assertSame(500, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertDebuggingCalled();
        $this->assertSame(issuance::OFFERED, $this->current()->status);
        $this->assertFalse($DB->record_exists('local_sovracred_webhook', ['eventid' => 'evt-1']));

        // El reintento de Sovra, ya sano, se procesa.
        $body = $this->body('credential.issued');
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(issuance::ISSUED, $this->current()->status);
    }

    public function test_reloj_de_moodle_adelantado_no_descarta_el_issued(): void {
        // Oferta recién creada (statustime = 0) y un evento cuya hora, en el reloj de Sovra, es anterior al de Moodle.
        issuance::update($this->issuance, ['statustime' => 0]);
        $body = $this->body('credential.issued', 'evt-1', gmdate('Y-m-d\TH:i:s\Z', time() - HOURSECS));
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(issuance::ISSUED, $this->current()->status);
    }

    public function test_unsuspended_antes_que_el_issued_tiene_fecha(): void {
        $body = $this->body('credential.unsuspended', 'evt-u', '2026-10-08T11:00:00Z');
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(issuance::ISSUED, $this->current()->status);
        $this->assertEquals(strtotime('2026-10-08T11:00:00Z'), $this->current()->timeissued);
    }

    public function test_sin_secreto_configurado(): void {
        $body = $this->body('credential.issued');
        $this->assertSame(503, webhook::handle($body, hmac::sign($body, self::SECRET), ''));
        $this->assertSame(issuance::OFFERED, $this->current()->status);
    }

    public function test_cuerpo_malformado_con_firma_valida(): void {
        $body = 'no es json';
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
    }
}
