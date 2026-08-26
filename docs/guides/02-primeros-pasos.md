# 2. Primeros pasos

## Tabla de contenidos

1. [Entornos](#entornos)
2. [Paso 1 — Acceder al dashboard](#paso-1--acceder-al-dashboard)
3. [Paso 2 — Esperar el DID del workspace](#paso-2--esperar-el-did-del-workspace)
4. [Paso 3 — Crear un esquema](#paso-3--crear-un-esquema)
5. [Paso 4 — Crear una API key](#paso-4--crear-una-api-key)
6. [Paso 5 — Configurar el webhook](#paso-5--configurar-el-webhook)
7. [Paso 6 — Primera llamada](#paso-6--primera-llamada)
8. [Checklist](#checklist)

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
[`identity.did-generated`](05-webhooks.md#identitydid-generated).

## Paso 3 — Crear un esquema

Un **esquema** define qué campos lleva una credencial. Se crean **desde el
dashboard** (menú **Schemas**), no por API: la API servidor-a-servidor emite y
verifica, pero el modelo de datos se define en la consola.

El asistente pide:

| Campo | Qué es | Notas |
|---|---|---|
| **Name** | Nombre visible del esquema | De acá se deriva el `schema_id`. |
| **Credential type** | El `vct` de la credencial | Sensible a mayúsculas. Es lo que el verificador debe reconocer. |
| **Validity period** | Cuánto vive la credencial | Formato `^\d+(y\|m\|d)$`: `10y`, `6m`, `30d`. Si no se puede parsear, se usa 1 año en silencio. |
| **Kind** | `credential` o `document` | Para emitir a una wallet: **`credential`**. |
| **Claims** | Los campos | Cada uno con `key`, `label`, `type` (`string`, `number`, `date`, `boolean`) y `required`. |

Tres cosas que conviene saber antes de cargar el primer esquema:

**1. El `schema_id` se deriva del nombre.** Se toma el *name*, se pasa a minúsculas,
todo lo que no sea `a-z0-9` se convierte en `_`, y se corta a 24 caracteres.
"Licencia de Conducir" → `licencia_de_conducir`. **Ese es el valor que vas a mandar
en `schema_id` al crear una oferta** — lo podés confirmar en el listado de esquemas.

**2. El `key` es lo único que sobrevive.** El `label` es decorativo: no se persiste
en la credencial. El `key` es el nombre real del campo dentro del SD-JWT, es lo que
ve el ciudadano en la wallet y **es lo que un verificador tiene que pedir, letra por
letra**. Usá `snake_case` y revisá la ortografía: un `fist_name` con errata obliga a
todos los verificadores a pedir `fist_name` para siempre.

**3. Todos los claims son divulgables.** El dashboard marca `disclosable: true` en
cada campo, así que la divulgación selectiva funciona sin configurar nada: el
ciudadano elige campo por campo qué comparte.

> 💡 **Mantené los esquemas en ~12 claims.** El JWT del emisor lleva un digest `_sd`
> por **cada** claim divulgable, revele el holder uno o todos — unos 44 caracteres
> cada uno. Un esquema de 30 campos infla el QR de respuesta de la wallet más de un
> kilobyte incluso cuando se comparte un solo dato, y un QR muy denso se vuelve
> difícil de escanear.

### Versionado

Editar un esquema **no lo modifica**: crea una **versión nueva**. Las credenciales
ya emitidas quedan ancladas a la versión con la que se emitieron. Al crear una
oferta, Sovra usa siempre la versión más reciente de ese `schema_id`.

Un esquema con credenciales emitidas no se puede borrar (`409 schema_in_use`).

## Paso 4 — Crear una API key

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

## Paso 5 — Configurar el webhook

Los dos flujos — emisión y verificación — **terminan en un webhook**. Sin webhook
configurado te vas a quedar sin saber el resultado, salvo que hagas polling.

En **Settings → Workspace**:

1. Cargá la **Webhook URL** de tu servidor (`https://tu-servidor.com/webhooks/sovra`).
2. Generá el **webhook secret**. También se muestra **una sola vez**; se usa para
   validar la firma HMAC de cada entrega.

Los detalles del payload, la firma y los reintentos están en
[5. Webhooks](05-webhooks.md).

## Paso 6 — Primera llamada

Con el DID listo, un esquema creado y la API key en mano, verificá la conexión
listando las credenciales emitidas (al principio, una lista vacía):

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

Antes de pasar a emitir:

- [ ] Tenés acceso al dashboard del entorno.
- [ ] El workspace muestra un DID `did:sovra:0x…`.
- [ ] Hay al menos un esquema de kind `credential`, y anotaste su `schema_id`.
- [ ] Tenés una API key `sovra_sk_…` guardada de forma segura.
- [ ] La webhook URL apunta a un endpoint tuyo accesible por HTTPS.
- [ ] Guardaste el webhook secret.
- [ ] `GET /api/v1/issuer/credentials` responde `200`.

---

**Anterior:** [← 1. Introducción](01-introduccion.md) · **Siguiente:** [3. Emisión de credenciales →](03-emision-de-credenciales.md)
