<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Sólo emisores permitidos», «Identificación por número de documento», «Cuentas que no pueden entrar».
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resolver_test extends \advanced_testcase {
    use fixtures_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->configure_plugin();
    }

    // Sólo emisores permitidos.

    public function test_emisor_permitido(): void {
        $user = $this->create_person('10203045');
        $this->assertSame([resolver::OK, (int) $user->id], resolver::resolve($this->claims('10203045')));
    }

    public function test_emisor_ajeno(): void {
        $this->create_person('10203045');
        $claims = $this->claims('10203045', ['iss' => 'https://test-api-sovra.flagonsa.com/' . self::$otherdid]);
        $this->assertSame([resolver::ISSUER_NOT_ALLOWED, null], resolver::resolve($claims));
    }

    public function test_sin_lista_de_emisores_nadie_entra(): void {
        $this->create_person('10203045');
        set_config('allowedissuers', '', 'auth_sovra');
        $this->assertSame([resolver::ISSUER_NOT_ALLOWED, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_sin_iss_nadie_entra(): void {
        $this->create_person('10203045');
        $claims = $this->claims('10203045');
        unset($claims['iss']);
        $this->assertSame([resolver::ISSUER_MISSING, null], resolver::resolve($claims));
    }

    public function test_iss_como_did_y_varios_emisores(): void {
        $user = $this->create_person('10203045');
        set_config('allowedissuers', self::$otherdid . "\n" . self::$issuerdid . "\n", 'auth_sovra');
        $this->assertSame([resolver::OK, (int) $user->id],
            resolver::resolve($this->claims('10203045', ['iss' => self::$issuerdid])));
    }

    public function test_did_en_el_host_no_sirve(): void {
        // El DID cuenta sólo en la ruta, que es lo que usa Sovra para elegir la llave.
        $this->create_person('10203045');
        $claims = $this->claims('10203045', ['iss' => 'https://' . self::$issuerdid . '.example/' . self::$otherdid]);
        $this->assertSame([resolver::ISSUER_NOT_ALLOWED, null], resolver::resolve($claims));
    }

    public function test_tipo_de_credencial_ajeno(): void {
        $this->create_person('10203045');
        $this->assertSame([resolver::TYPE_NOT_ALLOWED, null],
            resolver::resolve($this->claims('10203045', ['vct' => 'CertificadoDeCurso'])));
    }

    public function test_sin_tipos_configurados_no_se_exige_vct(): void {
        $user = $this->create_person('10203045');
        set_config('credentialtypes', '', 'auth_sovra');
        $this->assertSame([resolver::OK, (int) $user->id],
            resolver::resolve($this->claims('10203045', ['vct' => 'Otra'])));
    }

    // Identificación por número de documento.

    public function test_una_coincidencia_normalizada(): void {
        $user = $this->create_person('10203045');
        $this->assertSame([resolver::OK, (int) $user->id], resolver::resolve($this->claims('1.020.304-5')));
    }

    public function test_normaliza_tambien_lo_guardado_en_moodle(): void {
        $user = $this->create_person('1.020 304-5');
        $this->assertSame([resolver::OK, (int) $user->id], resolver::resolve($this->claims('10203045')));
    }

    public function test_sin_distinguir_mayusculas(): void {
        $user = $this->create_person('ab123456');
        $this->assertSame([resolver::OK, (int) $user->id], resolver::resolve($this->claims('AB-123456')));
    }

    public function test_campo_de_perfil(): void {
        $this->use_profile_fields();
        $user = $this->create_person_profile('52.111.222', 'CC');
        $this->create_person('52111222'); // El idnumber ya no cuenta.
        $this->assertSame([resolver::OK, (int) $user->id], resolver::resolve($this->claims('52111222')));
    }

    public function test_ninguna_coincidencia(): void {
        $this->create_person('10203045');
        $this->assertSame([resolver::NOT_FOUND, null], resolver::resolve($this->claims('99999999')));
    }

    public function test_no_coincide_por_prefijo(): void {
        $this->create_person('102030451');
        $this->assertSame([resolver::NOT_FOUND, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_cuentas_borradas_no_cuentan(): void {
        $user = $this->create_person('10203045');
        delete_user($user);
        $this->assertSame([resolver::NOT_FOUND, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_duplicados(): void {
        $this->create_person('10203045');
        $this->create_person('10.203.045');
        $this->assertSame([resolver::DUPLICATE, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_sin_numero_de_documento(): void {
        $this->create_person('10203045');
        $claims = $this->claims('10203045');
        unset($claims['num_doc']);
        $this->assertSame([resolver::NO_DOCUMENT, null], resolver::resolve($claims));
        $this->assertSame([resolver::NO_DOCUMENT, null], resolver::resolve($this->claims(' . - ')));
        $this->assertSame([resolver::NO_DOCUMENT, null], resolver::resolve($this->claims('', ['num_doc' => ['10203045']])));
    }

    public function test_tipo_de_documento_distinto(): void {
        $this->use_profile_fields();
        set_config('matchdoctype', 1, 'auth_sovra');
        $this->create_person_profile('52111222', 'PA');
        $this->assertSame([resolver::NOT_FOUND, null], resolver::resolve($this->claims('52111222')));
    }

    public function test_el_tipo_desempata(): void {
        $this->use_profile_fields();
        set_config('matchdoctype', 1, 'auth_sovra');
        set_config('doctypemap', "Cédula de ciudadanía = CC\nPasaporte = PA", 'auth_sovra');
        $cc = $this->create_person_profile('52111222', 'cc');
        $this->create_person_profile('52111222', 'PA');
        $this->assertSame([resolver::OK, (int) $cc->id], resolver::resolve($this->claims('52111222')));
        // Sin comparar el tipo, son duplicados.
        set_config('matchdoctype', 0, 'auth_sovra');
        $this->assertSame([resolver::DUPLICATE, null], resolver::resolve($this->claims('52111222')));
    }

    public function test_tipo_sin_equivalencia_se_compara_tal_cual(): void {
        $this->use_profile_fields();
        set_config('matchdoctype', 1, 'auth_sovra');
        set_config('doctypemap', '', 'auth_sovra');
        $user = $this->create_person_profile('52111222', 'CEDULA DE CIUDADANIA');
        $this->assertSame([resolver::OK, (int) $user->id], resolver::resolve($this->claims('52111222')));
    }

    public function test_campo_de_perfil_inexistente(): void {
        set_config('documentfield', 'profile_field_noexiste', 'auth_sovra');
        $this->assertSame([resolver::MISCONFIGURED, null], resolver::resolve($this->claims('10203045')));
    }

    // Cuentas que no pueden entrar.

    public function test_cuenta_suspendida(): void {
        $this->create_person('10203045', ['suspended' => 1]);
        $this->assertSame([resolver::SUSPENDED, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_metodo_no_aceptado(): void {
        $this->create_person('10203045', ['auth' => 'oauth2']);
        $this->assertSame([resolver::AUTH_NOT_ALLOWED, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_nologin_nunca(): void {
        $this->create_person('10203045', ['auth' => 'nologin']);
        set_config('allowedauths', 'manual,nologin', 'auth_sovra');
        $this->assertSame([resolver::AUTH_NOT_ALLOWED, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_administrador_del_sitio(): void {
        global $DB;
        $DB->set_field('user', 'idnumber', '10203045', ['username' => 'admin']);
        $admin = get_admin();
        $this->assertSame([resolver::ADMIN, null], resolver::resolve($this->claims('10203045')));
        set_config('allowadmins', 1, 'auth_sovra');
        $this->assertSame([resolver::OK, (int) $admin->id], resolver::resolve($this->claims('10203045')));
    }

    public function test_normalizaciones(): void {
        $this->assertSame('10203045', resolver::normalize_document(" 1.020.304-5\t"));
        $this->assertSame('cedula de ciudadania', resolver::normalize_text('  Cédula   de CIUDADANÍA '));
        $this->assertSame(self::$issuerdid, resolver::issuer_did('https://api.sovra.io/' . self::$issuerdid));
        $this->assertSame(self::$issuerdid, resolver::issuer_did(self::$issuerdid));
    }
}
