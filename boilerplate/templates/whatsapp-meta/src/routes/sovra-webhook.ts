import express, { Router } from "express";
import * as flow from "../flow.js";
import * as sovra from "../sovra.js";
import * as store from "../store.js";

export const sovraWebhook = Router();

/**
 * El desenlace de la verificación. Es asincrónico: el POST que creó la sesión
 * devolvió un QR, y el veredicto llega acá.
 *
 * Igual que con Meta: cuerpo crudo para poder validar el HMAC.
 */
sovraWebhook.post(
  "/webhooks/sovra",
  express.raw({ type: "application/json" }),
  (req, res) => {
    const raw = req.body as Buffer;

    if (!sovra.isValidSignature(raw, req.get("X-Sovra-Signature"))) {
      res.sendStatus(401);
      return;
    }

    res.sendStatus(200);

    const envelope = JSON.parse(raw.toString("utf8")) as sovra.SovraEnvelope<unknown>;

    if (store.alreadyHandled(envelope.id)) return;

    handle(envelope).catch((err) => {
      console.error("Error procesando evento", envelope.event, envelope.id, err);
    });
  },
);

async function handle(envelope: sovra.SovraEnvelope<unknown>): Promise<void> {
  switch (envelope.event) {
    case "presentation.verified":
      // Llegar acá significa que Sovra ya validó todo: firma del emisor, prueba
      // de posesión del holder, vigencia y revocación. Los claims son confiables.
      await flow.handleVerified(envelope.data as sovra.PresentationVerified);
      break;

    case "presentation.failed":
      await flow.handleFailed(envelope.data as sovra.PresentationFailed);
      break;

    default:
      // credential.issued, credential.revoked, etc. Este bot no emite.
      console.log(`Evento ignorado: ${envelope.event}`);
  }
}
