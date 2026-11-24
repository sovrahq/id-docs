# Documentación de Sovra ID

## 📘 Documentación vigente

La documentación vive en el **sitio** (`apps/docs`), ordenada por concepto: primero los
conceptos, después una sección por rol. Levantalo con `npm install && npm run dev`
(http://localhost:4321) o leé las páginas en
[`../apps/docs/src/content/docs/`](../apps/docs/src/content/docs/).

| Sección | Qué cubre |
|---|---|
| [Conceptos](../apps/docs/src/content/docs/conceptos/) | Credenciales verificables, tipos (SD-JWT VC, mDoc ISO, mDoc propio), documentos, esquemas, **ciclo de vida** de cada elemento, glosario. |
| [Primeros pasos](../apps/docs/src/content/docs/primeros-pasos.mdx) | Entornos, workspace y DID, API key, webhook, primera llamada, convenciones de la API. |
| [Credenciales](../apps/docs/src/content/docs/credenciales/) | El emisor: esquemas SD-JWT y mDoc, emisión, estados y revocación. |
| [Verificadores](../apps/docs/src/content/docs/verificadores/) | Verificar SD-JWT y mDoc (OID4VP + DCQL), habilitar mDL ISO, verificar sin Sovra, estados de la sesión. |
| [Documentos](../apps/docs/src/content/docs/documentos/) | Documentos firmados: esquema, emisión, estados, visibilidad, renderizado, verificación. |
| [Webhooks](../apps/docs/src/content/docs/webhooks.mdx) | Eventos, payloads, firma HMAC, reintentos. |
| [Integraciones](../apps/docs/src/content/docs/integraciones/) | WhatsApp y Moodle. |
| [Errores](../apps/docs/src/content/docs/errores/) | Cada código de error y su solución. |

La referencia de la API (`/api` en el sitio) se genera desde
[`packages/openapi/openapi.json`](../packages/openapi/openapi.json).

**La regla práctica:** si el dato es sobre una persona y ella decide cuándo y cuánto
mostrarlo, es una **credencial**. Si es una hoja que la institución emite y reparte,
es un **documento**.

## 🧰 Recursos

- [Colección de Postman — Credenciales](resources/sovra-credenciales.postman_collection.json)
- [Colección de Postman — mDoc](resources/sovra-mdoc.postman_collection.json)
- [Colección de Postman — Documentos firmados](resources/sovra-documentos-firmados.postman_collection.json)
- Especificación OpenAPI en vivo: `GET {baseUrl}/openapi/api`

## 🗄 Documentación obsoleta

[`deprecated/`](deprecated/) conserva la documentación de la **plataforma anterior**
(`x-api-key`, `did:quarkid`, DIDComm, BBS+). Se mantiene solo como referencia
histórica para integraciones que todavía no migraron. También guarda, en
[`deprecated/guias-por-formato/`](deprecated/guias-por-formato/), las guías vigentes en
su organización anterior (por formato). **No la uses para desarrollos
nuevos** — los endpoints, los formatos y los eventos no son compatibles.
