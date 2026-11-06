<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/completion/completion_completion.php');

/**
 * Datos de prueba compartidos: configuración, curso, estudiante y completitud.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait fixtures_trait {

    /**
     * Configura el plugin contra una API de mentira (el HTTP se simula en cada prueba).
     *
     * @return void
     */
    protected function configure_plugin(): void {
        set_config('apiurl', 'https://api.example.test', 'local_sovracred');
        set_config('apikey', 'sovra_sk_prueba', 'local_sovracred');
        set_config('webhooksecret', 'secreto-de-prueba', 'local_sovracred');
        set_config('schemaid', 'bogota-formacion', 'local_sovracred');
        set_config('coursescope', config::SCOPE_ALL, 'local_sovracred');
        set_config('documentfield', 'profile_field_documento', 'local_sovracred');
        set_config('documenttypefield', 'profile_field_tipodocumento', 'local_sovracred');
    }

    /**
     * Curso con completitud habilitada, en una categoría (programa).
     *
     * @param string $program Nombre de la categoría.
     * @return \stdClass
     */
    protected function create_completable_course(string $program = 'Talento Digital'): \stdClass {
        $gen = $this->getDataGenerator();
        $coursecat = $gen->create_category(['name' => $program]);
        return $gen->create_course(['enablecompletion' => 1, 'fullname' => 'Programación en Python', 'category' => $coursecat->id]);
    }

    /**
     * Estudiante con número y tipo de documento en campos propios del perfil.
     *
     * @param string $documento
     * @param string $tipodocumento
     * @return \stdClass
     */
    protected function create_student(string $documento = '1020304050', string $tipodocumento = 'CC'): \stdClass {
        $gen = $this->getDataGenerator();
        global $DB;
        if (!$DB->record_exists('user_info_field', ['shortname' => 'documento'])) {
            $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'documento', 'name' => 'Documento']);
            $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'tipodocumento', 'name' => 'Tipo de documento']);
        }
        return $gen->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace',
            'profile_field_documento' => $documento, 'profile_field_tipodocumento' => $tipodocumento]);
    }

    /**
     * Inscribe y marca completo, como lo haría el cron de completitud (dispara course_completed).
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @return void
     */
    protected function complete_course(\stdClass $user, \stdClass $course): void {
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $completion = new \completion_completion(['course' => $course->id, 'userid' => $user->id]);
        $completion->mark_complete(strtotime('2026-10-01 12:00:00'));
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
     * Respuesta de POST /credential-offer.
     *
     * @param string $credentialid
     * @param int $expires
     * @return \GuzzleHttp\Psr7\Response
     */
    protected function offer_response(string $credentialid, int $expires): \GuzzleHttp\Psr7\Response {
        return $this->json_response(201, [
            'credential_id' => $credentialid,
            'offer_uri' => 'openid-credential-offer://?credential_offer=%7B%22credential_issuer%22%3A%22x%22%7D',
            'pre_authorized_code' => 'abc',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
            'credential' => [],
        ]);
    }
}
