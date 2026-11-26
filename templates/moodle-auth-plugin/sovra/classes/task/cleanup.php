<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra\task;

/**
 * Borra las sesiones de ingreso vencidas y el registro viejo.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {

    /**
     * Nombre de la tarea.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup', 'auth_sovra');
    }

    /**
     * Ejecuta la tarea.
     *
     * @return void
     */
    public function execute(): void {
        \auth_sovra\login_session::prune();
        \auth_sovra\log::prune();
    }
}
