<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred\task;

use local_sovracred\api_client;
use local_sovracred\api_exception;
use local_sovracred\issuance;

/**
 * Consulta a la API las ofertas creadas y no confirmadas, y aplica su estado.
 *
 * Es lo que hace opcional al webhook: si el firewall del sitio bloquea los avisos de Sovra,
 * las emisiones se confirman igual, con unos minutos de demora.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile extends \core\task\scheduled_task {

    /** @var int Ofertas por corrida. */
    const BATCH = 100;

    /**
     * Nombre de la tarea.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskreconcile', 'local_sovracred');
    }

    /**
     * Ejecuta la reconciliación.
     *
     * @return void
     */
    public function execute(): void {
        try {
            $api = api_client::from_config();
        } catch (api_exception $e) {
            mtrace('local_sovracred: sin configurar, nada que reconciliar.');
            return;
        }
        $this->run($api);
    }

    /**
     * Reconcilia con un cliente dado (las pruebas pasan uno con HTTP simulado).
     *
     * @param api_client $api
     * @return array Conteo por estado resultante, más 'errors'.
     */
    public function run(api_client $api): array {
        global $DB;

        $counts = ['errors' => 0];
        $records = $DB->get_records_select(issuance::TABLE, 'status = :status AND credentialid IS NOT NULL',
            ['status' => issuance::OFFERED], 'timemodified ASC', '*', 0, self::BATCH);

        foreach ($records as $record) {
            try {
                $record = issuance::refresh($record, $api);
                $counts[$record->status] = ($counts[$record->status] ?? 0) + 1;
            } catch (api_exception $e) {
                // El error quedó en el derecho; la próxima corrida vuelve a intentarlo.
                $counts['errors']++;
            }
        }
        mtrace('local_sovracred: ' . count($records) . ' ofertas consultadas ' . json_encode($counts));
        return $counts;
    }
}
