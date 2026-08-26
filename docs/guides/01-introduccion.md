# 1. Introducción

## Tabla de contenidos

1. [Los tres roles](#los-tres-roles)
2. [Qué es una credencial en Sovra](#qué-es-una-credencial-en-sovra)
3. [Anatomía de un SD-JWT VC](#anatomía-de-un-sd-jwt-vc)
4. [El flujo completo, de punta a punta](#el-flujo-completo-de-punta-a-punta)
5. [Qué hace tu servidor y qué hace Sovra](#qué-hace-tu-servidor-y-qué-hace-sovra)
6. [Glosario](#glosario)

---

## Los tres roles

Todo el modelo se reduce a tres actores. Una misma organización puede ocupar dos
de ellos (por ejemplo, emitir credenciales y también verificarlas), pero conviene
pensarlos por separado porque usan endpoints distintos.

| Rol | Quién es | Qué hace |
|---|---|---|
| **Emisor** (*issuer*) | Tu organización | Firma credenciales y las ofrece a un ciudadano. Puede revocarlas. |
| **Holder** (*portador*) | El ciudadano | Guarda la credencial en la wallet móvil de Sovra. Decide qué campos comparte. |
| **Verificador** (*verifier*) | Tu organización, o un tercero | Pide campos concretos y comprueba que la credencial sea auténtica, vigente y no revocada. |

La clave del modelo: **el emisor y el verificador nunca hablan entre sí**. El
ciudadano es quien transporta la credencial, y la criptografía es lo que garantiza
que no la haya alterado.

## Qué es una credencial en Sovra

Una credencial de Sovra es un **SD-JWT VC** (`format: "vc+sd-jwt"`): un JWT firmado
por el emisor con ES256, seguido de una lista de *disclosures* — los valores reales
de cada campo.

Lo que hace especial al formato es la **divulgación selectiva** (*selective
disclosure*): el JWT firmado no contiene los valores, sino **hashes** de cada campo
(el arreglo `_sd`). Los valores viajan aparte, uno por *disclosure*. Cuando el
ciudadano presenta la credencial, elige **qué disclosures adjunta**.

Consecuencia práctica: un ciudadano puede demostrar que es mayor de edad
(`age_over_18: true`) **sin revelar su fecha de nacimiento ni su nombre**, y la
firma del emisor sigue siendo verificable sobre lo que sí compartió.

Además:

- La credencial está **atada a la llave del holder** (claim `cnf`). Un archivo
  robado no sirve: cada presentación se firma en el momento con la passkey del
  ciudadano, guardada en el Secure Enclave del teléfono.
- El estado de revocación vive **on-chain**, como una *Bitstring Status List*
  firmada. Cualquiera puede consultarlo sin pedirle permiso a Sovra.

## Anatomía de un SD-JWT VC

Un SD-JWT es una sola cadena de texto con partes separadas por `~`:

```
<JWT firmado por el emisor>~<disclosure 1>~<disclosure 2>~...~
```

Tomando una credencial real emitida por la plataforma, el JWT decodifica a:

```json
{
  "iss": "https://api.sovra.io/did:sovra:0xcda79e1ee5612c230a6faf68236e3679b0579bae",
  "sub": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274",
  "vct": "VerifiableCredential",
  "iat": 1787719292,
  "exp": 1819255292,
  "cnf": {
    "kid": "did:sovra:0x90833e201da2dd3c146619217f18a85bbd12f274#2of2"
  },
  "_sd_alg": "sha-256",
  "_sd": [
    "azf2RkVkCL__eXv6MflluQqcaTuXGJc4liir05sCwgk",
    "hAGWxeANi5Y2A5XGgZJzcH72LzOA9NNYXF7U8Rcy9oY",
    "ebxirQ5YxKmTHH3lUEyFbZVrPnjttP6DHr-iMv8x9WI",
    "k_uZ3GuqoiD_2zSqlCDh_B5kKdGPF5jRVtn6QIxyyRM",
    "t9R1PHBn5IWnEMWN5hPE6a_eEDvCbjr86_FiI-tKneY"
  ],
  "credentialStatus": [
    {
      "type": "BitstringStatusListEntry",
      "statusPurpose": "revocation",
      "statusListIndex": "79328",
      "statusListCredential": "https://api.sovra.io/api/v1/status/did:sovra:0xcda…bae/revocation"
    },
    {
      "type": "BitstringStatusListEntry",
      "statusPurpose": "suspension",
      "statusListIndex": "79328",
      "statusListCredential": "https://api.sovra.io/api/v1/status/did:sovra:0xcda…bae/suspension"
    }
  ]
}
```

| Claim | Qué significa |
|---|---|
| `iss` | Identificador del emisor: una URL HTTPS cuyo *path* es el DID del workspace. |
| `sub` | DID del holder (su cuenta en SovraChain). |
| `vct` | *Verifiable Credential Type* — el `credential_type` del esquema. Es lo que un verificador debe reconocer. |
| `iat` / `exp` | Emisión y expiración, en segundos Unix. `exp` sale del `validity_period` del esquema. |
| `cnf.kid` | La llave del holder a la que está atada la credencial. El sufijo `#2of2` refiere a la validación 2-de-2 de la cuenta. |
| `_sd` | Un hash SHA-256 por cada claim divulgable. |
| `_sd_alg` | Algoritmo de los hashes (`sha-256`). |
| `credentialStatus` | Dónde consultar revocación y suspensión. Siempre son **dos** entradas con el mismo `statusListIndex`. |

Y cada *disclosure* es `base64url(JSON([salt, clave, valor]))`. Las cinco del
ejemplo decodifican a:

```json
["N4Zr2CS-ak93J0Wh2_jCDw", "email",      "quinterosm.daniel@gmail.com"]
["sPwyJ5BurK5HJpYE8QUY3w", "fist_name",  "Daniel"]
["41f6uSrCKbg6TlSy9QxB8g", "last_name",  "Quinteros"]
["NpIANu63jyJtWlEZItWFvA", "role",       "normal"]
["72DvoTgKCRYx6jvWLrdzmA", "curp",       "123123123123"]
```

> **Detalle importante del ejemplo:** la clave dice `fist_name`, no `first_name`.
> Es el `key` que se cargó en el esquema, con la errata incluida. Si más tarde un
> verificador pide `first_name`, la verificación falla con
> `missing_required_claim:first_name` — aunque el dato "esté". El `key` del esquema
> es un contrato literal entre emisor y verificador; ver
> [Errores y troubleshooting](08-errores-y-troubleshooting.md#missing_required_claim).

## El flujo completo, de punta a punta

### Emisión (OID4VCI)

```
  Tu servidor                    Sovra API                Wallet del ciudadano
       │                             │                             │
       │ POST /credential-offer      │                             │
       │  { schema_id, claims }      │                             │
       ├────────────────────────────►│                             │
       │                             │                             │
       │ 201 { credential_id,        │                             │
       │       offer_uri, ... }      │                             │
       │◄────────────────────────────┤                             │
       │                             │                             │
       │  Mostrás offer_uri como QR ─────── escanea ──────────────►│
       │                             │                             │
       │                             │◄─ token + prueba de llave ──┤
       │                             │── SD-JWT firmado ──────────►│
       │                             │                             │
       │ webhook credential.issued   │                             │
       │◄────────────────────────────┤                             │
```

La oferta vive **24 horas**. El `credential_id` que recibís al crearla es el mismo
identificador durante todo el ciclo de vida: lo vas a ver en el webhook y lo vas a
usar para consultar o revocar.

### Verificación (OID4VP)

```
  Tu servidor                    Sovra API                Wallet del ciudadano
       │                             │                             │
       │ POST /verifications         │                             │
       │  { dcql_query }             │                             │
       ├────────────────────────────►│                             │
       │ 201 { session_id,           │                             │
       │  authorization_request_uri }│                             │
       │◄────────────────────────────┤                             │
       │                             │                             │
       │  Mostrás la URI como QR ────────── escanea ──────────────►│
       │                             │                             │
       │                             │   el ciudadano aprueba      │
       │                             │   campo por campo           │
       │                             │◄── vp_token (direct_post) ──┤
       │                             │                             │
       │  webhook presentation.      │  Sovra valida: firma del    │
       │  verified / failed          │  emisor, prueba del holder, │
       │◄────────────────────────────┤  expiración, revocación,    │
       │                             │  y el match del DCQL        │
```

La sesión de verificación vive **10 minutos**.

## Qué hace tu servidor y qué hace Sovra

| Responsabilidad | Quién |
|---|---|
| Definir esquemas y su diseño visual | Vos, desde el dashboard |
| Decidir a quién emitir y con qué datos | Tu servidor (`POST /credential-offer`) |
| Renderizar el QR | Tu aplicación |
| Firmar la credencial (ES256, llave en KMS) | Sovra |
| Guardar la credencial | La wallet del ciudadano — **Sovra no almacena el SD-JWT firmado** |
| Elegir qué campos se comparten | El ciudadano, en la wallet |
| Validar una presentación | Sovra (o tu propio verificador, ver [guía 9](09-verificacion-sin-sovra.md)) |
| Publicar el estado de revocación | Sovra, en SovraChain |
| Reaccionar a los eventos | Tu servidor, vía webhooks |

> ⚠️ **El SD-JWT firmado se entrega una sola vez**, en el webhook
> `credential.issued`. No se guarda del lado del servidor y no hay endpoint para
> volver a pedirlo. Si tu sistema lo necesita (por ejemplo para reimprimirlo o
> auditarlo), **persistilo cuando llega el webhook**.

## Glosario

| Término | Definición |
|---|---|
| **SD-JWT VC** | Formato de credencial: JWT firmado + disclosures. Permite divulgación selectiva. |
| **Disclosure** | `base64url(JSON([salt, clave, valor]))`. Un campo revelable de la credencial. |
| **OID4VCI** | *OpenID for Verifiable Credential Issuance*. El protocolo de emisión (el QR `openid-credential-offer://`). |
| **OID4VP** | *OpenID for Verifiable Presentations*. El protocolo de verificación (el QR `openid4vp://`). |
| **DCQL** | *Digital Credentials Query Language*. El lenguaje con el que el verificador pide credenciales y campos. |
| **DID** | *Decentralized Identifier*. En Sovra, `did:sovra:0x…` — una cuenta en SovraChain. |
| **KB-JWT** | *Key Binding JWT*. La firma que el holder agrega a cada presentación, probando que la llave es suya. |
| **vp_token** | Lo que la wallet envía al verificador: la credencial con las disclosures elegidas + el KB-JWT. |
| **vct** | El tipo de credencial dentro del SD-JWT. Igual al `credential_type` del esquema. |
| **Bitstring Status List** | Mapa de bits firmado donde cada credencial ocupa un índice; 1 = revocada/suspendida. |
| **Workspace** | La unidad de aislamiento en Sovra: tiene su propio DID, llaves, esquemas, API keys y webhook. |

---

**Siguiente:** [2. Primeros pasos →](02-primeros-pasos.md)
