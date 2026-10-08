<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Registro del derecho al completar un curso».
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_sovracred\observer
 * @covers     \local_sovracred\issuance::register
 * @covers     \local_sovracred\issuance::notify
 */
final class observer_test extends \advanced_testcase {
    use fixtures_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
        $this->setAdminUser();
    }

    public function test_curso_habilitado_completado(): void {
        global $DB;
        $course = $this->create_course_with_fields();
        $student = $this->create_student();
        $sink = $this->redirectMessages();

        $this->complete_course($student, $course);

        $record = $DB->get_record('local_sovracred_issuance', ['userid' => $student->id, 'courseid' => $course->id], '*', MUST_EXIST);
        $this->assertSame(issuance::AVAILABLE, $record->status);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $record->verificationcode);
        $this->assertEquals(strtotime('2026-10-01 12:00:00'), $record->timecompleted);

        $messages = array_values(array_filter($sink->get_messages(), fn($m) => $m->component === 'local_sovracred'));
        $this->assertCount(1, $messages);
        $this->assertEquals($student->id, $messages[0]->useridto);
        $this->assertStringContainsString('/local/sovracred/credenciales.php', $messages[0]->contexturl);
    }

    public function test_curso_no_habilitado(): void {
        global $DB;
        $enabled = $this->create_course_with_fields();
        $other = $this->create_course_with_fields();
        set_config('coursescope', config::SCOPE_LIST, 'local_sovracred');
        set_config('courseids', (string) $enabled->id, 'local_sovracred');
        $student = $this->create_student();
        $sink = $this->redirectMessages();

        $this->complete_course($student, $other);

        $this->assertFalse($DB->record_exists('local_sovracred_issuance', ['userid' => $student->id]));
        $this->assertCount(0, array_filter($sink->get_messages(), fn($m) => $m->component === 'local_sovracred'));

        // Control: el curso listado sí registra.
        $this->complete_course($student, $enabled);
        $this->assertTrue($DB->record_exists('local_sovracred_issuance', ['userid' => $student->id, 'courseid' => $enabled->id]));
    }

    public function test_completitud_repetida(): void {
        global $DB;
        $course = $this->create_course_with_fields();
        $student = $this->create_student();
        $sink = $this->redirectMessages();

        $this->complete_course($student, $course);
        $completion = $DB->get_record('course_completions', ['userid' => $student->id, 'course' => $course->id]);
        \core\event\course_completed::create_from_completion($completion)->trigger();

        $this->assertEquals(1, $DB->count_records('local_sovracred_issuance', ['userid' => $student->id]));
        $this->assertCount(1, array_filter($sink->get_messages(), fn($m) => $m->component === 'local_sovracred'));
    }

    public function test_el_estudiante_es_el_relacionado_no_el_actor(): void {
        global $DB, $USER;
        $course = $this->create_course_with_fields();
        $student = $this->create_student();
        $this->redirectMessages();

        // El evento lo dispara el administrador (como el cron o un docente que marca a mano).
        $this->complete_course($student, $course);

        $this->assertNotEquals($USER->id, $student->id);
        $this->assertTrue($DB->record_exists('local_sovracred_issuance', ['userid' => $student->id]));
        $this->assertFalse($DB->record_exists('local_sovracred_issuance', ['userid' => $USER->id]));
    }
}
