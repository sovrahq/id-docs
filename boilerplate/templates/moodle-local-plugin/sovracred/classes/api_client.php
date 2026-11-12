<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Cliente mínimo de la API de emisión de Sovra ID.
 *
 * Usa \core\http_client (Guzzle del núcleo), que respeta la lista de hosts y puertos bloqueados de
 * Administración del sitio → Seguridad → Seguridad HTTP, y que en las pruebas se reemplaza por uno simulado.
 *
 * Referencia: docs/guides/credentials/04-emision-de-credenciales.md
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {

    /** @var string URL base, sin barra final. */
    private string $baseurl;

    /** @var string sovra_sk_... */
    private string $apikey;

    /** @var int Estado HTTP de la última respuesta, para el registro. */
    private int $laststatus = 0;

    /**
     * Constructor.
     *
     * @param string $baseurl
     * @param string $apikey
     */
    public function __construct(string $baseurl, string $apikey) {
        $this->baseurl = rtrim($baseurl, '/');
        $this->apikey = $apikey;
    }

    /**
     * Cliente con la configuración del sitio.
     *
     * @return self
     * @throws api_exception notconfigured
     */
    public static function from_config(): self {
        if (config::api_url() === '' || config::api_key() === '') {
            throw new api_exception('notconfigured');
        }
        return new self(config::api_url(), config::api_key());
    }

    /**
     * Crea una oferta OID4VCI. Vence a las 24 h y es de un solo uso.
     *
     * @param string $schemaid El slug del esquema, no el UUID.
     * @param array $claims key → valor; las keys que el esquema no declara se descartan en silencio.
     * @return array credential_id, offer_uri, expires_at, pre_authorized_code, credential (vista previa sin firma)
     */
    public function create_offer(string $schemaid, array $claims): array {
        // Un array PHP vacío se serializa como [] y la API responde 500 a "claims": []. Tiene que ser un objeto.
        $body = ['schema_id' => $schemaid, 'claims' => (object) $claims];
        $data = $this->request('POST', '/api/v1/issuer/credential-offer', $body);
        if (empty($data['credential_id']) || empty($data['offer_uri'])) {
            throw new api_exception('invalidresponse');
        }
        return $data;
    }

    /**
     * Estado de una credencial: pending, expired, issued, suspended o revoked.
     *
     * @param string $credentialid
     * @return array id, status, holder_did, issued_at, expires_at, schema
     */
    public function get_credential(string $credentialid): array {
        return $this->request('GET', '/api/v1/issuer/credentials/' . rawurlencode($credentialid), null, $credentialid);
    }

    /**
     * Los esquemas del workspace de la API key, una entrada por versión.
     *
     * @return array[] Cada uno con schema_id, version, kind, claims, registration_status, registration_error…
     */
    public function list_schemas(): array {
        $data = $this->request('GET', '/api/v1/issuer/schemas');
        if (!isset($data['schemas']) || !is_array($data['schemas'])) {
            throw new api_exception('invalidresponse');
        }
        return $data['schemas'];
    }

    /**
     * Hace la llamada y la deja en el registro, salga bien o mal.
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @param string|null $credentialid Para el registro, si la llamada es sobre una credencial.
     * @return array
     */
    private function request(string $method, string $path, ?array $body = null, ?string $credentialid = null): array {
        $start = microtime(true);
        try {
            $data = $this->send($method, $path, $body);
        } catch (api_exception $e) {
            log::api($method, $path, $e->httpstatus, $e->errorcode, self::elapsed($start), $credentialid, $e->summary());
            throw $e;
        }
        $credentialid = $credentialid ?? (is_string($data['credential_id'] ?? null) ? $data['credential_id'] : null);
        log::api($method, $path, $this->laststatus, 'ok', self::elapsed($start), $credentialid);
        return $data;
    }

    /**
     * Milisegundos desde $start.
     *
     * @param float $start
     * @return int
     */
    private static function elapsed(float $start): int {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * Hace la llamada y devuelve el JSON decodificado; cualquier error sale como api_exception.
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @return array
     */
    private function send(string $method, string $path, ?array $body): array {
        $url = $this->baseurl . $path;

        // Mismo chequeo que hace el cliente HTTP del núcleo, hecho antes para dar un error claro.
        if ((new \core\files\curl_security_helper())->url_is_blocked($url)) {
            throw new api_exception('hostblocked');
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apikey,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
            'connect_timeout' => 5,
            'timeout' => 15,
        ];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $response = \core\di::get(\core\http_client::class)->request($method, $url, $options);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            // El mensaje de Guzzle puede incluir la URL, nunca los headers.
            throw new api_exception('network', 0, $e->getMessage());
        }

        $status = $this->laststatus = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);

        if ($status >= 400) {
            $code = is_array($data) && isset($data['error']) && is_string($data['error']) ? $data['error'] : 'http_error';
            throw new api_exception($code, $status, is_array($data) ? ($data['details'] ?? null) : null);
        }
        if (!is_array($data)) {
            throw new api_exception('invalidresponse', $status);
        }
        return $data;
    }
}
