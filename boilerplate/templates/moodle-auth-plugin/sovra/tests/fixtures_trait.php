<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * Datos de prueba compartidos. Deterministas: documentos y DIDs fijos, nada aleatorio.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait fixtures_trait {

    /** @var string DID del emisor permitido (la Alcaldía, en las pruebas). */
    protected static string $issuerdid = 'did:sovra:0xa40c8c14f6ab6af87d28c3553550cf994f94e153';

    /** @var string DID de otro emisor de la red. */
    protected static string $otherdid = 'did:sovra:0x65088df76fd9231c772f9e60de919ed04a5ea5fc';

    /**
     * Configura el plugin contra una API de mentira y lo habilita.
     *
     * @return void
     */
    protected function configure_plugin(): void {
        set_config('apiurl', 'https://api.example.test', 'auth_sovra');
        set_config('apikey', 'sovra_sk_prueba', 'auth_sovra');
        set_config('webhooksecret', 'secreto-de-prueba', 'auth_sovra');
        set_config('allowedissuers', self::$issuerdid, 'auth_sovra');
        set_config('credentialtypes', 'IdentidadMigrante', 'auth_sovra');
        set_config('claimdocnumber', 'num_doc', 'auth_sovra');
        set_config('claimdoctype', 'tipo_doc', 'auth_sovra');
        set_config('documentfield', 'idnumber', 'auth_sovra');
        set_config('matchdoctype', 0, 'auth_sovra');
        set_config('allowedauths', config::DEFAULT_AUTHS, 'auth_sovra');
        set_config('allowadmins', 0, 'auth_sovra');
        set_config('auth', 'email,sovra');
    }

    /**
     * Activa el documento en un campo de perfil, con tipo.
     *
     * @return void
     */
    protected function use_profile_fields(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        if (!$DB->record_exists('user_info_field', ['shortname' => 'documento'])) {
            $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'documento', 'name' => 'Documento']);
            $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'tipodoc', 'name' => 'Tipo de documento']);
        }
        set_config('documentfield', 'profile_field_documento', 'auth_sovra');
        set_config('documenttypefield', 'profile_field_tipodoc', 'auth_sovra');
    }

    /**
     * Una cuenta con documento en idnumber.
     *
     * @param string $idnumber
     * @param array $extra
     * @return \stdClass
     */
    protected function create_person(string $idnumber, array $extra = []): \stdClass {
        return $this->getDataGenerator()->create_user($extra + ['idnumber' => $idnumber, 'auth' => 'manual',
            'firstname' => 'Ada', 'lastname' => 'Lovelace']);
    }

    /**
     * Una cuenta con documento y tipo en campos de perfil.
     *
     * @param string $documento
     * @param string $tipo
     * @return \stdClass
     */
    protected function create_person_profile(string $documento, string $tipo): \stdClass {
        return $this->getDataGenerator()->create_user(['auth' => 'manual',
            'profile_field_documento' => $documento, 'profile_field_tipodoc' => $tipo]);
    }

    /**
     * Claims de una presentación de la Credencial de Identidad.
     *
     * @param string $numdoc
     * @param array $overrides
     * @return array
     */
    protected function claims(string $numdoc, array $overrides = []): array {
        return $overrides + [
            'iss' => 'https://test-api-sovra.flagonsa.com/' . self::$issuerdid,
            'vct' => 'IdentidadMigrante',
            'tipo_doc' => 'Cédula de ciudadanía',
            'num_doc' => $numdoc,
        ];
    }

    /**
     * Respuesta JSON para la cola del cliente HTTP simulado.
     *
     * @param int $status
     * @param array $body
     * @return \GuzzleHttp\Psr7\Response
     */
    protected function json_response(int $status, array $body): \GuzzleHttp\Psr7\Response {
        return new \GuzzleHttp\Psr7\Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * Respuesta de POST /verifier/verifications.
     *
     * @param string $id
     * @param int $expires
     * @return \GuzzleHttp\Psr7\Response
     */
    protected function verification_created(string $id, int $expires): \GuzzleHttp\Psr7\Response {
        return $this->json_response(201, [
            'session_id' => $id,
            'status' => 'pending',
            'authorization_request_uri' => 'openid4vp://?client_id=x&request=y',
            'authorization_request_uri_ref' => 'openid4vp://?client_id=x&request_uri=https%3A%2F%2Fapi.example.test%2Fr%2F' . $id,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
        ]);
    }

    /**
     * Respuesta de GET /verifier/verifications/{id}.
     *
     * @param string $id
     * @param string $status
     * @param array|null $claims
     * @param string|null $error
     * @return \GuzzleHttp\Psr7\Response
     */
    protected function verification_state(string $id, string $status, ?array $claims = null,
            ?string $error = null): \GuzzleHttp\Psr7\Response {
        return $this->json_response(200, [
            'session_id' => $id,
            'status' => $status,
            'holder_did' => 'did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274',
            'presentation_data' => $claims === null ? null : ['credentials' => [
                ['id' => login_session::DCQL_ID, 'format' => 'vc+sd-jwt', 'claims' => $claims],
            ]],
            'error' => $error,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600),
        ]);
    }

    /**
     * Abre una sesión de ingreso contra la API simulada y la devuelve.
     *
     * @param \GuzzleHttp\Handler\MockHandler $mock
     * @param string $id
     * @return \stdClass
     */
    protected function start_session(\GuzzleHttp\Handler\MockHandler $mock, string $id): \stdClass {
        $mock->append($this->verification_created($id, time() + 600));
        [$record] = login_session::start();
        return $record;
    }
}
