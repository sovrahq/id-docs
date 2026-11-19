<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * Cliente mínimo de la API de verificación de Sovra ID (OID4VP).
 *
 * Usa \core\http_client (Guzzle del núcleo), que respeta la lista de hosts y puertos bloqueados de
 * Administración del sitio → Seguridad → Seguridad HTTP, y que en las pruebas se reemplaza por uno simulado.
 *
 * Referencia: docs/guides/credentials/05-verificacion-de-credenciales.md
 *
 * @package    auth_sovra
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
     * Abre una verificación OID4VP. Vive 10 minutos.
     *
     * @param array $dcql La consulta DCQL.
     * @return array session_id, authorization_request_uri, authorization_request_uri_ref, status, expires_at
     */
    public function create_verification(array $dcql): array {
        $data = $this->request('POST', '/api/v1/verifier/verifications', ['dcql_query' => $dcql]);
        if (empty($data['session_id']) || !is_string($data['session_id'])
                || (empty($data['authorization_request_uri_ref']) && empty($data['authorization_request_uri']))) {
            throw new api_exception('invalidresponse');
        }
        return $data;
    }

    /**
     * Estado de una verificación: pending, completed, failed o expired.
     *
     * @param string $verificationid
     * @return array session_id, status, presentation_data, error, expires_at…
     */
    public function get_verification(string $verificationid): array {
        return $this->request('GET', '/api/v1/verifier/verifications/' . rawurlencode($verificationid), null, $verificationid);
    }

    /**
     * Hace la llamada y la deja en el registro, salga bien o mal.
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @param string|null $verificationid Para el registro.
     * @return array
     */
    private function request(string $method, string $path, ?array $body = null, ?string $verificationid = null): array {
        $start = microtime(true);
        // La ruta del registro no lleva el id: va en su propia columna.
        $logpath = $verificationid !== null ? str_replace(rawurlencode($verificationid), '{id}', $path) : $path;
        try {
            $data = $this->send($method, $path, $body);
        } catch (api_exception $e) {
            log::api($method, $logpath, $e->httpstatus, $e->errorcode, self::elapsed($start), $verificationid, $e->summary());
            throw $e;
        }
        $verificationid = $verificationid ?? (is_string($data['session_id'] ?? null) ? $data['session_id'] : null);
        log::api($method, $logpath, $this->laststatus, 'ok', self::elapsed($start), $verificationid);
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
            'timeout' => 10,
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
