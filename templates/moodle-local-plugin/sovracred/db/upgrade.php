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

    if ($oldversion < 2026111200) {
        // Registro de llamadas a la API y de avisos del webhook, para el administrador.
        $table = new xmldb_table('local_sovracred_log');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('httpstatus', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('result', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('credentialid', XMLDB_TYPE_CHAR, '36', null, null, null, null);
        $table->add_field('eventid', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('workspaceid', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('duration', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('detail', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('kindtime', XMLDB_INDEX_NOTUNIQUE, ['kind', 'timecreated']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
        $table->add_index('credentialid', XMLDB_INDEX_NOTUNIQUE, ['credentialid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026111200, 'local', 'sovracred');
    }

    return true;
}
