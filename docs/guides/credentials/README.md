# Guías de credenciales verificables

Documentación para **emitir** y **verificar** credenciales verificables con la API
de Sovra ID.

Todo lo que sigue está escrito contra la plataforma actual: credenciales
**SD-JWT VC**, emisión por **OID4VCI**, verificación por **OID4VP + DCQL**, estado
de revocación publicado en **SovraChain**, y autenticación servidor-a-servidor con
una **API key de workspace** (`Authorization: Bearer sovra_sk_...`).

> Si lo que necesitás es una hoja que la institución firma y reparte —un certificado,
> una constancia, un acta—, sin wallet y sin holder, la documentación es
> [`../documents/`](../documents/). La comparación entre los dos formatos está en el
> [índice de guías](../README.md).

> La documentación de la plataforma anterior (`x-api-key`, `did:quarkid`, DIDComm,
> BBS+) está archivada en [`../../deprecated/`](../../deprecated/) y **no debe usarse
> para integraciones nuevas**.

## Ruta de lectura

| # | Guía | Para qué sirve |
|---|---|---|
| 1 | [Introducción](01-introduccion.md) | Qué es una credencial verificable, los tres roles, y cómo se ve el flujo completo. |
| 2 | [Primeros pasos](02-primeros-pasos.md) | Entornos, workspace, DID, API key, webhook. Primera llamada. |
| 3 | [Crear un esquema](03-crear-un-esquema.md) | Los dos identificadores, el dashboard y la API, el registro on-chain, los claims, el diseño de la tarjeta, versionado. |
| 4 | [Emisión de credenciales](04-emision-de-credenciales.md) | Crear la oferta, mostrar el QR, recibir el `credential.issued`. |
| 5 | [Verificación de credenciales](05-verificacion-de-credenciales.md) | Crear la sesión OID4VP, escribir el DCQL, leer el resultado. |
| 6 | [Webhooks](06-webhooks.md) | Los nueve eventos, sus payloads, las suscripciones por endpoint, la firma HMAC y los reintentos. |
| 7 | [Ciclo de vida y revocación](07-ciclo-de-vida-y-revocacion.md) | Estados de una credencial, revocar, suspender, reactivar. |
| 8 | [Referencia de la API](08-referencia-api.md) | Todos los endpoints —esquemas, diseño, emisión, verificación—, parámetros y respuestas. |
| 9 | [Errores y troubleshooting](09-errores-y-troubleshooting.md) | Cada código de error, qué lo causa y cómo se arregla. |
| 10 | [Verificación sin Sovra](10-verificacion-sin-sovra.md) | Verificar contra la cadena, sin depender de la API de Sovra. |
| 11 | [Verificar por WhatsApp](11-whatsapp.md) | Llevar la verificación al chat: deep link, QR, correlación y cotejo de identidad. |
| 12 | [Emitir desde Moodle](12-moodle.md) | Credencial al completar un curso: oferta a pedido, webhook opcional, plugin local. |

## Atajo: los dos flujos en una pantalla

**Emitir** una credencial a un ciudadano:

```bash
# 1. Crear la oferta (tu servidor)
curl -X POST "$BASE_URL/api/v1/issuer/credential-offer" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"schema_id": "licencia_conducir", "claims": {"full_name": "Ada Lovelace"}}'
# → { "credential_id": "...", "offer_uri": "openid-credential-offer://?...", ... }

# 2. Renderizás offer_uri como QR. El ciudadano lo escanea con la wallet.
# 3. Tu webhook recibe `credential.issued` con el SD-JWT firmado.
```

**Verificar** una credencial:

```bash
# 1. Crear la sesión de verificación (tu servidor)
curl -X POST "$BASE_URL/api/v1/verifier/verifications" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"dcql_query": {"credentials": [{"id": "lic", "format": "vc+sd-jwt",
        "claims": [{"path": ["full_name"]}]}]}}'
# → { "session_id": "...", "authorization_request_uri": "openid4vp://?...", ... }

# 2. Renderizás authorization_request_uri como QR. El ciudadano lo escanea.
# 3. Tu webhook recibe `presentation.verified` (o `presentation.failed`).
```

## Recursos

- [Colección de Postman](../../resources/sovra-credenciales.postman_collection.json) — importala y configurá `baseUrl` + `apiKey`.
- [Boilerplate de WhatsApp](../../../boilerplate/templates/whatsapp-meta/) — bot que verifica dentro del chat, directo contra la Cloud API de Meta.
- [Boilerplate de Moodle](../../../boilerplate/templates/moodle-local-plugin/) — plugin local que emite una credencial al completar un curso.
- Especificación OpenAPI en vivo: `GET {baseUrl}/openapi/api`

---

**Volver a [todas las guías](../README.md)** · **Documentos firmados:** [`../documents/`](../documents/)
