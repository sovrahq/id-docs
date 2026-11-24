/**
 * Estado del bot, en memoria.
 *
 * ⚠️ Es un Map: se pierde en cada reinicio y no sirve con más de una instancia.
 * Para producción reemplazá este archivo por tu base de datos — la interfaz
 * (`getConversation`, `saveConversation`, `linkSession`, `takeSession`) es todo
 * lo que usa el resto del código.
 *
 * Dos tablas, como en el diagrama:
 *
 *   conversación  phone (wa_id)  → estado del trámite
 *   sesión        session_id     → phone + la URI que le mandamos
 *
 * La segunda es la que permite correlacionar el webhook de Sovra —que solo
 * conoce el `verification_id`— con el chat de WhatsApp que lo originó.
 */

export type Step =
  | "idle" /** Sin trámite en curso. */
  | "awaiting_presentation" /** Le mandamos el link y esperamos el webhook. */
  | "verified"; /** Presentó y validó. */

export interface Conversation {
  phone: string;
  step: Step;
  /** Sesión de verificación en curso, si la hay. */
  sessionId?: string;
  /** ISO 8601. La sesión de Sovra vive 10 minutos. */
  expiresAt?: string;
  updatedAt: string;
}

export interface Session {
  sessionId: string;
  /** `wa_id` del ciudadano: adónde le contestamos cuando llegue el webhook. */
  phone: string;
  /** `authorization_request_uri` de Sovra, servido por /w/:id y /qr/:id.png. */
  authorizationRequestUri: string;
  /** ISO 8601. Las sesiones de Sovra viven 10 minutos. */
  expiresAt: string;
}

const conversations = new Map<string, Conversation>();
const sessions = new Map<string, Session>();
/** IDs de entrega ya procesados, para idempotencia de webhooks. */
const seenDeliveries = new Set<string>();

export function getConversation(phone: string): Conversation {
  return (
    conversations.get(phone) ?? {
      phone,
      step: "idle",
      updatedAt: new Date().toISOString(),
    }
  );
}

export function saveConversation(conversation: Omit<Conversation, "updatedAt">): void {
  conversations.set(conversation.phone, {
    ...conversation,
    updatedAt: new Date().toISOString(),
  });
}

export function resetConversation(phone: string): void {
  const previous = conversations.get(phone);
  if (previous?.sessionId) sessions.delete(previous.sessionId);
  conversations.delete(phone);
}

export function linkSession(session: Session): void {
  sessions.set(session.sessionId, session);
}

export function getSession(sessionId: string): Session | undefined {
  return sessions.get(sessionId);
}

/** La sesión, solo si todavía no venció. Descarta la vencida de paso. */
export function getLiveSession(sessionId: string): Session | undefined {
  const session = sessions.get(sessionId);
  if (!session) return undefined;

  if (new Date(session.expiresAt) < new Date()) {
    sessions.delete(sessionId);
    return undefined;
  }

  return session;
}

/** Lee y descarta la sesión: cada verificación se resuelve una sola vez. */
export function takeSession(sessionId: string): Session | undefined {
  const session = sessions.get(sessionId);
  if (session) sessions.delete(sessionId);
  return session;
}

/**
 * Idempotencia: Sovra y Meta reintentan las entregas, así que el mismo evento
 * puede llegar más de una vez. `true` significa "ya lo procesé, ignoralo".
 */
export function alreadyHandled(deliveryId: string): boolean {
  if (seenDeliveries.has(deliveryId)) return true;
  seenDeliveries.add(deliveryId);
  return false;
}
