<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Falta un dato obligatorio de la credencial: no se llama a la API.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class missing_claims_exception extends \Exception {

    /** @var string[] Las keys que faltan. */
    public array $missing;

    /**
     * Constructor.
     *
     * @param string[] $missing
     */
    public function __construct(array $missing) {
        $this->missing = $missing;
        parent::__construct('missingclaims: ' . implode(', ', $missing));
    }
}
