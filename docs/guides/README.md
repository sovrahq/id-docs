# Guías de Sovra ID

La plataforma emite dos cosas, y cada una tiene su propia ruta de lectura:

| | [**Credenciales verificables**](credentials/) | [**Documentos firmados**](documents/) |
|---|---|---|
| Qué es | Una credencial que vive en la wallet del ciudadano | Una credencial **sin holder**: el emisor la firma y la entrega |
| Kind del esquema | `credential` | `document` |
| Quién la guarda | La wallet del ciudadano | Quien la recibe: un archivo, un enlace, un papel |
| Divulgación selectiva | ✅ El ciudadano elige campo por campo | ❌ Todos los claims van a la vista |
| Prueba de posesión | ✅ Passkey del ciudadano (KB-JWT) | ❌ Prueba autoría, no titularidad |
| Emisión | OID4VCI (QR de oferta) | Una llamada HTTP |
| Presentación | OID4VP + DCQL (QR de verificación) | Se manda el string o el enlace |
| Verificación | Hospedada por Sovra, u on-chain | On-chain, siempre |
| Webhooks | ✅ `credential.issued`, `presentation.verified`, … | ❌ El resultado llega en la respuesta del `POST` |
| Casos típicos | Licencias, identidad, membresías, títulos habilitantes | Certificados, constancias, actas, comprobantes |

**La regla práctica:** si el dato es sobre una persona y ella decide cuándo y cuánto
mostrarlo, es una **credencial**. Si es una hoja que la institución emite y reparte,
es un **documento**.

Los dos formatos son **SD-JWT VC** firmados con **ES256** por el mismo emisor, con el
estado publicado en **SovraChain**, y usan la misma **API key de workspace**
(`Authorization: Bearer sovra_sk_...`).

---

## 📄 [`credentials/`](credentials/) — Credenciales verificables

Emisión por **OID4VCI**, verificación por **OID4VP + DCQL**, revocación por
**Bitstring Status List** on-chain.

| # | Guía | Para qué sirve |
|---|---|---|
| 1 | [Introducción](credentials/01-introduccion.md) | Qué es una credencial verificable, los tres roles, y cómo se ve el flujo completo. |
| 2 | [Primeros pasos](credentials/02-primeros-pasos.md) | Entornos, workspace, DID, esquemas, API key, webhook. Primera llamada. |
| 3 | [Emisión de credenciales](credentials/03-emision-de-credenciales.md) | Crear la oferta, mostrar el QR, recibir el `credential.issued`. |
| 4 | [Verificación de credenciales](credentials/04-verificacion-de-credenciales.md) | Crear la sesión OID4VP, escribir el DCQL, leer el resultado. |
| 5 | [Webhooks](credentials/05-webhooks.md) | Todos los eventos, sus payloads y cómo validar la firma HMAC. |
| 6 | [Ciclo de vida y revocación](credentials/06-ciclo-de-vida-y-revocacion.md) | Estados de una credencial, revocar, suspender, reactivar. |
| 7 | [Referencia de la API](credentials/07-referencia-api.md) | Todos los endpoints, parámetros y respuestas. |
| 8 | [Errores y troubleshooting](credentials/08-errores-y-troubleshooting.md) | Cada código de error, qué lo causa y cómo se arregla. |
| 9 | [Verificación sin Sovra](credentials/09-verificacion-sin-sovra.md) | Verificar contra la cadena, sin depender de la API de Sovra. |
| 10 | [Verificar por WhatsApp](credentials/10-whatsapp.md) | Llevar la verificación al chat: deep link, QR, correlación y cotejo de identidad. |

## 📑 [`documents/`](documents/) — Documentos firmados

Una sola llamada valida, firma y **ancla on-chain**. El `credential` resultante es un
string autocontenido: quien lo recibe lo verifica contra la cadena, no preguntándole
al servicio que lo emitió.

| # | Guía | Para qué sirve |
|---|---|---|
| 1 | [Introducción](documents/01-introduccion.md) | Qué es un documento firmado, documento vs. credencial, anatomía del `credential`, las dos capas de confianza. |
| 2 | [Primeros pasos](documents/02-primeros-pasos.md) | Entornos, las dos zonas de la API, el esquema de kind `document`, el layout, API key. |
| 3 | [Emisión de documentos](documents/03-emision-de-documentos.md) | `POST /issuer/documents`, la latencia de ~90 s, la respuesta campo por campo, qué guardar. |
| 4 | [Visibilidad y entrega](documents/04-visibilidad-y-entrega.md) | Publicar y despublicar, qué restringe y qué no, la lectura pública, los caminos de entrega. |
| 5 | [Renderizado de la hoja](documents/05-renderizado.md) | El objeto `layout`, la geometría, el saneo, la franja de procedencia, el QR. |
| 6 | [Verificación](documents/06-verificacion.md) | Por qué no hay un endpoint, los nueve checks, el SDK, códigos de error. |
| 7 | [Referencia de la API](documents/07-referencia-api.md) | Los cuatro endpoints, los objetos, los límites, la colección de Postman. |
| 8 | [Errores y troubleshooting](documents/08-errores-y-troubleshooting.md) | Cada código de error, qué lo causa y cómo se arregla. |

---

## Lo que comparten las dos rutas

| | |
|---|---|
| **Entornos** | Test: `https://test-api-sovra.flagonsa.com` · Producción: `https://api.sovra.io` |
| **Autenticación** | `Authorization: Bearer sovra_sk_...` — el esquema es sensible a mayúsculas |
| **Dashboard** | Los esquemas, los layouts, las API keys y el webhook se configuran ahí. No hay API para eso |
| **Formato** | SD-JWT VC (`vc+sd-jwt`), firma ES256, llaves en KMS |
| **Cadena** | SovraChain: DID Registry, Issuer Registry, estado de las credenciales |
| **OpenAPI** | `GET {baseUrl}/openapi/api` — la fuente de verdad si algo acá queda desactualizado |

## Recursos

- [Colección de Postman — Credenciales](../resources/sovra-credenciales.postman_collection.json)
- [Colección de Postman — Documentos firmados](../resources/sovra-documentos-firmados.postman_collection.json)

> La documentación de la plataforma anterior (`x-api-key`, `did:quarkid`, DIDComm,
> BBS+) está archivada en [`../deprecated/`](../deprecated/) y **no debe usarse para
> integraciones nuevas**.
