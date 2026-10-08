<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Admin settings for local_sovracred.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// El reporte lo ve quien tenga local/sovracred:viewreport, aunque no administre el sitio.
$ADMIN->add('reports', new admin_externalpage('local_sovracred_report',
    get_string('report', 'local_sovracred'),
    new moodle_url('/local/sovracred/index.php'),
    'local/sovracred:viewreport'));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_sovracred', get_string('pluginname', 'local_sovracred'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading('local_sovracred/connection',
            get_string('settings_connection', 'local_sovracred'),
            get_string('settings_connection_desc', 'local_sovracred', (object) [
                'webhookurl' => \local_sovracred\config::webhook_url()->out(false),
                'reporturl' => (new moodle_url('/local/sovracred/index.php'))->out(false),
            ])));

        $settings->add(new admin_setting_configtext('local_sovracred/apiurl',
            get_string('apiurl', 'local_sovracred'), get_string('apiurl_desc', 'local_sovracred'),
            'https://test-api-sovra.flagonsa.com', PARAM_URL));

        // Cifrados en la base con la clave del sitio; nunca se vuelven a mostrar.
        $settings->add(new admin_setting_encryptedpassword('local_sovracred/apikey',
            get_string('apikey', 'local_sovracred'), get_string('apikey_desc', 'local_sovracred')));
        $settings->add(new admin_setting_encryptedpassword('local_sovracred/webhooksecret',
            get_string('webhooksecret', 'local_sovracred'), get_string('webhooksecret_desc', 'local_sovracred')));

        $settings->add(new admin_setting_configtext('local_sovracred/schemaid',
            get_string('schemaid', 'local_sovracred'), get_string('schemaid_desc', 'local_sovracred'),
            'bogota-formacion', PARAM_ALPHANUMEXT));

        $settings->add(new admin_setting_heading('local_sovracred/issuing',
            get_string('settings_issuing', 'local_sovracred'), ''));

        $settings->add(new admin_setting_configselect('local_sovracred/coursescope',
            get_string('coursescope', 'local_sovracred'), get_string('coursescope_desc', 'local_sovracred'),
            \local_sovracred\config::SCOPE_ALL, [
                \local_sovracred\config::SCOPE_ALL => get_string('coursescope_all', 'local_sovracred'),
                \local_sovracred\config::SCOPE_LIST => get_string('coursescope_list', 'local_sovracred'),
            ]));
        $settings->add(new admin_setting_configtext('local_sovracred/courseids',
            get_string('courseids', 'local_sovracred'), get_string('courseids_desc', 'local_sovracred'),
            '', PARAM_SEQUENCE));

        // Origen del número de documento: el campo estándar «Número de ID» o un campo propio del perfil.
        $documentfields = ['idnumber' => get_string('idnumber')];
        foreach ($DB->get_records('user_info_field', null, 'sortorder', 'id, shortname, name') as $field) {
            $documentfields['profile_field_' . $field->shortname] = format_string($field->name) . " ({$field->shortname})";
        }
        $settings->add(new admin_setting_configselect('local_sovracred/documentfield',
            get_string('documentfield', 'local_sovracred'), get_string('documentfield_desc', 'local_sovracred'),
            'idnumber', $documentfields));
    }
}
