# Ingreso a Moodle con la SuperApp (`auth_sovra`)

Plugin de autenticación de Moodle para que una persona entre **sin usuario ni contraseña**, presentando la
**Credencial de Identidad** de la SuperApp de Bogotá. Moodle la identifica por su **número de documento**.

- Moodle 4.5 LTS y 5.1. PHP, sin dependencias fuera del núcleo.
- Verificación OID4VP con [Sovra ID](../../apps/docs/src/content/docs/verificadores/sd-jwt-vc/verificar.mdx): Sovra valida la firma del emisor, la posesión, la vigencia y la revocación.
- **No crea cuentas.** Si no hay una cuenta con ese documento, muestra «No tienes cuenta en Atenea» y el enlace al registro.
- Las cuentas con método manual o email siguen pudiendo entrar también con su contraseña (las de método `sovra`, sólo con la SuperApp).

## Cómo funciona

1. En la pantalla de ingreso aparece **«Ingresar con la SuperApp»**.
2. Moodle abre una verificación en Sovra que pide sólo el **emisor**, el **tipo de credencial** y el **número de documento** (y el tipo de documento, si se activa la comparación por tipo). Muestra un QR y un botón **«Abrir en la SuperApp»** para quien ya está en el teléfono.
3. La persona comparte la credencial desde la billetera. La página consulta el resultado cada 3 segundos (la billetera no vuelve a la página). Sin webhook funciona igual; con webhook, el resultado no depende de que la API responda en cada consulta.
4. Moodle controla que el emisor esté en la lista de **emisores permitidos**, busca la cuenta por documento y la deja entrar sólo si hay **exactamente una**.

| Resultado | Qué ve la persona |
|---|---|
| Una cuenta, habilitada | Entra a Moodle, a la página que intentaba abrir |
| Ninguna cuenta | «No tienes cuenta en Atenea» + enlace al registro |
| Dos o más cuentas | Aviso de que contacte a soporte; nadie entra |
| Cuenta suspendida, sin confirmar, con método no aceptado o deshabilitado, o administradora del sitio | Aviso; nadie entra |
| Credencial de otro emisor o de otro tipo | «Esa credencial no está aceptada»; nadie entra |
| La billetera rechaza o Sovra no valida la credencial | «No pudimos validar tu credencial» |
| Pasan 10 minutos sin presentación | El QR vence; botón para generar otro |
| Recarga la página | Ve el mismo QR mientras le queden más de 2 minutos |
| Pide más de 5 códigos en 10 minutos | Aviso de que espere unos minutos |

## La credencial: el esquema en Sovra

El plugin no emite: acepta una credencial de identidad que emite otro workspace (el emisor). Con la configuración por
defecto, ese esquema tiene que cumplir tres cosas, y el resto es libre (los nombres se pueden cambiar en
«Tipos de credencial aceptados», «Claim del número de documento» y «Claim del tipo de documento»):

| Campo del esquema | Valor | Por qué |
|---|---|---|
| `credential_type` | `IdentidadCiudadano` | Viaja como `vct`. El plugin lo pide en el DCQL (`meta.vct_values`, para que la billetera ofrezca esta credencial) y lo compara en el servidor, distinguiendo mayúsculas (si «Tipos de credencial aceptados» está vacío, no se controla). **No se puede cambiar después de crear el esquema.** |
| claim `num_doc` | `string`, sólo dígitos | El número de documento, como lo guarda Atenea (sólo números). Como `string` para no perder los ceros a la izquierda, que sí cuentan. |
| claim `tipo_doc` | `string`, con la **sigla de Atenea** | Sólo se pide si se compara el tipo. Valores: `RC`, `PPT`, `CC`, `TI`, `CE`, `PTP`, `PEP`, `PIP`, `NES`, `PA`, `NIT`, `SC`, los mismos que guarda `profile_field_tipo_documento`. Una sigla se compara directo, sin distinguir mayúsculas ni tildes, así que no necesita línea en «Equivalencias de tipos». |

`iss` y `vct` no se declaran: van en todo SD-JWT. El `schema_id` y los demás claims no los usa el plugin, y
no los pide: sólo sirven para emitir y para lo que muestre la billetera.

**Opción 1 — mínima**, sólo lo que usa el plugin:

```json
{
  "schema_id": "bogota_identidad",
  "credential_type": "IdentidadCiudadano",
  "name": "Bogota Identidad",
  "validity_period": "1y",
  "claims": [
    {"key": "tipo_doc", "type": "string", "required": true, "disclosable": true, "label": "Tipo de documento"},
    {"key": "num_doc",  "type": "string", "required": true, "disclosable": true, "label": "Número de documento"}
  ]
}
```

**Opción 2 — con datos básicos de la persona** (la publicada en el issuer de prueba), para que la credencial sirva también fuera de Moodle:

```json
{
  "schema_id": "bogota_identidad",
  "credential_type": "IdentidadCiudadano",
  "name": "Bogota Identidad",
  "validity_period": "1y",
  "claims": [
    {"key": "tipo_doc",         "type": "string", "required": true,  "disclosable": true, "label": "Tipo de documento"},
    {"key": "num_doc",          "type": "string", "required": true,  "disclosable": true, "label": "Número de documento"},
    {"key": "nombres",          "type": "string", "required": false, "disclosable": true, "label": "Nombres"},
    {"key": "apellidos",        "type": "string", "required": false, "disclosable": true, "label": "Apellidos"},
    {"key": "fecha_nacimiento", "type": "string", "required": false, "disclosable": true, "label": "Fecha de nacimiento (AAAA-MM-DD)"},
    {"key": "nacionalidad",     "type": "string", "required": false, "disclosable": true, "label": "Nacionalidad"}
  ]
}
```

Se crea en el workspace **emisor** y se espera a que quede `registered` (no hay webhook de registro):

```bash
curl -s -X POST "$SOVRA_API_URL/api/v1/issuer/schemas" \
  -H "Authorization: Bearer $SOVRA_API_KEY_EMISOR" -H 'Content-Type: application/json' \
  -d @esquema-identidad.json

curl -s "$SOVRA_API_URL/api/v1/issuer/schemas" -H "Authorization: Bearer $SOVRA_API_KEY_EMISOR" \
  | jq '.schemas[] | select(.schema_id=="bogota_identidad") | {version, registration_status, registration_error}'
```

Una oferta de prueba (`POST /api/v1/issuer/credential-offer`), con un documento que exista en Moodle:

```json
{"schema_id": "bogota_identidad", "claims": {"tipo_doc": "CC", "num_doc": "10203045"}}
```

Después, el DID de ese workspace va en **Emisores permitidos**: en prueba, el del issuer de prueba; en producción, el de la
Alcaldía (o los dos, uno por línea).

> **Si el sitio ya tenía instalada una versión anterior**, la opción «Tipos de credencial aceptados» quedó
> guardada como `IdentidadMigrante`: cambiar el valor por defecto no la pisa. Ponerla en `IdentidadCiudadano` (o
> `IdentidadCiudadano, IdentidadMigrante` para aceptar las dos). Si no, el registro muestra `typenotallowed` con
> `vct=IdentidadCiudadano`.

## Instalación

1. Copiar la carpeta `sovra/` a `auth/sovra/` del Moodle (en Moodle 5.1: `public/auth/sovra/`). **Copiar, no enlazar con symlink**: las páginas del plugin buscan `config.php` dos carpetas arriba.
2. Entrar como administrador y completar la actualización (o `php admin/cli/upgrade.php`).
3. **Administración del sitio → Plugins → Autenticación → Gestionar autenticación**: habilitar «Ingreso con la SuperApp (Sovra ID)».
4. Completar la configuración (abajo) y apretar **«Probar la conexión»** en **Administración del sitio → Informes → Registro del ingreso con la SuperApp**.

No hace falta bloquear los campos del documento: el plugin no deja que la persona los cambie desde su perfil (ver «Seguridad»).

## Configuración

| Opción | Valor para Atenea | Notas |
|---|---|---|
| URL de la API | `https://api.sovra.io` (prueba: `https://test-api-sovra.flagonsa.com`) | **Viene apuntando a prueba:** cambiarla para producción |
| API key | La del **workspace verificador de Atenea** (`sovra_sk_…`) | Se guarda cifrada |
| Secreto del webhook | El del endpoint creado en la consola de Sovra | Opcional |
| Workspace ID | El del workspace de Atenea | Opcional: ignora avisos de otros workspaces |
| **Emisores permitidos** | El DID del workspace de la Alcaldía que emite la Credencial de Identidad | **Obligatorio.** Vacío = el botón no aparece |
| Tipos de credencial aceptados | `IdentidadCiudadano` | El `vct` de la Credencial de Identidad |
| Claim del número de documento | `num_doc` | |
| Claim del tipo de documento | `tipo_doc` | Sólo si se compara el tipo |
| Campo con el número de documento | `profile_field_numero_documento` | Atenea: sólo números, obligatorio y único. La persona no lo puede cambiar desde su perfil |
| Comparar también el tipo de documento | **Sí** | Ver «Tipos de documento» abajo: evita que un número de otro tipo entre a una cuenta ajena |
| Campo con el tipo de documento | `profile_field_tipo_documento` | Siglas: RC, PPT, CC, TI, CE, PTP, PEP, PIP, NES, PA, NIT, SC |
| Equivalencias de tipos | El valor por defecto (abajo) | `IdentidadCiudadano` ya trae la sigla y no las usa; sirven para la credencial anterior (`IdentidadMigrante`), que trae el nombre largo, sólo si ese tipo sigue en «Tipos de credencial aceptados» |
| Métodos de autenticación aceptados | `manual`, `email`, `sovra` | Los de las cuentas existentes |
| Permitir administradores del sitio | No | Recomendado |
| Página de registro | `https://academia.agenciaatenea.gov.co/registrarse/` | |
| Nombre de la plataforma | `Atenea` | Para el aviso «No tienes cuenta en Atenea». Vacío: el nombre corto del sitio |

El número se compara **sin puntos, espacios ni guiones y sin distinguir mayúsculas**, tanto el de la credencial
como el de Moodle: `1.020.304-5` y `10203045` coinciden. Los ceros a la izquierda **sí** cuentan.

### Tipos de documento (Atenea)

`IdentidadCiudadano` trae en `tipo_doc` la misma sigla que guarda Atenea (`CC`, `PPT`, `PA`…), así que se compara
directo. La credencial anterior (`IdentidadMigrante`) trae en cambio el nombre largo; para ella son las
equivalencias por defecto, que no afectan a las siglas. Sólo se usan si `IdentidadMigrante` sigue en «Tipos de
credencial aceptados»; si no, esa credencial se rechaza antes (`typenotallowed`). Una sigla en la credencial no
encuentra una cuenta que guarde el nombre largo: las equivalencias van sólo de la credencial a Moodle.

```
Cédula de ciudadanía = CC
Cédula de extranjería = CE
Pasaporte = PA
PPT = PPT
PEP = PEP
Salvoconducto = SC
```

**Comparar el tipo importa aunque en Atenea el número sea único:** el número es único dentro de Atenea, pero la credencial puede traer un número de otro tipo de documento que coincida con el de una cuenta ajena (por ejemplo, un documento extranjero con los mismos dígitos que una cédula). Comparando el tipo, eso da «No tienes cuenta en Atenea» en lugar de abrir la cuenta equivocada.

Dos tipos de la credencial anterior **no tienen sigla en Atenea** y quedan sin equivalencia a propósito, así que esas personas ven «No tienes cuenta en Atenea»:

- **PEP Tutor**: el permiso es otro documento que el PEP; equipararlo a `PEP` sólo si Atenea confirma que lo registra así.
- **DNI del país de origen**: es un documento extranjero sin equivalente en la lista de Atenea.

Los tipos de Atenea sin contraparte en esa credencial (RC, TI, PTP, PIP, NES, NIT) no necesitan línea.

### Limitaciones conocidas

- **Pasaportes con letras.** El número se compara tal como llega (sólo se quitan puntos, espacios y guiones, y se pasa a mayúsculas). Atenea guarda el documento sólo con números, así que una persona cuyo pasaporte tiene letras no va a encontrar su cuenta. Pendiente de definir con Atenea.
- **PEP Tutor y DNI del país de origen** no entran (ver arriba), por decisión.

Para un despliegue automatizado, los valores se pueden fijar en `config.php`:

```php
$CFG->forced_plugin_settings['auth_sovra'] = [
    'apiurl' => 'https://api.sovra.io',
    'apikey' => getenv('SOVRA_API_KEY'),
    'allowedissuers' => 'did:sovra:0x…',
    'documentfield' => 'profile_field_numero_documento',
    'matchdoctype' => 1,
    'documenttypefield' => 'profile_field_tipo_documento',
    'signupurl' => 'https://academia.agenciaatenea.gov.co/registrarse/',
    'platformname' => 'Atenea',
];
```

### Webhook (opcional)

En la consola de Sovra, en el workspace de Atenea, crear un endpoint con la URL
`https://<moodle>/auth/sovra/webhook.php` y los eventos `presentation.verified` y `presentation.failed`.
Sin webhook el ingreso funciona igual: la página consulta el resultado a la API. Ante una firma inválida el plugin responde 503 (no 401): así, si el secreto estaba mal copiado, al corregirlo Sovra entrega los reintentos pendientes.

## Seguridad

- **Emisores permitidos, falla cerrada.** Sin esta lista cualquier workspace de la red podría emitirse una credencial con un documento ajeno. El plugin pide el claim `iss` de la credencial y compara el DID de su ruta con la lista.
- **Exactamente una cuenta.** Con duplicados nadie entra.
- **Sesión atada al navegador.** La verificación sólo la puede consultar y completar el navegador que la abrió (token aleatorio en la sesión de Moodle + `sesskey`), una sola vez y dentro de los 5 minutos de verificada.
- **Verificaciones con freno.** Sólo el botón de la pantalla de ingreso (con `sesskey`) abre una verificación en Sovra; un enlace de otro sitio o un bot sin cookies no gastan cuota. El tope es por sesión de Moodle: un bot que guarda cookies puede pedir otra sesión. Recargar muestra el mismo código, y cada navegador puede pedir 5 códigos nuevos cada 10 minutos. Para un tope por IP, configurarlo en el servidor web.
- **Cuentas sin confirmar o con un método deshabilitado.** No entran, igual que con contraseña.
- **Nadie cambia su propio documento.** Si la persona pudiera editarlo desde su perfil, podría poner el documento de otra: esa otra quedaría como duplicada, o, si no tiene cuenta, entraría con la SuperApp a la cuenta de quien lo puso. Por eso el plugin descarta el cambio del número (y del tipo, si se compara) cuando quien edita es la propia persona; le avisa y lo anota en el registro (`documentlocked`). Lo siguen pudiendo cambiar los administradores, las sincronizaciones y los servicios web con permiso para editar cuentas. No se bloquea el campo, porque un campo de perfil bloqueado también queda congelado en el formulario de registro. Con autorregistro, quien se registra elige su documento: eso lo tiene que controlar el proceso de registro.
- **Sin datos personales guardados.** Ni el número ni el tipo de documento ni el DID de la persona se guardan en la base ni en el registro; sólo el resultado, ids técnicos (verificación, evento) y el id de la cuenta. Si una credencial se rechaza por emisor, tipo o falta de documento, el registro anota el DID del emisor, el `vct` y los nombres de los claims recibidos (sin sus valores), para corregir la configuración.
- **Errores a la vista del administrador.** El registro anota las excepciones del webhook y de la consulta, las consultas sin sesión válida (como mucho una por minuto) y la causa de una configuración rota. Si la API key o el secreto no se pueden descifrar (por ejemplo, después de migrar el sitio), lo avisan el registro y «Probar la conexión».
- **Riesgo propio del ingreso por QR:** quien muestre un QR de ingreso a otra persona y la convenza de escanearlo entra como ella. La billetera muestra el nombre del verificador antes de compartir; conviene que el nombre de la organización de Atenea en Sovra sea claro.

## Pruebas

Con Docker, `scripts/probar-en-docker.sh` levanta Moodle 4.5 con PostgreSQL, instala el plugin, corre sus
pruebas y deja el sitio en `http://localhost:8001` (admin / test). Con `MOODLE_BRANCH=MOODLE_501_STABLE`,
lo mismo en 5.1. En un Moodle propio:

```bash
# Moodle 4.5
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite auth_sovra_testsuite
# Moodle 5.1
php public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite auth_sovra_testsuite
```

La especificación, con cada escenario, está en
[`openspec/changes/agregar-autenticacion-moodle`](../../openspec/changes/agregar-autenticacion-moodle/).
