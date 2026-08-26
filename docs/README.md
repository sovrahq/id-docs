# Documentación de Sovra ID

## 📘 Documentación vigente

👉 **[`guides/`](guides/)** — cómo emitir y verificar credenciales con la API actual
de Sovra ID.

| # | Guía | Contenido |
|---|---|---|
| 1 | [Introducción](guides/01-introduccion.md) | Roles, SD-JWT VC, el flujo completo, glosario. |
| 2 | [Primeros pasos](guides/02-primeros-pasos.md) | Entornos, workspace, DID, esquemas, API key, webhook. |
| 3 | [Emisión de credenciales](guides/03-emision-de-credenciales.md) | Oferta → QR → `credential.issued`. |
| 4 | [Verificación de credenciales](guides/04-verificacion-de-credenciales.md) | Sesión OID4VP, DCQL, `presentation.verified`. |
| 5 | [Webhooks](guides/05-webhooks.md) | Eventos, payloads, firma HMAC, reintentos. |
| 6 | [Ciclo de vida y revocación](guides/06-ciclo-de-vida-y-revocacion.md) | Estados, transiciones, Bitstring Status List. |
| 7 | [Referencia de la API](guides/07-referencia-api.md) | Todos los endpoints de `/api/v1`. |
| 8 | [Errores y troubleshooting](guides/08-errores-y-troubleshooting.md) | Cada código de error y su solución. |
| 9 | [Verificación sin Sovra](guides/09-verificacion-sin-sovra.md) | Verificar contra SovraChain con el SDK. |

## 🧰 Recursos

- [Colección de Postman](resources/sovra-credenciales.postman_collection.json)

## 🗄 Documentación obsoleta

[`deprecated/`](deprecated/) conserva la documentación de la **plataforma anterior**
(`x-api-key`, `did:quarkid`, DIDComm, BBS+). Se mantiene solo como referencia
histórica para integraciones que todavía no migraron. **No la uses para desarrollos
nuevos** — los endpoints, los formatos y los eventos no son compatibles.
