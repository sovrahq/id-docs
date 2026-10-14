<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Observa la completitud de cursos.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Un estudiante completó un curso: registrar el derecho y avisarle.
     *
     * Sólo escribe una fila y encola una notificación: la oferta se crea cuando el estudiante la pide.
     * El estudiante es relateduserid; userid es quien disparó el evento (el cron, o un docente).
     *
     * @param \core\event\course_completed $event
     * @return void
     */
    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;
        if (!$userid || !config::course_enabled($courseid)) {
            return;
        }

        $timecompleted = (int) $DB->get_field('course_completions', 'timecompleted', ['id' => $event->objectid]);
        $issuance = issuance::register($userid, $courseid, $timecompleted ?: (int) $event->timecreated);
        if ($issuance) {
            issuance::notify($issuance);
        }
    }
}
