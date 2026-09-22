import { config } from "./config.js";
import { PHONE_CLAIM } from "./credential.js";
import { messages } from "./messages.js";
import { samePhone } from "./phone.js";
import * as sovra from "./sovra.js";
import * as store from "./store.js";
import * as whatsapp from "./whatsapp.js";

/**
 * La máquina de estados del bot.
 *
 *   idle ──(cualquier mensaje)─────▶ awaiting_presentation   [link + QR]
 *        ──(presentation.verified)─▶ verified                [✅ con sus datos]
 *        ──(presentation.failed)───▶ idle                    [❌ con motivo]
 *
 * El bot no le pregunta nada al ciudadano. La credencial trae los datos: el
 * primer mensaje ya devuelve el deep link, y la identificación llega en el
 * webhook.
 */

/** Las sesiones de verificación de Sovra viven 10 minutos. */
const SESSION_MINUTES = 10;

const RETRY_WORDS = new Set(["reintentar", "reintento", "de nuevo", "otro link"]);
const CANCEL_WORDS = new Set(["cancelar", "cancela", "salir", "basta"]);

export async function handleMessage(message: whatsapp.IncomingMessage): Promise<void> {
  const phone = message.from;
  const normalized = whatsapp.messageText(message).toLowerCase();

  // Fire and forget: que el tilde azul no bloquee la respuesta.
  whatsapp.markAsRead(message.id).catch((err) => console.error("markAsRead", err));

  const conversation = store.getConversation(phone);

  if (CANCEL_WORDS.has(normalized)) {
    store.resetConversation(phone);
    await whatsapp.sendText(phone, messages.cancelled());
    return;
  }

  if (conversation.step === "awaiting_presentation") {
    const expired =
      !conversation.expiresAt || new Date(conversation.expiresAt) < new Date();

    // Si venció, o si pide explícitamente otro, le damos un link nuevo.
    if (expired || RETRY_WORDS.has(normalized)) {
      if (conversation.sessionId) store.takeSession(conversation.sessionId);
      await startVerification(phone);
      return;
    }

    await whatsapp.sendText(phone, messages.stillWaiting());
    return;
  }

  // "idle" y "verified": cualquier mensaje arranca un trámite nuevo.
  await startVerification(phone);
}

/**
 * Crea la sesión OID4VP y manda las dos formas de abrirla: el link https
 * (clickeable en el celular) y el QR (para quien lee WhatsApp en la compu).
 */
async function startVerification(phone: string): Promise<void> {
  const session = await sovra.createVerification();

  store.linkSession({
    sessionId: session.session_id,
    phone,
    authorizationRequestUri: session.authorization_request_uri,
    expiresAt: session.expires_at,
  });

  store.saveConversation({
    phone,
    step: "awaiting_presentation",
    sessionId: session.session_id,
    expiresAt: session.expires_at,
  });

  // `openid4vp://...` no es clickeable dentro de WhatsApp, así que mandamos una
  // URL https nuestra que redirige (ver src/routes/wallet.ts).
  const walletUrl = `${config.publicBaseUrl}/w/${session.session_id}`;
  const qrUrl = `${config.publicBaseUrl}/qr/${session.session_id}.png`;

  await whatsapp.sendText(phone, messages.presentationLink(walletUrl, SESSION_MINUTES));
  await whatsapp.sendImage(phone, qrUrl, messages.qrCaption());
}

/* ─── Webhooks de Sovra ───────────────────────────────────────────────────── */

export async function handleVerified(data: sovra.PresentationVerified): Promise<void> {
  const session = store.takeSession(data.verification_id);
  if (!session) {
    // Puede pasar si reiniciaste el proceso (estado en memoria) o si el evento
    // llegó dos veces. Con una base de datos real esto es casi siempre un bug.
    console.warn(`Sesión desconocida: ${data.verification_id}`);
    return;
  }

  const claims = data.credentials[0]?.claims ?? {};

  // Sovra ya probó que la credencial es auténtica y que quien la presentó es su
  // titular. Lo que no puede saber es si ese titular es quien abrió este chat:
  // el link se puede reenviar. El teléfono de la credencial cierra esa puerta.
  if (PHONE_CLAIM) {
    const declared = String(claims[PHONE_CLAIM] ?? "");

    if (!samePhone(declared, session.phone)) {
      store.resetConversation(session.phone);
      await whatsapp.sendText(session.phone, messages.phoneMismatch());
      return;
    }
  }

  store.saveConversation({ phone: session.phone, step: "verified" });

  await whatsapp.sendText(session.phone, messages.verified(claims));

  // 👉 Acá enganchás tu trámite: iniciar la sesión, crear el expediente, dar de
  //    alta el turno. Los valores de `claims` ya están verificados: son el
  //    nombre, el CUIL y el teléfono reales del ciudadano.
}

export async function handleFailed(data: sovra.PresentationFailed): Promise<void> {
  const session = store.takeSession(data.verification_id);
  if (!session) {
    console.warn(`Sesión desconocida: ${data.verification_id}`);
    return;
  }

  store.resetConversation(session.phone);
  await whatsapp.sendText(
    session.phone,
    messages.failed(sovra.explainError(data.error)),
  );
}
