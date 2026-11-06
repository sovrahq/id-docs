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
     * @param string $documento
     * @param string $tipodocumento
     * @return \stdClass
     */
    private function issuance_for(string $documento = '1020304050', string $tipodocumento = 'CC'): \stdClass {
        global $DB;
        $course = $this->create_completable_course();
        $student = $this->create_student($documento, $tipodocumento);
        $this->complete_course($student, $course);
        return $DB->get_record('local_sovracred_issuance', ['userid' => $student->id], '*', MUST_EXIST);
    }

    public function test_claims_completos(): void {
        global $SITE;
        $issuance = $this->issuance_for();

        $claims = claims::build($issuance);

        $expected = [
            'holderName' => 'Ada Lovelace',
            'documento' => '1020304050',
            'tipoDocumento' => 'CC',
            'curso' => 'Programación en Python',
            'entidadFormadora' => $SITE->fullname,
            'fechaFinalizacion' => userdate(strtotime('2026-10-01 12:00:00'), '%Y-%m-%d', \core_date::get_server_timezone(), false),
            'codigoVerificacion' => $issuance->verificationcode,
            'programa' => 'Talento Digital',
        ];
        $this->assertSame($expected, $claims);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $claims['fechaFinalizacion']);
        $this->assertSame([], claims::missing($claims));
    }

    public function test_programa_vacio_no_se_envia(): void {
        global $DB;
        $issuance = $this->issuance_for();
        $categoryid = $DB->get_field('course', 'category', ['id' => $issuance->courseid]);
        $DB->set_field('course_categories', 'name', '', ['id' => $categoryid]);
        \cache_helper::purge_by_definition('core', 'coursecat');

        $claims = claims::build($issuance);
        $this->assertArrayNotHasKey('programa', $claims);
        $this->assertSame([], claims::missing($claims));
    }

    public function test_documento_desde_campo_propio_y_desde_idnumber(): void {
        global $DB;
        $issuance = $this->issuance_for('99887766');
        $this->assertSame('99887766', claims::build($issuance)['documento']);

        $DB->set_field('user', 'idnumber', 'CC-555', ['id' => $issuance->userid]);
        set_config('documentfield', 'idnumber', 'local_sovracred');
        $this->assertSame('CC-555', claims::build($issuance)['documento']);
    }

    public function test_tipo_documento_sin_configurar_falta(): void {
        $issuance = $this->issuance_for();
        set_config('documenttypefield', '', 'local_sovracred');
        $this->assertSame(['tipoDocumento'], claims::missing(claims::build($issuance)));
    }

    public function test_faltantes(): void {
        $claims = claims::build($this->issuance_for('', ''));
        $this->assertSame(['documento', 'tipoDocumento'], claims::missing($claims));
    }
}
