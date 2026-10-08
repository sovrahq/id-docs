<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Qué datos lleva la credencial, y de dónde salen en Moodle.
 *
 * Está en código y no en la configuración a propósito: qué datos personales viajan en una credencial
 * es una decisión de producto y de privacidad, y tiene que pasar por revisión, no editarse en caliente.
 *
 * Las keys tienen que coincidir carácter por carácter con las del esquema en Sovra: una key que el
 * esquema no declara se descarta en silencio al firmar. Este mapeo es el del esquema de prueba
 * `bogota-formacion`; adaptalo al esquema de tu institución.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claims {

    /** @var string[] Claims obligatorios del esquema. Si falta uno, no se llama a la API. */
    const REQUIRED = [
        'holderName',
        'documento',
        'programa',
        'curso',
        'horas',
        'entidadFormadora',
        'fechaFinalizacion',
        'codigoVerificacion',
    ];

    /** @var string Campo personalizado del curso con la carga horaria (Administración → Cursos → Campos personalizados). */
    const COURSE_FIELD_HOURS = 'sovra_horas';

    /** @var string Campo personalizado del curso con el nivel. Opcional. */
    const COURSE_FIELD_LEVEL = 'sovra_nivel';

    /**
     * Arma los claims de la credencial de un derecho.
     *
     * @param \stdClass $issuance Registro de local_sovracred_issuance.
     * @return array key → valor. `nivel` sólo aparece si tiene valor.
     */
    public static function build(\stdClass $issuance): array {
        global $SITE;

        $user = \core_user::get_user($issuance->userid, '*', MUST_EXIST);
        $course = get_course($issuance->courseid);
        $coursefields = self::course_fields($course->id);
        $category = \core_course_category::get($course->category, IGNORE_MISSING, true);

        $claims = [
            'holderName' => trim($user->firstname . ' ' . $user->lastname),
            'documento' => self::document($user),
            'programa' => $category ? self::plain($category->name) : '',
            'curso' => self::plain($course->fullname),
            'horas' => $coursefields[self::COURSE_FIELD_HOURS] ?? '',
            'entidadFormadora' => self::plain($SITE->fullname),
            'fechaFinalizacion' => $issuance->timecompleted
                ? userdate($issuance->timecompleted, '%Y-%m-%d', \core_date::get_server_timezone(), false)
                : '',
            'codigoVerificacion' => $issuance->verificationcode,
        ];

        $level = $coursefields[self::COURSE_FIELD_LEVEL] ?? '';
        if ($level !== '') {
            $claims['nivel'] = $level;
        }
        return $claims;
    }

    /**
     * Claims obligatorios vacíos.
     *
     * @param array $claims
     * @return string[]
     */
    public static function missing(array $claims): array {
        return array_values(array_filter(self::REQUIRED, function (string $key) use ($claims): bool {
            return !isset($claims[$key]) || trim((string) $claims[$key]) === '';
        }));
    }

    /**
     * El número de documento, del campo que eligió el administrador.
     *
     * @param \stdClass $user
     * @return string
     */
    private static function document(\stdClass $user): string {
        global $CFG;

        $field = config::document_field();
        if (strpos($field, 'profile_field_') === 0) {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            $profile = profile_user_record($user->id, false);
            $shortname = substr($field, strlen('profile_field_'));
            return trim((string) ($profile->$shortname ?? ''));
        }
        return trim((string) ($user->$field ?? ''));
    }

    /**
     * Valores de los campos personalizados del curso, por shortname.
     *
     * @param int $courseid
     * @return array
     */
    private static function course_fields(int $courseid): array {
        $values = [];
        foreach (\core_course\customfield\course_handler::create()->get_instance_data($courseid, true) as $data) {
            $value = $data->get_value();
            $values[$data->get_field()->get('shortname')] = is_scalar($value) ? trim((string) $value) : '';
        }
        return $values;
    }

    /**
     * Texto plano a partir de un nombre que admite filtros multilenguaje.
     *
     * @param string $text
     * @return string
     */
    private static function plain(string $text): string {
        $formatted = format_string($text, true, ['context' => \context_system::instance(), 'escape' => false]);
        return trim(html_entity_decode(strip_tags($formatted), ENT_QUOTES, 'UTF-8'));
    }
}
