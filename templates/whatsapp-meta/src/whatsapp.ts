import crypto from "node:crypto";
import { config } from "./config.js";

/**
 * Cliente de la Cloud API de Meta — directo contra graph.facebook.com, sin BSP.
 * Los cuerpos salen tal cual del collection "WhatsApp Cloud API" de Meta.
 */

const GRAPH_URL = `https://graph.facebook.com/${config.whatsapp.graphVersion}/${config.whatsapp.phoneNumberId}/messages`;

async function send(body: Record<string, unknown>): Promise<void> {
  const res = await fetch(GRAPH_URL, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${config.whatsapp.token}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    // El error de Graph viene como { error: { message, code, error_subcode } }.
    const detail = await res.text();
    throw new Error(`WhatsApp ${res.status}: ${detail}`);
  }
}

export async function sendText(to: string, text: string): Promise<void> {
  await send({
    messaging_product: "whatsapp",
    recipient_type: "individual",
    to,
    type: "text",
    // preview_url: true deja que WhatsApp arme la tarjeta del link. Lo dejamos
    // en false para que el mensaje no dependa de que Meta pueda alcanzar la URL.
    text: { preview_url: false, body: text },
  });
}

/**
 * `link` tiene que ser una URL pública: el que la descarga es Meta, no el
 * ciudadano. Con ngrok caído o con localhost, el mensaje falla con el
 * error 131014 ("Request for url ... failed").
 */
export async function sendImage(
  to: string,
  link: string,
  caption?: string,
): Promise<void> {
  await send({
    messaging_product: "whatsapp",
    recipient_type: "individual",
    to,
    type: "image",
    image: caption ? { link, caption } : { link },
  });
}

/** Los dos tildes azules. Puramente cosmético, pero el chat se siente vivo. */
export async function markAsRead(messageId: string): Promise<void> {
  await send({
    messaging_product: "whatsapp",
    status: "read",
    message_id: messageId,
  });
}

/**
 * Meta firma cada webhook con el App Secret:
 *   X-Hub-Signature-256: sha256=<hex>
 * sobre el cuerpo CRUDO. Si parseás y volvés a serializar, no coincide.
 */
export function isValidSignature(rawBody: Buffer, header: string | undefined): boolean {
  if (!header) return false;

  const expected =
    "sha256=" +
    crypto
      .createHmac("sha256", config.whatsapp.appSecret)
      .update(rawBody)
      .digest("hex");

  const a = Buffer.from(header);
  const b = Buffer.from(expected);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/* ─── Tipos del webhook (solo lo que usamos) ──────────────────────────────── */

export interface IncomingMessage {
  from: string; // wa_id del ciudadano, en formato E.164 sin '+'
  id: string; // wamid....
  timestamp: string;
  type: string;
  text?: { body: string };
  button?: { text: string; payload: string };
  interactive?: {
    type: string;
    button_reply?: { id: string; title: string };
    list_reply?: { id: string; title: string };
  };
}

export interface WhatsAppWebhookBody {
  object: string;
  entry?: Array<{
    id: string;
    changes?: Array<{
      field: string;
      value: {
        messaging_product: string;
        metadata?: { display_phone_number: string; phone_number_id: string };
        contacts?: Array<{ profile: { name: string }; wa_id: string }>;
        messages?: IncomingMessage[];
        statuses?: Array<{ id: string; status: string; recipient_id: string }>;
      };
    }>;
  }>;
}

/** Aplana el sobre de Meta y devuelve solo los mensajes entrantes. */
export function extractMessages(body: WhatsAppWebhookBody): IncomingMessage[] {
  return (body.entry ?? [])
    .flatMap((entry) => entry.changes ?? [])
    .filter((change) => change.field === "messages")
    .flatMap((change) => change.value.messages ?? []);
}

/** Texto del mensaje, venga como texto libre, botón o item de lista. */
export function messageText(message: IncomingMessage): string {
  return (
    message.text?.body ??
    message.interactive?.button_reply?.title ??
    message.interactive?.list_reply?.title ??
    message.button?.text ??
    ""
  ).trim();
}
