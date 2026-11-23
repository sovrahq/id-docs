<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacidad: el registro de ingresos y las sesiones en curso guardan el id de la cuenta, el resultado y la
 * verificación. Nada del documento.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    /**
     * Qué se guarda y qué sale del sitio.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('auth_sovra_log', [
            'userid' => 'privacy:metadata:auth_sovra_log:userid',
            'verificationid' => 'privacy:metadata:auth_sovra_log:verificationid',
            'result' => 'privacy:metadata:auth_sovra_log:result',
            'timecreated' => 'privacy:metadata:auth_sovra_log:timecreated',
        ], 'privacy:metadata:auth_sovra_log');
        $collection->add_database_table('auth_sovra_session', [
            'userid' => 'privacy:metadata:auth_sovra_session:userid',
        ], 'privacy:metadata:auth_sovra_session');
        $collection->add_external_location_link('sovra', [
            'document' => 'privacy:metadata:sovra:document',
        ], 'privacy:metadata:sovra');
        return $collection;
    }

    /**
     * Todo vive en el contexto del sistema.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if ($DB->record_exists('auth_sovra_log', ['userid' => $userid])
                || $DB->record_exists('auth_sovra_session', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Las cuentas con datos en el contexto.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {auth_sovra_log} WHERE userid IS NOT NULL', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {auth_sovra_session} WHERE userid IS NOT NULL', []);
    }

    /**
     * Exporta los ingresos de la cuenta.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $context = \context_system::instance();
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $rows = $DB->get_records('auth_sovra_log', ['userid' => $contextlist->get_user()->id], 'timecreated',
            'id, action, result, verificationid, timecreated');
        if ($rows) {
            $data = array_map(fn($r) => (object) [
                'action' => $r->action,
                'result' => $r->result,
                'verificationid' => $r->verificationid,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ], array_values($rows));
            writer::with_context($context)->export_data([get_string('pluginname', 'auth_sovra')], (object) ['logins' => $data]);
        }
    }

    /**
     * Borra todo en el contexto.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records_select('auth_sovra_log', 'userid IS NOT NULL');
            $DB->delete_records_select('auth_sovra_session', 'userid IS NOT NULL');
        }
    }

    /**
     * Borra lo de una cuenta.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if (!in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('auth_sovra_log', ['userid' => $userid]);
        $DB->delete_records('auth_sovra_session', ['userid' => $userid]);
    }

    /**
     * Borra lo de varias cuentas.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        if (!$userlist->get_context() instanceof \context_system || !$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select('auth_sovra_log', "userid $insql", $params);
        $DB->delete_records_select('auth_sovra_session', "userid $insql", $params);
    }
}
