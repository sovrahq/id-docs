# 2. Primeros pasos

## Tabla de contenidos

1. [Entornos](#entornos)
2. [Las dos zonas de la API](#las-dos-zonas-de-la-api)
3. [Paso 1 — Acceder al dashboard](#paso-1--acceder-al-dashboard)
4. [Paso 2 — Esperar el DID del workspace](#paso-2--esperar-el-did-del-workspace)
5. [Paso 3 — Crear el esquema de documento](#paso-3--crear-el-esquema-de-documento)
6. [Paso 4 — Diseñar el layout](#paso-4--diseñar-el-layout)
7. [Paso 5 — Crear una API key](#paso-5--crear-una-api-key)
8. [Paso 6 — Primera llamada](#paso-6--primera-llamada)
9. [Checklist](#checklist)

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

## Paso 3 — Crear el esquema de documento

Un **esquema** define qué claims lleva el documento. Se crea **desde el dashboard**
(menú **Schemas**), no por API: la API servidor-a-servidor emite, pero el modelo de
datos y el diseño se definen en la consola.

El asistente tiene cuatro pasos: **Metadata → Claims → Layout → Review**.

### Metadata

| Campo | Qué es | Notas |
|---|---|---|
| **Type** | `Credential` o `Document` | Elegí **`Document`**: *"Signed and issued directly by you, no wallet and no holder. Verified by link."* |
| **Display name** | Nombre visible del esquema | De acá se deriva el `schema_id`. |
| **Credential type** | El `vct` del documento | PascalCase, sensible a mayúsculas (`Document`, `ConstanciaDeEstudios`). Es contra este valor que se comprueba on-chain si el emisor está autorizado. |
| **Validity** | Cuánto vive el documento | Formato `^\d+(y\|m\|d)$`: `1y`, `6m`, `30d`. Define el `exp` del JWT. |
| **Schema ID** | El identificador legible | Se deriva del *display name*; es el valor que vas a mandar en `schema_id` al emitir. |

> El **kind** es lo que separa un documento de una credencial. Un esquema de kind
> `credential` no sirve acá: la emisión responde `422 wrong_schema_kind`.

### Claims

Una fila por campo, con **claim key**, **label**, **type** y **required**:

| Columna | Qué es |
|---|---|
| **Claim key** | El nombre real del campo dentro del `credential`. Es lo que mandás en `claims` al emitir y lo que un layout bindea. |
| **Label** | Etiqueta legible, para la UI y el layout. **No se persiste en el `credential`.** |
| **Type** | `string`, `number`, `date` o `boolean`. Se valida durante la emisión. |
| **Required** | Si es `true` y falta al emitir → `422 missing_claims`. |

Ejemplo, el esquema `document_example` que usan estas guías:

| Claim key | Label | Type | Required |
|---|---|---|---|
| `date` | Date | `date` | ✅ |
| `idDocument` | ID Document | `number` | ✅ |
| `content` | Content | `string` | ✅ |
| `isConfidential` | Confidencial | `boolean` | ✅ |

Tres cosas que conviene saber antes de guardar el primer esquema:

**1. El `key` es lo único que sobrevive.** El `label` es decorativo. El `key` es el
nombre del campo dentro del JWT firmado y **es lo que tu código y cualquier
renderizador van a buscar, letra por letra**. Revisá la ortografía: una errata en un
`key` queda dentro de la firma de todos los documentos emitidos con esa versión.

**2. `required` es sobre la emisión, no sobre la lectura.** Marcar un campo como
requerido solo significa que **tiene que estar presente al emitir**. No afecta quién
puede leerlo: en un documento todos los claims van en el payload, a la vista de
cualquiera que tenga el string.

**3. `disclosable` no cambia nada acá.** El dashboard marca `disclosable: true` en
cada claim y el campo aparece en las respuestas de la API, pero un documento no tiene
holder que negocie divulgación: la lista de disclosures del SD-JWT está vacía y todos
los claims son visibles. Es relevante solo para esquemas de kind `credential`.

### Versionado

Editar un esquema **no lo modifica**: publica una **versión nueva**
(`document_example · v1 → v2`). Los documentos ya emitidos quedan anclados a la
versión con la que se emitieron y siguen siendo válidos. Al emitir, Sovra usa siempre
la versión más reciente de ese `schema_id`.

Dos campos **no** pueden cambiar entre versiones: el **Schema ID** (es estable) y el
**Credential type** (es el `vct`, y los verificadores lo reconocen).

## Paso 4 — Diseñar el layout

El paso **Layout** del asistente es un editor visual: se arrastran los claims sobre
una hoja A4 (794 × 1123 px, la proporción A4 a 96 dpi).

Del panel izquierdo salen dos cosas por cada claim — **Label** (el texto fijo) y
**Value** (el campo dinámico) — más elementos libres: **Add title**, **Add text**,
**Add line**, **Add box**, **Add image** y **Upload template** para una imagen de
fondo. Un `Preview` muestra la hoja con datos de ejemplo.

> El propio editor lo dice: *"This is presentation only, it is not part of what gets
> signed."* El layout **no está cubierto por la firma**. Define cómo se dibuja la
> hoja, no qué dice el documento.

**El layout es opcional.** Sin layout la emisión funciona igual; lo que se pierde es
el renderizado — la respuesta pública no trae con qué dibujar la hoja. Los detalles
del objeto `layout` y cómo recorrerlo están en
[5. Renderizado de la hoja](05-renderizado.md).

## Paso 5 — Crear una API key

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

## Paso 6 — Primera llamada

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

Antes de pasar a emitir:

- [ ] Tenés acceso al dashboard del entorno.
- [ ] El workspace muestra un DID `did:sovra:0x…`.
- [ ] Hay al menos un esquema de kind **`document`**, y anotaste su `schema_id`.
- [ ] Anotaste los `key` y los `type` de cada claim, y cuáles son `required`.
- [ ] *(Opcional)* El esquema tiene un layout diseñado.
- [ ] Tenés una API key `sovra_sk_…` guardada de forma segura.
- [ ] `GET /api/v1/issuer/documents` responde `200`.
- [ ] El timeout de tu cliente HTTP está en **120 s** — la emisión puede tardar ~90 s.

---

**Anterior:** [← 1. Introducción](01-introduccion.md) · **Siguiente:** [3. Emisión de documentos →](03-emision-de-documentos.md)
