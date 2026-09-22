import crypto from "node:crypto";
import { config } from "./config.js";
import { CLAIMS, CREDENTIAL_LABEL } from "./credential.js";

/**
 * Cliente de Sovra ID — plataforma actual: SD-JWT VC, OID4VP + DCQL,
 * `Authorization: Bearer sovra_sk_...`.
 *
 * Este boilerplate asume que el ciudadano YA TIENE la credencial en su wallet:
 * solo usa la zona de verificación (`/api/v1/verifier/...`). La emisión está en
 * docs/guides/credentials/03-emision-de-credenciales.md.
 */

export interface VerificationSession {
  session_id: string;
  /** URI autocontenida: lleva el DCQL embebido. La que mandamos por defecto. */
  authorization_request_uri: string;
  /** URI corta: la wallet descarga la solicitud aparte. Mejor si el DCQL crece. */
  authorization_request_uri_ref: string;
  status: "pending";
  expires_at: string;
}

/**
 * Crea la sesión OID4VP y devuelve las dos URIs.
 *
 * Todos los claims del DCQL son obligatorios: si el ciudadano no divulga uno
 * solo, la presentación entera falla con `missing_required_claim:<path>`.
 * Pedí el mínimo indispensable.
 */
export async function createVerification(): Promise<VerificationSession> {
  const res = await fetch(`${config.sovra.baseUrl}/api/v1/verifier/verifications`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${config.sovra.apiKey}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      dcql_query: {
        credentials: [
          {
            id: CREDENTIAL_LABEL,
            format: "vc+sd-jwt",
            claims: CLAIMS.map((path) => ({ path: [path] })),
          },
        ],
      },
    }),
  });

  if (!res.ok) {
    const detail = await res.text();
    throw new Error(`Sovra ${res.status}: ${detail}`);
  }

  return (await res.json()) as VerificationSession;
}

/** Red de contención por si tu endpoint de webhook estuvo caído. */
export async function getVerification(sessionId: string): Promise<unknown> {
  const res = await fetch(
    `${config.sovra.baseUrl}/api/v1/verifier/verifications/${sessionId}`,
    { headers: { Authorization: `Bearer ${config.sovra.apiKey}` } },
  );

  if (!res.ok) throw new Error(`Sovra ${res.status}: ${await res.text()}`);
  return res.json();
}

/**
 * X-Sovra-Signature: sha256=<hex> = HMAC-SHA256(webhook_secret, cuerpo crudo).
 * Sin esta validación, cualquiera que conozca tu URL te inventa una
 * verificación aprobada.
 */
export function isValidSignature(rawBody: Buffer, header: string | undefined): boolean {
  if (!header) return false;

  const expected =
    "sha256=" +
    crypto
      .createHmac("sha256", config.sovra.webhookSecret)
      .update(rawBody)
      .digest("hex");

  const a = Buffer.from(header);
  const b = Buffer.from(expected);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/* ─── Tipos del webhook ───────────────────────────────────────────────────── */

export interface SovraEnvelope<T> {
  event: string;
  /** UUID único de esta entrega. Clave de idempotencia. */
  id: string;
  timestamp: string;
  workspace_id: string;
  data: T;
}

export interface PresentationVerified {
  verification_id: string;
  holder_did: string | null;
  success: true;
  completed_at: string;
  credentials: Array<{
    id: string;
    format: string;
    /** Valores divulgados, indexados por el path unido con puntos. */
    claims: Record<string, string | number | boolean>;
  }>;
}

export interface PresentationFailed {
  verification_id: string;
  holder_did: string | null;
  success: false;
  error: string;
  completed_at: string;
}

/**
 * Traduce el `error` técnico a algo que un ciudadano pueda entender y accionar.
 * El catálogo completo está en
 * docs/guides/credentials/08-errores-y-troubleshooting.md.
 */
export function explainError(error: string): string {
  if (error.startsWith("missing_required_claim:")) {
    const claim = error.slice("missing_required_claim:".length);
    return `No compartiste el dato "${claim}", y lo necesitamos para seguir. Probá de nuevo y aprobá todos los campos que te pide la wallet.`;
  }

  const messages: Record<string, string> = {
    credential_expired: "Tu credencial está vencida. Tenés que renovarla antes de seguir.",
    credential_revoked: "Tu credencial fue revocada y ya no es válida.",
    credential_suspended:
      "Tu credencial está suspendida. Comunicate con el organismo que la emitió.",
    session_expired: "El link venció. Escribime de nuevo y te mando uno nuevo.",
    status_check_failed:
      "No pudimos consultar el estado de tu credencial. Intentá de nuevo en un rato.",
    unknown_issuer: "Tu credencial no fue emitida por un organismo que reconozcamos.",
    credential_signature_invalid: "La firma de tu credencial no es válida.",
    holder_key_mismatch:
      "No pudimos confirmar que la credencial sea tuya. Presentala desde la wallet donde la recibiste.",
    holder_did_mismatch:
      "No pudimos confirmar que la credencial sea tuya. Presentala desde la wallet donde la recibiste.",
    holder_did_missing:
      "La credencial no tiene titular asociado, así que no podemos usarla acá.",
    holder_did_unresolvable: "No pudimos resolver tu identidad en la red. Intentá de nuevo.",
    malformed_vp_token: "Hubo un problema con la respuesta de tu wallet. Intentá de nuevo.",
  };

  return (
    messages[error] ??
    "No pudimos verificar tu credencial. Escribime de nuevo para reintentar."
  );
}
