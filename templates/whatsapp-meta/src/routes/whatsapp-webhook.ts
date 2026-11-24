import express, { Router } from "express";
import { config } from "../config.js";
import * as flow from "../flow.js";
import * as store from "../store.js";
import * as whatsapp from "../whatsapp.js";

export const whatsappWebhook = Router();

/**
 * Handshake de verificación. Meta pega acá una sola vez, cuando pegás la
 * Callback URL en App → WhatsApp → Configuration. Tiene que devolver el
 * `hub.challenge` en texto plano, o Meta no guarda la URL.
 */
whatsappWebhook.get("/webhooks/whatsapp", (req, res) => {
  const mode = req.query["hub.mode"];
  const token = req.query["hub.verify_token"];
  const challenge = req.query["hub.challenge"];

  if (mode === "subscribe" && token === config.whatsapp.verifyToken) {
    res.status(200).send(String(challenge));
    return;
  }

  res.sendStatus(403);
});

/**
 * Mensajes entrantes.
 *
 * express.raw() es obligatorio: la firma X-Hub-Signature-256 se calcula sobre
 * el cuerpo crudo. Con express.json() el buffer ya se perdió.
 */
whatsappWebhook.post(
  "/webhooks/whatsapp",
  express.raw({ type: "application/json" }),
  (req, res) => {
    const raw = req.body as Buffer;

    if (!whatsapp.isValidSignature(raw, req.get("X-Hub-Signature-256"))) {
      res.sendStatus(401);
      return;
    }

    // Meta reintenta si no contestás en pocos segundos, y los reintentos
    // duplican mensajes. Respondemos ya y procesamos en segundo plano.
    res.sendStatus(200);

    const body = JSON.parse(raw.toString("utf8")) as whatsapp.WhatsAppWebhookBody;

    for (const message of whatsapp.extractMessages(body)) {
      // El wamid es único por mensaje: sirve de clave de idempotencia.
      if (store.alreadyHandled(message.id)) continue;

      flow.handleMessage(message).catch((err) => {
        console.error("Error procesando mensaje", message.id, err);
      });
    }
  },
);
