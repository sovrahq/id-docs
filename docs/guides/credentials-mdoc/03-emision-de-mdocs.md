# 3. Emisión de mDocs

El procedimiento consta de tres pasos: crear la oferta, mostrar el QR y recibir
el webhook.

El endpoint es **el mismo que para una credencial SD-JWT**. El formato lo
determina el esquema, no la llamada: si el `schema_id` designa un esquema
`mso_mdoc`, lo que se emite es un mDoc.

## Tabla de contenidos

1. [Paso 1 — Crear la oferta](#paso-1--crear-la-oferta)
2. [Codificación de cada claim](#codificación-de-cada-claim)
3. [La respuesta, campo por campo](#la-respuesta-campo-por-campo)
4. [Paso 2 — Mostrar el QR](#paso-2--mostrar-el-qr)
5. [Paso 3 — Recibir `credential.issued`](#paso-3--recibir-credentialissued)
6. [Vigencia](#vigencia)
7. [Ciclo de vida y revocación](#ciclo-de-vida-y-revocación)
8. [Errores al crear la oferta](#errores-al-crear-la-oferta)
9. [Ejemplo completo en Node.js](#ejemplo-completo-en-nodejs)

---

## Paso 1 — Crear la oferta

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
      "driving_privileges": [
        {
          "vehicle_category_code": "B",
          "issue_date": "2026-01-15",
          "expiry_date": "2036-01-15"
        }
      ],
      "age_over_18": true
    }
  }'
```

| Parámetro | Obligatorio | Descripción |
|---|---|---|
| `schema_id` | ✅ | El **slug** del esquema, no su UUID. Se emplea siempre su última versión. |
| `claims` | ❌ | Los valores, **en estructura plana e indexados por `key`**. Sovra los ubica en el namespace del esquema. |

> **Los claims se envían en estructura plana, no anidados por namespace.** Debe
> enviarse `{"family_name": "Lovelace"}`, no
> `{"org.iso.18013.5.1": {"family_name": "Lovelace"}}`. El namespace se obtiene
> del esquema; la API no lo solicita ni lo admite.

Todos los claims marcados `required` en el esquema deben estar presentes. Ante la
ausencia de alguno, la respuesta es `422 missing_claims` con la lista
correspondiente.

## Codificación de cada claim

Es en este punto donde un mDoc se distingue de un SD-JWT. CBOR resulta **más
estricto en un aspecto y más expresivo en otro**: las fechas incorporan etiqueta
en lugar de representarse como texto por convención, y los valores de coma
flotante carecen por completo de codificación determinista.

| Tipo del esquema | Valor en el JSON | Ejemplo | Representación en la credencial |
|---|---|---|---|
| `string` / `uri` | Texto | `"Lovelace"` | Texto |
| `integer` | Un entero | `170` | Entero |
| `number` | Un número **entero** | `4` o `4.0` | Entero |
| `boolean` | `true` / `false` | `true` | Booleano |
| `date` | `"YYYY-MM-DD"`, 10 caracteres | `"1815-12-10"` | `full-date` etiquetada |
| `datetime` | ISO 8601 con offset | `"2026-01-15T10:30:00Z"` | `tdate` etiquetada |
| `bytes` | **base64 estándar con padding** | `"iVBORw0KGgo…"` | Byte string con los bytes decodificados |
| `driving_privileges` | Array de categorías | véase más adelante | Array de mapas, con las fechas etiquetadas en su interior |

Las reglas de mayor incidencia práctica:

**`number` no admite valores decimales.** `4.5` se rechaza. Un valor de coma
flotante carece de forma CBOR determinista, y asignarle una implicaría que un
verificador pudiera calcular sobre la misma credencial un hash distinto del
empleado en la firma. La comprobación se realiza **al crear la oferta**, no en la
entrega, de modo que el error constituya un `422` sobre la solicitud que lo
introdujo y no un `500` en mitad del flujo de la wallet, mucho después de que el
operador haya finalizado su intervención.

**`bytes` se expresa en base64 estándar, con padding, y exclusivamente así.** Sin
alfabeto URL-safe y sin espacios en blanco. Se trata de un cuerpo de solicitud
HTTP, no de una URL: admitir cuatro grafías del mismo valor es el origen de
discrepancias entre emisores respecto de lo efectivamente enviado.

**Un tipo conocido con un valor de forma incorrecta constituye un error, no una
conversión.** Admitir una cadena para un claim `integer` produciría una
credencial que verifica correctamente y afirma algo falso.

Todos los valores no codificables se notifican de forma conjunta como
`422 unencodable_claims`, con la lista de claves afectadas.

### `driving_privileges`

Las categorías de vehículo que habilita la licencia. En el caso corriente basta
con la categoría y sus fechas:

```json
[
  {
    "vehicle_category_code": "B",
    "issue_date": "2020-03-15",
    "expiry_date": "2030-03-15"
  },
  { "vehicle_category_code": "A" }
]
```

- `vehicle_category_code` es el único campo obligatorio de cada entrada. Es la
  categoría: `B` para automóvil, `A` para motocicleta, `C` para camión.
- `issue_date` y `expiry_date` son opcionales, en formato `YYYY-MM-DD`. Una
  categoría puede haberse obtenido en una fecha distinta de la del documento.
- **Un array vacío se rechaza**: ISO exige al menos un privilegio, y un mDL que no
  declara ninguno constituye una licencia sin habilitación alguna.

#### `codes`: las restricciones de cada categoría

Campo **opcional**. Es el equivalente digital de la columna de observaciones que
lleva impresa una licencia de plástico:

```
CATEGORÍAS                      RESTRICCIONES
B   15/01/2026 – 15/01/2036     01   Usa lentes correctivos
                                05   Velocidad máxima 70 km/h
```

Mientras `vehicle_category_code` indica *qué* puede conducir el titular, `codes`
indica *bajo qué condiciones*.

Esa misma licencia, en JSON:

```json
"codes": [
  { "code": "01" },
  { "code": "05", "sign": "<=", "value": "70" }
]
```

Una entrada por renglón impreso, en el mismo orden:

| Lo que se envía | Renglón que representa |
|---|---|
| `{ "code": "01" }` | `01  Usa lentes correctivos` |
| `{ "code": "05", "sign": "<=", "value": "70" }` | `05  Velocidad máxima 70 km/h` |

### Por qué la primera tiene un campo y la segunda tres

Porque existen dos clases de restricción.

**Las que son de sí o no.** «Usa lentes correctivos» no admite grados: o la
persona la tiene o no la tiene. Basta con nombrarla, y por eso la primera entrada
lleva únicamente `code`.

**Las que llevan una cantidad.** «Velocidad máxima 70» necesita el número. En
lugar de definir un código distinto para cada velocidad posible —uno para 70,
otro para 80, otro para 90—, ISO separa el *qué* del *cuánto*:

```
{ "code": "05",   "sign": "<=",   "value": "70" }
         │                │                │
         │                │                └── cuánto ............ 70
         │                └─────────────────── en qué sentido .... como máximo
         └──────────────────────────────────── qué ............... la restricción
                                                                   que la autoridad
                                                                   haya definido
                                                                   como 05
```

Leída de corrido: **«restricción 05, como máximo 70»**.

| Campo | Responde a | Obligatorio | Ejemplos |
|---|---|---|---|
| `code` | ¿Qué está restringido? | ✅ | `"01"`, `"05"` |
| `sign` | ¿En qué sentido? | ❌ | `"<="`, `">="`, `"="` |
| `value` | ¿Cuánto? | ❌ | `"70"`, `"3500"` |

### Tres detalles que no se deducen del ejemplo

**La unidad no viaja en los datos.** La credencial dice `05`, `<=`, `70`, y en
ningún lugar dice «km/h». La unidad forma parte de lo que significa el código
`05` en el catálogo de la autoridad, y es el lector quien la conoce al resolver
ese código. Por eso `value` no es un número con dimensión, sino la cifra a secas.

**`sign` y `value` van juntos.** La API los acepta por separado, pero una
restricción con signo y sin cifra —o al revés— produce una entrada que ningún
lector puede interpretar. O se envían los dos, o ninguno.

**Los tres campos son texto, también `sign` y `value`.** ISO los define así
—`sign` es un signo, no una operación, y `value` una cifra escrita—, de modo que
`"value": 70`, como número, se rechaza con `unencodable_claims`. El codificador
no lo convierte por su cuenta, por la misma razón que no convierte en ningún otro
punto del formato: una conversión silenciosa produce una credencial que verifica
correctamente y afirma algo distinto de lo que el emisor quiso decir.

### Quién define los códigos

**ISO no fija el catálogo de valores de `code`.** Lo establece la autoridad
emisora: en la Unión Europea son los códigos armonizados del Anexo I de la
Directiva 2006/126/CE; en otras jurisdicciones, el reglamento que corresponda.

> ⚠️ Los valores `01` y `05` de esta sección son **ilustrativos**. Sirven para
> mostrar la estructura, no para copiarlos: hay que emplear los del catálogo
> propio.

> **Si no se dispone de un catálogo de restricciones, `codes` se omite.** Es un
> campo opcional, y los ejemplos del resto de estas guías no lo incluyen
> precisamente por ese motivo.

## La respuesta, campo por campo

```json
{
  "credential_id": "3f2a1c88-0f3e-4b1a-9b7e-7c5c9a2e4d11",
  "offer_uri": "openid-credential-offer://?credential_offer_uri=https%3A%2F%2F…",
  "pre_authorized_code": "8f7d…",
  "expires_at": "2026-09-29T12:58:34Z",
  "credential": { "…": "vista previa sin firmar" }
}
```

| Campo | Descripción |
|---|---|
| `credential_id` | Identificador de todo el ciclo de vida. Figura en el webhook y es admitido por `GET /credentials/:id` y `PUT /credentials/:id/status/:status`. |
| `offer_uri` | El valor que debe renderizarse como QR. |
| `pre_authorized_code` | El código que la wallet canjea por un token. No requiere manipulación. |
| `expires_at` | La oferta tiene una vigencia de **24 horas**. |
| `credential` | Vista previa **sin firmar**: sin llave del holder y sin MSO. Permite mostrar en pantalla el contenido que se emitirá. |

> ⚠️ **El mDoc firmado no figura en esta respuesta.** Se compone en el momento en
> que la wallet canjea la oferta, dado que hasta ese instante no existe la
> `deviceKey` del holder a la que vincularlo. Se entrega en el webhook
> `credential.issued`.

## Paso 2 — Mostrar el QR

El valor `offer_uri` se renderiza como código QR. El ciudadano lo escanea con la
wallet de Sovra, que ejecuta el flujo OID4VCI: descubre los metadatos del emisor,
canjea el `pre_authorized_code` por un token y solicita la credencial enviando una
**prueba de posesión con una llave COSE**.

> Un mDoc se vincula a la llave en sí (`MSO.deviceKeyInfo.deviceKey`), no a una
> referencia `cnf` a un DID como en el caso del SD-JWT. **Sin llave del holder la
> emisión no es posible**: no existe elemento al que vincular la credencial.

## Paso 3 — Recibir `credential.issued`

El payload corresponde al mismo evento empleado para SD-JWT, con dos diferencias:

```json
{
  "event": "credential.issued",
  "data": {
    "credential_id": "3f2a1c88-0f3e-4b1a-9b7e-7c5c9a2e4d11",
    "holder_did": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
    "schema_type": "DrivingLicence",
    "issued_at": "2026-09-28T13:02:11Z",
    "expires_at": "2036-09-28T13:02:11Z",
    "format": "mso_mdoc",
    "doc_type": "org.iso.18013.5.1.mDL",
    "credential": "omppc3N1ZXJBdXRohEShAS…"
  }
}
```

| Campo | Observaciones |
|---|---|
| `format` | **`"mso_mdoc"`**. Constituye el campo idóneo para la bifurcación lógica. |
| `doc_type` | **Presente únicamente en mDoc.** Se trata de un campo aditivo: un payload SD-JWT no obtendría beneficio alguno de una clave con valor nulo. |
| `credential` | El `IssuerSigned` en **CBOR, base64url sin padding**. No es un JWT y no contiene puntos ni caracteres `~`. |

El resto del catálogo de eventos, las cabeceras y la validación de la firma HMAC
son idénticos a los de las credenciales: [Webhooks](../credentials/05-webhooks.md).

> ⚠️ **Constituye la única entrega.** Sovra no conserva el mDoc firmado y no
> existe endpoint para solicitarlo nuevamente. Si el sistema receptor lo
> requiere, debe persistirse en el momento de recepción del webhook.

Para decodificarlo e inspeccionarlo:

```bash
# El base64url sin padding se convierte a base64 estándar antes de decodificar.
node -e '
  const b64u = process.argv[1];
  const b64 = b64u.replace(/-/g, "+").replace(/_/g, "/");
  process.stdout.write(Buffer.from(b64, "base64"));
' "$CREDENTIAL" > mdoc.cbor
```

## Vigencia

ISO establece `validityInfo.validUntil` como **obligatorio**, por lo que un mDoc
carece de mecanismo para expresar la ausencia de vencimiento, que es precisamente
lo que declara un esquema sin `validity_period`.

| Esquema | SD-JWT | mDoc |
|---|---|---|
| Con `validity_period` | `exp` = emisión + período | `validUntil` = emisión + período |
| Sin `validity_period` | Sin `exp` | **`validUntil` = emisión + 1 año** |

Si la fecha de vencimiento resulta relevante, debe declararse el período en el
esquema.

## Ciclo de vida y revocación

Idénticos a los de una credencial SD-JWT: mismos estados, mismas transiciones y
mismos endpoints.

```bash
# Revocar — permanente, sin reversión posible.
curl -X PUT "$BASE_URL/api/v1/issuer/credentials/$CREDENTIAL_ID/status/revoked" \
  -H "Authorization: Bearer $SOVRA_API_KEY"

# Suspender — reversible.
curl -X PUT "$BASE_URL/api/v1/issuer/credentials/$CREDENTIAL_ID/status/suspended" \
  -H "Authorization: Bearer $SOVRA_API_KEY"

# Reactivar una credencial suspendida.
curl -X PUT "$BASE_URL/api/v1/issuer/credentials/$CREDENTIAL_ID/status/issued" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

> ⚠️ **El valor de reactivación es `issued`, no `active`.** El segmento indica el
> estado de destino, que es aquel en el que la credencial quedó al emitirse.
> Cualquier otro valor devuelve `400 unknown_status`, y aplicar `issued` sobre una
> credencial revocada devuelve `422 invalid_status_transition`.

La diferencia reside en **la ubicación del índice**: un mDoc carece de campo
`credentialStatus`, de modo que el `status_list_index` se transporta dentro del
namespace `io.sovra.meta.1`, firmado por el emisor. Un único índice sirve a ambos
propósitos —revocación y suspensión—, exactamente igual que el array
`credentialStatus` del SD-JWT.

El detalle completo figura en
[Ciclo de vida y revocación](../credentials/06-ciclo-de-vida-y-revocacion.md).

## Errores al crear la oferta

| HTTP | `error` | Causa |
|---|---|---|
| 401 | `invalid_api_key` | API key ausente, mal formada o revocada. |
| 404 | `schema_not_found` | El `schema_id` no existe en este workspace. Debe recordarse que se trata del slug. |
| 404 | `workspace_not_found` | La API key no resuelve a un workspace. |
| 409 | `workspace_not_provisioned` | El aprovisionamiento en cadena aún no ha concluido. |
| 422 | `missing_claims` | Faltan claims `required`. El campo `details` contiene la lista. |
| 422 | `unencodable_claims` | Un valor no admite codificación como CBOR de ISO 18013-5. El campo `details` identifica las claves. |
| 422 | `wrong_schema_kind` | El esquema es de kind `document`. Un mDoc es siempre `credential`. |
| 422 | `document_signer_required` | El esquema declara un docType ISO y el workspace carece de document signer registrado. Véase la [guía 8](08-habilitar-mdl-iso.md). |

El detalle de cada uno figura en
[Errores y troubleshooting](06-errores-y-troubleshooting.md).

## Ejemplo completo en Node.js

```js
const BASE_URL = process.env.BASE_URL;
const API_KEY = process.env.SOVRA_API_KEY;

/** Un PNG o JPEG en base64 estándar con padding, según exige el tipo `bytes`. */
function portraitFrom(buffer) {
  return buffer.toString("base64");
}

async function emitirLicencia(persona, fotoBuffer) {
  const res = await fetch(`${BASE_URL}/api/v1/issuer/credential-offer`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${API_KEY}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      schema_id: "licencia_de_conducir",
      claims: {
        family_name: persona.apellido,
        given_name: persona.nombre,
        birth_date: persona.nacimiento,          // "1815-12-10"
        issue_date: persona.emision,
        expiry_date: persona.vencimiento,
        issuing_country: "MX",
        issuing_authority: "Nuevo León",
        document_number: persona.numero,
        un_distinguishing_sign: "MEX",
        portrait: portraitFrom(fotoBuffer),
        driving_privileges: persona.categorias.map((c) => ({
          vehicle_category_code: c,
          issue_date: persona.emision,
          expiry_date: persona.vencimiento,
        })),
        age_over_18: persona.mayorDeEdad,
      },
    }),
  });

  const body = await res.json();

  if (!res.ok) {
    // `details` identifica con precisión el claim rechazado y su motivo.
    throw new Error(`${body.error}: ${JSON.stringify(body.details ?? {})}`);
  }

  return body; // { credential_id, offer_uri, expires_at, ... }
}
```

Y el receptor del webhook, con bifurcación por formato:

```js
app.post("/webhooks/sovra", verificarFirmaHmac, (req, res) => {
  const { event, data } = req.body;

  if (event === "credential.issued") {
    if (data.format === "mso_mdoc") {
      // `credential` es CBOR en base64url sin padding, y `doc_type` figura
      // únicamente en mDoc. Constituye la única entrega: debe persistirse aquí.
      guardarMdoc(data.credential_id, data.doc_type, data.credential);
    } else {
      guardarSdJwt(data.credential_id, data.credential);
    }
  }

  res.sendStatus(200);
});
```

---

**Anterior:** [← 2. Primeros pasos](02-primeros-pasos.md) · **Siguiente:** [4. Verificación de mDocs →](04-verificacion-de-mdocs.md)
