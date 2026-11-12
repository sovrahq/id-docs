<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Mapeo de claims: qué esquema se emite y de qué dato de Moodle sale cada claim.
 *
 * Los claims, sus tipos y cuáles son obligatorios se leen del esquema en Sovra; el administrador elige
 * el origen de cada uno. Se guarda en la configuración (claimmap) y lo usa classes/claims.php.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_sovracred\api_client;
use local_sovracred\api_exception;
use local_sovracred\claims;
use local_sovracred\config;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_sovracred_claimmap');

$url = new moodle_url('/local/sovracred/mapeo.php');
$sources = claims::sources();

if (optional_param('op', '', PARAM_ALPHA) === 'save') {
    require_sesskey();
    $schemaid = required_param('schema', PARAM_ALPHANUMEXT);
    $keys = optional_param_array('claimkey', [], PARAM_RAW_TRIMMED);
    $chosen = optional_param_array('claimsource', [], PARAM_RAW_TRIMMED);
    $values = optional_param_array('claimvalue', [], PARAM_TEXT);
    $types = optional_param_array('claimtype', [], PARAM_ALPHANUMEXT);
    $required = optional_param_array('claimrequired', [], PARAM_BOOL);

    $map = [];
    foreach ($keys as $i => $key) {
        $source = (string) ($chosen[$i] ?? '');
        if ($key === '' || !array_key_exists($source, $sources)) {
            continue;
        }
        $map[] = [
            'key' => $key,
            'source' => $source,
            'value' => trim((string) ($values[$i] ?? '')),
            'type' => (string) ($types[$i] ?? 'string'),
            'required' => !empty($required[$i]),
        ];
    }
    set_config('schemaid', $schemaid, 'local_sovracred');
    claims::save_map($schemaid, $map);
    redirect(new moodle_url($url, ['schema' => $schemaid]), get_string('map_saved', 'local_sovracred'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$current = claims::map();
$schemaid = optional_param('schema', config::schema_id(), PARAM_ALPHANUMEXT);

// Los esquemas de credencial del workspace, en su versión más alta.
$schemas = [];
$error = null;
try {
    foreach (api_client::from_config()->list_schemas() as $row) {
        if (($row['kind'] ?? 'credential') !== 'credential' || empty($row['schema_id'])) {
            continue;
        }
        $slug = $row['schema_id'];
        if (!isset($schemas[$slug]) || ($row['version'] ?? 0) > ($schemas[$slug]['version'] ?? 0)) {
            $schemas[$slug] = $row;
        }
    }
} catch (api_exception $e) {
    $error = $e;
}

// Las filas: los claims del esquema si se pudo leer; si no, los del mapeo guardado.
$bykey = array_column($current['claims'], null, 'key');
$rows = [];
if (isset($schemas[$schemaid])) {
    foreach ($schemas[$schemaid]['claims'] ?? [] as $claim) {
        if (empty($claim['key'])) {
            continue;
        }
        $saved = $bykey[$claim['key']] ?? null;
        $rows[] = [
            'key' => $claim['key'],
            'label' => (string) ($claim['label'] ?? ''),
            'type' => (string) ($claim['type'] ?? 'string'),
            'required' => !empty($claim['required']),
            'source' => $saved['source'] ?? '',
            'value' => $saved['value'] ?? '',
        ];
    }
} else if ($error || !$schemas) {
    foreach ($current['claims'] as $claim) {
        $rows[] = $claim + ['label' => ''];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('map', 'local_sovracred'));
echo html_writer::tag('p', get_string('map_intro', 'local_sovracred'));

if ($error) {
    echo $OUTPUT->notification(get_string('map_apierror', 'local_sovracred', s($error->summary())), 'warning', false);
} else if ($schemaid !== '' && !isset($schemas[$schemaid])) {
    echo $OUTPUT->notification(get_string('map_schemanotfound', 'local_sovracred', s($schemaid)), 'error', false);
}

// Elegir el esquema: recarga la página con sus claims.
if ($schemas) {
    $options = [];
    foreach ($schemas as $slug => $row) {
        $options[$slug] = format_string($row['name'] ?? $slug) . " ({$slug}, v" . (int) ($row['version'] ?? 1) . ')';
    }
    $select = new single_select($url, 'schema', $options, isset($schemas[$schemaid]) ? $schemaid : '', ['' => 'choosedots']);
    $select->set_label(get_string('schemaid', 'local_sovracred'));
    echo html_writer::div($OUTPUT->render($select), 'mb-3');
}

if ($rows) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'op', 'value' => 'save']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'schema', 'value' => $schemaid]);

    $table = new html_table();
    $table->head = [
        get_string('map_claim', 'local_sovracred'), get_string('map_type', 'local_sovracred'),
        get_string('map_required', 'local_sovracred'), get_string('map_source', 'local_sovracred'),
        get_string('map_value', 'local_sovracred'),
    ];
    foreach ($rows as $i => $row) {
        $claim = html_writer::tag('code', s($row['key']));
        if ($row['label'] !== '') {
            $claim .= html_writer::div(s($row['label']), 'small text-muted');
        }
        $claim .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => "claimkey[$i]", 'value' => $row['key']]);
        $claim .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => "claimtype[$i]", 'value' => $row['type']]);
        $claim .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => "claimrequired[$i]",
            'value' => $row['required'] ? 1 : 0]);

        // Un origen guardado que ya no existe (un campo borrado) se muestra, para que se vea y se cambie.
        $options = $sources;
        if ($row['source'] !== '' && !isset($options[$row['source']])) {
            $options[$row['source']] = get_string('source_missing', 'local_sovracred', s($row['source']));
        }

        $table->data[] = [
            $claim,
            html_writer::tag('code', s($row['type'])),
            $row['required'] ? get_string('yes') : '—',
            html_writer::select($options, "claimsource[$i]", $row['source'], false,
                ['class' => 'custom-select form-select', 'aria-label' => get_string('map_source', 'local_sovracred')]),
            html_writer::empty_tag('input', ['type' => 'text', 'name' => "claimvalue[$i]", 'value' => $row['value'],
                'class' => 'form-control', 'size' => 20, 'aria-label' => get_string('map_value', 'local_sovracred')]),
        ];
    }
    echo html_writer::table($table);
    echo html_writer::tag('p', get_string('map_privacy', 'local_sovracred'), ['class' => 'small text-muted']);
    echo html_writer::tag('button', get_string('map_save', 'local_sovracred'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
