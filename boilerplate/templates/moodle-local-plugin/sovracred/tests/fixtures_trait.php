<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/completion/completion_completion.php');

/**
 * Datos de prueba compartidos: configuración, curso con campos propios, estudiante y completitud.
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
    }

    /**
     * Curso con completitud habilitada, en una categoría (programa) y con horas y nivel.
     *
     * @param array $fields Valores de los campos propios: ['sovra_horas' => '40', 'sovra_nivel' => 'Básico'].
     * @return \stdClass
     */
    protected function create_course_with_fields(array $fields = ['sovra_horas' => '40', 'sovra_nivel' => 'Básico']): \stdClass {
        global $DB;
        $gen = $this->getDataGenerator();
        $cfgen = $gen->get_plugin_generator('core_customfield');
        if (!$DB->record_exists('customfield_field', ['shortname' => 'sovra_horas'])) {
            $category = $cfgen->create_category(['component' => 'core_course', 'area' => 'course', 'itemid' => 0]);
            $cfgen->create_field(['categoryid' => $category->get('id'), 'type' => 'text', 'shortname' => 'sovra_horas']);
            $cfgen->create_field(['categoryid' => $category->get('id'), 'type' => 'text', 'shortname' => 'sovra_nivel']);
        }
        $coursecat = $gen->create_category(['name' => 'Talento Digital']);
        $data = ['enablecompletion' => 1, 'fullname' => 'Programación en Python', 'category' => $coursecat->id];
        foreach ($fields as $shortname => $value) {
            $data['customfield_' . $shortname] = $value;
        }
        return $gen->create_course($data);
    }

    /**
     * Estudiante con documento en un campo propio del perfil.
     *
     * @param string $documento
     * @return \stdClass
     */
    protected function create_student(string $documento = '1020304050'): \stdClass {
        $gen = $this->getDataGenerator();
        global $DB;
        if (!$DB->record_exists('user_info_field', ['shortname' => 'documento'])) {
            $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'documento', 'name' => 'Documento']);
        }
        return $gen->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace', 'profile_field_documento' => $documento]);
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
