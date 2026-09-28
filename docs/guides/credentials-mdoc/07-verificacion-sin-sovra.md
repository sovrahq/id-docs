# 7. Verificación sin Sovra

Procedimiento para verificar un mDoc contra la cadena y contra anclas de
confianza propias, sin dependencia de la API de Sovra. Constituye el equivalente
mDoc de
[Verificación sin Sovra para SD-JWT](../credentials/09-verificacion-sin-sovra.md).

## Tabla de contenidos

1. [Supuestos de aplicación](#supuestos-de-aplicación)
2. [Requisitos](#requisitos)
3. [El SDK](#el-sdk)
4. [Verificar un `DeviceResponse`](#verificar-un-deviceresponse)
5. [Las anclas de confianza](#las-anclas-de-confianza)
6. [Comportamiento de `verifyMdoc()`](#comportamiento-de-verifymdoc)
7. [Resultado](#resultado)
8. [Códigos de error](#códigos-de-error)
9. [Inspección de una cadena de certificados](#inspección-de-una-cadena-de-certificados)
10. [Comparación entre ambos procedimientos](#comparación-entre-ambos-procedimientos)

---

## Supuestos de aplicación

| Situación | Fundamento |
|---|---|
| La organización actúa como verificador y no como emisor | No se requiere una cuenta de Sovra para verificar. |
| Se persigue independencia operativa | La verificación no depende de la disponibilidad de la API de Sovra. |
| Se opera un lector de mDL de terceros | La lista de confianza aplicable es la de la autoridad correspondiente, no la de Sovra. |
| Auditoría | Permite reproducir la verificación de cualquier presentación conservada. |

## Requisitos

**La presentación.** El `DeviceResponse` generado por la wallet, en base64url.

**Acceso RPC a SovraChain** y las direcciones de los registros, coincidentes con
las empleadas para SD-JWT y recogidas en la
[tabla de la guía de credenciales](../credentials/09-verificacion-sin-sovra.md#qué-necesitás).

**Y, exclusivamente para la verificación de docTypes ISO, las anclas de
confianza:** los certificados raíz IACA que la organización acepta. Véase el
[apartado correspondiente](#las-anclas-de-confianza).

## El SDK

`@sovrahq/verification-sdk`, el mismo paquete empleado para SD-JWT.

```bash
npm install @sovrahq/verification-sdk
```

```ts
import { verifyMdoc, ErrorCode } from "@sovrahq/verification-sdk";
```

## Verificar un `DeviceResponse`

```ts
import { verifyMdoc } from "@sovrahq/verification-sdk";

const resultado = await verifyMdoc(deviceResponseB64u, {
  // Los tres vinculan la presentación a *esta* interacción. Los tres se
  // incorporan al session transcript, de modo que un valor incorrecto se
  // manifiesta como firma inválida.
  clientId: clientIdEmitido,
  nonce: nonceGenerado,
  responseUri: "https://verificador.ejemplo/oid4vp/response",

  l2RpcUrl: "https://rpc.sovra.io",
  didRegistryAddress: "0x…",
  issuerRegistryAddress: "0x…",
  revocationRegistryAddress: "0x…",

  // Aplicable únicamente a docTypes ISO. Un docType generado lo ignora
  // por completo.
  trustAnchors: pemBundleDeLaAutoridad,

  checkRevocation: true,
  maxClockSkew: 60, // segundos
});

if (resultado.valid) {
  console.log("Elementos divulgados:", resultado.claims);
} else {
  console.warn("Inválido:", resultado.errorCode, resultado.error);
}
```

### Opciones

`VerifyMdocOptions` corresponde a `VerifyOptions` **sin** `expectedNonce` ni
`expectedAudience` —inaplicables en mDoc— y **con** tres campos específicos:

| Opción | Obligatoria | Descripción |
|---|---|---|
| `clientId` | ✅ | El `client_id` enviado en la solicitud de autorización. |
| `nonce` | ✅ | El nonce de un solo uso de esa misma solicitud. |
| `responseUri` | ✅ | La `response_uri` a la que se envió la presentación. |
| `trustAnchors` | Para ISO | Raíces IACA aceptadas: `Uint8Array[]` en DER, o un bundle PEM. |
| `l2RpcUrl` | ✅ | Endpoint RPC de los registros. |
| `didRegistryAddress` | ✅ | Dirección del DID Registry. |
| `issuerRegistryAddress` | ✅ | Dirección del Issuer Registry. |
| `revocationRegistryAddress` | — | Necesaria si `checkRevocation` está habilitado. |
| `maxClockSkew` | — | Tolerancia horaria en segundos. Valor por defecto: `60`. |
| `checkRevocation` | — | Consulta el mapa en la cadena. |

> **Los tres primeros se incorporan al session transcript**, que es lo que
> vincula la presentación a un verificador y a una interacción determinada. Un
> nonce obsoleto o una `responseUri` incorrecta no se manifiestan como «nonce
> inválido», sino como `DEVICE_AUTH_INVALID`, dado que la totalidad del
> transcript interviene en el hash firmado por el holder.

## Las anclas de confianza

Aquí reside la diferencia sustancial con SD-JWT. Según el docType, el emisor se
resuelve por una u otra vía:

| docType | Resolución del emisor | `trustAnchors` |
|---|---|---|
| `io.sovra.<dirección>.<esquema>.1` | La dirección se transporta dentro del docType y se resuelve contra el Issuer Registry. | **Se ignora.** |
| `org.iso.18013.5.1.mDL` | El `x5chain` del `COSE_Sign1`, validado contra las raíces configuradas. | **Obligatorio.** |

```ts
import { readFileSync } from "node:fs";

// Un bundle PEM con todas las raíces IACA que la organización acepta.
const trustAnchors = readFileSync("./iaca-roots.pem", "utf8");
```

> 🔴 **No existe valor por defecto, y una lista vacía no lo constituye.** La
> determinación de qué autoridades merecen confianza corresponde al verificador y
> no puede inferirse de la credencial, dado que la credencial es precisamente el
> objeto sometido a comprobación. Sin `trustAnchors`, un mDoc con docType ISO no
> supera la verificación.

Sobre la cadena se aplica el perfil del Anexo B de ISO 18013-5: el certificado
hoja debe incorporar la EKU `1.0.18013.5.1.2` (`id-mdlDS`), no puede ser CA, y su
validez no puede superar los **457 días**. Ambas constantes se exportan como
`OID_MDL_DOCUMENT_SIGNER` y `MAX_DOCUMENT_SIGNER_DAYS`.

> Lo que una cadena validada acredita —y todo lo que acredita— es la autoridad
> emisora y el subject del document signer. **Una dirección de Sovra no figura en
> ningún punto**: un DSC de ISO identifica una llave y un subject, y un workspace
> no consta en ninguno de los dos. Por ese motivo la dirección se transporta
> adicionalmente en el namespace meta.

## Comportamiento de `verifyMdoc()`

Ejecuta la misma cascada de comprobaciones que la verificación alojada:

1. Analiza el `DeviceResponse` (CBOR).
2. Extrae el docType, el MSO y el namespace `io.sovra.meta.1`.
3. Resuelve el emisor: por la dirección del docType, o por el `x5chain` contra
   `trustAnchors`.
4. Verifica la firma del `issuerAuth` (`COSE_Sign1`, ES256).
5. Comprueba que el `docType` del MSO firmado coincida con el del documento.
6. Comprueba que el emisor conste como de confianza en el Issuer Registry.
7. Recalcula el hash de cada elemento divulgado y lo contrasta con
   `valueDigests`, sobre el envoltorio tag-24 y no sobre el mapa sin envolver.
8. Exige el namespace meta completo, y que su `issuer_address` coincida con el
   emisor resuelto.
9. Comprueba `validFrom` y `validUntil` aplicando la tolerancia horaria.
10. Comprueba la revocación por `status_list_index`, si `checkRevocation` está
    habilitado.
11. Resuelve el DID del holder para obtener las passkeys activas de su cuenta en
    la cadena, y exige que la `deviceKey` del MSO figure entre ellas.
12. Verifica la firma de device authentication contra el session transcript
    compuesto con `clientId`, `nonce` y `responseUri`.

## Resultado

```ts
interface VerificationResult {
  valid: boolean;
  error?: string;
  errorCode?: ErrorCode;
  claims?: Record<string, unknown>;  // únicamente lo divulgado por el holder
  issuer?: string;
  holder?: string;
  credentialType?: string;
  issuedAt?: number;
  expiresAt?: number;
  revocationChecked?: boolean;
}
```

> ⚠️ **Las claves de `claims` se entregan cualificadas por namespace**, unidas
> mediante un punto: `"org.iso.18013.5.1.family_name"`. Corresponde a la misma
> forma devuelta por la verificación alojada, de manera deliberada: una
> divergencia provocaría que la consulta de un integrador fallara de forma
> silenciosa.

## Códigos de error

A los códigos comunes con SD-JWT se añaden cuatro específicos de mDoc:

| `ErrorCode` | Descripción |
|---|---|
| `MDOC_PARSE_ERROR` | El `DeviceResponse` no se analiza correctamente como CBOR o carece de la estructura requerida. |
| `MSO_DIGEST_MISMATCH` | Un elemento divulgado no coincide con su digest en `valueDigests`. |
| `DEVICE_AUTH_INVALID` | La firma de device authentication no valida. Obedece habitualmente a un `clientId`, `nonce` o `responseUri` distinto del empleado en la solicitud. |
| `DOCTYPE_MISMATCH` | El docType no corresponde al esperado. |

Y los códigos comunes de mayor incidencia en mDoc:

| `ErrorCode` | Descripción |
|---|---|
| `ISSUER_NOT_FOUND` | No se resolvió el emisor: la dirección no figura en el registro, o el `x5chain` no encadena a ninguna de las `trustAnchors` configuradas. |
| `ISSUER_NOT_TRUSTED` | Se resolvió, pero el Issuer Registry no lo reconoce como de confianza. |
| `ISSUER_SIG_INVALID` | La firma del `issuerAuth` no valida. |
| `CREDENTIAL_EXPIRED` | Fuera de la ventana `validFrom` / `validUntil`. |
| `CREDENTIAL_REVOKED` | El índice consta marcado en la cadena. |
| `HOLDER_NOT_FOUND` | El `holder_did` del namespace meta no resuelve. |
| `SUBJECT_KEY_MISMATCH` | La `deviceKey` del MSO no figura entre las passkeys activas del holder. |

## Inspección de una cadena de certificados

En determinados supuestos, un lector de mDL requiere examinar una cadena en lugar
de limitarse a aceptarla o rechazarla. El SDK exporta los elementos necesarios:

```ts
import {
  parseCertificate,
  pemToDer,
  validateDocumentSignerChain,
  CertificateError,
  MAX_DOCUMENT_SIGNER_DAYS,
  OID_MDL_DOCUMENT_SIGNER,
} from "@sovrahq/verification-sdk";

const der = pemToDer(pem);
const cert = parseCertificate(der);

console.log(cert.subject);          // { country, state, organization, commonName }
console.log(cert.notBefore, cert.notAfter);
```

`pemToDer` se exporta precisamente porque un verificador configura sus
`trustAnchors` a partir de lo publicado por su autoridad, cuyo formato es PEM: sin
esta función, cada integrador implementaría el mismo bucle de base64.

## Comparación entre ambos procedimientos

| | Verificación alojada | `verifyMdoc()` |
|---|---|---|
| Responsable de componer el QR | Sovra (`POST /verifications`) | El verificador |
| Receptor de la presentación | Sovra | El `response_uri` del verificador |
| Entrega del resultado | Webhook `presentation.verified` | Valor de retorno de la función |
| Lista de confianza ISO | La de Sovra | **La del verificador** (`trustAnchors`) |
| Requiere API key | ✅ | ❌ |
| Requiere RPC a SovraChain | ❌ | ✅ |
| Session transcript | Lo compone Sovra | Lo compone el verificador con `clientId`, `nonce` y `responseUri` |

---

**Anterior:** [← 6. Errores y troubleshooting](06-errores-y-troubleshooting.md) · **Siguiente:** [8. Habilitar mDL ISO →](08-habilitar-mdl-iso.md)
