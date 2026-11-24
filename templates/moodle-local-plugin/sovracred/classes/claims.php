<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred;

/**
 * Qué datos lleva la credencial, y de dónde salen en Moodle: el mapeo de claims.
 *
 * El mapeo lo arma el administrador en «Mapeo de claims» (mapeo.php) a partir de los claims del esquema
 * en Sovra, y se guarda en la configuración (`claimmap`). Cada claim dice de qué dato de Moodle sale
 * (un origen de SOURCES, un campo de perfil, un campo del curso o un texto fijo), su tipo y si el
 * esquema lo exige. Elegir qué datos personales viajan en una credencial es una decisión de privacidad:
 * por eso lo hace sólo quien administra el sitio.
 *
 * Sin mapeo guardado se usa default_map(), el del esquema de prueba `bogota_formacion`.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class claims {

    /** @var string Prefijo de un campo propio del perfil del usuario: profile:<shortname>. */
    const PROFILE = 'profile:';

    /** @var string Prefijo de un campo personalizado del curso: coursefield:<shortname>. */
    const COURSEFIELD = 'coursefield:';

    /** @var string Texto fijo: el valor sale de la entrada del mapeo. */
    const CONSTANT = 'const';

    /** @var string[] Orígenes fijos. Los campos de perfil y del curso se suman en sources(). */
    const SOURCES = [
        'user:fullname', 'user:firstname', 'user:lastname', 'user:email', 'user:idnumber', 'user:username',
        'user:institution', 'user:department', 'user:city', 'user:country',
        'course:fullname', 'course:shortname', 'course:idnumber', 'course:category',
        'site:fullname', 'site:shortname',
        'completion:date',
        'issuance:verificationcode',
    ];

    /**
     * El mapeo vigente.
     *
     * @return array schema (el slug para el que se guardó, '' si es el de fábrica) y claims: lista de
     *     [key, source, value, type, required].
     */
    public static function map(): array {
        $saved = json_decode((string) get_config('local_sovracred', 'claimmap'), true);
        if (is_array($saved) && !empty($saved['claims']) && is_array($saved['claims'])) {
            $claims = [];
            foreach ($saved['claims'] as $claim) {
                if (!empty($claim['key']) && is_string($claim['key'])) {
                    $claims[] = self::entry($claim['key'], (string) ($claim['source'] ?? ''), (string) ($claim['value'] ?? ''),
                        (string) ($claim['type'] ?? 'string'), !empty($claim['required']));
                }
            }
            return ['schema' => (string) ($saved['schema'] ?? ''), 'claims' => $claims];
        }
        return ['schema' => '', 'claims' => self::default_map()];
    }

    /**
     * Guarda el mapeo de un esquema.
     *
     * @param string $schemaid
     * @param array $claims Lista de [key, source, value, type, required].
     * @return void
     */
    public static function save_map(string $schemaid, array $claims): void {
        $entries = [];
        foreach ($claims as $claim) {
            $entries[] = self::entry($claim['key'], $claim['source'], $claim['value'] ?? '', $claim['type'] ?? 'string',
                !empty($claim['required']));
        }
        set_config('claimmap', json_encode(['schema' => $schemaid, 'claims' => $entries], JSON_UNESCAPED_UNICODE),
            'local_sovracred');
    }

    /**
     * El mapeo de fábrica: el del esquema de prueba `bogota_formacion` (v2).
     *
     * El documento y su tipo salen de los ajustes de versiones anteriores, para que un sitio que actualiza
     * siga emitiendo igual hasta que guarde su propio mapeo.
     *
     * @return array[]
     */
    public static function default_map(): array {
        $field = function (string $setting): string {
            if ($setting === '') {
                return '';
            }
            return strpos($setting, 'profile_field_') === 0
                ? self::PROFILE . substr($setting, strlen('profile_field_'))
                : 'user:' . $setting;
        };
        return [
            self::entry('holderName', 'user:fullname', '', 'string', true),
            self::entry('documento', $field(config::document_field()), '', 'string', true),
            self::entry('tipoDocumento', $field(config::document_type_field()), '', 'string', true),
            self::entry('programa', 'course:category', '', 'string', false),
            self::entry('curso', 'course:fullname', '', 'string', true),
            self::entry('entidadFormadora', 'site:fullname', '', 'string', true),
            self::entry('fechaFinalizacion', 'completion:date', '', 'date', true),
            self::entry('codigoVerificacion', 'issuance:verificationcode', '', 'string', true),
        ];
    }

    /**
     * Los orígenes que se pueden elegir: los fijos, los campos propios del perfil y los del curso.
     *
     * @return string[] origen → nombre para mostrar.
     */
    public static function sources(): array {
        global $DB;

        $sources = ['' => get_string('source_none', 'local_sovracred')];
        foreach (self::SOURCES as $source) {
            $sources[$source] = get_string('source_' . str_replace(':', '_', $source), 'local_sovracred');
        }
        foreach ($DB->get_records('user_info_field', null, 'sortorder', 'id, shortname, name') as $field) {
            $sources[self::PROFILE . $field->shortname] = get_string('source_profile', 'local_sovracred',
                format_string($field->name) . " ({$field->shortname})");
        }
        foreach (\core_course\customfield\course_handler::create()->get_fields() as $field) {
            $sources[self::COURSEFIELD . $field->get('shortname')] = get_string('source_coursefield', 'local_sovracred',
                format_string($field->get('name')) . " ({$field->get('shortname')})");
        }
        $sources[self::CONSTANT] = get_string('source_const', 'local_sovracred');
        return $sources;
    }

    /**
     * Arma los claims de la credencial de un derecho.
     *
     * @param \stdClass $issuance Registro de local_sovracred_issuance.
     * @return array key → valor. Un claim sin valor no aparece.
     */
    public static function build(\stdClass $issuance): array {
        $context = (object) [
            'issuance' => $issuance,
            'user' => \core_user::get_user($issuance->userid, '*', MUST_EXIST),
            'course' => get_course($issuance->courseid),
            'profile' => null,
            'coursefields' => null,
        ];

        $claims = [];
        foreach (self::map()['claims'] as $claim) {
            $value = self::resolve($claim, $context);
            if ($value !== null) {
                $claims[$claim['key']] = $value;
            }
        }
        return $claims;
    }

    /**
     * Claims obligatorios sin valor.
     *
     * @param array $claims
     * @return string[]
     */
    public static function missing(array $claims): array {
        $missing = [];
        foreach (self::map()['claims'] as $claim) {
            if ($claim['required'] && !array_key_exists($claim['key'], $claims)) {
                $missing[] = $claim['key'];
            }
        }
        return $missing;
    }

    /**
     * Una entrada del mapeo, normalizada.
     *
     * @param string $key
     * @param string $source
     * @param string $value
     * @param string $type
     * @param bool $required
     * @return array
     */
    private static function entry(string $key, string $source, string $value, string $type, bool $required): array {
        return [
            'key' => $key,
            'source' => $source,
            'value' => $source === self::CONSTANT ? $value : '',
            'type' => $type !== '' ? $type : 'string',
            'required' => $required,
        ];
    }

    /**
     * El valor de un claim para un derecho, ya con el formato de su tipo; null si no tiene.
     *
     * @param array $claim
     * @param \stdClass $context issuance, user, course y los campos cargados a demanda.
     * @return mixed
     */
    private static function resolve(array $claim, \stdClass $context) {
        global $SITE, $CFG;

        $source = $claim['source'];
        $user = $context->user;
        $course = $context->course;

        if ($source === 'completion:date') {
            $time = (int) $context->issuance->timecompleted;
            if (!$time) {
                return null;
            }
            if ($claim['type'] === 'datetime') {
                return gmdate('Y-m-d\TH:i:s\Z', $time);
            }
            // La fecha en la zona horaria del sitio: la que figura en Moodle como día de finalización.
            return userdate($time, '%Y-%m-%d', \core_date::get_server_timezone(), false);
        }

        if ($source === 'user:fullname') {
            $raw = $user->firstname . ' ' . $user->lastname;
        } else if (strpos($source, 'user:') === 0) {
            $field = substr($source, strlen('user:'));
            $raw = $user->$field ?? '';
        } else if (strpos($source, self::PROFILE) === 0) {
            if ($context->profile === null) {
                require_once($CFG->dirroot . '/user/profile/lib.php');
                $context->profile = profile_user_record($user->id, false);
            }
            $shortname = substr($source, strlen(self::PROFILE));
            $raw = $context->profile->$shortname ?? '';
        } else if ($source === 'course:category') {
            $category = \core_course_category::get($course->category, IGNORE_MISSING, true);
            $raw = $category ? self::plain($category->name) : '';
        } else if (in_array($source, ['course:fullname', 'course:shortname'], true)) {
            $raw = self::plain($course->{substr($source, strlen('course:'))});
        } else if ($source === 'course:idnumber') {
            $raw = $course->idnumber;
        } else if (strpos($source, self::COURSEFIELD) === 0) {
            if ($context->coursefields === null) {
                $context->coursefields = self::course_fields($course->id);
            }
            $raw = $context->coursefields[substr($source, strlen(self::COURSEFIELD))] ?? '';
        } else if ($source === 'site:fullname' || $source === 'site:shortname') {
            $raw = self::plain($SITE->{substr($source, strlen('site:'))});
        } else if ($source === 'issuance:verificationcode') {
            $raw = $context->issuance->verificationcode;
        } else if ($source === self::CONSTANT) {
            $raw = $claim['value'];
        } else {
            // Sin origen, o uno que ya no existe (un campo borrado): el claim no se envía.
            return null;
        }

        return self::typed(trim((string) $raw), $claim['type']);
    }

    /**
     * Un texto con el tipo del claim. En vc+sd-jwt el tipo es indicativo: lo que no se puede convertir
     * viaja como texto.
     *
     * @param string $raw
     * @param string $type
     * @return mixed null si está vacío.
     */
    private static function typed(string $raw, string $type) {
        if ($raw === '') {
            return null;
        }
        switch ($type) {
            case 'integer':
                return preg_match('/^-?\d+$/', $raw) ? (int) $raw : $raw;
            case 'number':
                return is_numeric($raw) ? $raw + 0 : $raw;
            case 'boolean':
                $lower = \core_text::strtolower($raw);
                if (in_array($lower, ['1', 'true', 'sí', 'si', 'yes'], true)) {
                    return true;
                }
                return in_array($lower, ['0', 'false', 'no'], true) ? false : $raw;
            default:
                return $raw;
        }
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
            $value = $data->export_value();
            $values[$data->get_field()->get('shortname')] = is_scalar($value) ? self::plain((string) $value) : '';
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
