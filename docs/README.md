# Documentación de Sovra ID

## 📘 Documentación vigente

👉 **[`guides/`](guides/)** — dos rutas de lectura, según qué emitas:

| | Qué es | Cuándo |
|---|---|---|
| 📄 **[`guides/credentials/`](guides/credentials/)** | Credenciales verificables que viven en la wallet del ciudadano, con divulgación selectiva y prueba de posesión | Licencias, identidad, membresías, títulos habilitantes |
| 📑 **[`guides/documents/`](guides/documents/)** | Documentos firmados: una credencial **sin holder**, que el emisor firma, ancla on-chain y entrega | Certificados, constancias, actas, comprobantes |

**La regla práctica:** si el dato es sobre una persona y ella decide cuándo y cuánto
mostrarlo, es una **credencial**. Si es una hoja que la institución emite y reparte,
es un **documento**. La comparación completa está en el
[índice de guías](guides/README.md).

### Credenciales verificables

| # | Guía | Contenido |
|---|---|---|
| 1 | [Introducción](guides/credentials/01-introduccion.md) | Roles, SD-JWT VC, el flujo completo, glosario. |
| 2 | [Primeros pasos](guides/credentials/02-primeros-pasos.md) | Entornos, workspace, DID, esquemas, API key, webhook. |
| 3 | [Emisión de credenciales](guides/credentials/03-emision-de-credenciales.md) | Oferta → QR → `credential.issued`. |
| 4 | [Verificación de credenciales](guides/credentials/04-verificacion-de-credenciales.md) | Sesión OID4VP, DCQL, `presentation.verified`. |
| 5 | [Webhooks](guides/credentials/05-webhooks.md) | Eventos, payloads, firma HMAC, reintentos. |
| 6 | [Ciclo de vida y revocación](guides/credentials/06-ciclo-de-vida-y-revocacion.md) | Estados, transiciones, Bitstring Status List. |
| 7 | [Referencia de la API](guides/credentials/07-referencia-api.md) | Todos los endpoints de `/api/v1`. |
| 8 | [Errores y troubleshooting](guides/credentials/08-errores-y-troubleshooting.md) | Cada código de error y su solución. |
| 9 | [Verificación sin Sovra](guides/credentials/09-verificacion-sin-sovra.md) | Verificar contra SovraChain con el SDK. |

### Documentos firmados

| # | Guía | Contenido |
|---|---|---|
| 1 | [Introducción](guides/documents/01-introduccion.md) | Documento vs. credencial, anatomía del `credential`, las dos capas de confianza. |
| 2 | [Primeros pasos](guides/documents/02-primeros-pasos.md) | Entornos, las dos zonas de la API, esquema `document`, layout, API key. |
| 3 | [Emisión de documentos](guides/documents/03-emision-de-documentos.md) | `POST /issuer/documents`, la latencia de ~90 s, qué guardar. |
| 4 | [Visibilidad y entrega](guides/documents/04-visibilidad-y-entrega.md) | `public`/`private`, la lectura pública, los caminos de entrega. |
| 5 | [Renderizado de la hoja](guides/documents/05-renderizado.md) | `layout`, geometría, saneo, franja de procedencia, QR. |
| 6 | [Verificación](guides/documents/06-verificacion.md) | Los nueve checks, el SDK, códigos de error. |
| 7 | [Referencia de la API](guides/documents/07-referencia-api.md) | Los cuatro endpoints y los objetos. |
| 8 | [Errores y troubleshooting](guides/documents/08-errores-y-troubleshooting.md) | Cada código de error y su solución. |

## 🧰 Recursos

- [Colección de Postman — Credenciales](resources/sovra-credenciales.postman_collection.json)
- [Colección de Postman — Documentos firmados](resources/sovra-documentos-firmados.postman_collection.json)
- Especificación OpenAPI en vivo: `GET {baseUrl}/openapi/api`

## 🗄 Documentación obsoleta

[`deprecated/`](deprecated/) conserva la documentación de la **plataforma anterior**
(`x-api-key`, `did:quarkid`, DIDComm, BBS+). Se mantiene solo como referencia
histórica para integraciones que todavía no migraron. **No la uses para desarrollos
nuevos** — los endpoints, los formatos y los eventos no son compatibles.
