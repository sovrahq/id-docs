<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Error al hablar con la API de Sovra.
 *
 * $errorcode es el campo `error` de la respuesta (invalid_api_key, schema_not_found, missing_claims…)
 * o uno propio: notconfigured, hostblocked, network, invalidresponse.
 * Nunca contiene la API key.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \Exception {

    /** @var string Código de error. */
    public string $errorcode;

    /** @var int Estado HTTP, 0 si no hubo respuesta. */
    public int $httpstatus;

    /** @var mixed El campo `details` de la respuesta, si vino. */
    public $details;

    /**
     * Constructor.
     *
     * @param string $errorcode
     * @param int $httpstatus
     * @param mixed $details
     */
    public function __construct(string $errorcode, int $httpstatus = 0, $details = null) {
        $this->errorcode = $errorcode;
        $this->httpstatus = $httpstatus;
        $this->details = $details;
        parent::__construct($this->summary());
    }

    /**
     * Una línea legible para el reporte del administrador.
     *
     * @return string
     */
    public function summary(): string {
        $text = $this->errorcode . ($this->httpstatus ? " (HTTP {$this->httpstatus})" : '');
        if ($this->details !== null && $this->details !== '') {
            $text .= ': ' . (is_string($this->details) ? $this->details : json_encode($this->details, JSON_UNESCAPED_UNICODE));
        }
        return \core_text::substr($text, 0, 500);
    }
}
