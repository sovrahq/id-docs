<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Solicitud de la credencial», «Página de ingreso con QR», «Recepción del resultado por consulta», «Sesión de ingreso atada al navegador»
 * y «Registro sin datos personales».
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class login_session_test extends \advanced_testcase {
    use fixtures_trait;

    /** @var string */
    const VID = '22222222-2222-2222-2222-222222222222';

    /** @var array */
    private array $history = [];

    /** @var \GuzzleHttp\Handler\MockHandler */
    private $mock;

    protected function setUp(): void {
        global $SESSION;
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
        ['mock' => $this->mock] = $this->get_mocked_http_client($this->history);
        $SESSION->auth_sovra_tokens = [];
    }

    /**
     * Vuelve la sesión «vieja» para que la próxima consulta vaya a Sovra.
     *
     * @param \stdClass $record
     * @return \stdClass
     */
    private function age(\stdClass $record): \stdClass {
        global $DB;
        $DB->set_field(login_session::TABLE, 'timelastpoll', time() - 10, ['id' => $record->id]);
        return $DB->get_record(login_session::TABLE, ['id' => $record->id]);
    }

    // Solicitud de la credencial.

    public function test_la_consulta_pide_solo_emisor_tipo_y_documento(): void {
        $this->mock->append($this->verification_created(self::VID, time() + 600));
        [$record, $uri] = login_session::start();

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.example.test/api/v1/verifier/verifications', (string) $request->getUri());
        $this->assertSame('Bearer sovra_sk_prueba', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame(['credentials' => [[
            'id' => 'identidad',
            'format' => 'vc+sd-jwt',
            'claims' => [['path' => ['iss']], ['path' => ['vct']], ['path' => ['num_doc']]],
            'meta' => ['vct_values' => ['IdentidadMigrante']],
        ]]], $body['dcql_query']);

        // Por referencia, para que el QR quede corto.
        $this->assertStringContainsString('request_uri=', $uri);
        $this->assertSame(self::VID, $record->verificationid);
        $this->assertSame(login_session::PENDING, $record->status);
        $this->assertSame(64, strlen($record->token));
    }

    public function test_con_tipo_tambien_pide_el_tipo(): void {
        set_config('matchdoctype', 1, 'auth_sovra');
        set_config('documenttypefield', 'profile_field_tipodoc', 'auth_sovra');
        set_config('credentialtypes', '', 'auth_sovra');
        $dcql = login_session::dcql();
        $this->assertSame([['path' => ['iss']], ['path' => ['vct']], ['path' => ['tipo_doc']], ['path' => ['num_doc']]],
            $dcql['credentials'][0]['claims']);
        $this->assertArrayNotHasKey('meta', $dcql['credentials'][0]);
    }

    public function test_la_api_falla(): void {
        global $DB;
        $this->mock->append($this->json_response(401, ['error' => 'invalid_api_key']));
        try {
            login_session::start();
            $this->fail('Tenía que fallar');
        } catch (api_exception $e) {
            $this->assertSame('invalid_api_key', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(login_session::TABLE));
        $log = $DB->get_record(log::TABLE, ['kind' => log::API]);
        $this->assertSame('invalid_api_key', $log->result);
        $this->assertEquals(401, $log->httpstatus);
        $this->assertStringNotContainsString('sovra_sk_prueba', json_encode($log));
    }

    // Sesión atada al navegador.

    public function test_otro_navegador_no_la_ve(): void {
        global $SESSION;
        $record = $this->start_session($this->mock, self::VID);
        $this->assertNotNull(login_session::for_browser($record->token));

        // Otro navegador: otra $SESSION, sin el token.
        $SESSION->auth_sovra_tokens = [];
        $this->assertNull(login_session::for_browser($record->token));
        $this->assertNull(login_session::for_browser(''));
    }

    public function test_un_solo_uso(): void {
        $user = $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        login_session::apply(self::VID, 'completed', ['credentials' => [['id' => 'identidad',
            'claims' => $this->claims('10203045')]]], null, 'webhook');

        $record = login_session::for_browser($record->token);
        [$result, $account] = login_session::consume($record);
        $this->assertSame(resolver::OK, $result);
        $this->assertEquals($user->id, $account->id);

        // La segunda vez ya no existe para este navegador, ni en la base.
        $this->assertNull(login_session::for_browser($record->token));
        $this->assertSame(['gone', null], login_session::consume($record));
    }

    public function test_pendiente_no_se_consume(): void {
        $record = $this->start_session($this->mock, self::VID);
        $this->assertSame(['notready', null], login_session::consume($record));
    }

    public function test_vencida_sin_presentacion(): void {
        global $DB;
        $record = $this->start_session($this->mock, self::VID);
        $DB->set_field(login_session::TABLE, 'timeexpires', time() - 2 * MINSECS, ['id' => $record->id]);
        $record = login_session::refresh($DB->get_record(login_session::TABLE, ['id' => $record->id]));
        $this->assertSame(login_session::EXPIRED, $record->status);
        $this->assertCount(1, $this->history, 'Vencida no se le pregunta a Sovra');
    }

    public function test_resultado_tardio_no_deja_entrar(): void {
        global $DB;
        $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        $DB->set_field(login_session::TABLE, 'timeexpires', time() - 2 * MINSECS, ['id' => $record->id]);
        $result = login_session::apply(self::VID, 'completed', ['credentials' => [['id' => 'identidad',
            'claims' => $this->claims('10203045')]]], null, 'webhook');
        $this->assertSame(login_session::EXPIRED, $result);
        $this->assertSame(['notready', null], login_session::consume(login_session::for_browser($record->token)));
    }

    public function test_fuera_de_la_ventana_para_completar(): void {
        global $DB;
        $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        login_session::apply(self::VID, 'completed', ['credentials' => [['id' => 'identidad',
            'claims' => $this->claims('10203045')]]], null, 'webhook');
        $DB->set_field(login_session::TABLE, 'timecompleted', time() - login_session::FINISH_WINDOW - 1, ['id' => $record->id]);
        [$result, $user] = login_session::consume(login_session::for_browser($record->token));
        $this->assertSame(login_session::EXPIRED, $result);
        $this->assertNull($user);
    }

    public function test_la_cuenta_se_vuelve_a_revisar_al_completar(): void {
        global $DB;
        $user = $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        login_session::apply(self::VID, 'completed', ['credentials' => [['id' => 'identidad',
            'claims' => $this->claims('10203045')]]], null, 'webhook');
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertSame([resolver::SUSPENDED, null], login_session::consume(login_session::for_browser($record->token)));
    }

    // Resultado por GET (sin webhook).

    public function test_consulta_por_get_con_freno(): void {
        $user = $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);

        // Recién creada: todavía no se pregunta.
        $record = login_session::refresh($record);
        $this->assertCount(1, $this->history);

        $this->mock->append($this->verification_state(self::VID, 'pending'));
        $record = login_session::refresh($this->age($record));
        $this->assertCount(2, $this->history);
        $this->assertSame('GET', $this->history[1]['request']->getMethod());
        $this->assertStringEndsWith('/api/v1/verifier/verifications/' . self::VID, (string) $this->history[1]['request']->getUri());
        $this->assertSame(login_session::PENDING, $record->status);

        $this->mock->append($this->verification_state(self::VID, 'completed', $this->claims('10.203.045')));
        $record = login_session::refresh($this->age($record));
        $this->assertSame(login_session::RESOLVED, $record->status);
        $this->assertSame(resolver::OK, $record->result);
        $this->assertEquals($user->id, $record->userid);
    }

    public function test_get_informa_rechazo(): void {
        $record = $this->start_session($this->mock, self::VID);
        $this->mock->append($this->verification_state(self::VID, 'failed', null, 'credential_revoked'));
        $record = login_session::refresh($this->age($record));
        $this->assertSame(login_session::FAILED, $record->status);
        $this->assertSame('credential_revoked', $record->result);
    }

    public function test_get_falla_y_se_reintenta(): void {
        $record = $this->start_session($this->mock, self::VID);
        $this->mock->append($this->json_response(500, ['error' => 'internal']));
        $record = login_session::refresh($this->age($record));
        $this->assertSame(login_session::PENDING, $record->status);
    }

    public function test_el_segundo_resultado_no_cambia_el_primero(): void {
        $this->create_person('10203045');
        $this->start_session($this->mock, self::VID);
        $this->assertSame(resolver::OK, login_session::apply(self::VID, 'completed', ['credentials' => [
            ['id' => 'identidad', 'claims' => $this->claims('10203045')]]], null, 'webhook'));
        $this->assertNull(login_session::apply(self::VID, 'failed', null, 'credential_revoked', 'poll'));
        $this->assertNull(login_session::apply(self::VID, 'completed', ['credentials' => [
            ['id' => 'identidad', 'claims' => $this->claims('99999999')]]], null, 'poll'));
        global $DB;
        $this->assertSame(resolver::OK, $DB->get_field(login_session::TABLE, 'result', ['verificationid' => self::VID]));
    }

    // Registro sin datos personales.

    public function test_ninguna_tabla_guarda_el_documento(): void {
        global $DB;
        $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        $this->mock->append($this->verification_state(self::VID, 'completed', $this->claims('10.203.045')));
        login_session::refresh($this->age($record));
        login_session::apply(self::VID, 'completed', ['credentials' => [['id' => 'identidad',
            'claims' => $this->claims('10.203.045')]]], null, 'webhook');

        $dump = json_encode([$DB->get_records(login_session::TABLE), $DB->get_records(log::TABLE)]);
        foreach (['10203045', '10.203.045', 'Cédula', 'Cédula', 'Cédula', self::$issuerdid,
                'did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertTrue($DB->record_exists(log::TABLE, ['kind' => log::LOGIN, 'result' => resolver::OK]));
    }

    public function test_limpieza(): void {
        global $DB;
        $record = $this->start_session($this->mock, self::VID);
        $DB->set_field(login_session::TABLE, 'timeexpires', time() - 2 * HOURSECS, ['id' => $record->id]);
        (new task\cleanup())->execute();
        $this->assertSame(0, $DB->count_records(login_session::TABLE));
    }
}
