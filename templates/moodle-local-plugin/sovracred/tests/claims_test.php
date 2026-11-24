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
            'programa' => 'Talento Digital',
            'curso' => 'Programación en Python',
            'entidadFormadora' => $SITE->fullname,
            'fechaFinalizacion' => userdate(strtotime('2026-10-01 12:00:00'), '%Y-%m-%d', \core_date::get_server_timezone(), false),
            'codigoVerificacion' => $issuance->verificationcode,
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

    public function test_mapeo_de_fabrica_usa_los_ajustes_anteriores(): void {
        $this->assertSame('profile:documento', $this->claim_source('documento'));
        $this->assertSame('profile:tipodocumento', $this->claim_source('tipoDocumento'));
        set_config('documentfield', 'idnumber', 'local_sovracred');
        $this->assertSame('user:idnumber', $this->claim_source('documento'));
        $this->assertSame('', claims::map()['schema']);
    }

    /**
     * El origen de un claim en el mapeo vigente.
     *
     * @param string $key
     * @return string
     */
    private function claim_source(string $key): string {
        return array_column(claims::map()['claims'], 'source', 'key')[$key];
    }

    public function test_mapeo_guardado(): void {
        global $DB;
        $issuance = $this->issuance_for();
        $cfgen = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $cfgen->create_category(['component' => 'core_course', 'area' => 'course', 'itemid' => 0]);
        $field = $cfgen->create_field(['categoryid' => $category->get('id'), 'type' => 'text', 'shortname' => 'horas']);
        $cfgen->add_instance_data($field, $issuance->courseid, '40');
        $DB->set_field('user', 'email', 'ada@example.com', ['id' => $issuance->userid]);

        claims::save_map('otro_esquema', [
            ['key' => 'nombre', 'source' => 'user:fullname', 'type' => 'string', 'required' => true],
            ['key' => 'correo', 'source' => 'user:email', 'type' => 'string', 'required' => false],
            ['key' => 'tipo', 'source' => 'profile:tipodocumento', 'type' => 'string', 'required' => true],
            ['key' => 'horas', 'source' => 'coursefield:horas', 'type' => 'integer', 'required' => true],
            ['key' => 'emisor', 'source' => 'const', 'value' => 'Agencia Atenea', 'type' => 'string', 'required' => true],
            ['key' => 'aprobado', 'source' => 'const', 'value' => 'sí', 'type' => 'boolean', 'required' => false],
            ['key' => 'finalizado', 'source' => 'completion:date', 'type' => 'datetime', 'required' => true],
            ['key' => 'nivel', 'source' => '', 'type' => 'string', 'required' => false],
        ]);

        $claims = claims::build($issuance);

        $this->assertSame([
            'nombre' => 'Ada Lovelace',
            'correo' => 'ada@example.com',
            'tipo' => 'CC',
            'horas' => 40,
            'emisor' => 'Agencia Atenea',
            'aprobado' => true,
            'finalizado' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-10-01 12:00:00')),
        ], $claims);
        $this->assertSame([], claims::missing($claims));
        $this->assertSame('otro_esquema', claims::map()['schema']);
    }

    public function test_origen_que_ya_no_existe_falta(): void {
        $issuance = $this->issuance_for();
        claims::save_map('bogota_formacion', [
            ['key' => 'documento', 'source' => 'profile:borrado', 'type' => 'string', 'required' => true],
            ['key' => 'curso', 'source' => 'course:fullname', 'type' => 'string', 'required' => true],
        ]);

        $claims = claims::build($issuance);

        $this->assertSame(['curso' => 'Programación en Python'], $claims);
        $this->assertSame(['documento'], claims::missing($claims));
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
