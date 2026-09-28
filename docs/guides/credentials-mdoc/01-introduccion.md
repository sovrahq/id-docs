# 1. Introducción

## Tabla de contenidos

1. [Qué es un mDoc](#qué-es-un-mdoc)
2. [docType y namespace](#doctype-y-namespace)
3. [Las dos rutas de confianza](#las-dos-rutas-de-confianza)
4. [Anatomía de un `IssuerSigned`](#anatomía-de-un-issuersigned)
5. [El namespace meta de Sovra](#el-namespace-meta-de-sovra)
6. [Divulgación selectiva, elemento por elemento](#divulgación-selectiva-elemento-por-elemento)
7. [El flujo completo, de extremo a extremo](#el-flujo-completo-de-extremo-a-extremo)
8. [Responsabilidades de cada parte](#responsabilidades-de-cada-parte)
9. [Glosario](#glosario)

---

## Qué es un mDoc

Un **mDoc** es una credencial definida por **ISO/IEC 18013-5**, la norma de la
licencia de conducir móvil (**mDL**). La diferencia con un SD-JWT VC no es de
contenido, sino de codificación y de firma:

- Los datos se representan en **CBOR**, no en JSON.
- La firma es un **MSO** (*Mobile Security Object*): una estructura `COSE_Sign1`
  que no contiene los valores, sino un **digest SHA-256 por cada elemento**.
- Cada elemento viaja por separado, como un `IssuerSignedItem` con su propia sal.

Los tres roles son los mismos que en [credenciales SD-JWT](../credentials/01-introduccion.md#los-tres-roles)
—emisor, holder y verificador— y también el principio de fondo: **el emisor y el
verificador nunca se comunican entre sí**.

Razones para adoptarlo:

| Razón | Detalle |
|---|---|
| **Interoperabilidad** | Un lector conforme a ISO 18013-5 lee la credencial sin conocer nada de Sovra. |
| **Exigencia normativa** | Una licencia de conducir digital que aspire a ser reconocida fuera de su jurisdicción debe ser un mDL. |
| **Atestaciones de edad** | `age_over_18` es un booleano firmado: acredita la edad **sin** revelar la fecha de nacimiento. |

Y lo que actualmente **no** contempla:

> ⚠️ **La presentación por proximidad no está implementada.** El *device
> engagement* de ISO 18013-5 —BLE, handover NFC, cifrado de sesión— constituye
> otra pila de transporte, no una variante del flujo en línea. Todo lo descrito
> en estas guías corresponde al **perfil en línea**: OID4VP con
> `response_mode=direct_post`.

## docType y namespace

Son **dos cadenas distintas**, y confundirlas es el error de mayor impacto de
esta documentación.

- El **docType** identifica el *tipo de documento*. Se firma dentro del MSO y es
  lo que una consulta DCQL declara en `meta.doctype_value`.
- El **namespace** indica *dónde residen los elementos de datos*. Un documento
  puede contener varios.

ISO asocia el docType `org.iso.18013.5.1.mDL` con el namespace
`org.iso.18013.5.1`. Uno no se deriva del otro: es necesario conocer ambos.

Sovra contempla dos casos:

| | **docType generado** (opción por defecto) | **docType ISO** (definido por el operador) |
|---|---|---|
| Valor | `io.sovra.<dirección-del-workspace>.<schema_id>.1` | Por ejemplo `org.iso.18013.5.1.mDL` |
| Namespace | La misma cadena que el docType | Distinta — `org.iso.18013.5.1` |
| Quién lo determina | Sovra, a partir del esquema | El operador, al crear el esquema |
| Requisito | Ninguno | Un **document signer** registrado ([guía 8](08-habilitar-mdl-iso.md)) |
| Atribución al emisor | La dirección está contenida en el docType | La cadena `x5chain` del certificado |

> El docType se deriva en lugar de aceptarse como entrada por tres motivos:
> garantiza unicidad sin necesidad de una restricción, incorpora una dirección de
> emisor que el verificador puede leer, y permanece estable ante un cambio de
> nombre del workspace. La contrapartida es la legibilidad, que resulta
> irrelevante: un docType está destinado a las máquinas, y en el dashboard se
> muestra siempre en modo de solo lectura.

> ⚠️ **El docType constituye un contrato de interoperabilidad.** Se firma dentro
> del MSO y lo declara cada consulta DCQL. Modificarlo deja huérfanas todas las
> credenciales ya emitidas; por ese motivo el `format` de un esquema es
> **inmutable** entre revisiones.

## Las dos rutas de confianza

Un verificador debe responder una pregunta: *¿quién firmó esta credencial?*
Según el docType, la respuesta proviene de una u otra fuente.

```
   docType del mDoc
         │
         ├── io.sovra.0xab…ef.licencia.1
         │      → contiene la dirección del emisor
         │      → se resuelve contra SovraChain (registro de emisores)
         │      → no requiere ningún certificado
         │
         └── org.iso.18013.5.1.mDL
                → no contiene dirección alguna
                → se resuelve por la cadena x5chain del COSE_Sign1
                → requiere un Document Signer Certificate que encadene
                  a una raíz IACA que el verificador acepte
```

Por este motivo, **declarar un docType ISO exige un document signer**: una
credencial conforme no dispone de un lugar donde alojar una dirección de emisor,
y la cadena de certificados pasa a ser la única vía de atribución. Si el
workspace no tiene un signer registrado, la emisión falla con
`document_signer_required`.

## Anatomía de un `IssuerSigned`

Lo que la wallet recibe en la emisión es un `IssuerSigned`: los namespaces junto
con el `issuerAuth`. **No** se trata de un `DeviceResponse` completo, dado que el
holder aún no dispone de nada que firmar: la autenticación de dispositivo está
vinculada a la sesión de un verificador, y en la emisión esa sesión no existe.

En el webhook se entrega como **CBOR en base64url sin padding**. Una vez
decodificado:

```
IssuerSigned
├── nameSpaces
│   ├── "org.iso.18013.5.1"  → [ #6.24(bstr .cbor IssuerSignedItem), ... ]
│   └── "io.sovra.meta.1"    → [ #6.24(bstr .cbor IssuerSignedItem), ... ]
└── issuerAuth               → COSE_Sign1( MobileSecurityObject )
```

Cada `IssuerSignedItem` presenta la siguiente estructura:

```
{
  "digestID": 0,
  "random": h'…',                 // la sal
  "elementIdentifier": "family_name",
  "elementValue": "Lovelace"
}
```

Y el MSO firmado contiene:

| Campo | Significado |
|---|---|
| `version` | `"1.0"` |
| `digestAlgorithm` | `"SHA-256"` |
| `docType` | El tipo de documento. **Debe coincidir** con el del `IssuerSigned`, o la verificación falla. |
| `valueDigests` | Un digest por elemento, agrupado por namespace. |
| `deviceKeyInfo.deviceKey` | La llave pública P-256 del holder, en formato COSE. Es aquello a lo que queda vinculada la credencial. |
| `validityInfo` | `signed`, `validFrom`, `validUntil`. |

> **Detalle de implementación frecuentemente omitido:** los `valueDigests` se
> calculan sobre `#6.24(bstr .cbor IssuerSignedItem)` —el envoltorio tag-24
> codificado—, no sobre el mapa sin envolver. Una implementación incorrecta
> produce una credencial de apariencia válida que falla en toda verificación.

> ⚠️ ISO establece `validityInfo.validUntil` como obligatorio, por lo que **un
> mDoc no puede expresar la ausencia de vencimiento**. Un esquema sin
> `validity_period` emite mDocs con **un año** de vigencia. Únicamente el SD-JWT
> admite la omisión del `exp`.

## El namespace meta de Sovra

ISO no define dónde alojar varios elementos que el modelo de Sovra requiere, de
modo que estos se transportan en un namespace reservado, **`io.sovra.meta.1`**,
como elementos de datos ordinarios: firmados por el emisor igual que cualquier
otro y, por tanto, resistentes a manipulación.

| Elemento | Justificación |
|---|---|
| `holder_did` | Un mDoc identifica a su holder únicamente por la `deviceKey`, una llave COSE sin DID alguno. Sovra resuelve las llaves **actualmente autorizadas** del holder en la cadena: sin el DID no hay nada que resolver, y una passkey ya rotada conservaría su validez indefinidamente. |
| `status_list_index` | mDoc carece de campo `credentialStatus`, por lo que el índice en el mapa de revocación debe viajar dentro de la credencial. |
| `issuer_address` | La dirección EOA del workspace, clave de indexación de los mapas de revocación y del Issuer Trust Registry. Un docType ISO no la incorpora, y el certificado identifica la *llave*, no la dirección. |
| `namespace` | Dónde residen los elementos del documento. Es lo que permite a la wallet mostrar `family_name` en lugar de `org.iso.18013.5.1.family_name`. |
| `mandatory_elements` | El conjunto `disclosable: false`, **congelado en la emisión** y agrupado por namespace. |
| `credential_type` | Lo que el emisor registró en la cadena, en el Issuer Trust Registry. |

> **El verificador exige los seis elementos.** Constituyen insumos de control
> aportados por la parte sujeta a control: un holder que pudiera omitir
> `mandatory_elements` eludiría la verificación de elementos obligatorios, y uno
> que omitiera `status_list_index` eludiría la revocación. Un namespace meta
> incompleto es un fallo definitivo, no una ausencia de metadatos —lo cual
> resulta seguro precisamente porque la totalidad del namespace está cubierta por
> la firma del emisor.

## Divulgación selectiva, elemento por elemento

En un SD-JWT el holder selecciona *disclosures*. En un mDoc selecciona
**elementos de datos**, con idéntico efecto: la firma del emisor conserva su
validez sobre aquello que se compartió, porque cada elemento dispone de su propio
digest en el MSO.

Lo que el holder **no** puede retener son los elementos marcados
`disclosable: false`. Dicho conjunto se congela dentro de la credencial en el
momento de la emisión, con dos consecuencias:

- Un verificador no necesita consultar el esquema para determinar cuáles son.
- Una revisión posterior del esquema **no puede** modificar retroactivamente qué
  debe divulgar una credencial ya emitida.

En el dashboard, estos elementos se identifican con la etiqueta **`always
shared`**. Véase [Primeros pasos](02-primeros-pasos.md#required-frente-a-always-shared).

## El flujo completo, de extremo a extremo

### Emisión (OID4VCI)

```
  Servidor de la               Sovra API                Wallet del ciudadano
  integración                       │                             │
       │ POST /credential-offer      │                             │
       │  { schema_id, claims }      │                             │
       ├────────────────────────────►│                             │
       │                             │                             │
       │ 201 { credential_id,        │                             │
       │       offer_uri, ... }      │                             │
       │◄────────────────────────────┤                             │
       │                             │                             │
       │  Se renderiza offer_uri ────────── escanea ──────────────►│
       │  como QR                    │                             │
       │                             │◄─ token + prueba COSE ──────┤
       │                             │                             │
       │                             │  Codifica cada claim al      │
       │                             │  tipo CBOR del esquema,      │
       │                             │  compone el MSO y lo firma   │
       │                             │  (COSE_Sign1, ES256, KMS)    │
       │                             │                             │
       │                             │── IssuerSigned (mso_mdoc) ─►│
       │                             │                             │
       │ webhook credential.issued   │                             │
       │  { format: "mso_mdoc",      │                             │
       │    doc_type, credential }   │                             │
       │◄────────────────────────────┤                             │
```

La oferta tiene una vigencia de **24 horas**. El `credential_id` obtenido al
crearla constituye el identificador de todo el ciclo de vida.

> La prueba de posesión del holder en un mDoc es una **llave COSE**, no una
> referencia `cnf` a un DID como en el SD-JWT. **Sin llave del holder la emisión
> no es posible**: no existe elemento al que vincular la credencial.

### Verificación (OID4VP)

```
  Servidor de la               Sovra API                Wallet del ciudadano
  integración                       │                             │
       │ POST /verifications         │                             │
       │  { dcql_query: mso_mdoc }   │                             │
       ├────────────────────────────►│                             │
       │ 201 { session_id,           │                             │
       │  authorization_request_uri* }│                            │
       │◄────────────────────────────┤                             │
       │                             │                             │
       │  Se renderiza la URI ─────────────  escanea ─────────────►│
       │  como QR                    │                             │
       │                             │   la wallet identifica por   │
       │                             │   docType; el ciudadano      │
       │                             │   aprueba elemento por       │
       │                             │   elemento                   │
       │                             │◄── DeviceResponse ──────────┤
       │                             │    (direct_post)             │
       │                             │                             │
       │  webhook presentation.      │  Sovra valida: firma del     │
       │  verified / failed          │  emisor, digests, namespace  │
       │◄────────────────────────────┤  meta, obligatorios, device  │
       │                             │  auth, vigencia, revocación  │
```

La sesión tiene una vigencia de **10 minutos**.

> ⚠️ **Un mDoc exige `response_uri`.** El *session transcript* que vincula una
> presentación a un verificador incorpora el hash de esa URL. Un flujo que carezca
> de ella no dispone de nada que hashear, y emplear un valor de relleno
> constituiría una convención privada que únicamente el verificador de Sovra
> podría seguir. En ese supuesto, la wallet rechaza presentar el mDoc.

## Responsabilidades de cada parte

| Responsabilidad | Responsable |
|---|---|
| Definir el esquema mDoc, su docType y su namespace | El operador, desde el dashboard |
| Obtener el Document Signer Certificate (solo docTypes ISO) | El operador, ante su autoridad emisora |
| Admitir la raíz IACA en la lista de confianza | El administrador de plataforma |
| Determinar a quién emitir y con qué datos | El servidor de la integración (`POST /credential-offer`) |
| Codificar cada valor al tipo CBOR de ISO | Sovra |
| Firmar el MSO (`COSE_Sign1`, ES256, llave en KMS) | Sovra |
| Almacenar la credencial | La wallet del ciudadano — **Sovra no almacena el mDoc firmado** |
| Seleccionar qué elementos se comparten | El ciudadano, salvo los `always shared` |
| Validar una presentación | Sovra, o un verificador propio (véase la [guía 7](07-verificacion-sin-sovra.md)) |
| Publicar el estado de revocación | Sovra, en SovraChain |

> ⚠️ **El mDoc firmado se entrega una única vez**, en el webhook
> `credential.issued`. No se conserva del lado del servidor y no existe endpoint
> para solicitarlo nuevamente. Si el sistema receptor lo requiere, **debe
> persistirse en el momento de su recepción**.

## Glosario

| Término | Definición |
|---|---|
| **mDoc** | Credencial ISO/IEC 18013-5, codificada en CBOR y firmada con un MSO. |
| **mDL** | *Mobile Driving Licence*. El mDoc de docType `org.iso.18013.5.1.mDL`. |
| **CBOR** | *Concise Binary Object Representation*. La codificación binaria empleada por ISO 18013-5. |
| **COSE_Sign1** | La estructura de firma de CBOR, equivalente a un JWS compacto. |
| **MSO** | *Mobile Security Object*. El payload firmado: docType, digests, `deviceKey` y vigencia. |
| **`IssuerSigned`** | Lo que entrega el emisor: los namespaces con sus elementos y el `issuerAuth`. |
| **`DeviceResponse`** | Lo que presenta la wallet: el `IssuerSigned` reducido y el `DeviceSigned`. |
| **`IssuerSignedItem`** | Un elemento: `digestID`, sal, identificador y valor. |
| **docType** | El tipo de documento, firmado en el MSO. |
| **Namespace** | Dónde residen los elementos. `org.iso.18013.5.1` en el caso de un mDL. |
| **Elemento de datos** | El equivalente mDoc de un claim. Se denomina `[namespace, identificador]`. |
| **IACA** | *Issuing Authority Certificate Authority*. La raíz de confianza de un emisor de mDL. |
| **DSC** | *Document Signer Certificate*. El certificado que firma los mDocs de un workspace. |
| **`x5chain`** | La cadena de certificados transportada en la cabecera del `COSE_Sign1`. |
| **Device authentication** | La firma que el holder añade a cada presentación. El equivalente mDoc del KB-JWT. |
| **DCQL** | *Digital Credentials Query Language*. Con `format: "mso_mdoc"` y rutas de dos segmentos. |

---

**Siguiente:** [2. Primeros pasos →](02-primeros-pasos.md)
