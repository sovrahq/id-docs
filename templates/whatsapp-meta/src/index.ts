import express from "express";
import { config } from "./config.js";
import { CLAIMS } from "./credential.js";
import { sovraWebhook } from "./routes/sovra-webhook.js";
import { wallet } from "./routes/wallet.js";
import { whatsappWebhook } from "./routes/whatsapp-webhook.js";

const app = express();

// Ojo con el orden: los dos webhooks montan su propio express.raw() porque
// necesitan el cuerpo intacto para validar el HMAC. Por eso NO hay un
// app.use(express.json()) global.
app.use(whatsappWebhook);
app.use(sovraWebhook);
app.use(wallet);

app.listen(config.port, () => {
  console.log(`▶ Escuchando en http://localhost:${config.port}`);
  console.log(`  URL pública:       ${config.publicBaseUrl}`);
  console.log(`  Webhook WhatsApp:  ${config.publicBaseUrl}/webhooks/whatsapp`);
  console.log(`  Webhook Sovra:     ${config.publicBaseUrl}/webhooks/sovra`);
  console.log(`  Claims del DCQL:   ${CLAIMS.join(", ")}`);
});
