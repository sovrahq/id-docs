/**
 * Qué le pedimos a la credencial.
 *
 * Va acá y no en `.env` a propósito: esto no es configuración de despliegue, es
 * la definición del trámite. Cambiarla cambia qué datos le pedís al ciudadano —
 * una decisión de producto y de privacidad, que merece pasar por code review y
 * quedar en el historial de git, no editarse en caliente en un servidor.
 */

/**
 * Los claims del DCQL.
 *
 * Son también la identificación: el bot no le pregunta nada al ciudadano, lo
 * identifica con lo que llega en `presentation.verified`.
 *
 * ⚠️ Tienen que coincidir **carácter por carácter** con los `key` del esquema:
 * sensible a mayúsculas, sin normalizaciones, sin sinónimos. Si el esquema
 * declaró `fist_name` (con errata), pedir `first_name` falla con
 * `missing_required_claim:first_name`. Consultalos en Dashboard → Schemas.
 *
 * ⚠️ Son **todos obligatorios**: si el ciudadano no divulga uno solo, la
 * presentación entera falla. Pedí el mínimo indispensable — es mejor para la
 * privacidad y para la tasa de éxito.
 */
export const CLAIMS = ["cuil", "nombre", "apellido", "phone"] as const;

/**
 * Etiqueta libre del DCQL. Sovra la devuelve tal cual en el webhook, para que
 * sepas qué credencial es cuál cuando pedís más de una. No filtra ni valida nada.
 */
export const CREDENTIAL_LABEL = "identidad";

/**
 * El claim que lleva el teléfono, para atarlo al número de WhatsApp que escribió.
 *
 * Sovra prueba que la credencial es auténtica y que quien la presentó es su
 * titular. Lo que no puede saber es si ese titular es quien abrió *este* chat:
 * el link se puede reenviar. Comparar el teléfono de la credencial contra el
 * `wa_id` cierra esa puerta — el atacante no puede hacer que la credencial
 * ajena diga su propio número.
 *
 * Poné `null` si tu esquema no lleva el teléfono. El bot sigue funcionando, pero
 * un link reenviado puede asociar la identidad de otra persona a este chat.
 */
export const PHONE_CLAIM: string | null = "phone";

// Si pedís el cotejo, el claim tiene que estar en el DCQL: si no, nunca llega.
if (PHONE_CLAIM && !CLAIMS.includes(PHONE_CLAIM as (typeof CLAIMS)[number])) {
  throw new Error(
    `PHONE_CLAIM "${PHONE_CLAIM}" no está en CLAIMS. Agregalo, o poné PHONE_CLAIM = null.`,
  );
}
