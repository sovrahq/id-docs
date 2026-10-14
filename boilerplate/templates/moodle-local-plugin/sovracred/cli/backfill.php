<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Registra el derecho de quienes completaron un curso habilitado ANTES de instalar el plugin.
 *
 *   php local/sovracred/cli/backfill.php            # muestra cuántos registraría
 *   php local/sovracred/cli/backfill.php --run      # los registra
 *   php local/sovracred/cli/backfill.php --run --notify   # y avisa a cada estudiante
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    ['run' => false, 'notify' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || $unrecognized) {
    echo "Registra derechos de cursos completados antes de instalar local_sovracred.\n\n" .
        "  --run       Registrarlos (sin esto, sólo cuenta).\n" .
        "  --notify    Además, mandar la notificación a cada estudiante.\n";
    exit(0);
}

$completions = $DB->get_recordset_select('course_completions', 'timecompleted IS NOT NULL AND timecompleted > 0',
    null, 'timecompleted ASC', 'id, userid, course, timecompleted');

$found = 0;
$registered = 0;
foreach ($completions as $completion) {
    if (!\local_sovracred\config::course_enabled((int) $completion->course)) {
        continue;
    }
    if ($DB->record_exists('local_sovracred_issuance', ['userid' => $completion->userid, 'courseid' => $completion->course])) {
        continue;
    }
    $found++;
    if (!$options['run']) {
        continue;
    }
    $issuance = \local_sovracred\issuance::register((int) $completion->userid, (int) $completion->course,
        (int) $completion->timecompleted);
    if ($issuance) {
        $registered++;
        if ($options['notify']) {
            \local_sovracred\issuance::notify($issuance);
        }
    }
}
$completions->close();

cli_writeln($options['run'] ? "Registrados: {$registered} de {$found}." : "Por registrar: {$found}. Repetí con --run para registrarlos.");
