# Guías de Sovra ID

La plataforma emite dos cosas —credenciales y documentos— y las credenciales vienen
en dos formatos. Son tres rutas de lectura:

| | [**Credenciales verificables**](credentials/) | [**Documentos firmados**](documents/) |
|---|---|---|
| Qué es | Una credencial que vive en la wallet del ciudadano | Una credencial **sin holder**: el emisor la firma y la entrega |
| Kind del esquema | `credential` | `document` |
| Quién la guarda | La wallet del ciudadano | Quien la recibe: un archivo, un enlace, un papel |
| Divulgación selectiva | ✅ El ciudadano elige campo por campo | ❌ Todos los claims van a la vista |
| Prueba de posesión | ✅ Passkey del ciudadano (KB-JWT) | ❌ Prueba autoría, no titularidad |
| Emisión | OID4VCI (QR de oferta) | Una llamada HTTP, y después el anclaje on-chain |
| Presentación | OID4VP + DCQL (QR de verificación) | Se manda el string o el enlace |
| Verificación | Hospedada por Sovra, u on-chain | On-chain, siempre |
| Webhooks | ✅ `credential.issued`, `presentation.verified`, … | ✅ `document.status-changed` cuando el anclaje se resuelve |
| Casos típicos | Licencias, identidad, membresías, títulos habilitantes | Certificados, constancias, actas, comprobantes |

**La regla práctica:** si el dato es sobre una persona y ella decide cuándo y cuánto
mostrarlo, es una **credencial**. Si es una hoja que la institución emite y reparte,
es un **documento**.

Las dos son **SD-JWT VC** firmadas con **ES256** por el mismo emisor, con el estado
publicado en **SovraChain**, y usan la misma **API key de workspace**
(`Authorization: Bearer sovra_sk_...`).

Y una credencial puede emitirse además como **mDoc** (ISO 18013-5): mismos endpoints
y misma API key, pero CBOR firmado con un MSO en vez de un SD-JWT. Está en
[`credentials-mdoc/`](credentials-mdoc/).

---

## 📄 [`credentials/`](credentials/) — Credenciales verificables

Emisión por **OID4VCI**, verificación por **OID4VP + DCQL**, revocación por
**Bitstring Status List** on-chain.

| # | Guía | Para qué sirve |
|---|---|---|
| 1 | [Introducción](credentials/01-introduccion.md) | Qué es una credencial verificable, los tres roles, y cómo se ve el flujo completo. |
| 2 | [Primeros pasos](credentials/02-primeros-pasos.md) | Entornos, workspace, DID, API key, webhook. Primera llamada. |
| 3 | [Crear un esquema](credentials/03-crear-un-esquema.md) | Los dos identificadores, el dashboard y la API, el registro on-chain, los claims, el diseño de la tarjeta, versionado. |
| 4 | [Emisión de credenciales](credentials/04-emision-de-credenciales.md) | Crear la oferta, mostrar el QR, recibir el `credential.issued`. |
| 5 | [Verificación de credenciales](credentials/05-verificacion-de-credenciales.md) | Crear la sesión OID4VP, escribir el DCQL, leer el resultado. |
| 6 | [Webhooks](credentials/06-webhooks.md) | Los nueve eventos, sus payloads, las suscripciones por endpoint, la firma HMAC y los reintentos. |
| 7 | [Ciclo de vida y revocación](credentials/07-ciclo-de-vida-y-revocacion.md) | Estados de una credencial, revocar, suspender, reactivar. |
| 8 | [Referencia de la API](credentials/08-referencia-api.md) | Todos los endpoints —esquemas, diseño, emisión, verificación—, parámetros y respuestas. |
| 9 | [Errores y troubleshooting](credentials/09-errores-y-troubleshooting.md) | Cada código de error, qué lo causa y cómo se arregla. |
| 10 | [Verificación sin Sovra](credentials/10-verificacion-sin-sovra.md) | Verificar contra la cadena, sin depender de la API de Sovra. |
| 11 | [Verificar por WhatsApp](credentials/11-whatsapp.md) | Llevar la verificación al chat: deep link, QR, correlación y cotejo de identidad. |
| 12 | [Emitir desde Moodle](credentials/12-moodle.md) | Credencial al completar un curso: oferta a pedido, webhook opcional, plugin local. |

## 🪪 [`credentials-mdoc/`](credentials-mdoc/) — Credenciales mDoc (ISO 18013-5)

Una credencial verificable, en el formato de la norma de la **licencia de conducir
móvil**: **CBOR** firmado con un **MSO** `COSE_Sign1` en vez de un SD-JWT. Se emite y
se verifica por **los mismos endpoints** —el formato lo decide el esquema— pero un
claim se nombra con dos segmentos, `[namespace, identificador]`.

| | SD-JWT VC | mDoc |
|---|---|---|
| `format` del esquema | `vc+sd-jwt` | `mso_mdoc` |
| Codificación | JSON / JWT | CBOR |
| Cómo se nombra un claim | `["full_name"]` | `["org.iso.18013.5.1", "family_name"]` |
| Identidad del emisor | El DID del workspace | La dirección del docType, **o** la cadena `x5chain` |
| Prueba de posesión | KB-JWT | Device authentication |
| Presencial (BLE/NFC) | — | ❌ No implementado: sólo el perfil en línea |

| # | Guía | Para qué sirve |
|---|---|---|
| 1 | [Introducción](credentials-mdoc/01-introduccion.md) | Qué es un mDoc, docType y namespace, anatomía del `IssuerSigned`, el namespace meta, las dos rutas de confianza. |
| 2 | [Primeros pasos](credentials-mdoc/02-primeros-pasos.md) | Entornos, workspace aprovisionado, API key y webhook. Primera llamada. |
| 3 | [Crear un esquema](credentials-mdoc/03-crear-un-esquema.md) | `format: mso_mdoc`, los tipos de claim, `required` frente a `always shared`, la elección del docType y el namespace, versionado. |
| 4 | [Emisión de mDocs](credentials-mdoc/04-emision-de-mdocs.md) | Creación de la oferta, codificación de cada tipo, el QR, el webhook `credential.issued`. |
| 5 | [Verificación de mDocs](credentials-mdoc/05-verificacion-de-mdocs.md) | El DCQL `mso_mdoc`, las rutas `[namespace, elemento]`, lectura del resultado. |
| 6 | [Referencia de la API](credentials-mdoc/06-referencia-api.md) | Endpoints —incluida la creación del esquema—, objetos, tipos de claim, catálogo de elementos ISO. |
| 7 | [Errores y troubleshooting](credentials-mdoc/07-errores-y-troubleshooting.md) | Cada código de error, su causa y su solución. |
| 8 | [Verificación sin Sovra](credentials-mdoc/08-verificacion-sin-sovra.md) | `verifyMdoc()` contra la cadena y contra anclas de confianza propias. |
| 9 | [Habilitar mDL ISO](credentials-mdoc/09-habilitar-mdl-iso.md) | **Solo para el docType oficial de ISO.** El trámite del certificado ante la autoridad emisora. |

## 🧩 [`credentials-mdoc-custom/`](credentials-mdoc-custom/) — mDoc con namespace propio

`org.iso.18013.5.1` es un conjunto **cerrado**: un `key` ajeno a su catálogo es
rechazado al crear el esquema. Cuando el documento debe transportar datos que ISO no
define —CURP, colonia, tipo sanguíneo, alergias, condición de donante— la vía que la
propia norma prevé es **añadir un namespace**.

Supone leída [`credentials-mdoc/`](credentials-mdoc/): aquí solo se describe lo que
cambia. El caso que se desarrolla es una licencia de conducir mexicana.

| | Namespace de ISO | Namespace propio |
|---|---|---|
| Elementos fuera del catálogo | ❌ | ✅ |
| Document signer | Obligatorio | No se requiere |
| `doctype_value` identifica al emisor | ❌ | ✅ |
| Lectores mDL de terceros | La interpretan | No la interpretan como licencia |
| Ambos namespaces a la vez | — | ❌ No emitible hoy |

| # | Guía | Para qué sirve |
|---|---|---|
| 1 | [Por qué un namespace propio](credentials-mdoc-custom/01-introduccion.md) | El conjunto cerrado de ISO, las dos rutas comparadas, qué identificadores conviene conservar. |
| 2 | [Crear el esquema](credentials-mdoc-custom/02-crear-un-esquema.md) | El cuerpo completo, `required` frente a `always shared`, los 23 atributos y su clave. |
| 3 | [Emisión](credentials-mdoc-custom/03-emision.md) | La oferta con los 24 claims, `valid_until` frente a `expiry_date`, la codificación de cada tipo. |
| 4 | [Imágenes: foto y firma](credentials-mdoc-custom/04-imagenes.md) | Base64 estándar al emitir, base64url al verificar, y qué muestra hoy la cartera. |
| 5 | [Verificación](credentials-mdoc-custom/05-verificacion.md) | El DCQL con namespace propio, las tres cadenas que deben coincidir, seis escenarios reales y los fallos más frecuentes. |

## 📑 [`documents/`](documents/) — Documentos firmados

Una sola llamada valida y firma, y el **anclaje on-chain** se completa después, en
segundo plano. El `credential` resultante es un string autocontenido: quien lo recibe
lo verifica contra la cadena, no preguntándole al servicio que lo emitió.

| # | Guía | Para qué sirve |
|---|---|---|
| 1 | [Introducción](documents/01-introduccion.md) | Qué es un documento firmado, documento vs. credencial, anatomía del `credential`, las dos capas de confianza. |
| 2 | [Primeros pasos](documents/02-primeros-pasos.md) | Entornos, las dos zonas de la API, workspace y DID, API key. Primera llamada. |
| 3 | [Crear un esquema](documents/03-crear-un-esquema.md) | El esquema de kind `document`, los claims, el layout de la hoja, el registro on-chain, versionado. |
| 4 | [Emisión de documentos](documents/04-emision-de-documentos.md) | `POST /issuer/documents`, el anclaje asincrónico y su webhook, la respuesta campo por campo, qué guardar. |
| 5 | [Visibilidad y entrega](documents/05-visibilidad-y-entrega.md) | Publicar y despublicar, qué restringe y qué no, la lectura pública, los caminos de entrega. |
| 6 | [Renderizado de la hoja](documents/06-renderizado.md) | El objeto `layout`, la geometría, el saneo, la franja de procedencia, el QR. |
| 7 | [Verificación](documents/07-verificacion.md) | Por qué no hay un endpoint, los nueve checks, el SDK, códigos de error. |
| 8 | [Referencia de la API](documents/08-referencia-api.md) | Los endpoints de documentos y de esquemas, los objetos, los límites, la colección de Postman. |
| 9 | [Errores y troubleshooting](documents/09-errores-y-troubleshooting.md) | Cada código de error, qué lo causa y cómo se arregla. |

---

## Lo que comparten las tres rutas

| | |
|---|---|
| **Entornos** | Test: `https://test-api-sovra.flagonsa.com` · Producción: `https://api.sovra.io` |
| **Autenticación** | `Authorization: Bearer sovra_sk_...` — el esquema es sensible a mayúsculas |
| **Esquemas** | Se crean en el dashboard **o por API** (`POST /api/v1/issuer/schemas`). El diseño de la tarjeta, también (`PUT …/design`) |
| **Dashboard** | Los layouts de documento, las API keys, los webhooks, los document signers y las raíces IACA se configuran solo ahí |
| **Formato** | SD-JWT VC (`vc+sd-jwt`) o mDoc (`mso_mdoc`, ISO 18013-5), firma ES256, llaves en KMS |
| **Cadena** | SovraChain: DID Registry, Issuer Registry, estado de las credenciales |
| **OpenAPI** | `GET {baseUrl}/openapi/api` — la fuente de verdad si algo acá queda desactualizado |

## Recursos

- [Colección de Postman — Credenciales](../resources/sovra-credenciales.postman_collection.json)
- [Colección de Postman — mDoc](../resources/sovra-mdoc.postman_collection.json)
- [Colección de Postman — mDoc con namespace propio](../resources/sovra-mdoc-custom.postman_collection.json)
- [Colección de Postman — Documentos firmados](../resources/sovra-documentos-firmados.postman_collection.json)

> La documentación de la plataforma anterior (`x-api-key`, `did:quarkid`, DIDComm,
> BBS+) está archivada en [`../deprecated/`](../deprecated/) y **no debe usarse para
> integraciones nuevas**.
