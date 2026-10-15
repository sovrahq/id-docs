# Documentación de Sovra ID

## 📘 Documentación vigente

👉 **[`guides/`](guides/)** — tres rutas de lectura, según qué emitas:

| | Qué es | Cuándo |
|---|---|---|
| 📄 **[`guides/credentials/`](guides/credentials/)** | Credenciales verificables que viven en la wallet del ciudadano, con divulgación selectiva y prueba de posesión | Licencias, identidad, membresías, títulos habilitantes |
| 📑 **[`guides/documents/`](guides/documents/)** | Documentos firmados: una credencial **sin holder**, que el emisor firma, ancla on-chain y entrega | Certificados, constancias, actas, comprobantes |

| 🪪 **[`guides/credentials-mdoc/`](guides/credentials-mdoc/)** | Lo mismo que una credencial, en el formato **ISO 18013-5**: CBOR firmado con un MSO en vez de un SD-JWT | Licencias de conducir móviles (mDL), documentos de identidad interoperables |

**La regla práctica:** si el dato es sobre una persona y ella decide cuándo y cuánto
mostrarlo, es una **credencial**. Si es una hoja que la institución emite y reparte,
es un **documento**. La comparación completa está en el
[índice de guías](guides/README.md).

### Credenciales verificables

| # | Guía | Contenido |
|---|---|---|
| 1 | [Introducción](guides/credentials/01-introduccion.md) | Roles, SD-JWT VC, el flujo completo, glosario. |
| 2 | [Primeros pasos](guides/credentials/02-primeros-pasos.md) | Entornos, workspace, DID, API key, webhook. Primera llamada. |
| 3 | [Crear un esquema](guides/credentials/03-crear-un-esquema.md) | Los dos identificadores, el dashboard y la API, el registro on-chain, los claims, el diseño de la tarjeta, versionado. |
| 4 | [Emisión de credenciales](guides/credentials/04-emision-de-credenciales.md) | Oferta → QR → `credential.issued`. |
| 5 | [Verificación de credenciales](guides/credentials/05-verificacion-de-credenciales.md) | Sesión OID4VP, DCQL, `presentation.verified`. |
| 6 | [Webhooks](guides/credentials/06-webhooks.md) | Los nueve eventos, payloads, suscripciones, firma HMAC, reintentos. |
| 7 | [Ciclo de vida y revocación](guides/credentials/07-ciclo-de-vida-y-revocacion.md) | Estados, transiciones, Bitstring Status List. |
| 8 | [Referencia de la API](guides/credentials/08-referencia-api.md) | Todos los endpoints de `/api/v1`: esquemas, diseño, emisión, verificación. |
| 9 | [Errores y troubleshooting](guides/credentials/09-errores-y-troubleshooting.md) | Cada código de error y su solución. |
| 10 | [Verificación sin Sovra](guides/credentials/10-verificacion-sin-sovra.md) | Verificar contra SovraChain con el SDK. |
| 11 | [Verificar por WhatsApp](guides/credentials/11-whatsapp.md) | Deep link, QR, correlación y cotejo de identidad en el chat. |
| 12 | [Emitir desde Moodle](guides/credentials/12-moodle.md) | Credencial al completar un curso, desde un plugin local de Moodle. |

### Credenciales mDoc (ISO 18013-5)

| # | Guía | Contenido |
|---|---|---|
| 1 | [Introducción](guides/credentials-mdoc/01-introduccion.md) | Qué es un mDoc, docType y namespace, anatomía del `IssuerSigned`, el namespace meta, las dos rutas de confianza. |
| 2 | [Primeros pasos](guides/credentials-mdoc/02-primeros-pasos.md) | Entornos, workspace aprovisionado, API key y webhook. Primera llamada. |
| 3 | [Crear un esquema](guides/credentials-mdoc/03-crear-un-esquema.md) | `format: mso_mdoc`, los tipos de claim, `required` frente a `always shared`, la elección del docType y el namespace, versionado. |
| 4 | [Emisión de mDocs](guides/credentials-mdoc/04-emision-de-mdocs.md) | Creación de la oferta, codificación de cada tipo, el QR, el webhook `credential.issued`. |
| 5 | [Verificación de mDocs](guides/credentials-mdoc/05-verificacion-de-mdocs.md) | El DCQL `mso_mdoc`, las rutas `[namespace, elemento]`, lectura del resultado. |
| 6 | [Referencia de la API](guides/credentials-mdoc/06-referencia-api.md) | Endpoints —incluida la creación del esquema—, objetos, tipos de claim, catálogo de elementos ISO. |
| 7 | [Errores y troubleshooting](guides/credentials-mdoc/07-errores-y-troubleshooting.md) | Cada código de error, su causa y su solución. |
| 8 | [Verificación sin Sovra](guides/credentials-mdoc/08-verificacion-sin-sovra.md) | `verifyMdoc()` contra la cadena y contra anclas de confianza propias. |
| 9 | [Habilitar mDL ISO](guides/credentials-mdoc/09-habilitar-mdl-iso.md) | **Solo para el docType oficial de ISO.** El trámite del certificado ante la autoridad emisora. |

### Documentos firmados

| # | Guía | Contenido |
|---|---|---|
| 1 | [Introducción](guides/documents/01-introduccion.md) | Documento vs. credencial, anatomía del `credential`, las dos capas de confianza. |
| 2 | [Primeros pasos](guides/documents/02-primeros-pasos.md) | Entornos, las dos zonas de la API, workspace y DID, API key. Primera llamada. |
| 3 | [Crear un esquema](guides/documents/03-crear-un-esquema.md) | El esquema de kind `document`, los claims, el layout de la hoja, el registro on-chain, versionado. |
| 4 | [Emisión de documentos](guides/documents/04-emision-de-documentos.md) | `POST /issuer/documents`, el anclaje asincrónico, qué guardar. |
| 5 | [Visibilidad y entrega](guides/documents/05-visibilidad-y-entrega.md) | `public`/`private`, la lectura pública, los caminos de entrega. |
| 6 | [Renderizado de la hoja](guides/documents/06-renderizado.md) | `layout`, geometría, saneo, franja de procedencia, QR. |
| 7 | [Verificación](guides/documents/07-verificacion.md) | Los nueve checks, el SDK, códigos de error. |
| 8 | [Referencia de la API](guides/documents/08-referencia-api.md) | Los endpoints de documentos y de esquemas, y los objetos. |
| 9 | [Errores y troubleshooting](guides/documents/09-errores-y-troubleshooting.md) | Cada código de error y su solución. |

## 🧰 Recursos

- [Colección de Postman — Credenciales](resources/sovra-credenciales.postman_collection.json)
- [Colección de Postman — mDoc](resources/sovra-mdoc.postman_collection.json)
- [Colección de Postman — Documentos firmados](resources/sovra-documentos-firmados.postman_collection.json)
- Especificación OpenAPI en vivo: `GET {baseUrl}/openapi/api`

## 🗄 Documentación obsoleta

[`deprecated/`](deprecated/) conserva la documentación de la **plataforma anterior**
(`x-api-key`, `did:quarkid`, DIDComm, BBS+). Se mantiene solo como referencia
histórica para integraciones que todavía no migraron. **No la uses para desarrollos
nuevos** — los endpoints, los formatos y los eventos no son compatibles.
