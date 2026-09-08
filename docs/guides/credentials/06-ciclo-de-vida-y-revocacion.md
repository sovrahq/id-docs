# 6. Ciclo de vida y revocación

Una credencial emitida no es inmutable: se puede **suspender** temporalmente o
**revocar** de forma permanente. El estado se publica en SovraChain, así que
cualquier verificador lo ve — no solo los que usan la API de Sovra.

## Tabla de contenidos

1. [Los estados](#los-estados)
2. [Transiciones permitidas](#transiciones-permitidas)
3. [Cambiar el estado](#cambiar-el-estado)
4. [Errores](#errores)
5. [Efecto en la verificación](#efecto-en-la-verificación)
6. [Cómo se publica el estado on-chain](#cómo-se-publica-el-estado-on-chain)
7. [Consultar la lista de estado](#consultar-la-lista-de-estado)

---

## Los estados

| Estado | Significado |
|---|---|
| `pending` | La oferta existe, el ciudadano todavía no la escaneó. |
| `expired` | La oferta venció (24 h) sin que nadie la canjeara. |
| `issued` | La credencial está en la wallet y es válida. |
| `suspended` | Inhabilitada temporalmente. Sigue en la wallet, pero **falla al verificarse**. Es reversible. |
| `revoked` | Anulada de forma **permanente**. No hay vuelta atrás. |

`pending` y `expired` describen la **oferta**; los otros tres describen la
**credencial**. El `credential_id` es el mismo a lo largo de todo el recorrido.

## Transiciones permitidas

```
                 el ciudadano escanea
   pending ──────────────────────────────► issued
      │                                   │    ▲
      │ 24 h                     suspender│    │reactivar
      ▼                                   ▼    │
   expired                            suspended
                                          │
              revocar ───────────────► revoked  (terminal)
                                       ▲
                                       │
                  revocar ─────────────┘ (desde issued)
```

| Desde | Hacia | ¿Permitido? |
|---|---|---|
| `issued` | `revoked` | ✅ |
| `issued` | `suspended` | ✅ |
| `suspended` | `issued` | ✅ (reactivar) |
| `suspended` | `revoked` | ✅ |
| `revoked` | cualquiera | ❌ terminal |
| `pending` | cualquiera | ❌ todavía no hay credencial |
| cualquiera | el mismo estado | ❌ |

> ⚠️ **Las transiciones no son idempotentes.** Revocar dos veces la misma credencial
> devuelve `422 invalid_status_transition` en el segundo intento, no un `200`. Si
> reintentás por red o por cola, tratá ese 422 como "ya estaba en ese estado":
> consultá el estado actual antes de considerarlo un fallo real.

## Cambiar el estado

```http
PUT /api/v1/issuer/credentials/{credential_id}/status/{status}
Authorization: Bearer sovra_sk_...
```

`{status}` es el estado **destino**: `revoked`, `suspended` o `issued`.

```bash
# Revocar (permanente)
curl -X PUT "$BASE_URL/api/v1/issuer/credentials/$CREDENTIAL_ID/status/revoked" \
  -H "Authorization: Bearer $SOVRA_API_KEY"

# Suspender (reversible)
curl -X PUT "$BASE_URL/api/v1/issuer/credentials/$CREDENTIAL_ID/status/suspended" \
  -H "Authorization: Bearer $SOVRA_API_KEY"

# Reactivar una suspendida
curl -X PUT "$BASE_URL/api/v1/issuer/credentials/$CREDENTIAL_ID/status/issued" \
  -H "Authorization: Bearer $SOVRA_API_KEY"
```

`200 OK`:

```json
{ "id": "b662aaad-d940-48fb-be9e-7194c76210ff", "status": "revoked" }
```

Cada cambio exitoso dispara el webhook correspondiente — `credential.revoked`,
`credential.suspended` o `credential.unsuspended` — incluso si el cambio se hizo
desde el dashboard en vez de por API.

Con la API key del workspace alcanza. (En el dashboard, la misma operación pide rol
de administrador del workspace.)

## Errores

| HTTP | `error` | Causa |
|---|---|---|
| 400 | `unknown_status` | El `{status}` no es `revoked`, `suspended` ni `issued`. |
| 401 | `invalid_api_key` | Autorización ausente o inválida. |
| 404 | `credential_not_found` | El UUID no existe en este workspace, o no es un UUID válido. |
| 409 | `workspace_not_provisioned` | El workspace todavía no tiene DID. |
| 422 | `credential_not_yet_issued` | El id corresponde a una oferta `pending`: no hay credencial que revocar. |
| 422 | `invalid_status_transition` | La transición no está permitida (por ejemplo, revocar algo ya revocado). |

## Efecto en la verificación

Una credencial revocada o suspendida **sigue estando en la wallet del ciudadano** y
se ve normal. Lo que cambia es que ya no pasa la verificación:

| Estado | Resultado al verificar |
|---|---|
| `revoked` | `presentation.failed` con `error: "credential_revoked"` |
| `suspended` | `presentation.failed` con `error: "credential_suspended"` |

El corte es inmediato: el estado se consulta on-chain en cada verificación, no hay
caché intermedia del lado del verificador de Sovra.

## Cómo se publica el estado on-chain

Sovra implementa **W3C Bitstring Status List**. La idea:

- Cada workspace mantiene **dos mapas de bits** en SovraChain: uno de revocación y
  otro de suspensión.
- Cada credencial recibe un **índice** (`statusListIndex`) al crearse la oferta. En
  el ejemplo de la [guía 1](01-introduccion.md#anatomía-de-un-sd-jwt-vc) es `79328`,
  el mismo índice en ambos mapas.
- Revocar es poner ese bit en `1` en el mapa de revocación.
- La lista se sirve como una **credencial VC-JWT firmada con ES256**, comprimida.

Ese diseño tiene dos propiedades que importan:

**Privacidad.** Consultar el estado significa descargar el mapa entero de un emisor,
no preguntar por una credencial puntual. Nadie puede inferir a quién se está
verificando.

**Independencia.** El mapa vive en la cadena. Un verificador con acceso RPC a
SovraChain comprueba revocación **sin llamar a la API de Sovra** —
ver [9. Verificación sin Sovra](09-verificacion-sin-sovra.md).

Cada credencial lleva las dos URLs embebidas en su claim `credentialStatus`, así que
un verificador siempre sabe dónde mirar.

## Consultar la lista de estado

Endpoint **público**, sin autenticación:

```http
GET /api/v1/status/{issuer_did}/{purpose}
```

`{purpose}` es `revocation` o `suspension`.

```bash
curl -s "$BASE_URL/api/v1/status/did:sovra:0xcda79e1ee5612c230a6faf68236e3679b0579bae/revocation"
```

Responde `application/jwt`: un VC-JWT firmado por el emisor con la lista comprimida
adentro. Es exactamente la URL que aparece en `statusListCredential` dentro de la
credencial.

La respuesta trae `ETag` y `Cache-Control`. Mandá `If-None-Match` para recibir `304
Not Modified` cuando nada cambió — es lo que hace un verificador que consulta seguido.

| HTTP | Cuándo |
|---|---|
| 200 | Lista firmada. |
| 304 | Sin cambios respecto de tu `If-None-Match`. |
| 400 | `purpose` distinto de `revocation` / `suspension`. |
| 404 | El DID del emisor no existe. |

> Esta ruta es **inmutable por diseño**: las credenciales ya emitidas llevan la URL
> adentro. No cambia entre versiones de la API.

---

**Anterior:** [← 5. Webhooks](05-webhooks.md) · **Siguiente:** [7. Referencia de la API →](07-referencia-api.md)
