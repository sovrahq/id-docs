<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Pasos de actualización de local_sovracred.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Actualiza el plugin desde una versión anterior.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_sovracred_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026101400) {
        // El expires_at de la credencial emitida: Sovra no informa «expired» para una credencial ya emitida.
        $table = new xmldb_table('local_sovracred_issuance');
        $field = new xmldb_field('credexpires', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timeissued');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026101400, 'local', 'sovracred');
    }

    return true;
}
