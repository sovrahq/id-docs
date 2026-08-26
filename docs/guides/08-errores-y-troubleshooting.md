# 8. Errores y troubleshooting

## Tabla de contenidos

1. [Errores HTTP de la API](#errores-http-de-la-api)
2. [Errores de presentación](#errores-de-presentación)
3. [`missing_required_claim`](#missing_required_claim)
4. [Problemas frecuentes](#problemas-frecuentes)
5. [Cómo depurar una credencial](#cómo-depurar-una-credencial)

---

## Errores HTTP de la API

Formato de todos los errores:

```json
{ "error": "codigo", "details": { } }
```

### Autenticación

| HTTP | `error` | Causa | Solución |
|---|---|---|---|
| 401 | `invalid_api_key` | Falta el header, la clave es inválida, está revocada, o el prefijo `Bearer ` está mal escrito. | Revisá `Authorization: Bearer sovra_sk_...`. El esquema es sensible a mayúsculas. |
| 403 | `workspace_inactive` | El workspace fue desactivado. | Reactivalo desde el dashboard. |
| 403 | `organization_inactive` | La organización fue desactivada. | Contactá a Sovra. |

### Workspace y esquemas

| HTTP | `error` | Causa | Solución |
|---|---|---|---|
| 404 | `workspace_not_found` | La API key no resuelve a un workspace. | Generá una clave nueva. |
| 409 | `workspace_not_provisioned` | El DID todavía no terminó de generarse. | Esperá unos minutos. Se avisa con `identity.did-generated`. |
| 404 | `schema_not_found` | El `schema_id` no existe en el workspace. | Usá el **slug** del esquema, no el UUID ni el nombre visible. |
| 422 | `wrong_schema_kind` | El esquema es de kind `document` y estás emitiendo a una wallet (o al revés). | Usá un esquema de kind `credential` para ofertas. |
| 409 | `schema_in_use` | Intentaste borrar un esquema con credenciales emitidas. | No se puede. Creá una versión nueva. |
| 409 | `schema_revision_conflict` | Alguien más revisó el esquema mientras vos lo editabas. | Recargá y volvé a aplicar tus cambios. |

### Emisión

| HTTP | `error` | Causa | Solución |
|---|---|---|---|
| 422 | `missing_claims` | Falta un claim `required` del esquema. `details` los lista. | Completá los campos. |
| 422 | `validation_error` | El body no pasó la validación. `details` da el detalle por campo. | Corregí el body. |
| 409 | `bitmap_exhausted` | No hay índices libres en la lista de estado. | Poco frecuente. Reportalo a Sovra. |

### Ciclo de vida

| HTTP | `error` | Causa | Solución |
|---|---|---|---|
| 400 | `unknown_status` | El estado destino no es `revoked`, `suspended` ni `issued`. | Corregí la URL. |
| 404 | `credential_not_found` | El UUID no existe en el workspace, o no es un UUID válido. | Verificá el `credential_id`. |
| 422 | `credential_not_yet_issued` | El id corresponde a una oferta `pending`. | No hay credencial que revocar todavía. |
| 422 | `invalid_status_transition` | La transición no está permitida. | Ver la [tabla de transiciones](06-ciclo-de-vida-y-revocacion.md#transiciones-permitidas). Revocar dos veces cae acá. |

### Verificación

| HTTP | `error` | Causa | Solución |
|---|---|---|---|
| 400 | `missing_dcql_query` | No mandaste `dcql_query`. | Agregalo al body. |
| 400 | `invalid_dcql_query` | `dcql_query` no es un objeto, o `credentials` falta / está vacío. | `credentials` debe tener al menos un elemento. |
| 404 | `session_not_found` | La sesión no existe, o pertenece a otro workspace. | Verificá el `session_id`. |

---

## Errores de presentación

Estos valores llegan en `data.error` del webhook `presentation.failed` (y en el campo
`error` de la sesión). La validación se detiene en el primer fallo, así que el código
identifica **exactamente** el paso que falló.

### Problemas con el formato del `vp_token`

| `error` | Qué pasó | Qué hacer |
|---|---|---|
| `malformed_vp_token` | El `vp_token` no tiene la forma que exige OID4VP para DCQL, o el SD-JWT no parsea. | Casi siempre es una wallet de terceros no compatible. Probá con la wallet de Sovra. |
| `invalid_disclosure` | Una disclosure no corresponde a ningún digest `_sd` del JWT firmado. | Indica manipulación o una wallet con bugs. **Tratalo como intento de fraude.** |

### Problemas con el emisor

| `error` | Qué pasó | Qué hacer |
|---|---|---|
| `unknown_issuer` | El emisor de la credencial no está registrado en Sovra. | La credencial viene de un emisor ajeno a la red. Rechazala. |
| `credential_signature_invalid` | La firma ES256 del emisor no valida. | La credencial fue alterada o está corrupta. **Rechazala.** |

### Problemas con el holder

| `error` | Qué pasó | Qué hacer |
|---|---|---|
| `holder_did_missing` | La credencial no declara `sub`. | Credencial mal formada. |
| `holder_did_unresolvable` | El DID del holder no resuelve a llaves on-chain. | La cuenta puede no estar aprovisionada, o hay un problema de RPC. Pedile al ciudadano que reintente. |
| `holder_key_mismatch` | El KB-JWT no está firmado por una llave del holder. | La credencial no la presenta su dueño. **Rechazala.** |
| `holder_did_mismatch` | El `iss` del KB-JWT no coincide con el `sub` de la credencial. | Ídem: presentación de un tercero. |

### Problemas con la prueba de presentación (KB-JWT)

| `error` | Qué pasó | Qué hacer |
|---|---|---|
| `holder_nonce_mismatch` | El `nonce` no es el de esta sesión. | Suele ser un intento de **replay** con una presentación vieja. |
| `holder_audience_mismatch` | La `aud` apunta a otro verificador. | La presentación fue armada para otra sesión o para otro workspace. |
| `holder_iat_out_of_range` | El `iat` está fuera de ±5 minutos. | Reloj desincronizado en el teléfono del ciudadano. Pedile que corrija la hora y reintente. |
| `sd_hash_mismatch` | El `sd_hash` no coincide con las disclosures enviadas. | Manipulación entre la firma y el envío. **Rechazala.** |
| `session_expired` | El holder respondió después de los 10 minutos. | Generá un QR nuevo. |

### Problemas de vigencia y estado

| `error` | Qué pasó | Qué hacer |
|---|---|---|
| `credential_expired` | El `exp` de la credencial ya pasó. | Hay que emitir una nueva. |
| `credential_revoked` | El emisor la revocó de forma permanente. | Rechazala. |
| `credential_suspended` | Está suspendida temporalmente. | Rechazala; puede volver a habilitarse. |
| `status_check_failed` | No se pudo leer el estado on-chain. | Problema transitorio de RPC o `credentialStatus` mal formado. Reintentá. |

### Problemas con el DCQL

| `error` | Qué pasó | Qué hacer |
|---|---|---|
| `missing_required_claim:<path>` | El holder no divulgó un claim que pediste, o el claim no existe en la credencial. | Ver [abajo](#missing_required_claim). |
| `format_mismatch:<formato>` | La credencial no es del formato pedido. | El `format` del DCQL debe ser `vc+sd-jwt` o `dc+sd-jwt`. |
| `invalid_dcql_query` | El DCQL de la sesión no tiene la forma esperada al momento de evaluar. | Revisá la estructura. |

---

## `missing_required_claim`

Es, de lejos, el error más frecuente. Tiene **dos causas** muy distintas, y conviene
distinguirlas antes de tocar nada.

### Causa 1 — El claim no existe con ese nombre

El `path` del DCQL debe coincidir **carácter por carácter** con el `key` que definió
el esquema. Este caso real lo muestra bien:

```json
{ "event": "presentation.failed",
  "data": { "error": "missing_required_claim:first_name", "success": false } }
```

La credencial presentada contenía:

```json
["sPwyJ5BurK5HJpYE8QUY3w", "fist_name", "Daniel"]
```

`fist_name`, no `first_name`. El esquema se cargó con una errata, y esa errata quedó
**dentro de la credencial firmada**. El verificador pidió el nombre correcto y por eso
falló, aunque el dato estuviera ahí.

**Cómo diagnosticarlo:** mirá los keys reales del esquema en **Schemas** del
dashboard, o decodificá un SD-JWT ya emitido (ver [abajo](#cómo-depurar-una-credencial)).

**Cómo resolverlo:**

- **Corto plazo:** pedí el `path` tal como está en la credencial (`fist_name`).
- **Largo plazo:** creá una **versión nueva del esquema** con el key corregido. Las
  credenciales viejas conservan el key viejo para siempre, así que durante la
  transición vas a tener que aceptar los dos. Una forma de hacerlo: crear una sesión
  de verificación por variante y aceptar la que salga bien.

> Por esto conviene revisar la ortografía de los keys **antes** de emitir la primera
> credencial. Un key es un contrato permanente entre emisor y verificador.

### Causa 2 — El holder no compartió el campo

En la wallet, el ciudadano aprueba **campo por campo**. Si desmarca uno de los que
pediste, la presentación falla completa: en Sovra **todos los claims del DCQL son
obligatorios**.

**Cómo resolverlo:** pedí menos campos. Es mejor para la privacidad y sube la tasa de
éxito. Si necesitás campos opcionales, creá **dos sesiones**: una con el conjunto
mínimo y otra con el conjunto ampliado.

---

## Problemas frecuentes

### El QR se escanea pero no pasa nada

- **La oferta ya se usó.** Es de un solo uso: si el ciudadano ya la canjeó, ese QR
  quedó muerto. Consultá `GET /credentials/{id}`: si está `issued`, ya funcionó.
- **La oferta venció.** 24 horas. Verificá `expires_at`.
- **La sesión de verificación venció.** 10 minutos. Regenerá el QR.

### No llegan los webhooks

1. ¿Está configurada la **Webhook URL** en **Settings → Workspace**? Sin URL, los
   eventos se descartan sin más.
2. ¿Tu endpoint es **HTTPS y accesible desde internet**? `localhost` no sirve; usá un
   túnel (ngrok, Cloudflare Tunnel) durante el desarrollo.
3. ¿Estás respondiendo **2xx en menos de 5 segundos**? Más lento cuenta como fallo.
4. ⚠️ ¿Estás devolviendo **4xx**? Un 4xx **descarta el evento sin reintentos**. Si tu
   validación de firma rechaza por un secreto mal configurado, perdés el evento.
   Loguéalo mientras integrás.

### La firma del webhook nunca valida

La causa número uno: estás firmando un cuerpo **re-serializado**. `JSON.parse` +
`JSON.stringify` cambia el orden de las claves y el espaciado, y el HMAC deja de
coincidir. Usá el **cuerpo crudo** (`express.raw()`, `request.body` en FastAPI).

La número dos: el secreto es de **otro workspace**. Si varios workspaces apuntan al
mismo endpoint, elegí el secreto según el `workspace_id` del payload.

### `workspace_not_provisioned` que no se va

El aprovisionamiento tarda unos minutos, pero no debería quedar colgado. Si el DID no
aparece después de un rato, en el dashboard hay un **retry de aprovisionamiento**
(**Settings → Workspaces**). Si sigue igual, contactá a Sovra.

### Perdí el SD-JWT firmado

No hay forma de recuperarlo: se entrega **una sola vez**, en el webhook
`credential.issued`, y no se almacena del lado servidor. La credencial sigue siendo
válida en la wallet del ciudadano — lo que perdiste es tu copia. Para el futuro,
persistí `data.credential` al procesar el evento.

### Recibo `presentation.failed` con `holder_did: null`

La falla ocurrió antes de poder identificar al holder — típicamente
`malformed_vp_token` o `session_expired`. No es un problema del ciudadano en
particular.

---

## Cómo depurar una credencial

Un SD-JWT es texto plano: podés inspeccionarlo sin herramientas especiales. Esto es
lo primero que conviene hacer ante cualquier `missing_required_claim`.

```bash
# Guardá el SD-JWT del webhook credential.issued en cred.txt
python3 - <<'PY'
import base64, json

def b64(s):
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))

sd_jwt = open("cred.txt").read().strip()
partes = sd_jwt.split("~")

cabecera, payload, _ = partes[0].split(".")
print("=== HEADER ===")
print(json.dumps(json.loads(b64(cabecera)), indent=2))
print("=== PAYLOAD ===")
print(json.dumps(json.loads(b64(payload)), indent=2, ensure_ascii=False))
print("=== DISCLOSURES (salt, key, valor) ===")
for d in partes[1:]:
    if d:
        print(json.loads(b64(d)))
PY
```

Qué mirar:

- **Los `key` de las disclosures.** Son los `path` exactos que tenés que pedir en el
  DCQL. Acá se ve al instante un `fist_name` con errata.
- **`vct`.** El tipo de credencial. Debe coincidir con el `credential_type` del
  esquema.
- **`exp`.** Segundos Unix. Si ya pasó, vas a recibir `credential_expired`.
- **`credentialStatus[].statusListIndex`.** El índice de esta credencial en los mapas
  de revocación y suspensión.
- **`iss`.** El emisor: `https://<api>/did:sovra:0x…`.

> ⚠️ Decodificar **no verifica nada**. Cualquiera puede fabricar un JWT con el
> contenido que quiera. Para verificar de verdad hace falta comprobar la firma contra
> la llave del emisor: usá la [API de verificación](04-verificacion-de-credenciales.md)
> o el [SDK on-chain](09-verificacion-sin-sovra.md).

---

**Anterior:** [← 7. Referencia de la API](07-referencia-api.md) · **Siguiente:** [9. Verificación sin Sovra →](09-verificacion-sin-sovra.md)
