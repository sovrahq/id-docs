<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Spec: «Privacidad» y acceso al reporte.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_sovracred\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    use fixtures_trait;

    /** @var \stdClass */
    private \stdClass $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
        $this->setAdminUser();
        $this->redirectMessages();
        $this->student = $this->create_student();
        $this->complete_course($this->student, $this->create_course_with_fields());
    }

    public function test_exportacion(): void {
        $context = \context_user::instance($this->student->id);
        $contextlist = privacy\provider::get_contexts_for_userid($this->student->id);
        $this->assertEquals([$context->id], $contextlist->get_contextids());

        privacy\provider::export_user_data(new approved_contextlist($this->student, 'local_sovracred', [$context->id]));
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_sovracred')]);
        $this->assertCount(1, $data->credentials);
        $this->assertSame('Programación en Python', $data->credentials[0]->course);
    }

    public function test_borrado(): void {
        global $DB;
        $context = \context_user::instance($this->student->id);
        privacy\provider::delete_data_for_user(new approved_contextlist($this->student, 'local_sovracred', [$context->id]));
        $this->assertFalse($DB->record_exists('local_sovracred_issuance', ['userid' => $this->student->id]));
    }

    public function test_reporte_restringido(): void {
        $syscontext = \context_system::instance();
        $this->assertFalse(has_capability('local/sovracred:viewreport', $syscontext, $this->student));
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->assertTrue(has_capability('local/sovracred:viewreport', $syscontext, $manager));
    }
}
