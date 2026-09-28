# 8. Habilitar mDL ISO

Esta guía se aplica **únicamente** si la credencial debe llevar el docType oficial
de ISO, `org.iso.18013.5.1.mDL`. Es el caso de una licencia de conducir que aspire
a ser leída por lectores de terceros, fuera de Sovra.

> **Si el docType es el generado por Sovra** (`io.sovra.…`), no hace falta nada de
> esto y se puede emitir de inmediato. Es la opción por defecto y cubre la mayoría
> de los casos. Véase [Elección del docType](02-primeros-pasos.md#elección-del-doctype).

## Por qué hace falta un trámite

Una credencial común de Sovra lleva la dirección del emisor dentro de su propio
identificador, y cualquiera puede resolverla contra SovraChain. **Un mDL conforme
a ISO no lleva ninguna dirección**, porque la norma no previó un campo para eso.

Entonces, ¿cómo sabe un lector quién firmó la licencia? Por un **certificado**, de
la misma forma que un navegador sabe quién está detrás de un sitio HTTPS. Y ese
certificado lo tiene que emitir una **autoridad** reconocida, no Sovra.

De ahí el trámite: conseguir ese certificado.

## Quién hace qué

**Sovra acompaña el proceso; no lo ejecuta.** No somos una autoridad certificante
y no podemos serlo: el valor de un certificado reside precisamente en que lo emite
un organismo reconocido y no el proveedor de software.

| Quién | Qué hace |
|---|---|
| **Sovra** | Entrega la cuenta, guía el procedimiento, prepara la solicitud de certificado desde la consola y registra el resultado. Da soporte en cada paso. |
| **Su organización** | Gestiona el trámite con la autoridad emisora y aporta el certificado que esta devuelve. |
| **La autoridad emisora** | Firma el certificado. Es un organismo externo: en Europa, la autoridad nacional de tránsito; en América, el organismo que corresponda por jurisdicción. |

## Los tres pasos

Todo se realiza desde la consola, en **Settings → Driving licence**. Sovra
acompaña en cada uno.

### Paso 1 — Generar la llave

Un botón: **Create signing key**. Se genera una llave exclusiva para las licencias
de conducir, separada de la que el workspace emplea para el resto de las
credenciales.

No requiere ninguna decisión. Es inmediato.

### Paso 2 — Pedir el certificado a su autoridad

**Ask your authority for a certificate.** Se completan tres datos —país, nombre
del emisor y, si corresponde, provincia y organización— y la consola genera un
archivo de solicitud.

| Dato | Observación |
|---|---|
| **Country** | Código de dos letras (`MX`, `ES`, `AR`). **Debe coincidir con el de la autoridad**, o esta no podrá firmar la solicitud. |
| **Common name** | Cómo se denomina el emisor en el certificado. |
| **State/province**, **Organization** | Opcionales. |

Ese archivo se envía a la autoridad emisora. **Aquí el proceso queda fuera de
Sovra**: los tiempos y los requisitos los fija la autoridad, y pueden ir de días a
meses. Es el paso más largo, y conviene iniciarlo temprano.

### Paso 3 — Registrar el certificado recibido

La autoridad devuelve dos archivos: su **certificado raíz** y el **certificado del
emisor**. Ambos se entregan a Sovra:

1. El certificado raíz se incorpora a la lista de confianza de la plataforma. Lo
   hace Sovra; basta con enviarlo.
2. El certificado del emisor se carga en el paso 3 de la consola.

La consola verifica que todo encaje y, si es correcto, aparece una tarjeta
**Ready**. A partir de ese momento, **Schemas → New → mDoc → ISO driving licence**
queda habilitado y ya se puede emitir.

## Cómo saber si está habilitado

En **Settings → Driving licence**:

- Sin la tarjeta **Ready**, un esquema con docType ISO no emite. Toda oferta falla
  con `document_signer_required`.
- Con la tarjeta **Ready**, se emite con normalidad.

El estado es **por workspace y por entorno**. Habilitarlo en test no lo habilita
en producción: el trámite se repite con un certificado válido para ese entorno.

## Si algo falla

Al cargar un certificado, la consola no responde «certificado inválido»: indica
**qué regla no se cumple**, para poder volver a la autoridad con un dato concreto.
Los mensajes tienen la forma `dsc_profile.country_mismatch` o
`iaca_profile.key_usage`.

Ante cualquiera de ellos, conviene remitir el mensaje exacto a Sovra: la
traducción de cada regla a lo que hay que pedirle a la autoridad figura en el
[anexo técnico](#anexo-2-cuando-el-registro-es-rechazado), y damos soporte en ese
paso.

---

# Anexos técnicos

Lo que sigue está destinado a perfiles técnicos. Para el trámite corriente no es
necesario.

## Anexo 1: Levantar una autoridad propia para desarrollo

Destinado a desarrollo, demostraciones y pilotos, donde no se dispone de una
autoridad real. Los certificados se contrastan con el mismo perfil que cualquier
otro, de modo que el ejercicio reproduce el flujo real en lugar de eludirlo.

> ⚠️ Una raíz autogenerada es una raíz en la que confía únicamente quien la
> generó, y carece de lugar en cualquier entorno cuya lista de confianza resulte
> relevante para un verificador real.

Debe guardarse el archivo de solicitud del paso 2 como `csr.pem` y ejecutarse a
continuación:

```bash
cat > iaca.cnf <<'EOF'
[req]
distinguished_name = dn
prompt = no
x509_extensions = iaca
[dn]
C = ES
CN = Dev IACA
[iaca]
basicConstraints = critical,CA:TRUE,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
EOF

cat > dsc.cnf <<'EOF'
[dsc]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = critical,1.0.18013.5.1.2
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always
EOF

# La raíz, que hace de autoridad. Se envía a Sovra para su admisión.
openssl ecparam -name prime256v1 -genkey -noout -out iaca.key
openssl req -x509 -new -key iaca.key -sha256 -days 3650 -config iaca.cnf -out iaca.pem

# El certificado del emisor, a partir de la solicitud de la propia consola.
# Se carga en el paso 3.
openssl x509 -req -in csr.pem -CA iaca.pem -CAkey iaca.key -CAcreateserial \
  -days 400 -sha256 -extfile dsc.cnf -extensions dsc -out dsc.pem
```

> El valor `C = ES` de `iaca.cnf` debe ser **idéntico al país indicado en el paso
> 2**. Debe modificarse antes de ejecutar el bloque; los heredocs están
> entrecomillados para que los archivos se generen de forma literal.

Cada instrucción responde a un requisito del validador de certificados:

| Ajuste | Regla que satisface |
|---|---|
| `prime256v1` | el analizador admite únicamente un punto P-256 sin comprimir de 65 bytes |
| raíz `CA:TRUE,pathlen:0` | `pathLenConstraint` debe ser 0 o estar ausente |
| raíz `keyCertSign,cRLSign` | ambos son obligatorios en una IACA |
| raíz `C` + `CN` | una IACA debe declarar país y common name |
| raíz autofirmada | cualquier otra configuración se rechaza |
| DSC `CA:FALSE` | un document signer no puede ser CA |
| DSC `digitalSignature` **en exclusiva** | el key usage se compara como lista exacta: añadir `keyEncipherment` provoca el rechazo |
| DSC EKU `1.0.18013.5.1.2` | `id-mdlDS`. Su carácter crítico es admisible: el validador de cadena incluye ese OID en su lista blanca |
| `-days 400` | dentro del límite de 457 días del Anexo B de ISO 18013-5 |
| país del DSC igual al de la raíz | concordancia de subject. Debe añadirse `ST` al DSC únicamente si la raíz declara uno |
| el CSR reutilizado sin modificación | el certificado hereda la llave pública de la solicitud, mecanismo por el cual acaba certificando la llave del workspace |

No existe límite para la vigencia de una IACA, por lo que `-days 3650` constituye
una mera convención. Debe conservarse `iaca.key` si se prevé certificar emisores
adicionales bajo la misma raíz.

## Anexo 2: Cuando el registro es rechazado

El prefijo `iaca_profile.*` corresponde al certificado raíz, `dsc_profile.*` al
del emisor, y `dsc_profile.chain.*` a la relación entre ambos. La respuesta es
`422`:

```json
{ "error": "invalid_certificate", "reason": "dsc_profile.validity_too_long" }
```

| `reason` | Qué pedirle a la autoridad |
|---|---|
| `iaca_profile.not_self_signed` | la raíz debe ser autofirmada — se ha enviado una intermedia |
| `iaca_profile.basic_constraints` | `CA:TRUE` con `pathlen` 0 o ausente |
| `iaca_profile.key_usage` | `keyCertSign` **y** `cRLSign` |
| `iaca_profile.expired` | la raíz está vencida |
| `dsc_profile.key_usage` | `digitalSignature` y ningún otro |
| `dsc_profile.missing_mdl_eku` | añadir la EKU `1.0.18013.5.1.2` |
| `dsc_profile.validity_too_long` | 457 días o menos |
| `dsc_profile.country_mismatch` | el país del emisor debe ser idéntico al de la raíz |
| `dsc_profile.state_mismatch` | la raíz declara una provincia, por lo que el emisor debe declarar la misma |
| `dsc_profile.is_a_ca` | `CA:FALSE`, o ausencia total de `basicConstraints` |
| `dsc_profile.chain.*` | el certificado no fue emitido por la raíz enviada |
| `dsc_profile.certifies_another_key` | debe certificarse la solicitud actual, no una anterior |
| `dsc_profile.not_yet_valid` | la fecha de inicio de validez es futura |

Al retirar una raíz de la que aún dependen workspaces, la respuesta es `409
issuing_authority_in_use`. El rechazo es deliberado: la alternativa consistiría en
un workspace que continúa emitiendo credenciales que ningún verificador acepta, lo
cual se manifestaría como un `unknown_issuer` sin explicación en lugar de como la
retirada que en realidad se produjo.

## Anexo 3: Detalles de implementación

**La llave es independiente de la general.** ISO prevé que el certificado de un
emisor rote según su propio calendario —el Anexo B le fija un techo de 457 días— y
reutilizar la llave general del workspace ataría un formato que no requiere
certificado al ciclo de vida de uno. En desarrollo y test la llave reside en el
almacén de la base de datos; en producción se genera en KMS y no se exporta en
ningún momento.

**La solicitud no se almacena.** Se reconstruye a partir de la llave en cada
ocasión, de modo que no existe copia susceptible de divergir de la llave que
identifica.

**El registro revalida todo.** Es la última instancia de supervisión: que el
certificado se analice correctamente, que encadene a la raíz admitida, que supere
el perfil del Anexo B y —la única comprobación relativa a la corrección y no a la
conformidad— que certifique la llave de **este** workspace. Una cadena válida que
certificara la llave de otro superaría todas las reglas de perfil y produciría
credenciales cuyo `x5chain` afirmaría una vinculación de la que la firma carece.

**Reinicio de la configuración.** No existe endpoint que elimine un emisor
registrado. En una base de datos de desarrollo, el orden correcto es: primero las
llaves, después las columnas y por último las raíces.

```elixir
# mix run, únicamente en entorno de desarrollo
import Ecto.Query
alias Sovra.{Crypto.Key, Repo, Mdl.IssuingAuthority, Workspaces.Workspace}

# `mdl_signing_key_id` es el único identificador del material de llave, por lo
# que las llaves preceden a las columnas que las referencian.
from(w in Workspace,
  where: not is_nil(w.mdl_signing_key_id),
  select: w.mdl_signing_key_id
)
|> Repo.all()
|> Enum.each(&Key.delete_key/1)

Repo.update_all(
  from(w in Workspace, where: not is_nil(w.mdl_signing_key_id)),
  set: [
    mdl_signer_certificate: nil,
    mdl_issuing_authority_id: nil,
    mdl_signing_key_id: nil,
    mdl_signing_public_key_x: nil,
    mdl_signing_public_key_y: nil
  ]
)

Repo.delete_all(IssuingAuthority)
```

Eliminar la llave es lo que confiere consistencia al reinicio: el certificado
emitido por la autoridad identifica una llave pública, de modo que una llave que
permanezca constituye un emisor operativo de un workspace que ya no declara
disponer de uno.

> ⚠️ **Debe eliminarse exclusivamente la llave prevista.** `mdl_signing_key_id`
> corresponde al emisor de licencias; `signing_key_id` —la llave detrás de todas
> las demás credenciales del workspace— es una columna distinta. En KMS, la
> primera es la que tiene el alias
> `alias/sovra/<env>/app/workspace-<workspace id>-mdl-ds`.

Los almacenes se comportan de forma distinta: el de base de datos elimina la fila
y la llave privada desaparece al retornar la llamada; el de KMS programa la
eliminación con una ventana de siete días, durante la cual la llave existe pero no
firma nada y su eliminación puede cancelarse. En ambos casos la llamada es
idempotente.

> Los esquemas ya creados bajo `org.iso.18013.5.1.mDL` **subsisten** a esta
> operación, y no podrán emitir hasta que se registre nuevamente un certificado.

---

**Anterior:** [← 7. Verificación sin Sovra](07-verificacion-sin-sovra.md) · **Volver al [índice](README.md)**
