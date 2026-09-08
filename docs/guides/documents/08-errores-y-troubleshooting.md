# 8. Errores y troubleshooting

## Tabla de contenidos

1. [El sobre de error](#el-sobre-de-error)
2. [Tabla completa](#tabla-completa)
3. [Cada error en detalle](#cada-error-en-detalle)
4. [Síntomas que no son errores de la API](#síntomas-que-no-son-errores-de-la-api)
5. [Checklist de diagnóstico](#checklist-de-diagnóstico)

---

## El sobre de error

Siempre el mismo, en los cuatro endpoints:

```json
{ "error": "codigo_de_error", "details": { } }
```

`details` es opcional y aparece solo en errores de validación (por ejemplo,
`missing_claims` lista los claims que faltaron).

## Tabla completa

Los endpoints se referencian por su número de sección en
[7. Referencia de la API](07-referencia-api.md).

| Respuesta | Endpoints | Causa | Qué revisar |
|---|---|---|---|
| `401 invalid_api_key` | `POST`, `PUT`, `GET /issuer` | Key desconocida, malformada o revocada — o el esquema no era literalmente `Bearer` | Que el header diga `Bearer` con **B** mayúscula |
| `401 authentication_required` | `GET /documents/{id}` | Documento privado, sin sesión. Se devuelve **incluso cuando el header traía una API key** | Publicar el documento, o abrirlo desde el dashboard |
| `404 document_not_found` | `PUT`, `GET /documents/{id}` | Id inexistente, malformado, o de otro workspace. No distingue entre los tres casos, a propósito | El id y el workspace al que pertenece la key |
| `409 workspace_not_provisioned` | `POST`, `GET /issuer` | El workspace todavía no tiene DID ni claves | Aprovisionar el workspace desde el dashboard |
| `422 wrong_schema_kind` | `POST` | El esquema es de kind `credential` (con holder), no `document` | Usar un esquema de documentos |
| `422 missing_claims` | `POST` | Falta un claim requerido. La respuesta lista cuáles | Los `required` de `schema.claims[]` |
| `422 invalid_visibility` | `POST`, `PUT` | El valor no es `public` ni `private` | El último segmento del path, o el campo del body |
| `503 chain_not_configured` | `POST` | No hay registro de firmas configurado: se niega a firmar | Es intencional — prefiere fallar antes que emitir algo no anclable |

## Cada error en detalle

### `401 invalid_api_key`

```json
{ "error": "invalid_api_key" }
```

Cuatro causas, en orden de frecuencia:

1. **El esquema en minúsculas.** `bearer sovra_sk_…` devuelve `401`. La comparación es
   literal: tiene que ser `Bearer` con **B** mayúscula.
2. **La key está mal copiada.** Un salto de línea o un espacio al final del valor
   pegado desde el gestor de secretos alcanza.
3. **La key fue revocada.** Revisá **Settings → API keys** en el dashboard.
4. **La key es de otro entorno.** Una key de test contra `api.sovra.io` no valida.

```bash
# El header, tal cual tiene que salir
curl -sv "$BASE_URL/api/v1/issuer/documents" \
  -H "Authorization: Bearer $SOVRA_API_KEY" 2>&1 | grep -i authorization
```

### `401 authentication_required`

```json
{ "error": "authentication_required" }
```

**No es una falla de la integración.** Todo documento nace privado, y la zona pública
de un documento privado responde así — **incluso si mandaste la API key**, porque una
key no sustituye una sesión del dashboard.

Dos salidas:

- Publicar el documento:
  `PUT /api/v1/issuer/documents/{id}/visibility/public`.
- Abrirlo desde el dashboard, con sesión.

Y una tercera, si lo que necesitás es que el destinatario lo tenga sin publicarlo:
**mandale el string `credential`.** El `credential` no depende de la visibilidad —
ver [4. Visibilidad y entrega](04-visibilidad-y-entrega.md#los-caminos-de-entrega).

### `404 document_not_found`

```json
{ "error": "document_not_found" }
```

Tres casos distintos que responden lo mismo, **a propósito**: no filtra información
sobre qué ids existen en otros workspaces.

| Caso | Cómo distinguirlo |
|---|---|
| El id no existe | Buscalo en `GET /api/v1/issuer/documents`. |
| El id está malformado | Tiene que ser un UUID v4. |
| El documento es de otro workspace | La API key determina el workspace. Si tenés varios, probá con la key correspondiente. |

### `409 workspace_not_provisioned`

```json
{ "error": "workspace_not_provisioned" }
```

El workspace todavía no tiene DID ni claves de firma. Es lo normal en los primeros
minutos después de crear un workspace: el aprovisionamiento contra SovraChain tarda.

Lo ves en **Settings → Workspace**: cuando aparece el DID `did:sovra:0x…`, está listo.
Si pasaron más de unos minutos, contactá al equipo de Sovra.

### `422 wrong_schema_kind`

```json
{ "error": "wrong_schema_kind" }
```

El `schema_id` que mandaste corresponde a un esquema de kind **`credential`** (para
emitir a una wallet), no de kind **`document`**.

Los dos kinds **no son intercambiables**: una credencial se emite por OID4VCI, tiene
holder y divulgación selectiva; un documento se firma y se entrega. El error también
ocurre al revés, desde `POST /api/v1/issuer/credential-offer` con un esquema de
documentos.

En el dashboard, el kind se elige en el primer paso del asistente (**Type:
Credential / Document**) y **no se puede cambiar después**: si el esquema quedó del
kind equivocado, creá uno nuevo.

### `422 missing_claims`

```json
{ "error": "missing_claims", "details": ["content", "idDocument"] }
```

`details` lista exactamente qué claims requeridos faltaron. Tres cosas que lo causan:

- **Una errata en el `key`.** El objeto `claims` se matchea por `key`, letra por
  letra. `id_document` no es `idDocument`.
- **Un valor `null` o `""`.** Presente pero vacío puede no contar como presente.
- **El esquema cambió de versión.** La emisión usa siempre la versión más reciente del
  `schema_id`, y una versión nueva puede haber agregado un campo requerido.

Los `key` y los `required` vigentes están en `schema.claims[]` de cualquier respuesta,
y en el listado de esquemas del dashboard.

### `422 invalid_visibility`

```json
{ "error": "invalid_visibility" }
```

`visibility` solo acepta `public` o `private`. Los sospechosos habituales:

- `"Public"` con mayúscula, `"PUBLIC"`, `"publico"`.
- `true` / `false` en vez del string.
- En el `PUT`, un path como `…/visibility` sin el último segmento, o
  `…/visibility?value=public` (el valor va **en el path**, no en un query param ni en
  un body).

### `503 chain_not_configured`

```json
{ "error": "chain_not_configured" }
```

No hay registro de firmas configurado en el entorno, así que el servicio **se niega a
firmar**.

**Es intencional, y es la respuesta correcta:** prefiere fallar antes que emitir algo
que no se puede anclar, porque un documento sin anclaje no es verificable y un
verificador lo rechazaría (`NOT_ANCHORED`). No lo reintentes en loop — es una
condición del entorno, no un fallo transitorio. Contactá al equipo de Sovra.

## Síntomas que no son errores de la API

### El request se corta antes de responder

**La emisión puede tardar hasta ~90 segundos.** Si tu cliente HTTP tiene el timeout en
el default (30 s en muchas librerías, 10 s en algunos gateways), la conexión se corta
mientras Sovra todavía está anclando.

| Dónde mirar | Qué poner |
|---|---|
| El cliente HTTP | 120 s (`--max-time 120` en curl) |
| Un reverse proxy / load balancer delante tuyo | 120 s de read timeout |
| Un API Gateway | Ojo: algunos tienen un techo duro de 29–30 s |
| Un handler serverless | El timeout de la función, también en 120 s |

> ⚠️ **Un timeout no significa que el documento no se emitió.** La firma y el anclaje
> pueden haber terminado igual. Antes de reintentar, buscá en
> `GET /api/v1/issuer/documents` — si está ahí, reemitir crea un duplicado.

### La hoja se dibuja vacía

El JSON llegó bien pero los campos aparecen en blanco: **`schema.claims[]` trae solo
definiciones, no valores.** Los valores están dentro del `credential`. Ver
[5. Renderizado](05-renderizado.md#las-tres-cosas-que-el-json-no-trae).

### El navegador bloquea la llamada (CORS)

Estás llamando a la **zona emisor** desde el navegador. El preflight
`OPTIONS /api/v1/issuer/documents` responde `204` con `Access-Control-Allow-Headers` y
`-Methods`, pero **sin `Access-Control-Allow-Origin`**.

No es configurable de tu lado, y no debería serlo: la API key no puede vivir en el
navegador. Necesitás un proxy en tu servidor, con una whitelist explícita de rutas —
ver [5. Renderizado](05-renderizado.md#boilerplate-de-referencia).

La **zona pública** sí responde `Access-Control-Allow-Origin: *` y se puede llamar
directo.

### El `digest` que calculo no coincide

Casi siempre es **sobre qué bytes** se calculó. Tiene que ser el *signing input*:

```
header_b64 . payload_b64        ← sí
header_b64 . payload_b64 . firma ← no
header_b64 . payload_b64 . firma ~ ← no
```

Ver [5. Renderizado](05-renderizado.md#calcular-el-digest). Compará en minúsculas e
ignorando un eventual prefijo `0x`.

### El QR no se genera

El `credential` supera el techo del encoder (~2953 bytes en corrección nivel L). Pasa
con esquemas de muchos claims o con valores largos. Ver
[5. Renderizado](05-renderizado.md#por-qué-el-qr-lleva-el-credential-y-no-un-link).

### El documento verifica pero el verificador lo rechaza

Revisá el `status`. **Solo `anchored` es un estado entregable:**

| `status` | Qué pasa al verificar |
|---|---|
| `signed` | El anclaje no confirmó todavía → `NOT_ANCHORED`. |
| `failed` | El anclaje **no confirmó**. La firma es válida, pero no está en la cadena → `NOT_ANCHORED`. **Reemitilo.** |
| `revoked` | Rechazado → `CREDENTIAL_REVOKED`. |

Y si el error es `ISSUER_NOT_TRUSTED`: la firma valida, pero el emisor no está
autorizado en el Issuer Registry para ese `vct`. Es una cuestión de aprovisionamiento
del workspace, no del documento — ver [6. Verificación](06-verificacion.md#los-cinco-checks-contra-la-cadena).

### Volví el documento a privado y sigue circulando

Correcto y esperado. **`visibility` restringe los endpoints, no el artefacto.** Un
`credential` ya entregado sigue siendo legible y verificable por quien lo tenga, para
siempre. Para invalidar el documento existe la revocación. Ver
[4. Visibilidad y entrega](04-visibilidad-y-entrega.md#qué-restringe-la-visibilidad-y-qué-no).

## Checklist de diagnóstico

Cuando algo falla y no está claro dónde:

- [ ] ¿El header dice `Bearer` con **B** mayúscula?
- [ ] ¿La API key es del entorno al que estás llamando?
- [ ] ¿El workspace muestra un DID en **Settings → Workspace**?
- [ ] ¿El esquema es de kind **`document`**?
- [ ] ¿El `schema_id` es el **slug legible**, no el UUID interno?
- [ ] ¿Los `key` del objeto `claims` matchean letra por letra los del esquema?
- [ ] ¿El timeout del cliente está en **120 s**?
- [ ] Si dio timeout: ¿buscaste el documento en `GET /api/v1/issuer/documents` antes de reintentar?
- [ ] ¿El `status` del documento es `anchored`?
- [ ] Si es un `401` en la zona pública: ¿el documento está `public`?
- [ ] Si la hoja sale vacía: ¿los valores los estás leyendo del `credential`?

---

**Anterior:** [← 7. Referencia de la API](07-referencia-api.md) · **Volver al [índice](README.md)**
