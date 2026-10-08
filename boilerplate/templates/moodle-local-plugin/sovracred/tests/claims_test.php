<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Los claims de la credencial».
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_sovracred\claims
 */
final class claims_test extends \advanced_testcase {
    use fixtures_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
        $this->setAdminUser();
        $this->redirectMessages();
    }

    /**
     * Derecho registrado para un curso nuevo.
     *
     * @param array $fields
     * @param string $documento
     * @return \stdClass
     */
    private function issuance_for(array $fields, string $documento = '1020304050'): \stdClass {
        global $DB;
        $course = $this->create_course_with_fields($fields);
        $student = $this->create_student($documento);
        $this->complete_course($student, $course);
        return $DB->get_record('local_sovracred_issuance', ['userid' => $student->id], '*', MUST_EXIST);
    }

    public function test_claims_completos(): void {
        global $SITE;
        $issuance = $this->issuance_for(['sovra_horas' => '40', 'sovra_nivel' => 'Básico']);

        $claims = claims::build($issuance);

        $expected = [
            'holderName' => 'Ada Lovelace',
            'documento' => '1020304050',
            'programa' => 'Talento Digital',
            'curso' => 'Programación en Python',
            'horas' => '40',
            'entidadFormadora' => $SITE->fullname,
            'fechaFinalizacion' => userdate(strtotime('2026-10-01 12:00:00'), '%Y-%m-%d', \core_date::get_server_timezone(), false),
            'codigoVerificacion' => $issuance->verificationcode,
            'nivel' => 'Básico',
        ];
        $this->assertSame($expected, $claims);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $claims['fechaFinalizacion']);
        $this->assertSame([], claims::missing($claims));
    }

    public function test_nivel_vacio_no_se_envia(): void {
        $claims = claims::build($this->issuance_for(['sovra_horas' => '20']));
        $this->assertArrayNotHasKey('nivel', $claims);
    }

    public function test_documento_desde_campo_propio_y_desde_idnumber(): void {
        global $DB;
        $issuance = $this->issuance_for(['sovra_horas' => '20'], '99887766');
        $this->assertSame('99887766', claims::build($issuance)['documento']);

        $DB->set_field('user', 'idnumber', 'CC-555', ['id' => $issuance->userid]);
        set_config('documentfield', 'idnumber', 'local_sovracred');
        $this->assertSame('CC-555', claims::build($issuance)['documento']);
    }

    public function test_faltantes(): void {
        $claims = claims::build($this->issuance_for([], ''));
        $this->assertSame(['documento', 'horas'], claims::missing($claims));
    }
}
