<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra\privacy;

use auth_sovra\log;
use auth_sovra\login_session;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Proveedor de privacidad: qué se exporta y qué se borra de cada cuenta.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends \core_privacy\tests\provider_testcase {

    /** @var \stdClass */
    private \stdClass $ada;

    /** @var \stdClass */
    private \stdClass $grace;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->ada = $gen->create_user();
        $this->grace = $gen->create_user();

        log::login('finish', 'ok', '11111111-1111-1111-1111-111111111111', (int) $this->ada->id, 'detalle interno');
        log::login('webhook', 'ok', '22222222-2222-2222-2222-222222222222', (int) $this->grace->id);
        // Filas sin cuenta: llamadas a la API, avisos, intentos sin cuenta encontrada.
        log::api('POST', '/api/v1/verifier/verifications', 201, 'ok', 120, '33333333-3333-3333-3333-333333333333');
        log::login('poll', 'notfound', '44444444-4444-4444-4444-444444444444');
        $this->session_for($this->ada, '55555555-5555-5555-5555-555555555555');
        $this->session_for(null, '66666666-6666-6666-6666-666666666666');
    }

    /**
     * Una sesión de ingreso resuelta para esa cuenta (o sin cuenta).
     *
     * @param \stdClass|null $user
     * @param string $vid
     * @return void
     */
    private function session_for(?\stdClass $user, string $vid): void {
        global $DB;
        $DB->insert_record(login_session::TABLE, (object) [
            'token' => bin2hex(random_bytes(32)),
            'verificationid' => $vid,
            'status' => login_session::RESOLVED,
            'result' => $user ? 'ok' : 'notfound',
            'userid' => $user ? $user->id : null,
            'timecreated' => time(),
            'timeexpires' => time() + 600,
            'timelastpoll' => time(),
        ]);
    }

    public function test_contextos_de_una_cuenta(): void {
        $system = \context_system::instance();
        $this->assertSame([(int) $system->id], array_map('intval', provider::get_contexts_for_userid((int) $this->ada->id)->get_contextids()));
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertSame([], provider::get_contexts_for_userid((int) $nobody->id)->get_contextids());
    }

    public function test_cuentas_en_el_contexto(): void {
        $userlist = new userlist(\context_system::instance(), 'auth_sovra');
        provider::get_users_in_context($userlist);
        $ids = array_map('intval', $userlist->get_userids());
        sort($ids);
        $this->assertSame([(int) $this->ada->id, (int) $this->grace->id], $ids);

        // En otro contexto no hay nada.
        $userlist = new userlist(\context_user::instance($this->ada->id), 'auth_sovra');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }

    public function test_exporta_los_ingresos_sin_el_detalle(): void {
        $system = \context_system::instance();
        provider::export_user_data(new approved_contextlist($this->ada, 'auth_sovra', [$system->id]));
        $data = writer::with_context($system)->get_data([get_string('pluginname', 'auth_sovra')]);
        $this->assertCount(1, $data->logins);
        $this->assertSame('finish', $data->logins[0]->action);
        $this->assertSame('ok', $data->logins[0]->result);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $data->logins[0]->verificationid);
        $this->assertFalse(property_exists($data->logins[0], 'detail'));
    }

    public function test_borra_solo_lo_de_esa_cuenta(): void {
        global $DB;
        $system = \context_system::instance();
        provider::delete_data_for_user(new approved_contextlist($this->ada, 'auth_sovra', [$system->id]));
        $this->assertFalse($DB->record_exists(log::TABLE, ['userid' => $this->ada->id]));
        $this->assertFalse($DB->record_exists(login_session::TABLE, ['userid' => $this->ada->id]));
        $this->assertTrue($DB->record_exists(log::TABLE, ['userid' => $this->grace->id]));
        $this->assertSame(2, $DB->count_records_select(log::TABLE, 'userid IS NULL'));
    }

    public function test_borra_varias_cuentas(): void {
        global $DB;
        $system = \context_system::instance();
        provider::delete_data_for_users(new approved_userlist($system, 'auth_sovra', [$this->ada->id, $this->grace->id]));
        $this->assertSame(0, $DB->count_records_select(log::TABLE, 'userid IS NOT NULL'));
        $this->assertSame(0, $DB->count_records_select(login_session::TABLE, 'userid IS NOT NULL'));
        $this->assertSame(2, $DB->count_records_select(log::TABLE, 'userid IS NULL'));
    }

    public function test_borrar_todo_el_contexto_deja_las_filas_sin_cuenta(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertSame(0, $DB->count_records_select(log::TABLE, 'userid IS NOT NULL'));
        $this->assertSame(2, $DB->count_records_select(log::TABLE, 'userid IS NULL'));
        $this->assertSame(1, $DB->count_records(login_session::TABLE));

        // Un contexto que no es el del sistema no borra nada.
        provider::delete_data_for_all_users_in_context(\context_user::instance($this->grace->id));
        $this->assertSame(2, $DB->count_records(log::TABLE));
    }
}
