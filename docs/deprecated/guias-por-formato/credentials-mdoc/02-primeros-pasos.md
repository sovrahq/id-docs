# 2. Primeros pasos

Requisitos para emitir el primer mDoc: un workspace aprovisionado, una API key y un
webhook. El esquema de formato `mso_mdoc` —la pieza propia de este formato— tiene
guía propia: [3. Crear un esquema](03-crear-un-esquema.md).

## Tabla de contenidos

1. [Entornos](#entornos)
2. [Paso 1 — Workspace aprovisionado](#paso-1--workspace-aprovisionado)
3. [Paso 2 — API key y webhook](#paso-2--api-key-y-webhook)
4. [Paso 3 — Primera llamada](#paso-3--primera-llamada)
5. [Lista de comprobación](#lista-de-comprobación)

---

## Entornos

| | Test | Producción |
|---|---|---|
| **API** | `https://test-api-sovra.flagonsa.com` | `https://api.sovra.io` |
| **Dashboard** | `https://test-issuer-sovra.flagonsa.com` | `https://issuer.sovra.io` |
| **RPC de SovraChain** | `https://rpc.testnet.sovra.io` | `https://rpc.sovra.io` |
| **Chain ID** | `65536101` | `65536001` |

```bash
export BASE_URL="https://test-api-sovra.flagonsa.com"
export SOVRA_API_KEY="sovra_sk_..."
```

La especificación OpenAPI del entorno se encuentra en `GET $BASE_URL/openapi/api`.

## Paso 1 — Workspace aprovisionado

El procedimiento es idéntico al de cualquier credencial: el acceso se realiza por
invitación y, al aceptarla, se crean el usuario, la organización y el workspace.
Este último se aprovisiona contra SovraChain y queda con un DID `did:sovra:0x…`.
El detalle completo figura en
[Primeros pasos de credenciales](../credentials/02-primeros-pasos.md#paso-1--acceder-al-dashboard).

Lo relevante en este contexto: **el mDoc requiere además la dirección EOA del
workspace**. De ella se deriva el docType generado
(`io.sovra.<dirección>.<schema_id>.1`) y es la clave de indexación de los mapas de
revocación. Mientras el aprovisionamiento no concluya, el docType se muestra vacío
en el dashboard y cualquier llamada responde:

```json
{ "error": "workspace_not_provisioned" }
```

> 📐 **El esquema va aparte.** La creación del esquema `mso_mdoc` —los tipos de
> claim, `always shared`, la elección del docType y el namespace— está en
> [3. Crear un esquema](03-crear-un-esquema.md).

## Paso 2 — API key y webhook

Idénticos a los de cualquier credencial:

- **API key**: Settings → API keys. Prefijo `sovra_sk_`, se muestra **una única
  vez**. Se envía como `Authorization: Bearer sovra_sk_...`.
- **Webhook**: Settings → Webhooks. Hasta 10 endpoints, cada uno con su URL HTTPS, su
  propio secreto `whsec_…` para la firma HMAC y **su lista explícita de eventos
  suscritos**: no hay comodín, de modo que un evento no suscrito no se entrega.

El detalle figura en [Primeros pasos de credenciales](../credentials/02-primeros-pasos.md#paso-3--crear-una-api-key)
y en [Webhooks](../credentials/06-webhooks.md).

## Paso 3 — Primera llamada

```bash
curl -s -X POST "$BASE_URL/api/v1/issuer/credential-offer" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "schema_id": "licencia_de_conducir",
    "claims": {
      "family_name": "Lovelace",
      "given_name": "Ada",
      "birth_date": "1815-12-10",
      "issue_date": "2026-01-15",
      "expiry_date": "2036-01-15",
      "issuing_country": "MX",
      "issuing_authority": "Nuevo León",
      "document_number": "NL-0001",
      "un_distinguishing_sign": "MEX",
      "portrait": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
      "driving_privileges": [{ "vehicle_category_code": "B" }],
      "age_over_18": true
    }
  }'
```

Una respuesta `201` con `offer_uri` confirma que el esquema, la API key y el
aprovisionamiento son correctos.

## Lista de comprobación

- [ ] El workspace muestra su DID y su dirección EOA en **Settings → Workspace**.
- [ ] La API key se almacena fuera del código fuente.
- [ ] El webhook responde `2xx` y valida la firma HMAC, y tiene suscritos los eventos
      que la integración necesita.

Lo relativo al esquema —formato, `schema_id`, tipos de claim, `always shared`,
document signer— figura en la lista de
[3. Crear un esquema](03-crear-un-esquema.md#lista-de-comprobación).

---

**Anterior:** [← 1. Introducción](01-introduccion.md) · **Siguiente:** [3. Crear un esquema →](03-crear-un-esquema.md)
