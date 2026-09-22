import "dotenv/config";

function required(name: string): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(
      `Falta la variable de entorno ${name}. Copiá .env.example a .env y completala.`,
    );
  }
  return value;
}

function optional(name: string, fallback = ""): string {
  return process.env[name] ?? fallback;
}

export const config = {
  port: Number(optional("PORT", "3000")),

  /** URL HTTPS pública de este servidor (ngrok en desarrollo). Sin barra final. */
  publicBaseUrl: required("PUBLIC_BASE_URL").replace(/\/+$/, ""),

  whatsapp: {
    token: required("WHATSAPP_TOKEN"),
    phoneNumberId: required("WHATSAPP_PHONE_NUMBER_ID"),
    appSecret: required("WHATSAPP_APP_SECRET"),
    verifyToken: required("WHATSAPP_VERIFY_TOKEN"),
    graphVersion: optional("GRAPH_API_VERSION", "v21.0"),
  },

  sovra: {
    baseUrl: required("SOVRA_BASE_URL").replace(/\/+$/, ""),
    apiKey: required("SOVRA_API_KEY"),
    webhookSecret: required("SOVRA_WEBHOOK_SECRET"),
  },

  /** Adónde mandamos al ciudadano que todavía no tiene la credencial. */
  portalUrl: optional("PORTAL_URL"),
} as const;
