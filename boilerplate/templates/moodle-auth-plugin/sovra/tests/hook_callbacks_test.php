<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures_trait.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Spec: «La persona no cambia su documento». Se guarda como lo hace user/edit.php: user_update_user y después
 * profile_save_data con el mismo objeto.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hook_callbacks_test extends \advanced_testcase {
    use fixtures_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
    }

    /**
     * Guarda el perfil como el formulario de edición.
     *
     * @param \stdClass $user
     * @param array $changes
     * @return \stdClass La cuenta tal como quedó.
     */
    private function save_profile(\stdClass $user, array $changes): \stdClass {
        $usernew = (object) (['id' => $user->id] + $changes);
        user_update_user($usernew, false, false);
        profile_save_data($usernew);
        return (object) ((array) \core_user::get_user($user->id) + (array) profile_user_record($user->id, false));
    }

    public function test_no_cambia_su_propio_id_number(): void {
        global $DB;
        $user = $this->create_person('10203045');
        $this->setUser($user);
        $saved = $this->save_profile($user, ['idnumber' => '99999999', 'firstname' => 'Augusta']);
        $this->assertSame('10203045', $saved->idnumber);
        // El resto del perfil se guarda igual.
        $this->assertSame('Augusta', $saved->firstname);
        $this->assertTrue($DB->record_exists(log::TABLE, ['kind' => log::LOGIN, 'result' => 'documentlocked',
            'userid' => $user->id]));
        $this->assertCount(1, \core\notification::fetch());
    }

    public function test_no_cambia_su_documento_ni_su_tipo_en_el_perfil(): void {
        $this->use_profile_fields();
        set_config('matchdoctype', 1, 'auth_sovra');
        $user = $this->create_person_profile('52111222', 'CC');
        $this->setUser($user);
        $saved = $this->save_profile($user, ['profile_field_documento' => '10203045', 'profile_field_tipodoc' => 'PA']);
        $this->assertSame('52111222', $saved->documento);
        $this->assertSame('CC', $saved->tipodoc);
    }

    public function test_guardar_sin_cambiarlo_no_avisa(): void {
        global $DB;
        $user = $this->create_person('10203045');
        $this->setUser($user);
        $saved = $this->save_profile($user, ['idnumber' => '10203045', 'firstname' => 'Augusta']);
        $this->assertSame('10203045', $saved->idnumber);
        $this->assertSame('Augusta', $saved->firstname);
        $this->assertFalse($DB->record_exists(log::TABLE, ['result' => 'documentlocked']));
        $this->assertCount(0, \core\notification::fetch());
    }

    public function test_un_administrador_si_lo_cambia(): void {
        $user = $this->create_person('10203045');
        $this->setAdminUser();
        $this->assertSame('99999999', $this->save_profile($user, ['idnumber' => '99999999'])->idnumber);
    }

    public function test_solo_protege_la_cuenta_propia(): void {
        // Editar otra cuenta ya exige moodle/user:update en el núcleo (user/editadvanced.php).
        $user = $this->create_person('10203045');
        $other = $this->create_person('30191292');
        $this->setUser($other);
        $this->assertSame('99999999', $this->save_profile($user, ['idnumber' => '99999999'])->idnumber);
    }

    public function test_con_el_plugin_deshabilitado_no_interviene(): void {
        $user = $this->create_person('10203045');
        set_config('auth', 'email');
        $this->setUser($user);
        $this->assertSame('99999999', $this->save_profile($user, ['idnumber' => '99999999'])->idnumber);
    }

    public function test_el_registro_no_pasa_por_aca(): void {
        // Crear la cuenta (el registro) puede cargar el documento: sólo se protege la actualización.
        $this->use_profile_fields();
        $user = $this->create_person_profile('52111222', 'CC');
        $this->assertSame('52111222', profile_user_record($user->id, false)->documento);
    }
}
