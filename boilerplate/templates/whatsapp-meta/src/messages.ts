import { config } from "./config.js";

/**
 * Todo el texto que ve el ciudadano, en un solo archivo.
 * Si adaptás el bot a otro trámite, empezá por acá.
 */

/** '27339186605' → '27-33918660-5'. Cosmético, solo para mostrar. */
function formatCuil(value: unknown): string {
  const digits = String(value ?? "").replace(/\D/g, "");
  if (digits.length !== 11) return String(value ?? "");
  return `${digits.slice(0, 2)}-${digits.slice(2, 10)}-${digits.slice(10)}`;
}

export const messages = {
  /**
   * El único mensaje que hace falta antes de la credencial: no le preguntamos
   * nada, va derecho al deep link.
   */
  presentationLink: (walletUrl: string, minutes: number) =>
    "¡Hola! 👋 Para hacer el trámite necesito verificar tu identidad.\n\n" +
    "Abrí tu wallet y presentá tu credencial acá:\n\n" +
    `${walletUrl}\n\n` +
    `⏱ El link vence en ${minutes} minutos. ` +
    "Si estás en la compu, escaneá el QR que te mando acá abajo.",

  qrCaption: () => "Escaneá este código con la wallet donde tenés tu credencial.",

  /** Ya le mandamos el link y todavía no presentó. */
  stillWaiting: () =>
    "Sigo esperando que presentes tu credencial. 🙂\n\n" +
    "Si el link venció, escribí *reintentar* y te mando uno nuevo.\n" +
    "Si preferís dejarlo acá, escribí *cancelar*.",

  cancelled: () => "Listo, cancelé el trámite. Escribime cuando quieras retomarlo.",

  /** Los datos salen de la credencial: nunca se los pedimos por chat. */
  verified: (claims: Record<string, unknown>) => {
    const nombre = [claims["nombre"], claims["apellido"]]
      .filter(Boolean)
      .join(" ")
      .trim();
    const cuil = claims["cuil"] ? formatCuil(claims["cuil"]) : "";

    return (
      "✅ *Identidad verificada.*\n\n" +
      (nombre ? `Bienvenido/a, ${nombre}.\n` : "") +
      (cuil ? `CUIL: ${cuil}\n` : "") +
      "\nYa podés continuar con el trámite de tu licencia."
    );
  },

  /**
   * La credencial es válida, pero el teléfono que declara no es este chat.
   * Pasa cuando alguien reenvía el link y lo presenta otra persona.
   */
  phoneMismatch: () =>
    "⚠️ La credencial que se presentó es válida, pero está a nombre de otro " +
    "número de teléfono.\n\n" +
    "Por seguridad no puedo seguir el trámite acá. Presentá tu credencial " +
    "desde este mismo WhatsApp, o pedila con este número si todavía no la tenés.",

  failed: (reason: string) =>
    `❌ ${reason}\n\n` +
    (config.portalUrl
      ? `Si todavía no tenés la credencial, sacala acá: ${config.portalUrl}`
      : ""),

  error: () => "Se nos complicó algo de este lado. Probá de nuevo en un minuto. 🙏",
} as const;
