# 4. Visibilidad y entrega

Un documento nace privado. Publicarlo es siempre una decisión deliberada, y lo que
esa decisión cambia —y lo que **no** cambia— es el malentendido más caro posible en
esta API.

## Tabla de contenidos

1. [Publicar o despublicar](#publicar-o-despublicar)
2. [Qué restringe la visibilidad, y qué no](#qué-restringe-la-visibilidad-y-qué-no)
3. [Leer un documento: la zona pública](#leer-un-documento-la-zona-pública)
4. [Diferencias con la respuesta de emisión](#diferencias-con-la-respuesta-de-emisión)
5. [Los caminos de entrega](#los-caminos-de-entrega)
6. [Errores](#errores)

---

## Publicar o despublicar

```
PUT $BASE_URL/api/v1/issuer/documents/{id}/visibility/{visibility}
Authorization: Bearer sovra_sk_...
```

**Sin body y sin `Content-Type`:** los dos valores viajan en el path.

| Parámetro | Posición | Tipo | Qué es |
|---|---|---|---|
| `id` | path | `uuid` | El `id` que devolvió la emisión. |
| `visibility` | path | `string` | `public` o `private`. Cualquier otro valor → `422 invalid_visibility`. |

```bash
# publicarlo
curl -s -X PUT -H "Authorization: Bearer $SOVRA_API_KEY" \
  "$BASE_URL/api/v1/issuer/documents/$DOCUMENT_ID/visibility/public"

# y volverlo privado
curl -s -X PUT -H "Authorization: Bearer $SOVRA_API_KEY" \
  "$BASE_URL/api/v1/issuer/documents/$DOCUMENT_ID/visibility/private"
```

**`200 OK`** devuelve el mismo objeto que la emisión, con `visibility` actualizada.
Todos los campos y su significado están en
[la tabla de la guía 3](03-emision-de-documentos.md#la-respuesta-campo-por-campo).

| Campo | Qué cambia |
|---|---|
| `visibility` | El valor nuevo, el que se pidió en el path. |
| Todo el resto | **Idéntico.** La firma, el `digest`, el `credential` y el `tx_hash` no se tocan: el documento no se reemite. |

## Qué restringe la visibilidad, y qué no

Lo importante de esta llamada no es lo que hace, sino lo que **no** hace.

**`visibility` restringe los endpoints, no el artefacto.**

- `public` → tener el id/enlace alcanza para leer el documento por la zona pública.
- `private` → el enlace por sí solo no alcanza; hace falta **sesión del dashboard**.

| Cambia | No cambia |
|---|---|
| Si la zona pública (`GET /api/v1/documents/{id}`) responde el documento o pide sesión | Las copias del `credential` **ya entregadas**, que siguen legibles y verificables para siempre |
| Nada más. El `credential`, la firma y el anclaje quedan intactos | El documento en sí: `visibility` **no está cubierta por la firma** |

Y las tres suposiciones que hay que desarmar:

| Suposición | Realidad |
|---|---|
| *"Privado = los bytes son secretos"* | **No.** Un `credential` ya entregado sigue siendo legible y verificable por quien lo tenga, para siempre. |
| *"Volver a privado revoca el documento"* | **No.** Cierra el enlace, no las copias. Para invalidar el documento existe la revocación (`revoked_at`) — ver [guía 3](03-emision-de-documentos.md#revocación). |
| *"La visibilidad es parte del documento"* | **No.** No está cubierta por la firma. Es un atributo del servicio. |

> En resumen: **privado significa "el enlace por sí solo no basta", nunca "los bytes
> son secretos".**

**Corolario para quien construya un buscador o índice encima de esta API:** servir un
documento privado por búsqueda —por id, por digest, por cualquier claim— anula la
decisión del emisor por la puerta de atrás. Un índice consultable por terceros
debería incluir **solo** documentos con `visibility: "public"`.

## Leer un documento: la zona pública

```
GET $BASE_URL/api/v1/documents/{id}
```

Es el endpoint que consume **el destinatario**, no el emisor. Dos diferencias con la
zona emisor, las dos visibles en el path: **no hay `/issuer`** y **no lleva API key**.

- Si el documento es **público**, tener el id es toda la autorización.
- Si es **privado**, hace falta sesión del dashboard. Una API key **no** la suple:
  responde `401 authentication_required` incluso con la key en el header.

Responde con `Access-Control-Allow-Origin: *`, así que un navegador en cualquier
dominio puede llamarlo directo, siempre que no envíe credenciales.

```bash
curl -s "$BASE_URL/api/v1/documents/$DOCUMENT_ID"
```

**`200 OK`**

```json
{
  "id": "63813a91-6986-46bf-9f23-128a6598cd82",
  "credential": "eyJhbGciOiJFUzI1NiIsInR5cCI6InZjK3Nk…eyJjb250ZW50…~",
  "issuer": {
    "did": "did:sovra:0xb516d2f945db504198d726bda2a01d19ecd3cc23",
    "name": "Lotería de San Juan",
    "address": "0xb516d2f945db504198d726bda2a01d19ecd3cc23"
  },
  "schema": {
    "name": "Document Example",
    "claims": [
      { "key": "date", "label": "Date", "type": "date", "required": true, "disclosable": true }
    ]
  },
  "layout": {
    "page": { "width": 794, "height": 1123 },
    "elements": [ /* … */ ]
  },
  "anchor": {
    "status": "anchored",
    "tx_hash": "0x9c93c1eec04ae7e4566b4ee394ac518b33c36b0ffd324276289e998eed72f8ca",
    "anchored_at": "2026-08-21T14:12:43Z"
  }
}
```

| Campo | Tipo | Qué es | Firmado |
|---|---|---|---|
| `id` | `uuid` | El identificador del documento, el mismo que va en la URL. | Sí (como `jti`) |
| `credential` | `string` | ★ **El documento en sí.** Lo único verificable de toda la respuesta. | Es la firma |
| `issuer.did` | `string` | El DID del emisor (`did:sovra:0x…`); con él se resuelve su clave pública on-chain. | Sí (como `iss`) |
| `issuer.address` | `string` (`0x…`) | La dirección del emisor en la cadena. | Sí, se deriva de `iss` |
| `issuer.name` | `string` | Nombre visible del emisor. **Solo informativo.** | No |
| `schema.name` | `string` | Nombre humano del esquema. Versión reducida: acá **no** vienen el UUID ni el `credential_type`. | No |
| `schema.claims[]` | `array<object>` | Definición de cada campo: `key`, `label`, `type`, `required`, `disclosable`. | No |
| `layout` | `object` | Instrucciones de cómo dibujar la hoja — ver [5. Renderizado](05-renderizado.md). | No |
| `anchor.status` | `string` | `anchored` = la firma quedó registrada on-chain. | No |
| `anchor.tx_hash` | `string` (`0x…`) | Hash de la transacción del anclaje. | No |
| `anchor.anchored_at` | `string` (ISO 8601) | Cuándo quedó anclada. | No |

> ⚠️ **`schema.claims[]` trae solo *definiciones*, no valores.** El `1234567` y el
> `"Contenido de Prueba"` están dentro del `credential`, no en esta respuesta. Es la
> sorpresa número uno al integrar el renderizado — ver
> [5. Renderizado](05-renderizado.md#las-tres-cosas-que-el-json-no-trae).

## Diferencias con la respuesta de emisión

| Solo en la emisión ([§3](03-emision-de-documentos.md)) | Solo en la lectura pública |
|---|---|
| `claims` decodificados | `layout` |
| `visibility` | |
| `signed_at`, `revoked_at` | |
| `digest`, `tx_hash` en la raíz | Los mismos datos, dentro de `anchor` |
| `schema.id`, `schema.schema_id`, `schema.credential_type` | |

La lógica del recorte: **la emisión responde al emisor**, que necesita el estado
interno del documento; **la lectura pública responde a quien lo recibió**, que
necesita poder dibujarlo. De ahí que este endpoint devuelva `layout` y el otro no.

## Los caminos de entrega

Hay dos formas de que el documento llegue a su destinatario, y **las dos terminan en
lo mismo**:

| Camino | Qué le mandás | Cuándo conviene |
|---|---|---|
| **El string** | El `credential` completo: por correo, en un archivo, en un QR impreso, embebido en un PDF | Cuando querés que el documento sobreviva a la API. No requiere que el documento sea público, ni que Sovra esté en línea. |
| **El enlace** | `$BASE_URL/api/v1/documents/{id}` | Cuando querés una URL compartible. Requiere `visibility: "public"`, y del lado de quien la abre hace falta renderizar el JSON — ver [5. Renderizado](05-renderizado.md). |

Un par de consecuencias prácticas:

- **El QR de una hoja impresa debería llevar el `credential`, no un link.** Ese es el
  único motivo por el que la hoja no hay que creerla: quien la recibe escanea,
  verifica la firma contra la cadena, y si el servicio desapareció el QR impreso
  sigue sirviendo. Un link a la hoja solo prueba que el servicio sigue en línea.
- **Publicar no es entregar.** Un documento `public` es legible por cualquiera que
  adivine o consiga el `id`. Es un UUID v4, así que no se adivina — pero tampoco es
  una autorización: si el `id` se filtra, el documento se lee.
- **Despublicar no recupera nada.** Ver
  [arriba](#qué-restringe-la-visibilidad-y-qué-no).

## Errores

### `PUT …/visibility/{visibility}`

| Respuesta | Causa |
|---|---|
| `401 invalid_api_key` | Key inválida, o el esquema no era literalmente `Bearer`. |
| `404 document_not_found` | Id inexistente, malformado, o de otro workspace. |
| `422 invalid_visibility` | El último segmento del path no es `public` ni `private`. |

### `GET /api/v1/documents/{id}`

| Respuesta | Causa |
|---|---|
| `401 authentication_required` | El documento es **privado** y no hay sesión. Se devuelve **incluso cuando el header traía una API key**. |
| `404 document_not_found` | Id inexistente, malformado, o de otro workspace. **No distingue entre los tres casos, a propósito:** no filtra información. |

> Un `401` en la zona pública **no es una falla de la integración**: es la respuesta
> correcta para un documento privado. Todo documento nace privado.

---

**Anterior:** [← 3. Emisión de documentos](03-emision-de-documentos.md) · **Siguiente:** [5. Renderizado de la hoja →](05-renderizado.md)
