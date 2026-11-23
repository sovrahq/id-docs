<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

/**
 * Callbacks de hooks del núcleo.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {

    /**
     * Nadie cambia su propio documento desde el perfil: el ingreso con la SuperApp encuentra la cuenta por él.
     *
     * Si la persona pudiera escribir el documento de otra, esa otra quedaría como duplicada (no entra) o, si no
     * tiene cuenta, entraría con la SuperApp a la cuenta de quien lo escribió. En lugar de bloquear el campo (un
     * campo de perfil bloqueado también queda congelado en el formulario de registro), se descarta el cambio
     * cuando quien edita es la propia persona sin permiso para editar cuentas. Crear la cuenta (el registro) no
     * pasa por acá, y un administrador, una sincronización o un servicio web con ese permiso lo siguen pudiendo
     * cambiar.
     *
     * Sacar el campo de los datos alcanza: user_update_user no toca lo que no viene, y profile_save_data saltea el
     * campo de perfil que no está.
     *
     * @param \core_user\hook\before_user_updated $hook
     * @return void
     */
    public static function before_user_updated(\core_user\hook\before_user_updated $hook): void {
        global $USER;
        $user = $hook->user;
        if (!is_enabled_auth('sovra') || empty($user->id) || (int) $user->id !== (int) $USER->id
                || has_capability('moodle/user:update', \context_system::instance())) {
            return;
        }

        $tried = false;
        foreach (config::document_fields() as $field) {
            if (!property_exists($user, $field)) {
                continue;
            }
            $value = is_scalar($user->$field) ? (string) $user->$field : null;
            $tried = $tried || $value !== self::current_value((int) $user->id, $field, $hook->currentuserdata);
            unset($user->$field);
        }
        if ($tried) {
            log::login('profile', 'documentlocked', null, (int) $user->id);
            \core\notification::warning(get_string('document_locked', 'auth_sovra'));
        }
    }

    /**
     * El valor guardado de un campo del documento, como texto.
     *
     * @param int $userid
     * @param string $field idnumber o profile_field_<shortname>.
     * @param \stdClass $current La fila de user antes del cambio.
     * @return string
     */
    private static function current_value(int $userid, string $field, \stdClass $current): string {
        global $DB;
        if ($field === 'idnumber') {
            return (string) ($current->idnumber ?? '');
        }
        $sql = "SELECT d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE d.userid = :userid AND f.shortname = :shortname";
        return (string) $DB->get_field_sql($sql, ['userid' => $userid,
            'shortname' => substr($field, strlen('profile_field_'))]);
    }
}
