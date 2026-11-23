<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Solicitud de la credencial», «Página de ingreso con QR», «Recepción del resultado por consulta», «Sesión de ingreso atada al navegador»
 * y «Registro sin datos personales». También lo que contestan status.php (poll) y finish.php (finish).
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class login_session_test extends \advanced_testcase {
    use fixtures_trait;

    /** @var string */
    const VID = '22222222-2222-2222-2222-222222222222';

    /** @var string */
    const VID2 = '66666666-6666-6666-6666-666666666666';

    /** @var string */
    const VID3 = '77777777-7777-7777-7777-777777777777';

    /** @var string */
    const VID4 = '88888888-8888-8888-8888-888888888888';

    /** @var string DID del holder en las respuestas simuladas. */
    const HOLDER = 'did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274';

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

    /**
     * Presentación verificada con la credencial de ese documento.
     *
     * @param string $vid
     * @param string $numdoc
     * @return string|null Lo que devolvió apply().
     */
    private function verify(string $vid, string $numdoc): ?string {
        return login_session::apply($vid, 'completed', ['credentials' => [['id' => 'identidad',
            'claims' => $this->claims($numdoc)]]], null, 'webhook');
    }

    /**
     * El parámetro `result` de una URL de resultado.
     *
     * @param array $response Lo que devolvió poll().
     * @return string|null
     */
    private function result_of(array $response): ?string {
        return (new \moodle_url($response['url']))->get_param('result');
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
        // Aunque no entre, la verificación ya no sirve.
        $this->assertFalse($DB->record_exists(login_session::TABLE, ['id' => $record->id]));
        $this->assertNull(login_session::for_browser($record->token));
    }

    public function test_la_cuenta_se_vuelve_a_revisar_al_completar(): void {
        global $DB;
        $user = $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        login_session::apply(self::VID, 'completed', ['credentials' => [['id' => 'identidad',
            'claims' => $this->claims('10203045')]]], null, 'webhook');
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertSame([resolver::SUSPENDED, null], login_session::consume(login_session::for_browser($record->token)));
        // Reactivar la cuenta no habilita a reintentar con la misma verificación.
        $this->assertFalse($DB->record_exists(login_session::TABLE, ['id' => $record->id]));
        $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
        $this->assertSame(['generic', null], login_session::finish($record->token, true));
    }

    public function test_rechazada_no_se_consume(): void {
        global $DB;
        $record = $this->start_session($this->mock, self::VID);
        login_session::apply(self::VID, 'failed', null, 'credential_revoked', 'webhook');
        $this->assertSame(['notready', null], login_session::consume($record));
        $this->assertTrue($DB->record_exists(login_session::TABLE, ['id' => $record->id]));
    }

    public function test_tolerancia_de_reloj_al_vencer(): void {
        global $DB;
        $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        $DB->set_field(login_session::TABLE, 'timeexpires', time() - login_session::CLOCK_SKEW + 30, ['id' => $record->id]);
        $this->assertSame(resolver::OK, $this->verify(self::VID, '10203045'));
    }

    public function test_sin_expires_at_vence_a_los_10_minutos(): void {
        $this->mock->append($this->json_response(201, ['session_id' => self::VID, 'status' => 'pending',
            'authorization_request_uri' => 'openid4vp://?client_id=x&request=y']));
        $before = time();
        [$record, $uri] = login_session::start();
        $this->assertGreaterThanOrEqual($before + 10 * MINSECS, (int) $record->timeexpires);
        $this->assertLessThanOrEqual(time() + 10 * MINSECS, (int) $record->timeexpires);
        $this->assertSame('openid4vp://?client_id=x&request=y', $uri);
    }

    public function test_usa_nuestra_credencial_aunque_no_sea_la_primera(): void {
        global $DB;
        $user = $this->create_person('10203045');
        $this->start_session($this->mock, self::VID);
        $this->assertSame(resolver::OK, login_session::apply(self::VID, 'completed', ['credentials' => [
            ['id' => 'otra', 'claims' => $this->claims('99999999')],
            ['id' => 'identidad', 'claims' => $this->claims('10203045')],
        ]], null, 'webhook'));
        $this->assertEquals($user->id, $DB->get_field(login_session::TABLE, 'userid', ['verificationid' => self::VID]));
    }

    public function test_completada_sin_datos_no_entra(): void {
        $this->create_person('10203045');
        $this->start_session($this->mock, self::VID);
        $this->assertSame(resolver::ISSUER_MISSING, login_session::apply(self::VID, 'completed', null, null, 'webhook'));
    }

    // Abrir verificaciones: reutilizar el código vigente y tope por navegador.

    public function test_recargar_muestra_el_mismo_codigo(): void {
        global $DB;
        $this->mock->append($this->verification_created(self::VID, time() + 600));
        [$record, $uri] = login_session::start();
        [$again, $againuri] = login_session::reusable();
        $this->assertEquals($record->id, $again->id);
        $this->assertSame($uri, $againuri);
        $this->assertCount(1, $this->history, 'Reutilizar no llama a Sovra');

        // Con poca vigencia, o ya resuelta, no se reutiliza.
        $DB->set_field(login_session::TABLE, 'timeexpires', time() + login_session::REUSE_MIN_LEFT - 1, ['id' => $record->id]);
        $this->assertNull(login_session::reusable());
        $DB->set_field(login_session::TABLE, 'timeexpires', time() + 600, ['id' => $record->id]);
        $DB->set_field(login_session::TABLE, 'status', login_session::FAILED, ['id' => $record->id]);
        $this->assertNull(login_session::reusable());
    }

    public function test_otro_navegador_no_reutiliza(): void {
        global $SESSION;
        $this->start_session($this->mock, self::VID);
        $SESSION->auth_sovra_tokens = [];
        $this->assertNull(login_session::reusable());
    }

    public function test_tope_de_codigos_nuevos(): void {
        global $SESSION;
        for ($i = 0; $i < login_session::MAX_STARTS; $i++) {
            $this->assertTrue(login_session::can_start());
            $this->start_session($this->mock, sprintf('00000000-0000-0000-0000-%012d', $i));
        }
        $this->assertFalse(login_session::can_start());
        // Pasada la ventana, vuelve a poder.
        $SESSION->auth_sovra_starts = array_map(fn($time) => $time - login_session::START_WINDOW, $SESSION->auth_sovra_starts);
        $this->assertTrue(login_session::can_start());
    }

    public function test_el_tope_no_cuenta_las_caidas_de_sovra(): void {
        global $SESSION;
        // Sovra caído (5xx o red): la persona tiene que ver el error real, no «pediste muchos códigos».
        $this->mock->append($this->json_response(503, ['error' => 'unavailable']));
        $this->assertSame(['action' => 'error'], login_session::open(true, true, true));
        $this->assertCount(0, $SESSION->auth_sovra_starts);
        // Un 4xx sí lo atendió la API: cuenta.
        $this->mock->append($this->json_response(400, ['error' => 'invalid_dcql_query']));
        $this->assertSame(['action' => 'error'], login_session::open(true, true, true));
        $this->assertCount(1, $SESSION->auth_sovra_starts);
    }

    public function test_session_id_demasiado_largo(): void {
        global $DB;
        $this->mock->append($this->verification_created(self::VID . '-extra', time() + 600));
        try {
            login_session::start();
            $this->fail('Tenía que fallar');
        } catch (api_exception $e) {
            $this->assertSame('invalidresponse', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(login_session::TABLE));
    }

    // Lo que decide login.php: nada que no sea mostrar un código llama a Sovra.

    public function test_open_no_llama_a_la_api_si_no_corresponde(): void {
        global $DB;
        $this->assertSame(['action' => 'unavailable'], login_session::open(false, true, false));
        $this->assertSame(['action' => 'ask'], login_session::open(true, false, false));
        $this->assertCount(0, $this->history);

        for ($i = 0; $i < login_session::MAX_STARTS; $i++) {
            $this->mock->append($this->verification_created(sprintf('00000000-0000-0000-0000-%012d', $i), time() + 600));
            $this->assertSame('show', login_session::open(true, true, true)['action']);
        }
        $this->assertSame(['action' => 'throttled'], login_session::open(true, true, true));
        $this->assertSame(['action' => 'throttled'], login_session::open(true, true, true));
        $this->assertCount(login_session::MAX_STARTS, $this->history);
        // Registrado, como mucho una vez por minuto.
        $this->assertSame(1, $DB->count_records(log::TABLE, ['kind' => log::LOGIN, 'result' => 'throttled']));
    }

    public function test_open_reutiliza_y_new_pide_otro(): void {
        $this->mock->append($this->verification_created(self::VID, time() + 600));
        $first = login_session::open(true, true, false);
        $this->assertSame('show', $first['action']);
        $again = login_session::open(true, true, false);
        $this->assertEquals($first['session']->id, $again['session']->id);
        $this->assertSame($first['uri'], $again['uri']);
        $this->assertCount(1, $this->history, 'Recargar no llama a Sovra');

        $this->mock->append($this->verification_created(self::VID2, time() + 600));
        $other = login_session::open(true, true, true);
        $this->assertSame(self::VID2, $other['session']->verificationid);
        $this->assertCount(2, $this->history);
    }

    public function test_un_navegador_recuerda_hasta_max_tokens(): void {
        global $SESSION;
        $first = $this->start_session($this->mock, '00000000-0000-0000-0000-000000000000');
        for ($i = 1; $i <= login_session::MAX_TOKENS; $i++) {
            $this->start_session($this->mock, sprintf('00000000-0000-0000-0000-%012d', $i));
        }
        $this->assertNull(login_session::for_browser($first->token));
        $this->assertCount(login_session::MAX_TOKENS, $SESSION->auth_sovra_uris);
    }

    // Lo que contesta status.php.

    public function test_poll_sin_sesskey_o_de_otro_navegador(): void {
        global $DB, $SESSION;
        $record = $this->start_session($this->mock, self::VID);
        $gone = ['state' => 'gone', 'url' => (new \moodle_url('/auth/sovra/login.php', ['result' => 'generic']))->out(false)];
        $this->assertSame($gone, login_session::poll($record->token, false));
        $SESSION->auth_sovra_tokens = [];
        $this->assertSame($gone, login_session::poll($record->token, true));
        // Se registra, pero como mucho una vez por minuto.
        $this->assertSame(1, $DB->count_records(log::TABLE, ['kind' => log::LOGIN, 'result' => 'badsession']));
    }

    public function test_poll_segun_el_estado(): void {
        $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        $this->assertSame(['state' => 'pending'], login_session::poll($record->token, true));

        $this->verify(self::VID, '10203045');
        $response = login_session::poll($record->token, true);
        $this->assertSame('ok', $response['state']);
        $this->assertStringContainsString('/auth/sovra/finish.php', $response['url']);
        $url = new \moodle_url($response['url']);
        $this->assertSame($record->token, $url->get_param('token'));
        $this->assertSame(sesskey(), $url->get_param('sesskey'));

        // Sin cuenta: el código va tal cual, para el aviso «No tienes cuenta en …».
        $other = $this->start_session($this->mock, self::VID2);
        $this->verify(self::VID2, '99999999');
        $response = login_session::poll($other->token, true);
        $this->assertSame('done', $response['state']);
        $this->assertSame(resolver::NOT_FOUND, $this->result_of($response));
    }

    public function test_poll_no_muestra_el_error_de_sovra(): void {
        $record = $this->start_session($this->mock, self::VID);
        login_session::apply(self::VID, 'failed', null, 'holder_key_mismatch', 'webhook');
        $this->assertSame('failed', $this->result_of(login_session::poll($record->token, true)));
    }

    public function test_poll_vencida(): void {
        global $DB;
        $record = $this->start_session($this->mock, self::VID);
        $DB->set_field(login_session::TABLE, 'timeexpires', time() - 2 * MINSECS, ['id' => $record->id]);
        $this->assertSame(['state' => 'expired'], login_session::poll($record->token, true));
    }

    public function test_la_api_no_deja_seguir_403_404(): void {
        foreach ([403 => self::VID, 404 => self::VID2] as $status => $vid) {
            $record = $this->start_session($this->mock, $vid);
            $this->mock->append($this->json_response($status, ['error' => 'x']));
            $record = login_session::refresh($this->age($record));
            $this->assertSame(login_session::FAILED, $record->status, (string) $status);
            $this->assertSame(login_session::UNAVAILABLE, $record->result, (string) $status);
        }
    }

    public function test_sin_api_key_cierra_el_intento(): void {
        global $DB;
        $record = $this->start_session($this->mock, self::VID);
        set_config('apikey', '', 'auth_sovra');
        $record = login_session::refresh($this->age($record));
        $this->assertSame(login_session::UNAVAILABLE, $record->result);
        $log = $DB->get_record(log::TABLE, ['kind' => log::LOGIN, 'verificationid' => self::VID], '*', MUST_EXIST);
        $this->assertStringContainsString('notconfigured', $log->detail);
    }

    public function test_red_caida_se_reintenta(): void {
        $record = $this->start_session($this->mock, self::VID);
        $this->mock->append(new \GuzzleHttp\Exception\ConnectException('sin red',
            new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.test')));
        $this->assertSame(login_session::PENDING, login_session::refresh($this->age($record))->status);
    }

    public function test_error_inesperado_en_la_consulta_queda_registrado(): void {
        global $CFG, $DB;
        $record = $this->start_session($this->mock, self::VID);
        $this->mock->append($this->verification_state(self::VID, 'completed', $this->claims('10203045')));
        // Una fábrica de locks rota: settle() falla con una excepción que no es de la API.
        $CFG->lock_factory = '\\auth_sovra\\no_existe';
        $record = login_session::refresh($this->age($record));
        // No lanza (status.php no tiene a quién pasársela) y el administrador ve la causa.
        $this->assertSame(login_session::PENDING, $record->status);
        $log = $DB->get_record(log::TABLE, ['kind' => log::LOGIN, 'result' => 'exception'], '*', MUST_EXIST);
        $this->assertSame(self::VID, $log->verificationid);
        $this->assertStringContainsString('coding_exception', $log->detail);
    }

    public function test_api_key_revocada_cierra_el_intento(): void {
        $record = $this->start_session($this->mock, self::VID);
        $this->mock->append($this->json_response(401, ['error' => 'invalid_api_key']));
        $record = login_session::refresh($this->age($record));
        $this->assertSame(login_session::FAILED, $record->status);
        $this->assertSame(login_session::UNAVAILABLE, $record->result);
        $this->assertSame(login_session::UNAVAILABLE, $this->result_of(login_session::poll($record->token, true)));
    }

    // Lo que hace finish.php.

    public function test_finish_entra_y_lo_registra(): void {
        global $DB;
        $user = $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        $this->verify(self::VID, '10203045');
        [$result, $account] = login_session::finish($record->token, true);
        $this->assertSame(resolver::OK, $result);
        $this->assertEquals($user->id, $account->id);
        $log = $DB->get_record(log::TABLE, ['kind' => log::LOGIN, 'action' => 'finish'], '*', MUST_EXIST);
        $this->assertSame(resolver::OK, $log->result);
        $this->assertEquals($user->id, $log->userid);
    }

    public function test_finish_exige_sesskey_y_el_mismo_navegador(): void {
        global $DB, $SESSION;
        $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        $this->verify(self::VID, '10203045');

        $this->assertSame(['generic', null], login_session::finish($record->token, false));
        $tokens = $SESSION->auth_sovra_tokens;
        $SESSION->auth_sovra_tokens = [];
        $this->assertSame(['generic', null], login_session::finish($record->token, true));
        $this->assertTrue($DB->record_exists(login_session::TABLE, ['id' => $record->id]), 'Ninguno de los dos la consume');

        $SESSION->auth_sovra_tokens = $tokens;
        $this->assertSame(resolver::OK, login_session::finish($record->token, true)[0]);
    }

    public function test_finish_pendiente_es_generico(): void {
        global $DB;
        $record = $this->start_session($this->mock, self::VID);
        $this->assertSame(['generic', null], login_session::finish($record->token, true));
        $this->assertTrue($DB->record_exists(login_session::TABLE, ['id' => $record->id]));
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
        // Y el registro tiene un solo veredicto.
        $this->assertSame(1, $DB->count_records(log::TABLE, ['kind' => log::LOGIN, 'verificationid' => self::VID]));
    }

    public function test_un_objeto_viejo_no_pisa_el_veredicto(): void {
        global $DB;
        // refresh() llama a settle() con el objeto que recibe: la relectura dentro del lock es la que protege.
        $this->create_person('10203045');
        $stale = $this->age($this->start_session($this->mock, self::VID));
        $this->assertSame(resolver::OK, $this->verify(self::VID, '10203045'));

        $this->mock->append($this->json_response(401, ['error' => 'invalid_api_key']));
        login_session::refresh($stale);
        $stale->timeexpires = time() - 2 * MINSECS;
        login_session::refresh($stale);

        $session = $DB->get_record(login_session::TABLE, ['verificationid' => self::VID]);
        $this->assertSame(login_session::RESOLVED, $session->status);
        $this->assertSame(resolver::OK, $session->result);
        $this->assertSame(1, $DB->count_records(log::TABLE, ['kind' => log::LOGIN, 'verificationid' => self::VID]));
    }

    public function test_lock_ocupado(): void {
        global $CFG, $DB;
        // Con locks por archivo, otro lock sobre el mismo recurso choca aunque sea el mismo proceso.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $this->create_person('10203045');
        $record = $this->start_session($this->mock, self::VID);
        $held = \core\lock\lock_config::get_lock_factory('auth_sovra')->get_lock('session_' . $record->id, 0);
        $this->assertNotFalse($held);
        try {
            $this->verify(self::VID, '10203045');
            $this->fail('Tenía que lanzar lock_exception');
        } catch (lock_exception $e) {
            $this->assertSame(login_session::PENDING, $DB->get_field(login_session::TABLE, 'status', ['id' => $record->id]));
            $this->assertTrue($DB->record_exists(log::TABLE, ['kind' => log::LOGIN, 'result' => 'lockfailed']));
        } finally {
            $held->release();
        }
        // Suelto el lock, el siguiente aviso lo aplica.
        $this->assertSame(resolver::OK, $this->verify(self::VID, '10203045'));
    }

    public function test_detalle_de_una_credencial_rechazada(): void {
        global $DB;
        $this->create_person('10203045');
        $this->start_session($this->mock, self::VID);
        login_session::apply(self::VID, 'completed', ['credentials' => [['id' => 'identidad', 'claims' =>
            $this->claims('10203045', ['iss' => 'https://test-api-sovra.flagonsa.com/' . self::$otherdid])]]], null, 'webhook');
        $log = $DB->get_record(log::TABLE, ['kind' => log::LOGIN, 'verificationid' => self::VID], '*', MUST_EXIST);
        $this->assertSame(resolver::ISSUER_NOT_ALLOWED, $log->result);
        $this->assertSame('iss=' . self::$otherdid . ' vct=IdentidadMigrante claims=iss,vct,tipo_doc,num_doc', $log->detail);
    }

    public function test_al_completar_se_revisa_la_cuenta_de_nuevo(): void {
        global $DB;
        $cases = [
            'borrada' => [fn($user) => delete_user($user), resolver::NOT_FOUND],
            'nologin' => [fn($user) => $DB->set_field('user', 'auth', 'nologin', ['id' => $user->id]), resolver::AUTH_NOT_ALLOWED],
            'deshabilitado' => [fn($user) => set_config('auth', 'sovra'), resolver::AUTH_NOT_ALLOWED],
            'admin' => [fn($user) => set_config('siteadmins', get_admin()->id . ',' . $user->id), resolver::ADMIN],
        ];
        $i = 0;
        foreach ($cases as $name => [$change, $expected]) {
            $this->configure_plugin();
            $user = $this->create_person('5000000' . $i, ['auth' => 'email']);
            $vid = sprintf('99999999-0000-0000-0000-%012d', $i++);
            $record = $this->start_session($this->mock, $vid);
            $this->assertSame(resolver::OK, $this->verify($vid, $user->idnumber), $name);
            $change($user);
            $this->assertSame([$expected, null], login_session::consume(login_session::for_browser($record->token)), $name);
        }
    }

    public function test_get_informa_vencida(): void {
        $record = $this->start_session($this->mock, self::VID);
        $this->mock->append($this->verification_state(self::VID, 'expired'));
        $this->assertSame(login_session::EXPIRED, login_session::refresh($this->age($record))->status);
    }

    // Registro sin datos personales.

    public function test_ninguna_tabla_guarda_el_documento(): void {
        global $DB;
        set_config('matchdoctype', 1, 'auth_sovra');
        set_config('doctypemap', 'Cédula de ciudadanía = CC', 'auth_sovra');
        $this->use_profile_fields();
        $this->create_person_profile('10203045', 'CC');
        $claims = $this->claims('10.203.045');

        // Por consulta.
        $record = $this->start_session($this->mock, self::VID);
        $this->mock->append($this->verification_state(self::VID, 'completed', $claims));
        login_session::refresh($this->age($record));

        // Por webhook: una verificada, un rechazo y un emisor ajeno.
        $ajeno = $this->claims('10203045', ['iss' => 'https://test-api-sovra.flagonsa.com/' . self::$otherdid]);
        foreach ([[self::VID2, 'presentation.verified', $claims], [self::VID3, 'presentation.failed', null],
                [self::VID4, 'presentation.verified', $ajeno]] as [$vid, $event, $presented]) {
            $this->start_session($this->mock, $vid);
            $data = ['verification_id' => $vid, 'holder_did' => self::HOLDER, 'error' => $presented ? null : 'credential_revoked'];
            if ($presented) {
                $data['credentials'] = [['id' => 'identidad', 'format' => 'vc+sd-jwt', 'claims' => $presented]];
            }
            $body = json_encode(['event' => $event, 'id' => 'e-' . $vid, 'workspace_id' => 'w', 'data' => $data]);
            $this->assertSame(200, webhook::handle($body, hmac::sign($body, 'secreto-de-prueba'), 'secreto-de-prueba'));
        }

        // Sin escapar: con \u00e9 en el volcado, buscar «Cédula» no probaría nada.
        $dump = json_encode([$DB->get_records(login_session::TABLE), $DB->get_records(log::TABLE)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // El DID del emisor sí puede aparecer (es del workspace, no del ciudadano): en el detalle del rechazo.
        foreach (['10203045', '10.203.045', "C\u{00E9}dula", "Ce\u{0301}dula", 'ciudadan', self::HOLDER] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertSame(2, $DB->count_records(log::TABLE, ['kind' => log::LOGIN, 'result' => resolver::OK]));
        $this->assertTrue($DB->record_exists(log::TABLE, ['kind' => log::LOGIN, 'result' => resolver::ISSUER_NOT_ALLOWED]));
    }

    public function test_limpieza(): void {
        global $DB;
        $old = $this->start_session($this->mock, self::VID);
        $recent = $this->start_session($this->mock, self::VID2);
        $current = $this->start_session($this->mock, self::VID3);
        $DB->set_field(login_session::TABLE, 'timeexpires', time() - 2 * HOURSECS, ['id' => $old->id]);
        $DB->set_field(login_session::TABLE, 'timeexpires', time() - 30 * MINSECS, ['id' => $recent->id]);

        $DB->delete_records(log::TABLE);
        foreach (['viejo' => 31, 'nuevo' => 29] as $result => $days) {
            $DB->insert_record(log::TABLE, (object) ['kind' => log::API, 'action' => 'GET /x', 'httpstatus' => 200,
                'result' => $result, 'timecreated' => time() - $days * DAYSECS]);
        }

        (new task\cleanup())->execute();
        $this->assertFalse($DB->record_exists(login_session::TABLE, ['id' => $old->id]));
        $this->assertTrue($DB->record_exists(login_session::TABLE, ['id' => $recent->id]), 'Vencida hace menos de una hora');
        $this->assertTrue($DB->record_exists(login_session::TABLE, ['id' => $current->id]));
        $this->assertSame(['nuevo'], array_values($DB->get_fieldset_select(log::TABLE, 'result', '1 = 1')));
    }
}
