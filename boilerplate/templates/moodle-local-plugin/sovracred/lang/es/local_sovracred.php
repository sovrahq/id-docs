<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Cadenas en español para local_sovracred.
 *
 * @package    local_sovracred
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apikey'] = 'API key';
$string['apikey_desc'] = 'La API key del workspace (<code>sovra_sk_…</code>), de Settings → API keys en la consola de Sovra. Se guarda cifrada.';
$string['apiurl'] = 'URL de la API';
$string['apiurl_desc'] = 'Pruebas: <code>https://test-api-sovra.flagonsa.com</code>. Producción: <code>https://api.sovra.io</code>. El host tiene que estar permitido en Administración del sitio → Seguridad → Seguridad HTTP.';
$string['check_api'] = 'URL de la API, API key y esquema configurados.';
$string['check_lastrejected'] = 'Último aviso rechazado por firma inválida: {$a}. Revisá que el secreto del webhook coincida con el de la consola de Sovra.';
$string['check_webhook'] = 'Secreto del webhook configurado. URL para pegar en la consola de Sovra: <code>{$a}</code> (opcional: la tarea programada confirma las emisiones sin él).';
$string['completedon'] = 'Completado el {$a}';
$string['completedon_short'] = 'Completado';
$string['courseids'] = 'IDs de los cursos';
$string['courseids_desc'] = 'IDs de los cursos que emiten credencial, separados por comas, cuando el alcance es «Sólo los cursos listados».';
$string['coursescope'] = 'Cursos que emiten';
$string['coursescope_all'] = 'Todos los cursos';
$string['coursescope_desc'] = 'Qué cursos completados dan derecho a una credencial.';
$string['coursescope_list'] = 'Sólo los cursos listados';
$string['documentfield'] = 'Campo del número de documento';
$string['documentfield_desc'] = 'El campo del perfil donde está el número de documento del estudiante (claim <code>documento</code>).';
$string['error_api'] = 'No pudimos preparar tu credencial en este momento. Probá de nuevo en unos minutos.';
$string['error_missingdata'] = 'Tu credencial está casi lista, pero falta un dato. Ya les avisamos a los administradores de la plataforma.';
$string['lasterror'] = 'Último error';
$string['messageprovider:credentialavailable'] = 'Hay una credencial de curso lista para recibir';
$string['mycredentials'] = 'Mis credenciales';
$string['mycredentials_intro'] = 'Cada curso que completás te da una credencial verificable que guardás en tu cartera y podés mostrarle a quien quieras.';
$string['nocredentials'] = 'Todavía no tenés credenciales. Cuando completes un curso que emite una, va a aparecer acá.';
$string['notified'] = 'Se le envió la notificación al estudiante.';
$string['notify'] = 'Reenviar aviso';
$string['notifybody'] = 'Completaste {$a->course}. Tu credencial está lista: entrá a {$a->url} y tocá «Recibir en mi cartera».';
$string['notifybodyhtml'] = '<p>Completaste <strong>{$a->course}</strong>. Tu credencial está lista.</p><p><a href="{$a->url}">Entrá a Mis credenciales</a> y tocá «Recibir en mi cartera».</p>';
$string['notifysmall'] = 'Tu credencial de {$a->course} está lista para recibir.';
$string['notifysubject'] = 'Tu credencial de {$a->course} está lista';
$string['offerexpires'] = 'Este código vale hasta el {$a} y se puede usar una sola vez. Si vence, pedilo de nuevo.';
$string['offerexpires_short'] = 'Vence la oferta';
$string['openwallet'] = 'Abrir mi cartera';
$string['openwallet_help'] = 'Si estás en el teléfono, este botón abre directamente la app de tu cartera.';
$string['pluginname'] = 'Credenciales de curso con Sovra ID';
$string['privacy:metadata:issuance'] = 'El derecho de un estudiante a la credencial de un curso completado, y su oferta en Sovra.';
$string['privacy:metadata:issuance:courseid'] = 'El curso completado.';
$string['privacy:metadata:issuance:credentialid'] = 'El identificador de la credencial en Sovra.';
$string['privacy:metadata:issuance:holderdid'] = 'El identificador descentralizado de la cartera que recibió la credencial.';
$string['privacy:metadata:issuance:status'] = 'Si la credencial está disponible, ofrecida, emitida, suspendida o revocada.';
$string['privacy:metadata:issuance:timeissued'] = 'Cuándo llegó la credencial a la cartera.';
$string['privacy:metadata:issuance:userid'] = 'El estudiante.';
$string['privacy:metadata:issuance:verificationcode'] = 'El código de verificación que lleva la credencial.';
$string['privacy:metadata:sovra'] = 'Cuando el estudiante pide recibir una credencial, sus datos se envían a Sovra ID, que la firma y la entrega en la cartera del estudiante.';
$string['privacy:metadata:sovra:curso'] = 'El nombre del curso.';
$string['privacy:metadata:sovra:documento'] = 'El número de documento del estudiante.';
$string['privacy:metadata:sovra:fecha'] = 'La fecha de finalización.';
$string['privacy:metadata:sovra:holdername'] = 'El nombre completo del estudiante.';
$string['qr_alt'] = 'Código QR para recibir la credencial';
$string['receive'] = 'Recibir en mi cartera';
$string['refresh'] = 'Consultar estado';
$string['refreshed'] = 'Estado consultado: {$a}.';
$string['report'] = 'Credenciales de curso (Sovra ID)';
$string['scanqr'] = 'Escaneá este código con tu cartera:';
$string['schemaid'] = 'Esquema';
$string['schemaid_desc'] = 'El slug del esquema (<code>schema_id</code>) en la consola de Sovra, no su UUID ni su nombre visible.';
$string['settings_connection'] = 'Conexión con Sovra ID';
$string['settings_connection_desc'] = 'URL del webhook para configurar en la consola de Sovra: <code>{$a->webhookurl}</code>. Estado y prueba de conexión: <a href="{$a->reporturl}">reporte</a>.';
$string['settings_issuing'] = 'Qué emite una credencial';
$string['sovracred:viewreport'] = 'Ver el reporte de credenciales de curso';
$string['status_available'] = 'Disponible';
$string['status_issued'] = 'Emitida';
$string['status_issued_student'] = 'Está en tu cartera desde el {$a}.';
$string['status_offered'] = 'Oferta creada';
$string['status_revoked'] = 'Revocada';
$string['status_revoked_student'] = 'Esta credencial fue revocada.';
$string['status_suspended'] = 'Suspendida';
$string['status_suspended_student'] = 'Esta credencial está suspendida.';
$string['taskreconcile'] = 'Confirmar ofertas de credenciales de Sovra ID';
$string['test_created'] = 'La conexión funciona, pero el esquema no tiene campos obligatorios, así que la prueba creó una oferta. Vence sola en 24 horas.';
$string['test_hostblocked'] = 'El host de la API está bloqueado en Administración del sitio → Seguridad → Seguridad HTTP.';
$string['test_invalid_api_key'] = 'Sovra rechazó la API key. Generá una nueva en la consola y volvé a guardarla.';
$string['test_network'] = 'Moodle no pudo llegar a la API. Revisá la URL, el DNS y el firewall de salida.';
$string['test_notconfigured'] = 'Falta la URL de la API, la API key o el esquema.';
$string['test_ok'] = 'Conexión correcta: la API key es válida y el esquema existe. No se creó ninguna oferta.';
$string['test_other'] = 'Respuesta inesperada de la API: {$a}';
$string['test_schema_not_found'] = 'El esquema no existe en este workspace. Usá su slug, no su UUID.';
$string['test_workspace_not_provisioned'] = 'El workspace todavía se está aprovisionando. Probá de nuevo en unos minutos.';
$string['testconnection'] = 'Probar conexión';
$string['verificationcode'] = 'Código de verificación: {$a}';
$string['webhooksecret'] = 'Secreto del webhook';
$string['webhooksecret_desc'] = 'De Settings → Workspace → Webhook secret en la consola de Sovra (se muestra una sola vez). Se guarda cifrado. Opcional: sin él, las emisiones se confirman con la tarea programada.';
