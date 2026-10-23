# 5. Verificación

Un claim mDoc se nombra con dos segmentos, `[namespace, identificador]`. Aquí el namespace es el propio, y el docType es otra cadena distinta.

## Tabla de contenidos

1. [Verificación](#verificacion)
2. [El QR: usar siempre `_ref`](#el-qr-usar-siempre-_ref)
3. [Las tres cadenas que deben coincidir](#las-tres-cadenas-que-deben-coincidir)
4. [Qué solicitar en cada escenario](#que-solicitar-en-cada-escenario)
5. [El resultado](#el-resultado)
6. [Cómo retorna cada tipo](#como-retorna-cada-tipo)
7. [Tres fallos que parecen el mismo](#tres-fallos-que-parecen-el-mismo)

---

## Verificación

Un claim mDoc se nombra con dos segmentos, `[namespace, identificador]`, y aquí el
namespace es el propio. El `doctype_value` lleva el **docType**, que es una cadena
distinta.

```bash
curl -X POST "$BASE_URL/api/v1/verifier/verifications" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "dcql_query": {
      "credentials": [{
        "id": "licencia",
        "format": "mso_mdoc",
        "meta": { "doctype_value": "io.sovra.0x5de0df12cce712ddaaa23feb359920ae4f3230d4.driving_licence_v2.1" },
        "claims": [
          {"path": ["mx.gob.nl.icv.1", "given_name"]},
          {"path": ["mx.gob.nl.icv.1", "family_name"]},
          {"path": ["mx.gob.nl.icv.1", "second_family_name"]},
          {"path": ["mx.gob.nl.icv.1", "document_number"]},
          {"path": ["mx.gob.nl.icv.1", "expiry_date"]},
          {"path": ["mx.gob.nl.icv.1", "driving_privileges"]},
          {"path": ["mx.gob.nl.icv.1", "portrait"]}
        ]
      }]
    }
  }'
```

Devuelve `session_id`, `authorization_request_uri_ref` —**esta** es la que se renderiza
como QR— y `expires_at`. La sesión tiene una vigencia de **10 minutos**.

### El QR: usar siempre `_ref`

La respuesta trae dos URIs, y la diferencia importa en la práctica:

| Campo | Qué lleva | Longitud |
|---|---|---|
| `authorization_request_uri_ref` | Un **enlace** a la solicitud firmada | ~250 caracteres, constante |
| `authorization_request_uri` | La solicitud firmada **entera**, incrustada | 2000–3000 caracteres con un DCQL completo |

La segunda crece con la consulta, porque transporta la cabecera, el payload con todo el
DCQL y la firma, en base64. Con siete claims no entra en un QR cómodo: el máximo absoluto
de un código en modo byte son **2953 caracteres**, y eso exige versión 40 con corrección
de errores baja.

> ⚠️ **Un generador de QR con límite de caracteres trunca la URI en silencio.** Varias
> herramientas en línea cortan a 1000 caracteres sin avisar más que con una advertencia
> al pie. La wallet recibe una URI mutilada, no encuentra `nonce` ni `dcql_query`, y
> responde `Missing required OpenID4VP parameters` — un error que parece de la wallet y
> en realidad es del generador.

Generado en local no hay límite que valga:

```bash
curl -s -X POST "$BASE_URL/api/v1/verifier/verifications" \
  -H "Authorization: Bearer $SOVRA_API_KEY" -H "Content-Type: application/json" \
  -d @query.json > resp.json

jq -r '.authorization_request_uri_ref' resp.json | qrencode -o qr.png -s 8
```

Y para la primera prueba conviene **pedir pocos claims**: menos elementos en la consulta
es un QR menos denso y una pantalla de consentimiento más corta. Un solo claim alcanza
para comprobar que el circuito entero funciona:

```json
"claims": [{"path": ["mx.gob.nl.icv.1", "age_over_18"]}]
```

La URI por valor queda como recurso para una wallet que no implemente pass-by-reference.
En ese caso, reducir la consulta no es una comodidad sino un requisito.

### Las tres cadenas que deben coincidir

| Dónde | Qué va | Si se equivoca |
|---|---|---|
| `meta.doctype_value` | El **docType**: `io.sovra.0x5de0df12cce712ddaaa23feb359920ae4f3230d4.driving_licence_v2.1` | `doctype_mismatch` |
| `claims[].path[0]` | El **namespace**: `mx.gob.nl.icv.1` | `missing_required_claim:<ns>.<elemento>` |
| Clave del resultado | `"<namespace>.<elemento>"`, nunca el elemento desnudo | La integración lee `undefined` |

Es donde más integraciones fallan: `doctype_value` transporta el docType y el `path`
transporta el namespace, y en este diseño **son cadenas distintas**. Deben leerse del
esquema (`GET /api/v1/issuer/schemas`), no deducirse: la regla «namespace = docType»
rige únicamente cuando el esquema **no** declara namespace propio.

### Qué solicitar en cada escenario

El desglose de los atributos es precisamente lo que habilita estas consultas. Con el
domicilio en una sola cadena, solicitar el código postal entregaba también la calle, el
número y el interior.

**Control de tránsito** — la licencia completa: las siete rutas del ejemplo anterior.

**Verificación de edad** — sin fecha de nacimiento:

```json
"claims": [{"path": ["mx.gob.nl.icv.1", "age_over_18"]}]
```

**Urgencias médicas** — lo que una licencia de plástico jamás pudo entregar de forma
selectiva:

```json
"claims": [
  {"path": ["mx.gob.nl.icv.1", "blood_type"]},
  {"path": ["mx.gob.nl.icv.1", "allergies"]},
  {"path": ["mx.gob.nl.icv.1", "organ_donor"]},
  {"path": ["mx.gob.nl.icv.1", "emergency_phone"]}
]
```

**Comprobante de domicilio** — sin fotografía, folio ni fecha de nacimiento:

```json
"claims": [
  {"path": ["mx.gob.nl.icv.1", "street"]},
  {"path": ["mx.gob.nl.icv.1", "exterior_number"]},
  {"path": ["mx.gob.nl.icv.1", "interior_number"]},
  {"path": ["mx.gob.nl.icv.1", "neighborhood"]},
  {"path": ["mx.gob.nl.icv.1", "resident_city"]},
  {"path": ["mx.gob.nl.icv.1", "resident_postal_code"]}
]
```

**Zona de cobertura** — calle y código postal, nada más:

```json
"claims": [
  {"path": ["mx.gob.nl.icv.1", "street"]},
  {"path": ["mx.gob.nl.icv.1", "resident_postal_code"]}
]
```

**Trámite que requiere CURP**:

```json
"claims": [
  {"path": ["mx.gob.nl.icv.1", "curp"]},
  {"path": ["mx.gob.nl.icv.1", "given_name"]},
  {"path": ["mx.gob.nl.icv.1", "family_name"]},
  {"path": ["mx.gob.nl.icv.1", "second_family_name"]}
]
```

> `family_name` y `second_family_name` son always shared, de modo que el materno viaja
> aunque no se solicite. Lo que el desglose modifica es que llegan como **dos claves
> separadas**, en lugar de una cadena que nadie puede partir.

> ⚠️ **Omitir `claims` no solicita la credencial completa.** La cartera divulga
> únicamente los elementos no retenibles y el resultado llega con `"claims": {}`.
> Sirve para «acredíteme que posee una credencial de este docType», no para obtener
> datos.

## El resultado

Webhook `presentation.verified`, o `GET /api/v1/verifier/verifications/:id`:

```json
{
  "event": "presentation.verified",
  "data": {
    "verification_id": "e2d16450-42e8-46a9-ae92-d72af51a7435",
    "holder_did": "did:sovra:0x9083…f274",
    "success": true,
    "completed_at": "2026-10-23T14:09:12Z",
    "credentials": [{
      "id": "licencia",
      "format": "mso_mdoc",
      "claims": {
        "mx.gob.nl.icv.1.given_name": "Sofía",
        "mx.gob.nl.icv.1.family_name": "Demo",
        "mx.gob.nl.icv.1.second_family_name": "Lambda",
        "mx.gob.nl.icv.1.document_number": "NL-2026-0001",
        "mx.gob.nl.icv.1.expiry_date": "2030-10-22",
        "mx.gob.nl.icv.1.driving_privileges": [
          {
            "vehicle_category_code": "B",
            "issue_date": "2026-10-22",
            "expiry_date": "2030-10-22",
            "codes": [{"code": "01", "sign": "<=", "value": "70"}]
          }
        ],
        "mx.gob.nl.icv.1.portrait": "_9j_4AAQSkZJRgABAQ..."
      }
    }]
  }
}
```

No viajaron la CURP, la calle, el número, la colonia, el tipo sanguíneo ni las
alergias: no se solicitaron, y son retenibles. Ahí reside la diferencia con la licencia
de plástico, que entrega todo cada vez que se muestra.

### Cómo retorna cada tipo

| Tipo | Se envía como | Retorna como |
|---|---|---|
| `string`, `uri` | `"Sofía"` | Igual |
| `integer`, `number` | `170` | `170` |
| `boolean` | `true` | `true` |
| `date` | `"2030-10-22"` | `"2030-10-22"` |
| `datetime` | `"2026-10-22T14:30:00Z"` | `"2026-10-22T14:30:00Z"` |
| `bytes` | base64 **estándar con padding** | base64url **sin padding** ⚠️ |
| `driving_privileges` | Array de objetos | Array de objetos; las fechas, `"YYYY-MM-DD"` |

Los tags de CBOR se desenvuelven: `full-date` y `tdate` llegan como cadenas ISO, no
como objetos.

## Tres fallos que parecen el mismo

Los tres se manifiestan como «no funciona la verificación» y ocurren en etapas
distintas. El mensaje dice en cuál.

| Mensaje | Etapa | Causa |
|---|---|---|
| `422 unencodable_claims: portrait, signature_usual_mark` | Emisión | Los `bytes` llevan el prefijo `data:image/jpeg;base64,`. Van **pelados** |
| `Missing required OpenID4VP parameters` | La wallet lee el QR | La URI llegó truncada. Casi siempre, un generador de QR con tope de caracteres |
| `DID … publishes no P-256 assertion key #key-assert` | La wallet resuelve el DID | La wallet consulta una cadena donde ese workspace no está registrado |

El orden importa: el segundo ocurre antes que el tercero, de modo que mientras el QR
llegue cortado nunca se sabrá si la cadena está bien.

### Comprobar en qué cadena vive el DID

Los dos primeros se arreglan del lado del emisor. El tercero no: depende de a qué
`EXPO_PUBLIC_DID_REGISTRY_ADDRESS` y `EXPO_PUBLIC_L2_RPC_URL` apunte el build de la
wallet, que son valores de compilación.

Antes de pedirle nada a nadie conviene comprobar dónde está registrado el DID. Es una
llamada de solo lectura, sin API key:

```bash
curl -s -X POST https://rpc.testnet.sovra.io \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"eth_call","id":1,"params":[{
        "to":"0xB9A2c855376ED79966e5f4956D3BfD21ef80d96E",
        "data":"0x55ea6c470000000000000000000000005de0df12cce712ddaaa23feb359920ae4f3230d4"
      },"latest"]}'
```

El `data` es `resolve(address)` seguido de la dirección del workspace rellenada a 32
bytes. En la respuesta:

- **Todo ceros** → el DID no está en esa cadena. Una wallet que resuelva ahí dará el
  error de la llave.
- **Aparece `9b37b628c726e77e17c0c89d093ef9a80df1cf7cdc4d658dd9a29953ff3105f8`** → la
  llave está publicada. Ese hash es `keccak256("key-assert")` y es constante, de modo
  que sirve de marcador visual.

| Entorno | RPC | DID Registry |
|---|---|---|
| Test | `https://rpc.testnet.sovra.io` | `0xB9A2c855376ED79966e5f4956D3BfD21ef80d96E` |
| Producción | `https://rpc.sovra.io` | `0x3829184dB00Ae8C7a6c72104A2Ede879F6F25e78` |

> Emitir funciona igual aunque esto falle, y es lo que más confunde: la emisión es HTTP
> contra la API, y no resuelve ningún DID. La verificación es el único flujo en que la
> wallet consulta la cadena.

---

**Anterior:** [← 4. Imágenes: foto y firma](04-imagenes.md)
