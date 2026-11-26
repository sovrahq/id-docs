<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace auth_sovra;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures_trait.php');

/**
 * Spec: «Sólo emisores permitidos», «Identificación por número de documento», «Comparación por tipo de documento», «Cuentas que no pueden entrar».
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

    public function test_sin_vct_con_tipos_configurados(): void {
        $this->create_person('10203045');
        $claims = $this->claims('10203045');
        unset($claims['vct']);
        $this->assertSame([resolver::TYPE_NOT_ALLOWED, null], resolver::resolve($claims));
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

    public function test_sigla_con_las_equivalencias_por_defecto(): void {
        // IdentidadCiudadano trae la sigla de Atenea; las equivalencias de nombre largo no la tocan.
        $this->use_profile_fields();
        set_config('matchdoctype', 1, 'auth_sovra');
        set_config('doctypemap', "Cédula de ciudadanía = CC\nCédula de extranjería = CE\nPasaporte = PA\nPPT = PPT\n"
            . "PEP = PEP\nSalvoconducto = SC", 'auth_sovra');
        $cc = $this->create_person_profile('52111222', 'CC');
        $ppt = $this->create_person_profile('7001234', 'PPT');
        $this->assertSame([resolver::OK, (int) $cc->id], resolver::resolve($this->claims('52111222', ['tipo_doc' => 'CC'])));
        $this->assertSame([resolver::OK, (int) $ppt->id], resolver::resolve($this->claims('7001234', ['tipo_doc' => 'ppt'])));
        $this->assertSame([resolver::NOT_FOUND, null], resolver::resolve($this->claims('52111222', ['tipo_doc' => 'CE'])));
    }

    public function test_campo_de_perfil_inexistente(): void {
        global $DB;
        set_config('documentfield', 'profile_field_noexiste', 'auth_sovra');
        $this->assertSame([resolver::MISCONFIGURED, null], resolver::resolve($this->claims('10203045')));
        // El administrador ve la causa en el registro, sin el documento.
        $log = $DB->get_record(log::TABLE, ['kind' => log::LOGIN, 'action' => 'resolve'], '*', MUST_EXIST);
        $this->assertSame(resolver::MISCONFIGURED, $log->result);
        $this->assertStringContainsString('no existe', $log->detail);
        $this->assertStringNotContainsString('10203045', $log->detail);
    }

    public function test_solo_cuentas_de_este_sitio(): void {
        global $DB;
        // Una cuenta MNet de otro sitio con el mismo documento no cuenta, ni como duplicado.
        $remote = $this->create_person('10203045');
        $DB->set_field('user', 'mnethostid', 999, ['id' => $remote->id]);
        $this->assertSame([resolver::NOT_FOUND, null], resolver::resolve($this->claims('10203045')));
        $local = $this->create_person('10203045');
        $this->assertSame([resolver::OK, (int) $local->id], resolver::resolve($this->claims('10203045')));
    }

    // Cuentas que no pueden entrar.

    public function test_cuenta_suspendida(): void {
        $this->create_person('10203045', ['suspended' => 1]);
        $this->assertSame([resolver::SUSPENDED, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_cuenta_sin_confirmar(): void {
        // Autorregistro por email sin confirmar: con contraseña no entra, con la SuperApp tampoco.
        $this->create_person('10203045', ['auth' => 'email', 'confirmed' => 0]);
        $this->assertSame([resolver::UNCONFIRMED, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_metodo_deshabilitado(): void {
        // Aceptado en el plugin pero deshabilitado en el sitio: con contraseña no entra, con la SuperApp tampoco.
        $this->create_person('10203045', ['auth' => 'email']);
        set_config('auth', 'sovra');
        $this->assertSame([resolver::AUTH_NOT_ALLOWED, null], resolver::resolve($this->claims('10203045')));
    }

    public function test_sin_metodos_aceptados_nadie_entra(): void {
        $this->create_person('10203045');
        set_config('allowedauths', '', 'auth_sovra');
        $this->assertSame([resolver::AUTH_NOT_ALLOWED, null], resolver::resolve($this->claims('10203045')));
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

    /**
     * Configuración de Atenea: campos propios, siglas y la tabla de equivalencias por defecto.
     *
     * @return void
     */
    private function configure_atenea(): void {
        $gen = $this->getDataGenerator();
        $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'numero_documento', 'name' => 'Número de documento']);
        $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'tipo_documento', 'name' => 'Tipo de documento']);
        set_config('documentfield', 'profile_field_numero_documento', 'auth_sovra');
        set_config('documenttypefield', 'profile_field_tipo_documento', 'auth_sovra');
        set_config('matchdoctype', 1, 'auth_sovra');
        set_config('doctypemap', "Cédula de ciudadanía = CC\nCédula de extranjería = CE\nPasaporte = PA\nPPT = PPT\nPEP = PEP\n"
            . "Salvoconducto = SC", 'auth_sovra');
    }

    /**
     * Una cuenta como las de Atenea.
     *
     * @param string $numero
     * @param string $sigla
     * @return \stdClass
     */
    private function create_atenea_person(string $numero, string $sigla): \stdClass {
        return $this->getDataGenerator()->create_user(['auth' => 'manual',
            'profile_field_numero_documento' => $numero, 'profile_field_tipo_documento' => $sigla]);
    }

    public function test_atenea_siglas(): void {
        $this->configure_atenea();
        $cc = $this->create_atenea_person('30191292', 'CC');
        $sc = $this->create_atenea_person('40001234', 'SC');
        $ce = $this->create_atenea_person('50001234', 'CE');
        $this->assertSame([resolver::OK, (int) $cc->id], resolver::resolve($this->claims('30.191.292')));
        $this->assertSame([resolver::OK, (int) $sc->id],
            resolver::resolve($this->claims('40001234', ['tipo_doc' => 'Salvoconducto'])));
        $this->assertSame([resolver::OK, (int) $ce->id],
            resolver::resolve($this->claims('50001234', ['tipo_doc' => 'Cédula de extranjería'])));
    }

    public function test_atenea_documento_de_origen_no_entra_a_una_cedula_ajena(): void {
        $this->configure_atenea();
        $this->create_atenea_person('30191292', 'CC');
        $this->assertSame([resolver::NOT_FOUND, null],
            resolver::resolve($this->claims('30191292', ['tipo_doc' => 'DNI del país de origen'])));
    }

    public function test_atenea_pep_tutor_no_se_equipara_a_pep(): void {
        $this->configure_atenea();
        $this->create_atenea_person('60001234', 'PEP');
        $this->assertSame([resolver::NOT_FOUND, null],
            resolver::resolve($this->claims('60001234', ['tipo_doc' => 'PEP Tutor'])));
    }

    public function test_iss_raro_no_entra(): void {
        $this->create_person('10203045');
        $this->assertSame([resolver::ISSUER_MISSING, null],
            resolver::resolve($this->claims('10203045', ['iss' => [self::$issuerdid]])));
        // Falla cerrada: una barra de más ya no es el DID de la lista.
        $this->assertSame([resolver::ISSUER_NOT_ALLOWED, null],
            resolver::resolve($this->claims('10203045', ['iss' => 'https://api.sovra.io/' . self::$issuerdid . '/'])));
    }

    public function test_normalizaciones(): void {
        $this->assertSame('10203045', resolver::normalize_document(" 1.020.304-5\t"));
        $this->assertSame('cedula de ciudadania', resolver::normalize_text('  Cédula   de CIUDADANÍA '));
        $this->assertSame(self::$issuerdid, resolver::issuer_did('https://api.sovra.io/' . self::$issuerdid));
        $this->assertSame(self::$issuerdid, resolver::issuer_did(self::$issuerdid));
    }
}
