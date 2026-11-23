<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «El aviso nombra a la plataforma» y lo que ve la persona en cada resultado.
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ui_test extends \advanced_testcase {
    use fixtures_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
    }

    public function test_aviso_con_el_nombre_configurado(): void {
        set_config('platformname', 'Atenea', 'auth_sovra');
        set_config('signupurl', 'https://academia.agenciaatenea.gov.co/registrarse/', 'auth_sovra');
        $html = ui::result(resolver::NOT_FOUND);
        $this->assertStringContainsString(get_string('result_notfound', 'auth_sovra', 'Atenea'), $html);
        $this->assertStringContainsString('href="https://academia.agenciaatenea.gov.co/registrarse/"', $html);
        $this->assertStringContainsString(get_string('signup', 'auth_sovra'), $html);
    }

    public function test_sin_nombre_usa_el_nombre_corto_del_sitio(): void {
        global $SITE;
        set_config('platformname', '', 'auth_sovra');
        $this->assertNotSame('', $SITE->shortname);
        $this->assertSame($SITE->shortname, config::platform_name());
        $this->assertStringContainsString(get_string('result_notfound', 'auth_sovra', $SITE->shortname),
            ui::result(resolver::NOT_FOUND));
    }

    public function test_el_nombre_se_escapa(): void {
        set_config('platformname', '<b>Atenea</b>', 'auth_sovra');
        $html = ui::result(resolver::NOT_FOUND);
        $this->assertStringNotContainsString('<b>Atenea</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Atenea&lt;/b&gt;', $html);
    }

    public function test_sin_registro_no_hay_boton(): void {
        set_config('signupurl', '', 'auth_sovra');
        set_config('registerauth', '');
        $html = ui::result(resolver::NOT_FOUND);
        $this->assertStringNotContainsString(get_string('signup', 'auth_sovra'), $html);
        $this->assertStringContainsString(get_string('result_notfound_nosignup', 'auth_sovra'), $html);
    }

    public function test_cuentas_que_no_pueden_entrar_no_ofrecen_reintentar(): void {
        foreach ([resolver::DUPLICATE, resolver::SUSPENDED, resolver::UNCONFIRMED] as $result) {
            $html = ui::result($result);
            $this->assertStringContainsString(s(get_string('result_' . $result, 'auth_sovra')), $html, $result);
            $this->assertStringNotContainsString(get_string('retry', 'auth_sovra'), $html, $result);
        }
    }

    public function test_reintentar_pide_un_codigo_nuevo_con_sesskey(): void {
        $html = ui::result(login_session::EXPIRED);
        $this->assertStringContainsString(get_string('retry', 'auth_sovra'), $html);
        $this->assertStringContainsString('sesskey=' . sesskey(), $html);
        $this->assertStringContainsString('new=1', $html);
    }

    public function test_sin_detalles_tecnicos(): void {
        // Un error de Sovra, o cualquier cosa puesta a mano en ?result=, es «no pudimos validar tu credencial».
        foreach (['holder_key_mismatch', 'cualquiercosa'] as $result) {
            $html = ui::result($result);
            $this->assertStringContainsString(s(get_string('result_failed', 'auth_sovra')), $html);
            $this->assertStringNotContainsString($result, $html);
        }
        // La API no deja seguir: no es culpa de la credencial.
        $this->assertStringContainsString(s(get_string('login_error', 'auth_sovra')), ui::result(login_session::UNAVAILABLE));
    }
}
