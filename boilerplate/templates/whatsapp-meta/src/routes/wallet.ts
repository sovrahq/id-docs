import { Router } from "express";
import QRCode from "qrcode";
import * as store from "../store.js";

export const wallet = Router();

/**
 * El puente entre WhatsApp y la wallet.
 *
 * `openid4vp://...` no es clickeable dentro de WhatsApp: el cliente solo
 * linkifica http/https. Así que en el chat mandamos una URL nuestra, y acá
 * redirigimos a la URI real que devolvió Sovra.
 */

/** El ciudadano toca el link en el chat → le abre la wallet. */
wallet.get("/w/:sessionId", (req, res) => {
  const session = store.getLiveSession(req.params.sessionId);

  if (!session) {
    res
      .status(410)
      .type("html")
      .send(
        "<h1>El link venció</h1>" +
          "<p>Volvé al chat de WhatsApp y escribí <b>reintentar</b> para recibir uno nuevo.</p>",
      );
    return;
  }

  // 302 y no 301: el destino cambia en cada sesión, no queremos que el
  // navegador se lo guarde.
  res.redirect(302, session.authorizationRequestUri);
});

/**
 * El QR, para quien lee WhatsApp desde la compu.
 *
 * Quien descarga esta imagen es Meta, no el ciudadano: tiene que ser pública y
 * alcanzable desde internet (en desarrollo, vía ngrok).
 */
wallet.get("/qr/:sessionId.png", async (req, res) => {
  const session = store.getLiveSession(req.params.sessionId);

  if (!session) {
    res.sendStatus(410);
    return;
  }

  try {
    const png = await QRCode.toBuffer(session.authorizationRequestUri, {
      type: "png",
      width: 512,
      margin: 2,
      errorCorrectionLevel: "M",
    });

    res.type("png").send(png);
  } catch (err) {
    console.error("Error generando el QR", err);
    res.sendStatus(500);
  }
});

wallet.get("/health", (_req, res) => {
  res.json({ ok: true });
});
