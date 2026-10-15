# 2. Primeros pasos

## Tabla de contenidos

1. [Entornos](#entornos)
2. [Las dos zonas de la API](#las-dos-zonas-de-la-api)
3. [Paso 1 — Acceder al dashboard](#paso-1--acceder-al-dashboard)
4. [Paso 2 — Esperar el DID del workspace](#paso-2--esperar-el-did-del-workspace)
5. [Paso 3 — Crear una API key](#paso-3--crear-una-api-key)
6. [Paso 4 — Primera llamada](#paso-4--primera-llamada)
7. [Checklist](#checklist)

---

## Entornos

| | Test | Producción |
|---|---|---|
| **API** | `https://test-api-sovra.flagonsa.com` | `https://api.sovra.io` |
| **Dashboard** | `https://test-issuer-sovra.flagonsa.com` | `https://issuer.sovra.io` |
| **RPC de SovraChain** | `https://rpc.testnet.sovra.io` | `https://rpc.sovra.io` |
| **Chain ID** | `65536101` | `65536001` |

En el resto de las guías `$BASE_URL` es la URL de la API del entorno que estés usando.

```bash
export BASE_URL="https://test-api-sovra.flagonsa.com"
export SOVRA_API_KEY="sovra_sk_..."
```

La especificación OpenAPI del entorno está siempre disponible en
`GET $BASE_URL/openapi/api` — es la fuente de verdad si algo en esta guía queda
desactualizado.

## Las dos zonas de la API

La API de documentos tiene **dos zonas** con reglas de autenticación distintas, y la
diferencia está en el path:

| Zona | Path | Autenticación |
|---|---|---|
| **Emisor** | `/api/v1/issuer/…` | `Authorization: Bearer <API_KEY>` |
| **Pública** | `/api/v1/documents/…` | Ninguna. Para documentos públicos, tener el `id` es toda la autorización. |

Dos detalles que producen `401` en la primera integración:

- **`Bearer` se compara de forma literal.** `bearer` en minúsculas devuelve
  `401 invalid_api_key`.
- **Una API key no sustituye una sesión.** Un documento privado leído por la zona
  pública devuelve `401 authentication_required` **incluso si mandás la key en el
  header**. Los documentos privados solo se abren con sesión del dashboard.

> 🔒 La API key (`sovra_sk_…`) es un secreto de servidor. No debe viajar en JavaScript
> servido al navegador, ni en un repositorio, ni en una colección de Postman
> exportada.

## Paso 1 — Acceder al dashboard

El acceso a Sovra ID es **por invitación**: el equipo de Sovra crea la invitación y
llega un correo con el enlace de alta.

Al aceptar la invitación se crean, en un solo movimiento, tu **usuario**, una
**organización** y un **workspace** inicial. El workspace es la unidad que importa
para la integración: tiene su propio DID, sus llaves de firma, sus esquemas y sus API
keys. Todo lo que emitís está aislado por workspace.

## Paso 2 — Esperar el DID del workspace

Al crearse, el workspace se **aprovisiona** contra SovraChain: se generan sus llaves
de firma y se registra su identidad on-chain. El resultado es un DID con esta forma:

```
did:sovra:0xb516d2f945db504198d726bda2a01d19ecd3cc23
```

⏱ **El aprovisionamiento tarda algunos minutos.** Mientras no termine, tanto emitir
como listar responden:

```json
{ "error": "workspace_not_provisioned" }
```

Lo ves en **Settings → Workspace**: cuando aparece el DID, el workspace está listo.

> 📐 **¿Y el esquema?** El molde del documento —sus claims, y el layout de la hoja—
> es el paso siguiente, y tiene guía propia:
> [3. Crear un esquema](03-crear-un-esquema.md). Puede hacerse antes o después de la
> API key; lo que importa es que exista y esté `registered` antes de emitir.

## Paso 3 — Crear una API key

**Settings → API keys → New API key.** Necesitás rol de administrador del workspace.

La clave se muestra **una sola vez**, al crearla:

```
sovra_sk_...
```

Guardala en tu gestor de secretos en ese momento. Si la perdés, revocás esa y creás
otra. Todas las llamadas a la zona emisor la usan así:

```
Authorization: Bearer sovra_sk_...
```

- La key está **atada al workspace**: no hay que mandar el `workspace_id` en ningún
  lado.
- Podés tener varias claves activas y revocar cualquiera sin tocar las demás.
- Cada clave registra su `last_used_at`, lo que ayuda a detectar filtraciones.

## Paso 4 — Primera llamada

Con el DID listo, un esquema de kind `document` creado y la API key en mano, verificá
la conexión listando los documentos del workspace (al principio, una lista vacía):

```bash
curl -s "$BASE_URL/api/v1/issuer/documents" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

```json
{ "documents": [] }
```

Si en cambio recibís:

| Respuesta | Qué revisar |
|---|---|
| `{ "error": "invalid_api_key" }` | La clave está mal copiada, revocada, o el esquema no dice `Bearer` con **B** mayúscula. |
| `{ "error": "workspace_not_provisioned" }` | El workspace todavía no tiene DID ni claves. Esperá el aprovisionamiento (Paso 2). |

## Checklist

Antes de pasar al esquema:

- [ ] Tenés acceso al dashboard del entorno.
- [ ] El workspace muestra un DID `did:sovra:0x…`.
- [ ] Tenés una API key `sovra_sk_…` guardada de forma segura.
- [ ] `GET /api/v1/issuer/documents` responde `200`.
- [ ] Hay un endpoint de webhook suscrito a **`document.status-changed`**: es la señal
      de que un documento quedó anclado y ya se puede entregar. Si no podés recibir
      webhooks, tu plan es consultar `GET /api/v1/issuer/documents`.

Y antes de emitir, lo de [3. Crear un esquema](03-crear-un-esquema.md): un esquema de
kind `document`, con su `schema_id` anotado y en estado `registered`.

---

**Anterior:** [← 1. Introducción](01-introduccion.md) · **Siguiente:** [3. Crear un esquema →](03-crear-un-esquema.md)
