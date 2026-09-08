# 3. Emisión de documentos

Emitir es **una sola llamada**. Sovra valida los claims contra el esquema, firma con
la llave del emisor y ancla la firma on-chain, todo dentro del mismo request.

## Tabla de contenidos

1. [La llamada](#la-llamada)
2. [El request](#el-request)
3. [La latencia: hasta ~90 segundos](#la-latencia-hasta-90-segundos)
4. [La respuesta, campo por campo](#la-respuesta-campo-por-campo)
5. [Qué guardar](#qué-guardar)
6. [Estados del documento](#estados-del-documento)
7. [Listar los documentos del workspace](#listar-los-documentos-del-workspace)
8. [Revocación](#revocación)
9. [Errores](#errores)

---

## La llamada

```
POST $BASE_URL/api/v1/issuer/documents
Authorization: Bearer sovra_sk_...
Content-Type: application/json
```

```bash
curl -s -X POST "$BASE_URL/api/v1/issuer/documents" \
  --max-time 120 \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
    "schema_id": "document_example",
    "claims": {
      "date": "20-08-2026",
      "idDocument": 1234567,
      "content": "Contenido de Prueba",
      "isConfidential": false
    },
    "visibility": "private"
  }'
```

## El request

| Campo | Tipo | Obligatorio | Qué es |
|---|---|---|---|
| `schema_id` | `string` | ✅ | El identificador **legible** del esquema (el `schema.schema_id` del dashboard), no el UUID interno. |
| `claims` | `object` | ✅ | Un par `key: valor` por cada claim del esquema. Los `required` no pueden faltar. |
| `visibility` | `string` | — | `"private"` (default) o `"public"`. |

Los tipos de cada claim los declara el esquema (`date`, `number`, `string`,
`boolean`) y se validan durante la emisión.

> **Todo documento nace privado.** Si omitís `visibility`, el documento queda
> `private` y hace falta una llamada explícita para publicarlo — ver
> [4. Visibilidad y entrega](04-visibilidad-y-entrega.md).

## La latencia: hasta ~90 segundos

La emisión no es la de un `POST` normal, porque incluye el anclaje on-chain:

- **Poné el timeout del cliente en 120 s.** El default de muchas librerías HTTP
  (30 s, o menos) corta la conexión antes de que la respuesta llegue.
- **Mostrá progreso al usuario.** Un botón que parece colgado durante 40 segundos
  invita a hacer doble clic, y cada clic emite otro documento.
- **En el ambiente de test la respuesta suele llegar en ~3 segundos**, pero el techo
  depende de la congestión de la cadena, no del caso feliz. No dimensiones el timeout
  contra lo que medís en test.

Si tu arquitectura no tolera una llamada sincrónica larga, encolá la emisión en un
worker y notificá a tu propia aplicación cuando termine: **no hay webhooks de
documentos**, así que el resultado llega únicamente como respuesta de este request.

## La respuesta, campo por campo

**`201 Created`**

```json
{
  "id": "63813a91-6986-46bf-9f23-128a6598cd82",
  "status": "anchored",
  "visibility": "private",
  "credential": "eyJhbGciOiJFUzI1NiIsInR5cCI6InZjK3Nk…eyJjb250ZW50…~",
  "claims": {
    "date": "20-08-2026",
    "idDocument": 1234567,
    "content": "Contenido de Prueba",
    "isConfidential": false
  },
  "digest": "1043…",
  "tx_hash": "0x9c93c1eec04ae7e4566b4ee394ac518b33c36b0ffd324276289e998eed72f8ca",
  "signed_at": "2026-08-21T14:12:34Z",
  "anchored_at": "2026-08-21T14:12:43Z",
  "revoked_at": null,
  "issuer": {
    "did": "did:sovra:0xb516d2f945db504198d726bda2a01d19ecd3cc23",
    "name": "Lotería de San Juan",
    "address": "0xb516d2f945db504198d726bda2a01d19ecd3cc23"
  },
  "schema": {
    "id": "3f2b…",
    "schema_id": "document_example",
    "credential_type": "Document",
    "name": "Document Example",
    "version": "v1",
    "claims": [ /* … */ ]
  }
}
```

La columna **Firmado** indica si el dato está cubierto por la firma del emisor (vive
dentro del `credential`) o si es una afirmación del servicio. Ver
[las dos capas de confianza](01-introduccion.md#las-dos-capas-de-confianza).

| Campo | Tipo | Qué es | Firmado |
|---|---|---|---|
| `id` | `uuid` | El identificador del documento. Se usa en las URLs (`/api/v1/documents/<id>`) y coincide con el claim `jti` de adentro del `credential`, así que una credencial no puede servirse bajo otro id. | Sí (como `jti`) |
| `status` | `string` | El ciclo de vida. `anchored` = firmado y con la firma registrada on-chain: es el estado final exitoso. | No |
| `visibility` | `string` | `public` o `private`. Controla el acceso vía la API — ver [guía 4](04-visibilidad-y-entrega.md). | No |
| `credential` | `string` | ★ **El documento en sí.** Un SD-JWT VC en base64url, autocontenido y verificable por cualquiera que lo tenga. Es el único campo que hay que conservar. | Es la firma |
| `claims` | `object` | Los mismos claims ya decodificados, por conveniencia. No es prueba de nada: es lo que el servicio *dice* que dice el documento. | Sí, adentro del `credential` |
| `digest` | `string` (hex) | El SHA-256 del *signing input* (los bytes que se firmaron). La huella del documento: la verificación lo recalcula y compara. Si alguien altera un byte, deja de coincidir. | No (se recalcula) |
| `tx_hash` | `string` (`0x…`) | El hash de la transacción on-chain donde quedó anclada la firma. | No |
| `signed_at` | `string` (ISO 8601) | Cuándo el emisor firmó. | Sí (como `iat`) |
| `anchored_at` | `string` (ISO 8601) | Cuándo la firma quedó registrada en la cadena. En las pruebas, segundos después de firmar. | No |
| `revoked_at` | `string` \| `null` | `null` = vigente. Con fecha, el documento está revocado. | No |
| `issuer` | `object` | Quién emitió — ver abajo. | Parcial |
| `schema` | `object` | El molde usado — ver abajo. | Parcial |

### `issuer`

| Campo | Tipo | Qué es | Firmado |
|---|---|---|---|
| `issuer.did` | `string` | El identificador descentralizado (`did:sovra:0x…`). Con este DID el verificador resuelve la clave pública del emisor desde el registry on-chain. | Sí (como `iss`) |
| `issuer.address` | `string` (`0x…`) | La dirección Ethereum-style del emisor en la cadena, derivada de su clave pública. | Sí, se deriva de `iss` |
| `issuer.name` | `string` | Nombre visible del emisor. **Afirmación del servicio** — un verificador no debería usarlo para decidir. | No |

### `schema`

| Campo | Tipo | Qué es | Firmado |
|---|---|---|---|
| `schema.id` | `string` (uuid) | UUID interno del esquema en el servicio. | No |
| `schema.schema_id` | `string` | El identificador legible, el que se manda al emitir. | No |
| `schema.name` | `string` | Nombre humano del molde. | No |
| `schema.version` | `string` | Versión del molde. | No |
| `schema.credential_type` | `string` | El tipo de credencial. Viaja como `vct` dentro del JWT firmado, y es contra este valor que se comprueba on-chain si el emisor está autorizado a emitirlo. | Sí (como `vct`) |
| `schema.claims[]` | `array<object>` | La definición de cada campo. | No |

### `schema.claims[]`

| Campo | Tipo | Qué es |
|---|---|---|
| `key` | `string` | Nombre del campo dentro del objeto `claims`. |
| `label` | `string` | Etiqueta legible, para UI y layout. |
| `type` | `string` | Tipo de dato: `date`, `number`, `string`, `boolean`. |
| `required` | `boolean` | Si es `true` y falta al emitir → `422 missing_claims`. |
| `disclosable` | `boolean` | Si el campo sería divulgable selectivamente bajo SD-JWT. En un documento no aplica: no hay holder que negocie divulgación. |

## Qué guardar

> ★ **De todo lo que devuelve este endpoint, `credential` es lo único que importa
> guardar.** Todo el resto es la afirmación del propio servicio sobre él y se puede
> volver a pedir —o perder— sin consecuencias. Si tu integración persiste un solo
> campo, es ese.

En la práctica conviene guardar además:

| Campo | Por qué |
|---|---|
| `id` | Para armar el enlace público y para cambiar la visibilidad después. |
| `tx_hash` | Para linkear al explorer sin volver a consultar la API. |
| `digest` | Se puede recalcular del `credential`, pero tenerlo a mano evita el cómputo. |

Todo eso también se puede recuperar más tarde con
[`GET /api/v1/issuer/documents`](#listar-los-documentos-del-workspace), que devuelve
el `credential` completo de cada documento.

## Estados del documento

`status` es el ciclo de vida que informa el servicio (no está firmado):

| `status` | Qué significa | ¿Entregable? |
|---|---|---|
| `signed` | Firmado, pero el anclaje todavía no confirmó. | No todavía |
| `anchored` | Firmado y con la firma registrada on-chain. **El estado final exitoso.** | ✅ Sí |
| `failed` | El documento se firmó, pero su transacción de anclaje **no confirmó**. | ❌ No |
| `revoked` | El documento fue invalidado. `revoked_at` trae la fecha. | ❌ No |

> ⚠️ **Solo `anchored` es un estado entregable.** Un `failed` significa que la firma
> existe pero no quedó registrada en la cadena, y un verificador que corra el check
> de anclaje lo va a rechazar (`NOT_ANCHORED`). No lo reenvíes: reemitilo.

## Listar los documentos del workspace

```bash
curl -s "$BASE_URL/api/v1/issuer/documents" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

```json
{
  "documents": [
    { "id": "63813a91-…", "status": "anchored", "credential": "eyJhbGci…~", "…": "…" }
  ]
}
```

| Campo | Tipo | Qué es |
|---|---|---|
| `documents` | `array<object>` | Una entrada por documento, ordenadas **de la más nueva a la más vieja**. |
| `documents[]` | `object` | Cada entrada tiene **exactamente la misma forma que la respuesta de emisión**, incluidos el `credential` completo y los `claims` decodificados. |

Que cada entrada traiga el `credential` completo es lo que hace útil este endpoint:
**alcanza una llamada para reconstruir todos los documentos emitidos**, sin pedir cada
uno por separado. Es también la red de seguridad si perdiste un `credential` de tu
lado.

Dos límites que conviene tener presentes:

- **Sin paginación.** No hay `?page`, `?limit` ni cursor, y los query params se
  ignoran. La respuesta crece con el workspace, y cada entrada incluye un SD-JWT
  completo (~520 bytes en el ejemplo de estas guías).
- **Sin filtros ni búsqueda.** No hay `?digest=`, ni `/by-digest/`, ni `/search`. En
  la práctica esto convierte a este endpoint en el **único punto de consulta del lado
  emisor**: si necesitás buscar, indexá del lado tuyo a partir de este listado.

> 🔒 Si construís un índice o buscador encima de este listado, **incluí solo los
> documentos con `visibility: "public"`**. Servir un privado por búsqueda —por id,
> por digest, por cualquier claim— anula la decisión del emisor por la puerta de
> atrás.

## Revocación

La respuesta trae `revoked_at` y `status: "revoked"`, así que un documento **se puede
invalidar**: es el mecanismo real para dar de baja un documento emitido, y es
distinto de volverlo `private` (eso cierra el enlace, no las copias).

> ⚠️ **La API de documentos no expone un endpoint de revocación.** Los cuatro
> endpoints disponibles son los de la [referencia](07-referencia-api.md); ninguno
> revoca. La baja se gestiona desde el dashboard. Si necesitás revocar por API,
> confirmalo con el equipo de Sovra antes de diseñar el flujo.

## Errores

| Respuesta | Causa | Qué revisar |
|---|---|---|
| `401 invalid_api_key` | Key desconocida, malformada o revocada, o el esquema no era literalmente `Bearer`. | Que el header diga `Bearer` con **B** mayúscula. |
| `409 workspace_not_provisioned` | El workspace todavía no tiene DID ni claves. | Aprovisionar el workspace desde el dashboard y esperar el DID. |
| `422 wrong_schema_kind` | El esquema es de kind `credential` (con holder), no `document`. | Usar un esquema de documentos. |
| `422 missing_claims` | Falta un claim requerido. **La respuesta lista cuáles.** | Los `required` de `schema.claims[]`. |
| `422 invalid_visibility` | `visibility` no es `public` ni `private`. | El campo del body. |
| `503 chain_not_configured` | No hay registro de firmas configurado: el servicio **se niega a firmar**. | Es intencional — prefiere fallar antes que emitir algo no anclable. Contactá al equipo de Sovra. |

El sobre de error es siempre el mismo:

```json
{ "error": "missing_claims", "details": ["content", "idDocument"] }
```

La tabla completa, con qué endpoints devuelven qué, está en
[8. Errores y troubleshooting](08-errores-y-troubleshooting.md).

---

**Anterior:** [← 2. Primeros pasos](02-primeros-pasos.md) · **Siguiente:** [4. Visibilidad y entrega →](04-visibilidad-y-entrega.md)
