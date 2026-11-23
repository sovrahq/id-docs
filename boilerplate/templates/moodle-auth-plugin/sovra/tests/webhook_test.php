<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Recepción del resultado por webhook» y la prueba de conexión.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webhook_test extends \advanced_testcase {
    use fixtures_trait;

    /** @var string */
    const SECRET = 'secreto-de-prueba';

    /** @var string */
    const VID = '33333333-3333-3333-3333-333333333333';

    /** @var string */
    const WORKSPACE = 'bfe85e0d-c2da-4ccd-bd99-d26827f7e45e';

    /** @var array */
    private array $history = [];

    /** @var \GuzzleHttp\Handler\MockHandler */
    private $mock;

    /** @var \stdClass */
    private \stdClass $user;

    protected function setUp(): void {
        global $SESSION;
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
        ['mock' => $this->mock] = $this->get_mocked_http_client($this->history);
        $SESSION->auth_sovra_tokens = [];
        $this->user = $this->create_person('10203045');
        $this->start_session($this->mock, self::VID);
    }

    /**
     * Cuerpo de una entrega, con el espaciado propio de Sovra (no el de json_encode).
     *
     * @param string $event
     * @param string $eventid
     * @param array $data
     * @return string
     */
    private function body(string $event, string $eventid, array $data): string {
        return '{"event": "' . $event . '", "id": "' . $eventid . '", "timestamp": "2026-11-19T12:00:00.000000Z", '
            . '"workspace_id": "' . self::WORKSPACE . '", "data": '
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '}';
    }

    /**
     * Datos de un presentation.verified.
     *
     * @param string $numdoc
     * @param string $vid
     * @return array
     */
    private function verified(string $numdoc, string $vid = self::VID): array {
        return [
            'verification_id' => $vid,
            'holder_did' => 'did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274',
            'success' => true,
            'completed_at' => '2026-11-19T12:00:00Z',
            'credentials' => [['id' => 'identidad', 'format' => 'vc+sd-jwt', 'claims' => $this->claims($numdoc)]],
        ];
    }

    /**
     * Estado de la sesión de ingreso.
     *
     * @return \stdClass
     */
    private function session(): \stdClass {
        global $DB;
        return $DB->get_record(login_session::TABLE, ['verificationid' => self::VID], '*', MUST_EXIST);
    }

    public function test_llega_el_webhook(): void {
        $body = $this->body('presentation.verified', 'e1', $this->verified('1.020.304-5'));
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $session = $this->session();
        $this->assertSame(login_session::RESOLVED, $session->status);
        $this->assertSame(resolver::OK, $session->result);
        $this->assertEquals($this->user->id, $session->userid);
    }

    public function test_firma_invalida(): void {
        global $DB;
        $body = $this->body('presentation.verified', 'e1', $this->verified('10203045'));
        // 503 y no 401: un 4xx haría que Sovra descarte el evento para siempre.
        $this->assertSame(503, webhook::handle($body, hmac::sign($body . ' ', self::SECRET), self::SECRET));
        $this->assertSame(503, webhook::handle($body, null, self::SECRET));
        $this->assertSame(login_session::PENDING, $this->session()->status);
        // Registrado, pero una sola vez por minuto.
        $this->assertSame(1, $DB->count_records(log::TABLE, ['kind' => log::WEBHOOK, 'result' => 'badsignature']));
    }

    public function test_sin_secreto(): void {
        global $DB;
        set_config('webhooksecret', '', 'auth_sovra');
        $body = $this->body('presentation.verified', 'e1', $this->verified('10203045'));
        $this->assertSame(503, webhook::handle($body, hmac::sign($body, self::SECRET), config::webhook_secret()));
        $this->assertSame(login_session::PENDING, $this->session()->status);
        $this->assertTrue($DB->record_exists(log::TABLE, ['kind' => log::WEBHOOK, 'httpstatus' => 503, 'result' => 'notconfigured']));
    }

    public function test_error_al_aplicar_da_500_con_el_evento(): void {
        global $CFG, $DB;
        // Una fábrica de locks rota: apply() falla. Sovra tiene que reintentar, y el registro decir qué evento era.
        $CFG->lock_factory = '\\auth_sovra\\no_existe';
        $body = $this->body('presentation.verified', 'e10', $this->verified('10203045'));
        $this->assertSame(500, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(login_session::PENDING, $this->session()->status);
        $log = $DB->get_record(log::TABLE, ['kind' => log::WEBHOOK, 'result' => 'exception'], '*', MUST_EXIST);
        $this->assertEquals(500, $log->httpstatus);
        $this->assertSame('presentation.verified', $log->action);
        $this->assertSame(self::VID, $log->verificationid);
        $this->assertSame('e10', $log->eventid);
    }

    public function test_presentacion_rechazada(): void {
        $body = $this->body('presentation.failed', 'e2', ['verification_id' => self::VID, 'holder_did' => null,
            'success' => false, 'error' => 'credential_revoked', 'completed_at' => '2026-11-19T12:00:00Z']);
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(login_session::FAILED, $this->session()->status);
        $this->assertSame('credential_revoked', $this->session()->result);
    }

    public function test_aviso_repetido(): void {
        global $DB;
        $body = $this->body('presentation.verified', 'e1', $this->verified('10203045'));
        $sig = hmac::sign($body, self::SECRET);
        $this->assertSame(200, webhook::handle($body, $sig, self::SECRET));
        $first = $this->session();
        $this->assertSame(200, webhook::handle($body, $sig, self::SECRET));
        $this->assertEquals($first, $this->session());
        $this->assertTrue($DB->record_exists(log::TABLE, ['kind' => log::WEBHOOK, 'result' => 'alreadysettled']));
        // Un solo veredicto en el registro.
        $this->assertSame(1, $DB->count_records(log::TABLE, ['kind' => log::LOGIN, 'verificationid' => self::VID]));
    }

    public function test_verificacion_ajena(): void {
        $body = $this->body('presentation.verified', 'e3', $this->verified('10203045', '44444444-4444-4444-4444-444444444444'));
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(login_session::PENDING, $this->session()->status);
    }

    public function test_otro_workspace(): void {
        set_config('workspaceid', '00000000-0000-0000-0000-000000000000', 'auth_sovra');
        $body = $this->body('presentation.verified', 'e4', $this->verified('10203045'));
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(login_session::PENDING, $this->session()->status);

        set_config('workspaceid', self::WORKSPACE, 'auth_sovra');
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(login_session::RESOLVED, $this->session()->status);
    }

    public function test_sin_workspace_id_con_workspace_configurado(): void {
        set_config('workspaceid', self::WORKSPACE, 'auth_sovra');
        $body = '{"event": "presentation.verified", "id": "e7", "data": '
            . json_encode($this->verified('10203045'), JSON_UNESCAPED_SLASHES) . '}';
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(login_session::PENDING, $this->session()->status);
    }

    public function test_variantes_de_la_firma(): void {
        $body = $this->body('presentation.verified', 'e8', $this->verified('10203045'));
        $hex = hash_hmac('sha256', $body, self::SECRET);
        $this->assertSame(503, webhook::handle($body, hmac::sign($body, 'otro-secreto'), self::SECRET));
        $this->assertSame(503, webhook::handle($body, $hex, self::SECRET), 'Sin el prefijo sha256=');
        $this->assertSame(503, webhook::handle($body, '', self::SECRET));
        $this->assertSame(login_session::PENDING, $this->session()->status);
        // Mayúsculas y espacios alrededor se aceptan.
        $this->assertSame(200, webhook::handle($body, '  SHA256=' . strtoupper($hex) . ' ', self::SECRET));
        $this->assertSame(login_session::RESOLVED, $this->session()->status);
    }

    public function test_secreto_que_no_se_puede_descifrar(): void {
        global $DB;
        // Como queda tras migrar el sitio con otra clave: cifrado, pero ilegible.
        set_config('webhooksecret', 'sodium:' . base64_encode(random_bytes(48)), 'auth_sovra');
        $this->assertSame('', config::webhook_secret());
        $this->assertSame(['webhooksecret'], config::undecryptable());
        $body = $this->body('presentation.verified', 'e9', $this->verified('10203045'));
        $this->assertSame(503, webhook::handle($body, hmac::sign($body, self::SECRET), config::webhook_secret()));
        $this->assertTrue($DB->record_exists(log::TABLE, ['kind' => log::WEBHOOK, 'result' => 'undecryptable']));
        // secret() avisa con debugging() cada vez que no puede descifrar; acá no es lo que se prueba.
        $this->resetDebugging();
    }

    public function test_otros_eventos_y_basura(): void {
        $body = $this->body('credential.issued', 'e5', ['credential_id' => 'x']);
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $body = 'no es json';
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(login_session::PENDING, $this->session()->status);
    }

    public function test_emisor_ajeno_por_webhook(): void {
        $data = $this->verified('10203045');
        $data['credentials'][0]['claims']['iss'] = 'https://test-api-sovra.flagonsa.com/' . self::$otherdid;
        $body = $this->body('presentation.verified', 'e6', $data);
        $this->assertSame(200, webhook::handle($body, hmac::sign($body, self::SECRET), self::SECRET));
        $this->assertSame(resolver::ISSUER_NOT_ALLOWED, $this->session()->result);
        $this->assertNull($this->session()->userid);
    }

    // Prueba de conexión.

    public function test_prueba_de_conexion(): void {
        $this->mock->append($this->verification_created('55555555-5555-5555-5555-555555555555', time() + 600));
        [$ok, $message] = connection_test::run();
        $this->assertTrue($ok);
        $this->assertStringContainsString('sovra_sk_…ueba', $message);
        $this->assertStringNotContainsString('sovra_sk_prueba', $message);

        $this->mock->append($this->json_response(401, ['error' => 'invalid_api_key']));
        [$ok, $message] = connection_test::run();
        $this->assertFalse($ok);
        $this->assertStringContainsString('invalid_api_key', $message);
    }

    public function test_prueba_de_conexion_avisa_la_clave_ilegible(): void {
        set_config('apikey', 'sodium:' . base64_encode(random_bytes(48)), 'auth_sovra');
        [$ok, $message] = connection_test::run();
        $this->assertFalse($ok);
        $this->assertSame(get_string('secret_undecryptable', 'auth_sovra', 'apikey'), $message);
        $this->assertCount(1, $this->history, 'Ni siquiera llama a la API');
        // secret() avisa con debugging() cada vez que no puede descifrar; acá no es lo que se prueba.
        $this->resetDebugging();
    }

    public function test_aviso_si_el_documento_es_editable(): void {
        $this->assertSame([], config::document_field_warnings());

        // ID number sin bloquear para email: alguien podría escribir el documento de otra persona.
        set_config('field_lock_idnumber', 'unlockedifempty', 'auth_email');
        $warnings = config::document_field_warnings();
        $this->assertSame([get_string('warn_idnumber_editable', 'auth_sovra', 'email')], $warnings);
        $this->mock->append($this->verification_created('55555555-5555-5555-5555-555555555555', time() + 600));
        [$ok, $message] = connection_test::run();
        $this->assertFalse($ok);
        $this->assertStringContainsString(s($warnings[0]), $message);

        // Un método deshabilitado no cuenta: con él no se entra.
        set_config('auth', 'sovra');
        $this->assertSame([], config::document_field_warnings());

        // Un campo de perfil sin bloquear.
        $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'abierto',
            'name' => 'Abierto', 'locked' => 0]);
        set_config('documentfield', 'profile_field_abierto', 'auth_sovra');
        $this->assertSame([get_string('warn_profilefield_editable', 'auth_sovra', 'abierto')], config::document_field_warnings());
    }

    public function test_boton_con_api_key_ilegible(): void {
        set_config('apikey', 'sodium:' . base64_encode(random_bytes(48)), 'auth_sovra');
        $this->assertFalse(config::ready());
        $this->assertSame([], get_auth_plugin('sovra')->loginpage_idp_list('/'));
        $this->resetDebugging();
    }

    public function test_boton_solo_con_configuracion_completa(): void {
        $auth = get_auth_plugin('sovra');
        $buttons = $auth->loginpage_idp_list('/');
        $this->assertCount(1, $buttons);
        // Con sesskey: un enlace de otro sitio no abre verificaciones.
        $this->assertSame(sesskey(), $buttons[0]['url']->get_param('sesskey'));
        foreach (['allowedissuers', 'apiurl', 'apikey'] as $name) {
            $saved = get_config('auth_sovra', $name);
            set_config($name, '', 'auth_sovra');
            $this->assertSame([], $auth->loginpage_idp_list('/'), $name);
            set_config($name, $saved, 'auth_sovra');
        }
        $this->assertCount(1, $this->history, 'Sin configuración completa no se llama a la API');
    }
}
