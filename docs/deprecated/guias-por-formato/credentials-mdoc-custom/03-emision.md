# 3. Emisión

El endpoint es el mismo que para SD-JWT; lo que determina el formato es el esquema. Lo que cambia es la codificación de cada tipo, que en CBOR es estricta.

## Tabla de contenidos

1. [Emisión](#emision)
2. [Codificación por tipo](#codificacion-por-tipo)

---

## Emisión

El endpoint es el mismo que para SD-JWT; lo que determina el formato es el esquema.
`claims` se envía **plano e indexado por `key`**: el namespace procede del esquema y
la API no lo solicita.

```bash
curl -X POST "$BASE_URL/api/v1/issuer/credential-offer" \
  -H "Authorization: Bearer $SOVRA_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "schema_id": "driving_licence_v2",
    "valid_until": "2030-10-22T23:59:59Z",
    "claims": {
      "given_name": "Sofía",
      "family_name": "Demo",
      "second_family_name": "Lambda",
      "birth_date": "1990-01-01",
      "document_number": "NL-2026-0001",
      "issue_date": "2026-10-22",
      "expiry_date": "2030-10-22",
      "driving_privileges": [
        {
          "vehicle_category_code": "B",
          "issue_date": "2026-10-22",
          "expiry_date": "2030-10-22",
          "codes": [{"code": "01", "sign": "<=", "value": "70"}]
        }
      ],
      "portrait": "/9j/4AAQSkZJRgABAQ...",
      "signature_usual_mark": "iVBORw0KGgoAAAANSUhEUg...",
      "issuing_authority": "Secretaría de Movilidad y Planeación Urbana",
      "street": "Av. Constitución",
      "exterior_number": "1234",
      "interior_number": "5B",
      "neighborhood": "Centro",
      "resident_city": "Monterrey",
      "resident_postal_code": "64000",
      "issuing_country": "MX",
      "age_over_18": true,
      "curp": "DELS900101MNLXXX01",
      "organ_donor": true,
      "blood_type": "O+",
      "allergies": "Penicilina",
      "emergency_phone": "8181234567"
    }
  }'
```

La respuesta `201` contiene `credential_id`, `offer_uri` (el QR,
`openid-credential-offer://…`), `pre_authorized_code`, `expires_at` (24 h) y
`credential`, una vista previa sin firmar. **La credencial firmada se entrega una sola
vez**, en el webhook `credential.issued`.

> ⚠️ **`valid_until` y `expiry_date` deben coincidir.** Son cosas distintas —el primero
> es el `validUntil` del MSO; el segundo, un claim legible— y, de divergir, la
> credencial declara una fecha y se comporta según otra. `valid_until` sobrescribe el
> `validity_period` del esquema para esa licencia; **`null` se rechaza**, y omitir la
> clave no equivale a enviarla en null.

### Codificación por tipo

| Tipo | Qué se envía | En caso contrario |
|---|---|---|
| `date` | `"YYYY-MM-DD"`, diez caracteres exactos | `422 unencodable_claims` |
| `datetime` | ISO 8601 **con offset** (`…Z` o `+02:00`) | ídem |
| `bytes` | base64 **estándar con padding**, sin espacios ni alfabeto URL-safe | ídem |
| `integer` | Entero JSON, nunca una cadena | ídem |
| `number` | Entero, o float cuya parte decimal sea cero (`170.0` sí, `170.5` no) | ídem |
| `boolean` | `true` / `false`, nunca `"true"` | ídem |
| `driving_privileges` | Array no vacío; `vehicle_category_code` obligatorio y no vacío; `sign` y `value` son **texto** | ídem |

Un claim que el esquema **no declara se descarta en silencio**: la emisión recorre los
claims del esquema, no lo que se envía. Un error tipográfico en una clave no produce
error, sino una credencial sin ese dato.

Un domicilio sin número se emite sin inventar nada: `exterior_number` admite `"S/N"`, y
un `interior_number` inexistente sencillamente no se envía.

---

**Anterior:** [← 2. Crear el esquema](02-crear-un-esquema.md) · **Siguiente:** [4. Imágenes: foto y firma →](04-imagenes.md)
