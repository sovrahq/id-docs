# ⚠️ Documentación obsoleta (deprecated)

**No uses estas guías para integraciones nuevas.**

Esta carpeta conserva la documentación de la **plataforma anterior** de Sovra ID,
que ya no corresponde a la API en producción. Se mantiene únicamente como
referencia histórica para integraciones antiguas que todavía no migraron.

## Qué cambió

| | Plataforma anterior (esta carpeta) | Plataforma actual ([`../guides/`](../guides/)) |
|---|---|---|
| **Autenticación** | `x-api-key: <clave>` | `Authorization: Bearer sovra_sk_...` |
| **Base URL** | `https://id.api.sandbox.sovra.io/api` | `https://api.sovra.io` (prod) |
| **Método DID** | `did:quarkid:Ei...` | `did:sovra:0x...` (cuenta en SovraChain) |
| **Formato de credencial** | W3C VC JSON-LD + BBS+ / mDoc | **SD-JWT VC** (`vc+sd-jwt`, firma ES256) |
| **Protocolo de emisión** | DIDComm u OID4VCI, según `protocol` | **OID4VCI** con `pre-authorized_code` |
| **Protocolo de verificación** | Presentation Exchange / DIDComm | **OID4VP** con consultas **DCQL** |
| **Revocación** | `credentialStatus` vacío | **Bitstring Status List** publicada on-chain |
| **Eventos de webhook** | `credential-issued`, `verifiable-presentation-finished` | `credential.issued`, `presentation.verified`, `presentation.failed`, … |
| **Firma de webhooks** | No verificada | HMAC-SHA256 en `X-Sovra-Signature` |

Los nombres de campos, los payloads y los endpoints **no son compatibles** entre
una plataforma y otra. Una integración contra la API anterior necesita reescribirse,
no adaptarse.

## Contenido archivado

- [`guides/api-sovra-id.md`](guides/api-sovra-id.md) — referencia de la API anterior.
- [`guides/webhooks.md`](guides/webhooks.md) — webhooks de la API anterior.
- [`resources/api_sovra_id.postman_collection.json`](resources/api_sovra_id.postman_collection.json) — colección de Postman anterior.

## ¿Dónde está la documentación vigente?

👉 **[`docs/guides/`](../guides/)**
