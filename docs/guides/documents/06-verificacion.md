# 6. Verificación

## Tabla de contenidos

1. [Por qué no hay un endpoint de verificación](#por-qué-no-hay-un-endpoint-de-verificación)
2. [Los nueve checks](#los-nueve-checks)
3. [Qué aporta la API para verificar](#qué-aporta-la-api-para-verificar)
4. [Los cuatro checks locales](#los-cuatro-checks-locales)
5. [Los cinco checks contra la cadena](#los-cinco-checks-contra-la-cadena)
6. [El SDK](#el-sdk)
7. [Códigos de error](#códigos-de-error)
8. [Qué NO prueba una verificación exitosa](#qué-no-prueba-una-verificación-exitosa)

---

## Por qué no hay un endpoint de verificación

**No existe un endpoint que responda "válido" o "inválido", y es a propósito.**

Un veredicto habría que creerlo; una firma se puede comprobar. Si la API respondiera
`{ "valid": true }`, el destinatario estaría confiando en el mismo servicio que emitió
el documento — exactamente la dependencia que el anclaje on-chain viene a eliminar.

**La verificación corre entera del lado de quien verifica, contra la cadena.** Esa es
la propiedad que hace que el documento sirva: el servicio puede caerse, cambiar de
dominio o dejar de existir, y el documento sigue siendo verificable.

Es también la diferencia estructural con las credenciales, donde Sovra ofrece
[verificación hospedada](../credentials/04-verificacion-de-credenciales.md) además del
[camino on-chain](../credentials/09-verificacion-sin-sovra.md). Para documentos, el
camino on-chain es el único.

## Los nueve checks

Verificar un documento son nueve comprobaciones. **Cuatro corren solo con WebCrypto**
(o el equivalente de tu lenguaje) y **cinco necesitan leer la cadena**:

| Corre local | Necesita la cadena |
|---|---|
| **`parse`** — estructura, `alg: ES256`, ausencia de `cnf` | **`key`** — la clave pública del emisor, desde el DID Registry |
| **`digest`** — `SHA-256(header.payload)` | **`signature`** — la firma ES256 contra esa clave |
| **`issuer`** — la dirección extraída de `iss` | **`trust`** — el emisor autorizado para ese `vct` |
| **`expiry`** — `exp` contra ahora | **`anchor`** — la firma está registrada on-chain |
| | **`anchor-match`** — lo anclado corresponde a *este* documento |

> ⚠️ **Los cuatro locales no prueban autenticidad.** Un documento adulterado los pasa
> todos si el atacante recalculó el digest y dejó una firma cualquiera. Lo único que
> distingue un documento legítimo de uno fabricado son los cinco de la derecha.
>
> Una integración que corra solo los locales **está mostrando, no verificando**.

## Qué aporta la API para verificar

La API no da veredictos, pero sí da todas las entradas del proceso:

| Campo | Para qué sirve al verificar |
|---|---|
| `credential` | Contiene el payload, la firma y el DID del emisor. **Es la entrada de todo el proceso.** |
| `issuer.did` | Con él se resuelve la clave pública del emisor en el registry on-chain. |
| `digest` | Se recalcula sobre los bytes del `credential` y se compara. |
| `tx_hash` | Ubica el registro de la firma anclada en la cadena. |

De esos cuatro, **solo `credential` es imprescindible**: `issuer.did` sale del claim
`iss` de adentro, y el `digest` se calcula. Los otros dos son atajos, no fuentes de
verdad — son [afirmaciones del servicio](01-introduccion.md#las-dos-capas-de-confianza).

Consecuencia práctica: **con el string `credential` solo, sin la respuesta de la API y
sin la API en línea, alcanza para verificar.**

## Los cuatro checks locales

### `parse` — la estructura

```js
const [jwt, ...disclosures] = credential.split("~");
const parts = jwt.split(".");
if (parts.length !== 3) throw new Error("no tiene tres partes");

const [headerB64, payloadB64, signatureB64] = parts;
const header  = decodeSegment(headerB64);
const payload = decodeSegment(payloadB64);
```

Tres cosas que comprobar acá:

| Comprobación | Por qué |
|---|---|
| `header.alg === "ES256"` | Es el único algoritmo que emite la plataforma. Aceptar otro abre la puerta a un `alg: "none"`. |
| `header.typ === "vc+sd-jwt"` | Confirma el formato. |
| **`payload.cnf` no existe** | Un documento **no tiene holder**. Si hay `cnf`, lo que tenés es una credencial, no un documento → `NOT_A_DOCUMENT`. |

La lista de disclosures tiene que estar **vacía**: la `~` final sola es lo esperado.

### `digest` — la huella

```js
const signingInput = `${headerB64}.${payloadB64}`;
const bytes = new TextEncoder().encode(signingInput);
const hash  = await crypto.subtle.digest("SHA-256", bytes);
const digest = [...new Uint8Array(hash)]
  .map((b) => b.toString(16).padStart(2, "0")).join("");
```

Sobre el **signing input**, no sobre el `credential` completo: incluir la firma o la
`~` da otro hash, que no coincide con nada de lo que hay en la cadena. Ver
[5. Renderizado](05-renderizado.md#calcular-el-digest).

### `issuer` — la dirección

El claim `iss` es una URL HTTPS cuyo *path* es el DID del emisor:

```
https://api.sovra.io/did:sovra:0xb516d2f945db504198d726bda2a01d19ecd3cc23
                                └──────────── la dirección ────────────┘
```

De ahí sale la dirección `0x…` con la que se consultan los registries. **Sacala del
`iss` firmado, no del campo `issuer.did` de la respuesta**, que no está cubierto por
la firma.

> Nota: el host del `iss` es el de la API que emitió. No lo uses como fuente de
> confianza — la confianza sale del registry, no del dominio.

### `expiry` — la vigencia

```js
const now = Math.floor(Date.now() / 1000);
if (payload.exp && now > payload.exp) throw new Error("expirado");
if (payload.iat && payload.iat > now + 60) throw new Error("iat en el futuro");
```

Dejá una tolerancia horaria (60 s es razonable) para no rechazar documentos por
desfasaje de reloj.

## Los cinco checks contra la cadena

Necesitás **acceso RPC a SovraChain** y las direcciones de los registries:

| | Test | Producción |
|---|---|---|
| RPC | `https://rpc.testnet.sovra.io` | `https://rpc.sovra.io` |
| Chain ID | `65536101` | `65536001` |
| DID Registry | `0xB9A2c855376ED79966e5f4956D3BfD21ef80d96E` | `0x3829184dB00Ae8C7a6c72104A2Ede879F6F25e78` |
| Issuer Registry | `0x9723B2586332b973AA18a8b82E6675DA73490Bd0` | `0x290eAc1114B66D14a4b911CEE6D571ddC0f254C8` |

> Confirmá estas direcciones con el equipo de Sovra antes de desplegar: un redeploy de
> contratos las cambia. La dirección del registry de **firmas ancladas** (el que
> resuelve `anchor` y `anchor-match`) no está publicada en estas guías — pedila junto
> con las otras.

| Check | Qué hace |
|---|---|
| **`key`** | Resuelve el DID del emisor en el **DID Registry** y obtiene la clave pública de su `assertionMethod`. |
| **`signature`** | Verifica los 64 bytes de firma ES256 sobre el *signing input*, con esa clave. **Es el check que hace verificable al documento.** |
| **`trust`** | Consulta el **Issuer Registry**: ¿está ese emisor autorizado a emitir este `vct`? Una firma válida de un emisor no autorizado no alcanza. |
| **`anchor`** | Comprueba que la firma esté registrada on-chain. Un documento con `status: "failed"` falla acá → `NOT_ANCHORED`. |
| **`anchor-match`** | Comprueba que lo anclado corresponda a *este* documento: el `digest` recalculado contra el registrado. Es lo que impide reusar un anclaje legítimo para otro contenido. |

Sin `trust`, cualquiera con una cuenta en la cadena puede firmar un documento que
valida criptográficamente. **La autoridad institucional vive en el Issuer Registry, no
en la firma.**

## El SDK

Los cinco checks on-chain implican resolver DIDs, decodificar claves y leer
contratos. El SDK de verificación de Sovra los encapsula:

```bash
npm install @sovrahq/verification-sdk
```

Sus códigos de error incluyen `NOT_A_DOCUMENT` y `NOT_ANCHORED`, específicos de este
flujo: el SDK distingue un documento firmado de una credencial con holder, y verifica
el anclaje.

> ⚠️ **Confirmá con el equipo de Sovra el punto de entrada para documentos y la
> disponibilidad del paquete en tu entorno** antes de diseñar la integración. La
> verificación de *presentaciones* de credenciales está documentada en
> [`../credentials/09-verificacion-sin-sovra.md`](../credentials/09-verificacion-sin-sovra.md);
> un documento no tiene holder, así que no lleva `expectedNonce`, `expectedAudience`
> ni KB-JWT, y su flujo agrega en cambio los checks de anclaje.

Si implementás la verificación por tu cuenta, el orden importa: **`parse` → `key` →
`signature` → `trust` → `anchor` → `anchor-match` → `expiry`**. Comprobar la
expiración antes de la firma hace que un documento fabricado con `exp` lejano parezca
"casi válido"; comprobar `trust` después de la firma evita gastar lecturas de cadena
en firmas que ya fallaron.

## Códigos de error

Los que aplican a un documento firmado:

| Código | Significado |
|---|---|
| `PARSE_ERROR` | El `credential` no parsea: no tiene tres partes, o un segmento no es base64url válido. |
| `NOT_A_DOCUMENT` | Se esperaba un documento firmado y no lo es (por ejemplo, trae `cnf`: es una credencial con holder). |
| `NOT_ANCHORED` | La firma del documento **no está anclada on-chain**. Típicamente un `status: "failed"`. |
| `ISSUER_SIG_INVALID` | La firma del emisor no valida contra su clave pública. |
| `ISSUER_NOT_FOUND` | El DID del emisor no resuelve en el DID Registry. |
| `ISSUER_NOT_TRUSTED` | El emisor no está autorizado para ese `vct` en el Issuer Registry. |
| `ISSUER_DEACTIVATED` | El emisor fue desactivado. |
| `CREDENTIAL_EXPIRED` | Pasó el `exp`. |
| `CREDENTIAL_REVOKED` | El documento fue revocado. |
| `IAT_INVALID` | `iat` fuera de la tolerancia horaria. |
| `INVALID_ADDRESS` | Una dirección de contrato no es válida. |

Los códigos de la familia `KB_*`, `HOLDER_*`, `DISCLOSURE_MISMATCH` y
`SUBJECT_KEY_MISMATCH` son de presentaciones de credenciales y **no aplican a
documentos**: no hay holder, no hay disclosures y no hay key binding. La tabla
completa está en
[`../credentials/09-verificacion-sin-sovra.md`](../credentials/09-verificacion-sin-sovra.md#códigos-de-error-del-sdk).

## Qué NO prueba una verificación exitosa

Los nueve checks en verde prueban una cosa concreta: **este contenido exacto fue
firmado por ese emisor, que estaba autorizado a emitir ese tipo, y la firma quedó
registrada en la cadena.** No prueban:

| No prueba | Por qué |
|---|---|
| Que el contenido sea **verdadero** | La firma cubre lo que el emisor afirmó, no la realidad. Si la institución se equivocó, la firma es válida y el dato es falso. |
| Que el documento sea **confidencial** | El contenido es legible por cualquiera que tenga el string. |
| Que la copia sea **única** | No hay nada que distinga el original de una copia: el mismo string verifica infinitas veces. Si tu proceso necesita "usado una sola vez", eso lo lleva tu sistema, no el documento. |
| Que quien lo presenta sea **su titular** | Un documento **no tiene holder**. No hay prueba de posesión. Si necesitás atar el dato a una persona presente, lo que necesitás es una [credencial](../credentials/), no un documento. |
| Que `issuer.name`, `visibility` o `layout` sean lo que dicen | Nada de eso está cubierto por la firma. |

Ese cuarto punto es el más importante al elegir formato: **un documento prueba
autoría, no titularidad.**

---

**Anterior:** [← 5. Renderizado de la hoja](05-renderizado.md) · **Siguiente:** [7. Referencia de la API →](07-referencia-api.md)
