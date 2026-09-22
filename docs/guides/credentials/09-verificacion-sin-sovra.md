# 9. Verificación sin Sovra

Las guías anteriores describen la **verificación hospedada**: creás una sesión, Sovra
valida y te avisa por webhook. Es el camino más simple y el recomendado para empezar.

Pero hay una segunda forma: **verificar por tu cuenta, leyendo directamente
SovraChain**. El emisor, su llave, la lista de confianza y el mapa de revocación están
todos on-chain. Con acceso RPC alcanza — no hace falta llamar a ninguna API de Sovra,
ni tener credenciales de Sovra, ni depender de su disponibilidad.

## Tabla de contenidos

1. [Cuándo conviene](#cuándo-conviene)
2. [Qué necesitás](#qué-necesitás)
3. [El SDK](#el-sdk)
4. [Verificar una presentación](#verificar-una-presentación)
5. [Consultar solo la revocación](#consultar-solo-la-revocación)
6. [Códigos de error del SDK](#códigos-de-error-del-sdk)
7. [Comparación entre ambos caminos](#comparación-entre-ambos-caminos)

---

## Cuándo conviene

| Situación | Camino |
|---|---|
| Integración típica: mostrás un QR y esperás el resultado | **Hospedada** ([guía 4](04-verificacion-de-credenciales.md)) |
| No podés depender de la disponibilidad de un tercero | On-chain |
| Verificás en un entorno aislado, con acceso RPC pero sin API keys | On-chain |
| Auditar por tu cuenta una credencial que ya guardaste | On-chain |
| Un tercero quiere verificar credenciales que emitiste, sin ser cliente de Sovra | On-chain |

Los dos caminos aplican las **mismas comprobaciones criptográficas**. La diferencia es
quién las corre y de dónde saca los datos de confianza.

## Qué necesitás

**La presentación.** El SD-JWT con las disclosures y el KB-JWT. Es lo que produce la
wallet; si ya guardaste un SD-JWT emitido, también podés verificar su firma y su
estado (sin la parte de prueba del holder, que es propia de cada presentación).

**Acceso RPC a SovraChain** y las direcciones de los registros:

| | Test | Producción |
|---|---|---|
| RPC | `https://rpc.testnet.sovra.io` | `https://rpc.sovra.io` |
| Chain ID | `65536101` | `65536001` |
| DID Registry | `0xB9A2c855376ED79966e5f4956D3BfD21ef80d96E` | `0x3829184dB00Ae8C7a6c72104A2Ede879F6F25e78` |
| Issuer Registry | `0x9723B2586332b973AA18a8b82E6675DA73490Bd0` | `0x290eAc1114B66D14a4b911CEE6D571ddC0f254C8` |
| Revocation Registry | `0xfF6B907900D099bD771B09Dd6Aaf65397166D5c7` | `0xEF9DaDA18C3f46147FD1Ed7a3C1F0ba37ac9d6F7` |

> Confirmá estas direcciones con el equipo de Sovra antes de desplegar: un redeploy de
> contratos las cambia.

## El SDK

`@sovrahq/verification-sdk` — TypeScript, sin dependencias de red más allá del RPC.

```bash
npm install @sovrahq/verification-sdk
```

```ts
import { verify, checkRevocation, ErrorCode } from "@sovrahq/verification-sdk";
```

## Verificar una presentación

```ts
import { verify } from "@sovrahq/verification-sdk";

const resultado = await verify(vpToken, {
  // Vinculan la presentación a *esta* interacción. Sin ellos, una presentación
  // vieja podría reproducirse.
  expectedNonce: nonceQueGeneraste,
  expectedAudience: audienceQueGeneraste,

  l2RpcUrl: "https://rpc.sovra.io",
  didRegistryAddress: "0x3829184dB00Ae8C7a6c72104A2Ede879F6F25e78",
  issuerRegistryAddress: "0x290eAc1114B66D14a4b911CEE6D571ddC0f254C8",
  revocationRegistryAddress: "0xEF9DaDA18C3f46147FD1Ed7a3C1F0ba37ac9d6F7",

  checkRevocation: true,
  maxClockSkew: 60, // segundos
});

if (resultado.valid) {
  console.log("Claims divulgados:", resultado.claims);
  console.log("Emisor:", resultado.issuer);
  console.log("Holder:", resultado.holder);
  console.log("Tipo:", resultado.credentialType);
} else {
  console.warn("Inválida:", resultado.errorCode, resultado.error);
}
```

### Opciones

| Opción | Obligatoria | Descripción |
|---|---|---|
| `expectedNonce` | ✅ | El nonce que generaste para esta interacción. |
| `expectedAudience` | ✅ | El identificador del verificador para esta interacción. |
| `l2RpcUrl` | ✅ | Endpoint RPC de los registros. |
| `didRegistryAddress` | ✅ | Dirección del DID Registry. |
| `issuerRegistryAddress` | ✅ | Dirección del Issuer Registry (lista de confianza). |
| `revocationRegistryAddress` | — | Necesaria si `checkRevocation` está activo. |
| `holderRpcUrl` | — | Solo si las cuentas de holders viven en otra cadena que los registros. Default: `l2RpcUrl`. |
| `maxClockSkew` | — | Tolerancia horaria en segundos. Default `60`. |
| `checkRevocation` | — | Consulta el mapa on-chain. |
| `offlineMode` | — | ⚠️ Ver abajo. |

> 🔴 **`offlineMode` no verifica nada.** Saltea **todas** las lecturas on-chain: sin
> firma del emisor, sin lista de confianza, sin prueba del holder. Solo comprueba la
> estructura. Puede devolver `valid: true` para una credencial que nadie firmó. Es
> para demos offline y tests, nunca para producción. Un resultado así viene marcado
> con `trustNotVerified: true` — **chequealo siempre** antes de confiar en un
> `valid: true`.

### Lo que hace `verify()`

1. Parsea la presentación SD-JWT.
2. Decodifica el JWT del emisor (`iss`, `sub`, `vct`, `cnf`, `exp`, `iat`, `_sd`).
3. Resuelve el DID del emisor → llave pública de `assertionMethod` (DID Registry).
4. Verifica la firma del emisor (ES256).
5. Comprueba que el emisor sea de confianza (`IssuerRegistry.isTrusted()`).
6. Verifica cada disclosure contra los digests `_sd`.
7. Comprueba la expiración.
8. Comprueba la revocación (si `checkRevocation`).
9. Resuelve el DID del holder → passkeys activas de su cuenta on-chain.
10. Verifica el KB-JWT (assertion WebAuthn o ES256 crudo).
11. Valida `aud`, `nonce`, `iat` y `sd_hash` del KB-JWT.
12. Devuelve el resultado.

Es la misma cascada que corre la verificación hospedada.

### Resultado

```ts
interface VerificationResult {
  valid: boolean;
  error?: string;
  errorCode?: ErrorCode;
  claims?: Record<string, unknown>;  // solo lo que el holder divulgó
  issuer?: string;
  holder?: string;
  credentialType?: string;           // el vct
  issuedAt?: number;
  expiresAt?: number;
  offlineMode?: boolean;
  trustNotVerified?: boolean;        // true → el resultado NO prueba nada
  revocationChecked?: boolean;
}
```

## Consultar solo la revocación

Si ya validaste la credencial por otro medio y solo querés saber si sigue vigente:

```ts
import { checkRevocation } from "@sovrahq/verification-sdk";

const revocada = await checkRevocation(
  "https://rpc.sovra.io",
  "0xEF9DaDA18C3f46147FD1Ed7a3C1F0ba37ac9d6F7", // revocation registry
  issuerAddress,   // dirección del emisor (del DID: did:sovra:0x<address>)
  79328,           // statusListIndex, del claim credentialStatus
  0,               // 0 = revocación, 1 = suspensión
);
```

El `statusListIndex` y la dirección del emisor salen de la propia credencial: el
claim `credentialStatus` trae el índice, y el `iss` trae el DID del emisor cuyo sufijo
`0x…` es la dirección.

También podés hacerlo sin SDK, descargando la lista firmada desde
[`GET /api/v1/status/{issuer_did}/{purpose}`](06-ciclo-de-vida-y-revocacion.md#consultar-la-lista-de-estado)
— aunque eso sí depende de la API de Sovra.

## Códigos de error del SDK

| Código | Significado |
|---|---|
| `PARSE_ERROR` | La presentación no parsea. |
| `NOT_A_DOCUMENT` | Se esperaba un documento firmado y no lo es. |
| `NOT_ANCHORED` | La firma del documento no está anclada on-chain. |
| `ISSUER_SIG_INVALID` | La firma del emisor no valida. |
| `ISSUER_NOT_FOUND` | El DID del emisor no resuelve. |
| `ISSUER_NOT_TRUSTED` | El emisor no está en la lista de confianza. |
| `ISSUER_DEACTIVATED` | El emisor fue desactivado. |
| `DISCLOSURE_MISMATCH` | Una disclosure no corresponde a ningún digest `_sd`. |
| `CREDENTIAL_EXPIRED` | Pasó el `exp`. |
| `CREDENTIAL_REVOKED` | El bit de revocación está en 1. |
| `HOLDER_NOT_FOUND` | La cuenta del holder no resuelve. |
| `HOLDER_DEACTIVATED` | La cuenta del holder fue desactivada. |
| `KB_JWT_MISSING` | La presentación no trae prueba del holder. |
| `KB_PASSKEY_SIG_INVALID` | La firma de la passkey no valida. |
| `KB_NONCE_MISMATCH` | El nonce no coincide con `expectedNonce`. |
| `KB_AUDIENCE_MISMATCH` | La audiencia no coincide con `expectedAudience`. |
| `KB_SD_HASH_MISMATCH` | El `sd_hash` no coincide con las disclosures. |
| `KB_EXPIRED` | La prueba del holder está vencida. |
| `IAT_INVALID` | `iat` fuera de la tolerancia horaria. |
| `SUBJECT_KEY_MISMATCH` | El `sub` no coincide con `cnf.kid`. |
| `INVALID_ADDRESS` | Una dirección de contrato no es válida. |

## Comparación entre ambos caminos

| | Hospedada | On-chain (SDK) |
|---|---|---|
| Qué integrás | 2 llamadas HTTP + 1 webhook | Una librería + acceso RPC |
| Quién genera nonce y audience | Sovra | Vos |
| Quién muestra el QR | Vos (`authorization_request_uri`) | Vos (armás el request OID4VP) |
| Dependencia de la API de Sovra | Sí | No |
| Dependencia de SovraChain | Indirecta | Directa (necesitás RPC) |
| Comprobaciones criptográficas | Idénticas | Idénticas |
| Esfuerzo de integración | Bajo | Medio |

Se pueden combinar: usar la verificación hospedada en el flujo interactivo, y el SDK
para re-auditar credenciales guardadas o para verificar en un entorno aislado.

---

**Anterior:** [← 8. Errores y troubleshooting](08-errores-y-troubleshooting.md) · **Siguiente:** [10. Verificar por WhatsApp →](10-whatsapp.md)
