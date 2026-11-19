<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Admin settings for auth_sovra.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('reports', new admin_externalpage('auth_sovra_logs',
    get_string('logs', 'auth_sovra'),
    new moodle_url('/auth/sovra/logs.php'),
    'moodle/site:config'));

if ($ADMIN->fulltree) {
    global $DB;

    // Conexión.
    $settings->add(new admin_setting_heading('auth_sovra/connection',
        get_string('settings_connection', 'auth_sovra'),
        get_string('settings_connection_desc', 'auth_sovra', (object) [
            'webhookurl' => \auth_sovra\config::webhook_url()->out(false),
            'logsurl' => (new moodle_url('/auth/sovra/logs.php'))->out(false),
        ])));
    $settings->add(new admin_setting_configtext('auth_sovra/apiurl',
        get_string('apiurl', 'auth_sovra'), get_string('apiurl_desc', 'auth_sovra'),
        'https://test-api-sovra.flagonsa.com', PARAM_URL));
    // Cifrados en la base con la clave del sitio; nunca se vuelven a mostrar.
    $settings->add(new admin_setting_encryptedpassword('auth_sovra/apikey',
        get_string('apikey', 'auth_sovra'), get_string('apikey_desc', 'auth_sovra')));
    $settings->add(new admin_setting_encryptedpassword('auth_sovra/webhooksecret',
        get_string('webhooksecret', 'auth_sovra'), get_string('webhooksecret_desc', 'auth_sovra')));
    $settings->add(new admin_setting_configtext('auth_sovra/workspaceid',
        get_string('workspaceid', 'auth_sovra'), get_string('workspaceid_desc', 'auth_sovra'),
        '', '/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})?$/'));

    // Qué credencial se acepta.
    $settings->add(new admin_setting_heading('auth_sovra/credential',
        get_string('settings_credential', 'auth_sovra'), get_string('settings_credential_desc', 'auth_sovra')));
    $settings->add(new admin_setting_configtextarea('auth_sovra/allowedissuers',
        get_string('allowedissuers', 'auth_sovra'), get_string('allowedissuers_desc', 'auth_sovra'), '', PARAM_RAW_TRIMMED));
    $settings->add(new admin_setting_configtext('auth_sovra/credentialtypes',
        get_string('credentialtypes', 'auth_sovra'), get_string('credentialtypes_desc', 'auth_sovra'),
        'IdentidadMigrante', PARAM_RAW_TRIMMED));
    $settings->add(new admin_setting_configtext('auth_sovra/claimdocnumber',
        get_string('claimdocnumber', 'auth_sovra'), get_string('claimdocnumber_desc', 'auth_sovra'),
        'num_doc', PARAM_ALPHANUMEXT));
    $settings->add(new admin_setting_configtext('auth_sovra/claimdoctype',
        get_string('claimdoctype', 'auth_sovra'), get_string('claimdoctype_desc', 'auth_sovra'),
        'tipo_doc', PARAM_ALPHANUMEXT));

    // Cómo se encuentra la cuenta.
    $settings->add(new admin_setting_heading('auth_sovra/matching',
        get_string('settings_matching', 'auth_sovra'), get_string('settings_matching_desc', 'auth_sovra')));
    $profilefields = [];
    foreach ($DB->get_records('user_info_field', null, 'sortorder', 'id, shortname, name') as $field) {
        $profilefields['profile_field_' . $field->shortname] = format_string($field->name) . ' (' . $field->shortname . ')';
    }
    $settings->add(new admin_setting_configselect('auth_sovra/documentfield',
        get_string('documentfield', 'auth_sovra'), get_string('documentfield_desc', 'auth_sovra'), 'idnumber',
        ['idnumber' => get_string('idnumber')] + $profilefields));
    $settings->add(new admin_setting_configcheckbox('auth_sovra/matchdoctype',
        get_string('matchdoctype', 'auth_sovra'), get_string('matchdoctype_desc', 'auth_sovra'), 0));
    $settings->add(new admin_setting_configselect('auth_sovra/documenttypefield',
        get_string('documenttypefield', 'auth_sovra'), get_string('documenttypefield_desc', 'auth_sovra'), '',
        ['' => get_string('none')] + $profilefields));
    $settings->add(new admin_setting_configtextarea('auth_sovra/doctypemap',
        get_string('doctypemap', 'auth_sovra'), get_string('doctypemap_desc', 'auth_sovra'),
        "Cédula de ciudadanía = CC\nCédula de extranjería = CE\nPasaporte = PA\nPPT = PPT\nPEP = PEP", PARAM_RAW_TRIMMED));

    // Quién puede entrar.
    $settings->add(new admin_setting_heading('auth_sovra/accounts',
        get_string('settings_accounts', 'auth_sovra'), ''));
    $auths = [];
    foreach (core_component::get_plugin_list('auth') as $name => $unused) {
        if ($name !== 'nologin') {
            $auths[$name] = get_string('pluginname', 'auth_' . $name);
        }
    }
    $settings->add(new admin_setting_configmulticheckbox('auth_sovra/allowedauths',
        get_string('allowedauths', 'auth_sovra'), get_string('allowedauths_desc', 'auth_sovra'),
        array_fill_keys(explode(',', \auth_sovra\config::DEFAULT_AUTHS), 1), $auths));
    $settings->add(new admin_setting_configcheckbox('auth_sovra/allowadmins',
        get_string('allowadmins', 'auth_sovra'), get_string('allowadmins_desc', 'auth_sovra'), 0));
    $settings->add(new admin_setting_configtext('auth_sovra/signupurl',
        get_string('signupurl', 'auth_sovra'), get_string('signupurl_desc', 'auth_sovra'), '', PARAM_URL));
}
