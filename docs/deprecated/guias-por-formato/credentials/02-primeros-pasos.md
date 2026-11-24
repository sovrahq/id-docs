# 2. Primeros pasos

## Tabla de contenidos

1. [Entornos](#entornos)
2. [Paso 1 — Acceder al dashboard](#paso-1--acceder-al-dashboard)
3. [Paso 2 — Esperar el DID del workspace](#paso-2--esperar-el-did-del-workspace)
4. [Paso 3 — Crear una API key](#paso-3--crear-una-api-key)
5. [Paso 4 — Configurar el webhook](#paso-4--configurar-el-webhook)
6. [Paso 5 — Primera llamada](#paso-5--primera-llamada)
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

## Paso 1 — Acceder al dashboard

El acceso a Sovra ID es **por invitación**: el equipo de Sovra crea la invitación
y llega un correo con el enlace de alta.

Al aceptar la invitación se crean, en un solo movimiento:

- Tu **usuario**.
- Una **organización**.
- Un **workspace** inicial dentro de esa organización.

El **workspace** es la unidad que importa para la integración: tiene su propio DID,
sus llaves de firma, sus esquemas, sus API keys y su webhook. Todo lo que emitís y
verificás está aislado por workspace. Podés crear más workspaces desde
**Settings → Workspaces** (por ejemplo, uno por dependencia o por línea de producto).

## Paso 2 — Esperar el DID del workspace

Al crearse, el workspace se **aprovisiona** contra SovraChain: se generan sus llaves
de firma y se registra su identidad on-chain. El resultado es un DID con esta forma:

```
did:sovra:0xcda79e1ee5612c230a6faf68236e3679b0579bae
```

⏱ **El aprovisionamiento tarda algunos minutos.** Mientras no termine, cualquier
intento de crear un esquema o una oferta responde:

```json
{ "error": "workspace_not_provisioned" }
```

Lo ves en **Settings → Workspace**: cuando aparece el DID, el workspace está listo.
Si configuraste el webhook antes de que termine, recibís el evento
[`identity.did-generated`](06-webhooks.md#identitydid-generated).

> 📐 **¿Y el esquema?** Crear el molde de la credencial es el paso siguiente, y
> tiene guía propia: [3. Crear un esquema](03-crear-un-esquema.md). Se puede hacer
> antes o después de la API key — sólo hace falta que exista, y que esté
> `registered`, antes de la primera emisión.

## Paso 3 — Crear una API key

**Settings → API keys → New API key.** Necesitás rol de administrador del workspace.

La clave se muestra **una sola vez**, al crearla:

```
sovra_sk_...
```

Guardala en tu gestor de secretos en ese momento. Si la perdés, revocás esa y creás
otra. Todas las llamadas servidor-a-servidor la usan así:

```
Authorization: Bearer sovra_sk_...
```

Un par de propiedades a tener en cuenta:

- La key está **atada al workspace**. No hay que mandar el `workspace_id` en ningún
  lado: el workspace se deduce de la clave.
- Podés tener varias claves activas a la vez (una por sistema integrado, por
  ejemplo) y revocar cualquiera sin tocar las demás.
- Cada clave registra su `last_used_at`, lo que ayuda a detectar filtraciones.

> 🔒 La API key es un secreto de servidor. **Nunca** la pongas en una app móvil, en
> un frontend web ni en un repositorio.

## Paso 4 — Configurar el webhook

Los dos flujos — emisión y verificación — **terminan en un webhook**. Sin webhook
configurado te vas a quedar sin saber el resultado, salvo que hagas polling.

En **Settings → Webhooks** (hasta **10 endpoints** por workspace, cada uno con su
propia configuración):

1. Cargá la **URL** de tu servidor (`https://tu-servidor.com/webhooks/sovra`).
2. **Elegí los eventos que ese endpoint recibe.** La suscripción es una lista
   explícita y **no hay comodín**: un evento que no suscribís no se entrega nunca.
   Para el flujo básico hacen falta `credential.issued`, `presentation.verified` y
   `presentation.failed`; si vas a revocar por API, agregá `credential.revoked`,
   `credential.suspended`, `credential.unsuspended` y
   **`credential.status-change-failed`**.
3. Guardá el **secreto** `whsec_…`. Se muestra **una sola vez**, y es la clave del
   HMAC con el que se firma cada entrega — **prefijo incluido**.

Los detalles del payload, la firma y los reintentos están en
[6. Webhooks](06-webhooks.md).

## Paso 5 — Primera llamada

Con el DID listo y la API key en mano, verificá la conexión listando las
credenciales emitidas (al principio, una lista vacía):

```bash
curl -s "$BASE_URL/api/v1/issuer/credentials" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

```json
{ "credentials": [] }
```

Si en cambio recibís:

```json
{ "error": "invalid_api_key" }
```

la clave está mal copiada, revocada, o le falta el prefijo `Bearer ` (el esquema es
sensible a mayúsculas).

## Checklist

Antes de pasar al esquema:

- [ ] Tenés acceso al dashboard del entorno.
- [ ] El workspace muestra un DID `did:sovra:0x…`.
- [ ] Tenés una API key `sovra_sk_…` guardada de forma segura.
- [ ] La webhook URL apunta a un endpoint tuyo accesible por HTTPS.
- [ ] Guardaste el webhook secret.
- [ ] `GET /api/v1/issuer/credentials` responde `200`.

Y antes de emitir, lo de [3. Crear un esquema](03-crear-un-esquema.md): un esquema de
kind `credential`, con su `schema_id` anotado y en estado `registered`.

---

**Anterior:** [← 1. Introducción](01-introduccion.md) · **Siguiente:** [3. Crear un esquema →](03-crear-un-esquema.md)
