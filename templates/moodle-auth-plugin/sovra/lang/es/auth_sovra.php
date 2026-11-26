<?php
// This file is part of the Sovra ID boilerplate for Moodle.
//
// Licensed under the GNU GPL v3 or later, like Moodle itself.

/**
 * Strings for auth_sovra (español).
 *
 * @package    auth_sovra
 * @copyright  2026 Sovra
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allowadmins'] = 'Permitir administradores del sitio';
$string['allowadmins_desc'] = 'Si está desactivado (recomendado), las cuentas administradoras del sitio no pueden entrar con la SuperApp: siguen entrando con su contraseña.';
$string['allowedauths'] = 'Métodos de autenticación aceptados';
$string['allowedauths_desc'] = 'Sólo pueden entrar con la SuperApp las cuentas que usan uno de estos métodos. Las cuentas con «Sin ingreso» (nologin) nunca entran.';
$string['allowedissuers'] = 'Emisores permitidos';
$string['allowedissuers_desc'] = 'DIDs de los emisores cuyas credenciales se aceptan, uno por línea (por ejemplo, el de la Alcaldía de Bogotá: <code>did:sovra:0x…</code>). <strong>Obligatorio:</strong> con la lista vacía el botón no aparece y nadie entra. Sin esta lista, cualquiera podría emitirse una credencial con un documento ajeno.';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'La API key del workspace verificador (<code>sovra_sk_…</code>). Se guarda cifrada.';
$string['apiurl'] = 'URL de la API';
$string['apiurl_desc'] = 'Prueba: <code>https://test-api-sovra.flagonsa.com</code>. Producción: <code>https://api.sovra.io</code>.';
$string['auth_sovradescription'] = 'Ingreso con la Credencial de Identidad de la billetera (Sovra ID). Identifica a la persona por su número de documento; no crea cuentas.';
$string['back'] = 'Volver al ingreso';
$string['claimdocnumber'] = 'Claim del número de documento';
$string['claimdocnumber_desc'] = 'Nombre exacto del claim en la credencial. Credencial de Identidad de Bogotá: <code>num_doc</code>.';
$string['claimdoctype'] = 'Claim del tipo de documento';
$string['claimdoctype_desc'] = 'Nombre exacto del claim en la credencial. Credencial de Identidad de Bogotá: <code>tipo_doc</code>. Sólo se pide si se compara el tipo.';
$string['credentialtypes'] = 'Tipos de credencial aceptados';
$string['credentialtypes_desc'] = 'Valores de <code>vct</code> aceptados, separados por comas. Credencial de Identidad de Bogotá: <code>IdentidadCiudadano</code>. Vacío: cualquier tipo de un emisor permitido.';
$string['doctypemap'] = 'Equivalencias de tipos de documento';
$string['doctypemap_desc'] = 'Una línea por equivalencia: <code>valor en la credencial = valor en Moodle</code>. Se compara sin mayúsculas ni tildes. Un tipo sin línea se compara tal cual.';
$string['document_locked'] = 'Tu número y tipo de documento no se pueden cambiar desde el perfil: con ellos entras con la SuperApp. Si hay un error, contacta a soporte.';
$string['documentfield'] = 'Campo con el número de documento';
$string['documentfield_desc'] = 'Dónde guarda Moodle el número de documento de cada cuenta. Se compara sin puntos, espacios ni guiones.';
$string['documenttypefield'] = 'Campo con el tipo de documento';
$string['documenttypefield_desc'] = 'Campo de perfil con el tipo de documento. Sólo se usa si se compara el tipo.';
$string['matchdoctype'] = 'Comparar también el tipo de documento';
$string['matchdoctype_desc'] = 'Además del número, compara el tipo de documento de la credencial con el del «Campo con el tipo de documento», usando las equivalencias. Evita que un documento de otro tipo con los mismos dígitos abra una cuenta ajena. Sólo tiene efecto si se elige ese campo; entonces también se pide el tipo a la billetera.';
$string['logs'] = 'Registro del ingreso con la SuperApp';
$string['logs_action'] = 'Llamada, evento u origen';
$string['logs_empty'] = 'Todavía no hay nada registrado.';
$string['logs_http'] = 'HTTP';
$string['logs_intro'] = 'Las últimas 300 entradas: llamadas a la API de Sovra, avisos del webhook y el resultado de cada intento de ingreso. Se guardan 30 días. No se guardan números ni tipos de documento.';
$string['logs_kind'] = 'Tipo';
$string['logs_result'] = 'Resultado';
$string['logs_time'] = 'Fecha';
$string['logs_user'] = 'Cuenta';
$string['logs_verification'] = 'Verificación';
$string['loginbutton'] = 'Ingresar con la SuperApp';
$string['login_connection'] = 'Tenemos problemas de conexión. Seguimos intentando…';
$string['login_error'] = 'No pudimos iniciar el ingreso con la SuperApp en este momento. Intenta de nuevo en unos minutos.';
$string['login_expired'] = 'El código venció. Genera uno nuevo para intentarlo otra vez.';
$string['login_expiresin'] = 'El código vence en {$a}.';
$string['login_intro'] = 'Escanea el código con la SuperApp de Bogotá y comparte tu Credencial de Identidad. Sólo se piden los datos de tu documento.';
$string['login_newcode'] = 'Generar un código nuevo';
$string['login_start'] = 'Mostrar el código';
$string['login_throttled'] = 'Pediste muchos códigos seguidos. Espera unos minutos e inténtalo de nuevo.';
$string['login_openwallet'] = 'Abrir en la SuperApp';
$string['login_openwallet_hint'] = '¿Estás en el teléfono? Toca el botón en lugar de escanear.';
$string['login_title'] = 'Ingresar con la SuperApp';
$string['login_unavailable'] = 'El ingreso con la SuperApp no está disponible en este momento.';
$string['login_waiting'] = 'Esperando que compartas tu credencial…';
$string['login_success'] = 'Listo, estamos abriendo tu cuenta…';
$string['pluginname'] = 'Ingreso con la SuperApp (Sovra ID)';
$string['platformname'] = 'Nombre de la plataforma';
$string['platformname_desc'] = 'Cómo se nombra la plataforma en el aviso para quien no tiene cuenta: «No tienes cuenta en <em>nombre</em>». Vacío: el nombre corto del sitio.';
$string['privacy:metadata:auth_sovra_log'] = 'Registro de los intentos de ingreso con la SuperApp.';
$string['privacy:metadata:auth_sovra_log:result'] = 'El resultado del intento.';
$string['privacy:metadata:auth_sovra_log:timecreated'] = 'Cuándo ocurrió.';
$string['privacy:metadata:auth_sovra_log:userid'] = 'La cuenta que entró o que se encontró.';
$string['privacy:metadata:auth_sovra_log:verificationid'] = 'El identificador de la verificación en Sovra.';
$string['privacy:metadata:auth_sovra_session'] = 'Sesiones de ingreso en curso. Se borran alrededor de una hora después de vencer.';
$string['privacy:metadata:auth_sovra_session:userid'] = 'La cuenta encontrada para la verificación.';
$string['privacy:metadata:sovra'] = 'Para verificar la credencial, la billetera de la persona le envía a Sovra ID el emisor, el tipo de credencial y el número de documento (y el tipo de documento, si se compara); Sovra se los devuelve a Moodle, que los usa para encontrar la cuenta y no los guarda.';
$string['privacy:metadata:sovra:document'] = 'Tipo y número de documento.';
$string['qr_alt'] = 'Código QR para compartir tu credencial con la SuperApp';
$string['result_admin'] = 'Esta cuenta no puede entrar con la SuperApp. Ingresa con tu usuario y contraseña.';
$string['result_authnotallowed'] = 'Esta cuenta no puede entrar con la SuperApp. Ingresa con tu usuario y contraseña, o contacta a soporte.';
$string['result_duplicate'] = 'Encontramos más de una cuenta con tu documento, así que no podemos saber cuál es la tuya. Contacta a soporte para que lo resuelvan.';
$string['result_expired'] = 'El ingreso tardó demasiado y venció. Inténtalo de nuevo.';
$string['result_failed'] = 'No pudimos validar tu credencial. Revisa que esté vigente en la SuperApp e inténtalo de nuevo.';
$string['result_generic'] = 'No pudimos completar el ingreso. Inténtalo de nuevo.';
$string['result_issuernotallowed'] = 'Esa credencial no está aceptada para ingresar aquí. Comparte la Credencial de Identidad de la SuperApp de Bogotá.';
$string['result_misconfigured'] = 'El ingreso con la SuperApp no está bien configurado. Ya pueden revisarlo los administradores de la plataforma.';
$string['result_nodocument'] = 'Tu credencial no trae el número de documento. Comparte la Credencial de Identidad de la SuperApp de Bogotá.';
$string['result_notfound'] = 'No tienes cuenta en {$a}';
$string['result_notfound_nosignup'] = 'No hay ninguna cuenta con tu documento. Contacta a soporte para que te ayuden a crearla.';
$string['result_notfound_signup'] = 'No hay ninguna cuenta con tu documento. Regístrate y después podrás entrar con la SuperApp.';
$string['result_unconfirmed'] = 'Tu cuenta todavía no está confirmada. Confírmala desde el correo que te enviamos al registrarte y después podrás entrar con la SuperApp.';
$string['result_suspended'] = 'Tu cuenta está suspendida. Contacta a soporte.';
$string['retry'] = 'Intentar de nuevo';
$string['secret_undecryptable'] = 'No se puede descifrar: {$a}. Pasa, por ejemplo, después de migrar el sitio. El ingreso con la SuperApp queda apagado hasta volver a guardarlo en la configuración.';
$string['settings_accounts'] = 'Qué cuentas pueden entrar';
$string['settings_connection'] = 'Conexión con Sovra ID';
$string['settings_connection_desc'] = 'URL del webhook para la consola de Sovra (evento <code>presentation.verified</code> y <code>presentation.failed</code>): <code>{$a->webhookurl}</code>. El webhook es opcional: la página de ingreso consulta el resultado mientras espera. Registro y prueba de conexión: <a href="{$a->logsurl}">{$a->logsurl}</a>.';
$string['settings_credential'] = 'Qué credencial se acepta';
$string['settings_credential_desc'] = 'Se piden sólo el emisor, el tipo de credencial y el número de documento (y el tipo de documento, si se compara).';
$string['settings_matching'] = 'Cómo se encuentra la cuenta';
$string['settings_matching_desc'] = 'Entra la persona sólo si hay exactamente una cuenta con su documento. Si no hay ninguna, no se crea: se le muestra «No tienes cuenta en <plataforma>» y el enlace al registro.';
$string['signupurl'] = 'Página de registro';
$string['signupurl_desc'] = 'Adónde se manda a quien no tiene cuenta. Vacío: el registro de Moodle, si está habilitado.';
$string['signup'] = 'Ir al registro';
$string['task_cleanup'] = 'Limpiar sesiones vencidas y registro viejo del ingreso con la SuperApp';
$string['test_button'] = 'Probar la conexión';
$string['test_error'] = 'La prueba falló: {$a}';
$string['test_issuers_missing'] = 'No hay emisores permitidos: el botón no aparece y nadie puede entrar.';
$string['test_ok'] = 'Conexión correcta: la API aceptó la consulta y abrió una verificación de prueba (vence sola en 10 minutos). API key {$a}.';
$string['webhooksecret'] = 'Secreto del webhook';
$string['webhooksecret_desc'] = 'El secreto con el que Sovra firma los avisos. Opcional. Se guarda cifrado.';
$string['workspaceid'] = 'Workspace ID';
$string['workspaceid_desc'] = 'Opcional. Si está, se ignoran los avisos de otros workspaces.';
