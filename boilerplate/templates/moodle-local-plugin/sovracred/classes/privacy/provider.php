<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Qué datos personales guarda el plugin y cuáles envía a Sovra.
 *
 * Los derechos se guardan en el contexto del usuario.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe los datos.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_sovracred_issuance', [
            'userid' => 'privacy:metadata:issuance:userid',
            'courseid' => 'privacy:metadata:issuance:courseid',
            'status' => 'privacy:metadata:issuance:status',
            'verificationcode' => 'privacy:metadata:issuance:verificationcode',
            'timecompleted' => 'privacy:metadata:issuance:timecompleted',
            'credentialid' => 'privacy:metadata:issuance:credentialid',
            'offeruri' => 'privacy:metadata:issuance:offeruri',
            'offerexpires' => 'privacy:metadata:issuance:offerexpires',
            'holderdid' => 'privacy:metadata:issuance:holderdid',
            'timeissued' => 'privacy:metadata:issuance:timeissued',
            'lasterror' => 'privacy:metadata:issuance:lasterror',
        ], 'privacy:metadata:issuance');

        // Lo que viaja a Sovra al crear la oferta: los claims de classes/claims.php.
        $collection->add_external_location_link('sovra', [
            'holderName' => 'privacy:metadata:sovra:holdername',
            'documento' => 'privacy:metadata:sovra:documento',
            'programa' => 'privacy:metadata:sovra:programa',
            'curso' => 'privacy:metadata:sovra:curso',
            'horas' => 'privacy:metadata:sovra:horas',
            'nivel' => 'privacy:metadata:sovra:nivel',
            'entidadFormadora' => 'privacy:metadata:sovra:entidad',
            'fechaFinalizacion' => 'privacy:metadata:sovra:fecha',
            'codigoVerificacion' => 'privacy:metadata:sovra:codigo',
        ], 'privacy:metadata:sovra');

        return $collection;
    }

    /**
     * Contextos con datos del usuario.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if ($DB->record_exists('local_sovracred_issuance', ['userid' => $userid])) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    /**
     * Usuarios con datos en un contexto.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_user) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_sovracred_issuance} WHERE userid = :userid',
                ['userid' => $context->instanceid]);
        }
    }

    /**
     * Exporta los derechos del usuario.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || $context->instanceid != $userid) {
                continue;
            }
            $records = $DB->get_records('local_sovracred_issuance', ['userid' => $userid]);
            $export = [];
            foreach ($records as $record) {
                $export[] = (object) [
                    'course' => $DB->get_field('course', 'fullname', ['id' => $record->courseid]),
                    'status' => $record->status,
                    'verificationcode' => $record->verificationcode,
                    'credentialid' => $record->credentialid,
                    'holderdid' => $record->holderdid,
                    'timecompleted' => $record->timecompleted ? \core_privacy\local\request\transform::datetime($record->timecompleted) : null,
                    'timeissued' => $record->timeissued ? \core_privacy\local\request\transform::datetime($record->timeissued) : null,
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_sovracred')], (object) ['credentials' => $export]);
        }
    }

    /**
     * Borra todos los datos de un contexto.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_user) {
            $DB->delete_records('local_sovracred_issuance', ['userid' => $context->instanceid]);
        }
    }

    /**
     * Borra los datos del usuario.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && $context->instanceid == $userid) {
                $DB->delete_records('local_sovracred_issuance', ['userid' => $userid]);
            }
        }
    }

    /**
     * Borra los datos de varios usuarios en un contexto.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context instanceof \context_user && in_array($context->instanceid, $userlist->get_userids())) {
            $DB->delete_records('local_sovracred_issuance', ['userid' => $context->instanceid]);
        }
    }
}
