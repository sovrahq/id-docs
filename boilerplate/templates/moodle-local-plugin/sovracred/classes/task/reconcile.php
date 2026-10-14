<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

namespace local_sovracred\task;

use local_sovracred\api_client;
use local_sovracred\api_exception;
use local_sovracred\issuance;

/**
 * Consulta a la API el estado de las credenciales y lo aplica, sin depender del webhook.
 *
 * Es lo que hace opcional al webhook: si el firewall del sitio bloquea los avisos de Sovra,
 * las emisiones se confirman igual (cada 5 minutos) y las revocaciones y suspensiones de
 * credenciales ya emitidas se reflejan una vez por día.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile extends \core\task\scheduled_task {

    /** @var int Ofertas abiertas por corrida. */
    const BATCH_OFFERED = 100;

    /** @var int Credenciales emitidas o suspendidas por corrida. */
    const BATCH_ISSUED = 50;

    /** @var int Cada cuánto se vuelve a consultar una credencial emitida o suspendida. */
    const ISSUED_INTERVAL = DAYSECS;

    /** @var int Cuánto se guardan los ids de entregas del webhook (Sovra reintenta 8 veces, ~1,5 h en total). */
    const WEBHOOK_RETENTION = 30 * DAYSECS;

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
        global $DB;

        $DB->delete_records_select('local_sovracred_webhook', 'timereceived < :cutoff',
            ['cutoff' => time() - self::WEBHOOK_RETENTION]);

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
     * Las menos consultadas primero: cada consulta actualiza timechecked, así que con más
     * candidatos que el lote, la corrida siguiente toma los que quedaron afuera.
     *
     * @param api_client $api
     * @param int $batchoffered
     * @param int $batchissued
     * @return array Conteo por estado resultante, más 'errors'.
     */
    public function run(api_client $api, int $batchoffered = self::BATCH_OFFERED,
            int $batchissued = self::BATCH_ISSUED): array {
        global $DB;

        $offered = $DB->get_records_select(issuance::TABLE, 'status = :status AND credentialid IS NOT NULL',
            ['status' => issuance::OFFERED], 'timechecked ASC, id ASC', '*', 0, $batchoffered);
        $issued = $DB->get_records_select(issuance::TABLE,
            'status IN (:issued, :suspended) AND credentialid IS NOT NULL AND timechecked < :due',
            ['issued' => issuance::ISSUED, 'suspended' => issuance::SUSPENDED, 'due' => time() - self::ISSUED_INTERVAL],
            'timechecked ASC, id ASC', '*', 0, $batchissued);

        $counts = ['errors' => 0];
        foreach (array_merge(array_values($offered), array_values($issued)) as $record) {
            try {
                $record = issuance::refresh($record, $api);
                $counts[$record->status] = ($counts[$record->status] ?? 0) + 1;
            } catch (api_exception $e) {
                // El error quedó en el derecho; la próxima corrida vuelve a intentarlo.
                $counts['errors']++;
            }
        }
        mtrace('local_sovracred: ' . (count($offered) + count($issued)) . ' consultadas ' . json_encode($counts));
        return $counts;
    }
}
