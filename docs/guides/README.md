# Guías de Sovra ID

Documentación para **emitir** y **verificar** credenciales verificables con la API
de Sovra ID.

Todo lo que sigue está escrito contra la plataforma actual: credenciales
**SD-JWT VC**, emisión por **OID4VCI**, verificación por **OID4VP + DCQL**, estado
de revocación publicado en **SovraChain**, y autenticación servidor-a-servidor con
una **API key de workspace** (`Authorization: Bearer sovra_sk_...`).

> La documentación de la plataforma anterior (`x-api-key`, `did:quarkid`, DIDComm,
> BBS+) está archivada en [`../deprecated/`](../deprecated/) y **no debe usarse
> para integraciones nuevas**.

## Ruta de lectura

| # | Guía | Para qué sirve |
|---|---|---|
| 1 | [Introducción](01-introduccion.md) | Qué es una credencial verificable, los tres roles, y cómo se ve el flujo completo. |
| 2 | [Primeros pasos](02-primeros-pasos.md) | Entornos, workspace, DID, esquemas, API key, webhook. Primera llamada. |
| 3 | [Emisión de credenciales](03-emision-de-credenciales.md) | Crear la oferta, mostrar el QR, recibir el `credential.issued`. |
| 4 | [Verificación de credenciales](04-verificacion-de-credenciales.md) | Crear la sesión OID4VP, escribir el DCQL, leer el resultado. |
| 5 | [Webhooks](05-webhooks.md) | Todos los eventos, sus payloads y cómo validar la firma HMAC. |
| 6 | [Ciclo de vida y revocación](06-ciclo-de-vida-y-revocacion.md) | Estados de una credencial, revocar, suspender, reactivar. |
| 7 | [Referencia de la API](07-referencia-api.md) | Todos los endpoints, parámetros y respuestas. |
| 8 | [Errores y troubleshooting](08-errores-y-troubleshooting.md) | Cada código de error, qué lo causa y cómo se arregla. |
| 9 | [Verificación sin Sovra](09-verificacion-sin-sovra.md) | Verificar contra la cadena, sin depender de la API de Sovra. |

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

- [Colección de Postman](../resources/sovra-credenciales.postman_collection.json) — importala y configurá `baseUrl` + `apiKey`.
- Especificación OpenAPI en vivo: `GET {baseUrl}/openapi/api`
