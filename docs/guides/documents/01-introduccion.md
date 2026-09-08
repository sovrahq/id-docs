# 1. Introducción

## Tabla de contenidos

1. [Qué es un documento firmado](#qué-es-un-documento-firmado)
2. [Documento o credencial: cuál usar](#documento-o-credencial-cuál-usar)
3. [Anatomía del `credential`](#anatomía-del-credential)
4. [Las dos capas de confianza](#las-dos-capas-de-confianza)
5. [El flujo completo, de punta a punta](#el-flujo-completo-de-punta-a-punta)
6. [Qué hace tu servidor y qué hace Sovra](#qué-hace-tu-servidor-y-qué-hace-sovra)
7. [Glosario](#glosario)

---

## Qué es un documento firmado

Un **documento firmado** es una credencial sin holder: el emisor la firma y la
entrega. No hay wallet, no hay OID4VCI, no hay flujo de aceptación por parte del
destinatario. Una sola llamada valida los datos, los firma y ancla la firma
on-chain.

La firma anclada es lo que cambia dónde vive la confianza: quien recibe el documento
lo verifica **contra la cadena**, no preguntándole al servicio que lo emitió. El
servicio puede caerse, cambiar de dominio o dejar de existir, y el documento sigue
siendo verificable.

En una frase: **el documento es un string autocontenido; la API es solo la forma de
producirlo y de repartirlo.** Ese string es el campo `credential` que aparece en casi
todas las respuestas de estas guías.

Sirve para certificados, constancias, actas, comprobantes y diplomas — cosas que se
comparten por enlace o en papel, no desde una wallet.

## Documento o credencial: cuál usar

Los dos formatos son SD-JWT VC firmados por el mismo emisor y anclados en la misma
cadena. Lo que cambia es quién custodia el artefacto y cómo se presenta.

| | Documento firmado (estas guías) | Credencial verificable ([`../credentials/`](../credentials/)) |
|---|---|---|
| **Kind del esquema** | `document` | `credential` |
| **Quién lo guarda** | Quien lo recibe: un archivo, un enlace, un papel | La wallet del ciudadano |
| **Hay holder** | No. No hay claim `cnf`, no hay llave del destinatario | Sí, atado a la passkey del ciudadano |
| **Divulgación selectiva** | No. Todos los claims van en el payload, a la vista | Sí. El ciudadano elige campo por campo |
| **Protocolo de emisión** | Una llamada HTTP. Sin QR de oferta | OID4VCI (`openid-credential-offer://`) |
| **Protocolo de presentación** | Ninguno: se manda el string o el enlace | OID4VP + DCQL |
| **Cómo se verifica** | Contra la cadena, por quien lo tenga | Sesión de verificación, o contra la cadena |
| **Webhooks** | No hay eventos de documentos | `credential.issued`, `presentation.verified`, … |
| **Se puede reimprimir** | Sí: `GET /api/v1/issuer/documents` devuelve el `credential` completo | No. El SD-JWT firmado se entrega una sola vez |

La regla práctica: **si el dato es sobre una persona y ella decide cuándo y cuánto
mostrarlo, es una credencial. Si es una hoja que la institución emite y reparte, es
un documento.**

> ⚠️ Los dos kinds no son intercambiables. Emitir un documento con un esquema de kind
> `credential` responde `422 wrong_schema_kind`, y al revés también.

## Anatomía del `credential`

El `credential` es un **SD-JWT VC**: `header.payload.firma~`, cada parte en
base64url, separadas por puntos.

```
eyJhbGciOiJFUzI1NiIsInR5cCI6InZjK3NkLWp3dCJ9 . eyJjb250ZW50Ijoi… . FEArm9ydMri… ~
└──────────────── header ────────────────────┘ └─── payload ───┘ └── firma ──┘  │
                                                                                └─ lista de
                                                                                   disclosures
                                                                                   vacía
```

Esa `~` final sola, sin nada después, **no es un error ni sobra**: un documento
firmado no tiene holder, así que no hay nada selectivamente divulgable que negociar y
todos los claims van directo en el payload.

**Header** — cómo está firmado:

```json
{ "alg": "ES256", "typ": "vc+sd-jwt" }
```

**Payload** — el documento en sí, legible tal cual. Los claims del esquema van al
mismo nivel que los metadatos estándar del JWT:

```json
{
  "content": "Contenido de Prueba",
  "date": "20-08-2026",
  "idDocument": 1234567,
  "isConfidential": false,
  "iss": "https://test-api-sovra.flagonsa.com/did:sovra:0xb516d2f945db504198d726bda2a01d19ecd3cc23",
  "jti": "63813a91-6986-46bf-9f23-128a6598cd82",
  "vct": "Document",
  "iat": 1787321554,
  "exp": 1818857554
}
```

| Claim | Qué es |
|---|---|
| *(los del esquema)* | El contenido del documento, con las `key` que declaró el esquema. |
| `iss` | El emisor: una URL HTTPS cuyo *path* es el DID del workspace. |
| `jti` | El id del documento — el mismo `id` que devuelve la API. Una credencial no puede servirse bajo otro id. |
| `vct` | El tipo de credencial: el `credential_type` del esquema. Es contra este valor que se comprueba on-chain si el emisor está autorizado a emitirlo. |
| `iat` | Cuándo fue emitido (epoch en segundos). |
| `exp` | Cuándo expira (epoch). Sale del *validity period* del esquema. |

En el ejemplo, `iat = 2026-08-21T00:12:34Z` y `exp = 2027-08-21T00:12:34Z`: un año de
validez.

**Firma** — 64 bytes, el `r‖s` de ES256:

```
c66635fb6befeb82a4b93ea8954629cf320026dc340ade0c5ae5e4f7454a17cc
0ac9c733fd365803bebdaefcc346ad010411dec38b746ed48086807e620c0707
```

Ese es exactamente el valor que se compara contra lo anclado en la cadena.

### No está cifrado — está codificado

Conviene decirlo explícito porque suele sorprender: **cualquiera puede leer el
contenido de un `credential`.** Base64url no es cifrado.

```python
import base64, json

jwt = credential.rstrip('~')
header_b64, payload_b64, sig_b64 = jwt.split('.')

def b64url_decode(s):
    return base64.urlsafe_b64decode(s + '=' * (-len(s) % 4))

header  = json.loads(b64url_decode(header_b64))
payload = json.loads(b64url_decode(payload_b64))
```

O pegando el string en `jwt.io`, quitando la `~` final.

De ahí salen tres consecuencias prácticas:

1. **Leer ≠ verificar.** Decodificar muestra el contenido y no dice absolutamente
   nada sobre autenticidad. Se puede fabricar un payload idéntico con otros datos; lo
   que no se puede fabricar es una firma válida. Decodificar sirve para **mostrar**,
   nunca para **decidir**.
2. **Acá no hay secretos.** El contenido es legible por cualquiera que tenga el
   string. Nunca pongas datos sensibles asumiendo que van "ocultos": la
   confidencialidad la da quién tiene la copia, no el formato.
3. **Un documento adulterado se ve idéntico a uno legítimo** si no se comprueba la
   firma. Alterar un claim y dejar la firma intacta pasa cualquier control que no sea
   criptográfico. Lo único que los distingue es la verificación contra la cadena.

## Las dos capas de confianza

Toda respuesta de esta API se divide en dos, y la distinción es **lo más importante
de estas guías**:

| Firmado y verificable | Solo afirmación del servicio |
|---|---|
| Los claims que declaró el esquema | `issuer.name` |
| `vct` (el tipo) | `visibility` |
| `iss` (el emisor) | `layout` y todo lo relativo a cómo se ve la página |
| `jti` (el id del documento) | `status`, `digest`, `tx_hash` y los tiempos que informa el servicio |
| `iat` / `exp` | Todos los metadatos alrededor de `credential` |

La columna izquierda vive dentro del `credential` y está cubierta por la firma. La
derecha la dice el servidor.

> ⚠️ Una integración que le dé valor legal al documento debería **renderizar y
> decidir a partir de los claims del `credential` verificado**, no de nada más en la
> respuesta.

## El flujo completo, de punta a punta

```
  Dashboard                    API                      Destinatario
  ─────────                    ───                      ────────────
  a. Definir el esquema
     (kind: document)
  b. Diseñar el layout
     (opcional)
                        c. POST /issuer/documents
                           → valida, firma, ancla
                           → devuelve el credential
                        d. PUT …/visibility/public
                           (opcional)
                                                  e. Recibe el credential
                                                     o el enlace
                                                  f. Verifica contra
                                                     la cadena
```

- **a** y **b** se hacen desde el dashboard y requieren sesión. **No hay API para
  esto.**
- **c** es una sola llamada que valida, firma y ancla. Puede tardar hasta ~90 s.
- **d** es opcional y deliberada: **todo documento nace privado.**
- **e** puede ser el string `credential` o el enlace `/api/v1/documents/{id}`. Los
  dos caminos terminan en lo mismo.
- **f** corre del lado de quien recibe, contra la cadena. **No hay un endpoint que
  responda "válido" o "inválido"** — ver [6. Verificación](06-verificacion.md).

## Qué hace tu servidor y qué hace Sovra

| Responsabilidad | Quién |
|---|---|
| Definir el esquema de kind `document` y su layout | Vos, desde el dashboard |
| Decidir qué emitir y con qué datos | Tu servidor (`POST /issuer/documents`) |
| Validar los claims contra el esquema | Sovra |
| Firmar (ES256, llave en KMS) | Sovra |
| Anclar la firma on-chain | Sovra |
| Guardar el documento | Sovra lo conserva y lo devuelve en el listado, pero **el que importa guardar es el `credential` de tu lado** |
| Decidir si es público o privado | Vos (`PUT …/visibility/{visibility}`) |
| Repartirlo | Vos: el string, un enlace, un PDF, un QR impreso |
| Renderizar la hoja | Vos, o el dashboard, a partir de `layout` |
| Verificar la firma | Quien recibe el documento, contra la cadena |

> ★ **De todo lo que devuelve la emisión, `credential` es lo único que importa
> guardar.** Todo el resto es la afirmación del propio servicio sobre él y se puede
> volver a pedir —o perder— sin consecuencias. Si tu integración persiste un solo
> campo, es ese.

## Glosario

| Término | Definición |
|---|---|
| **Documento firmado** | Credencial sin holder, firmada por el emisor y anclada on-chain. Kind `document`. |
| **`credential`** | El string SD-JWT VC que *es* el documento. Autocontenido y verificable. |
| **SD-JWT VC** | Formato de credencial: JWT firmado + lista de disclosures. En un documento la lista está vacía. |
| **Anclaje** (*anchor*) | Registrar la firma on-chain. `status: "anchored"` es el estado final exitoso. |
| **`digest`** | SHA-256 del *signing input* (`header.payload`). La huella del documento. |
| **`tx_hash`** | Hash de la transacción on-chain donde quedó anclada la firma. |
| **`jti`** | El id del documento, dentro del JWT firmado. Coincide con el `id` de la API. |
| **`vct`** | *Verifiable Credential Type* — el `credential_type` del esquema. |
| **Visibilidad** | `public` o `private`. Controla el acceso **vía la API**, no el artefacto. |
| **Layout** | Instrucciones de cómo dibujar la hoja. Presentación, no firma. |
| **DID** | *Decentralized Identifier*. En Sovra, `did:sovra:0x…` — una cuenta en SovraChain. |
| **Workspace** | La unidad de aislamiento: tiene su propio DID, llaves, esquemas y API keys. |

---

**Siguiente:** [2. Primeros pasos →](02-primeros-pasos.md)
