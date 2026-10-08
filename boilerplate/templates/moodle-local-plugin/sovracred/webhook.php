<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Endpoint del webhook de Sovra: https://tu-moodle/local/sovracred/webhook.php
 *
 * Sin sesión de Moodle: la autenticación es la firma X-Sovra-Signature sobre el cuerpo crudo.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Lo autentica la firma HMAC, no una sesión.
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

// El cuerpo crudo, byte por byte: la firma es sobre esto, no sobre el JSON decodificado.
$rawbody = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SOVRA_SIGNATURE'] ?? null;

$status = \local_sovracred\webhook::handle($rawbody, $signature, \local_sovracred\config::webhook_secret());

http_response_code($status);
echo json_encode(['ok' => $status === 200]);
