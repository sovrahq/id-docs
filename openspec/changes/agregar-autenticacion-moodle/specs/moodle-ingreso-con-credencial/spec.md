## ADDED Requirements

### Requirement: Botón de ingreso en la pantalla de login
El plugin SHALL agregar «Ingresar con la SuperApp» a la pantalla de ingreso de Moodle cuando está habilitado como método de autenticación y su configuración está completa (URL de la API, API key y al menos un emisor permitido). Con la configuración incompleta MUST NOT mostrar el botón.

#### Scenario: Configuración completa
- **WHEN** el plugin está habilitado y tiene URL de la API, API key y al menos un emisor permitido
- **THEN** la pantalla de ingreso muestra «Ingresar con la SuperApp», que lleva a la página de ingreso del plugin

#### Scenario: Sin emisores permitidos
- **WHEN** la lista de emisores permitidos está vacía
- **THEN** la pantalla de ingreso no muestra el botón
- **AND** abrir la página de ingreso del plugin directamente muestra que el ingreso con la SuperApp no está disponible, sin llamar a la API

### Requirement: Solicitud de la credencial
Al abrir la página de ingreso, el plugin SHALL crear una verificación en `POST /api/v1/verifier/verifications` con una consulta DCQL de una sola credencial `vc+sd-jwt` que pida `iss`, `vct`, el claim del tipo de documento (sólo si la comparación por tipo está activa) y el claim del número de documento, con los nombres de claim configurables. Si hay tipos de credencial configurados, SHALL enviarlos en `meta.vct_values` para que la billetera ofrezca la credencial correcta. MUST NOT pedir otros datos personales (nombre, correo, fecha de nacimiento). La página SHALL mostrar el QR y el enlace `openid4vp://` (paso por referencia) y consultar el resultado desde el navegador, porque la billetera no vuelve al sitio.

#### Scenario: Página de ingreso
- **WHEN** una persona sin sesión abre la página de ingreso del plugin
- **THEN** el plugin crea una verificación con la consulta descrita
- **AND** la página muestra un QR y un botón con el enlace `openid4vp://` de la verificación, y una cuenta regresiva hasta su vencimiento

#### Scenario: Ya hay sesión iniciada
- **WHEN** una persona con sesión iniciada abre la página de ingreso del plugin
- **THEN** el plugin no crea ninguna verificación y la lleva a la página de inicio

#### Scenario: La API falla
- **WHEN** la API responde un error o no responde al crear la verificación
- **THEN** la página muestra que no se pudo iniciar el ingreso y ofrece reintentar, sin mostrar detalles técnicos ni la API key
- **AND** el registro guarda el estado HTTP y el código de error

### Requirement: Sesión de ingreso atada al navegador
Cada verificación SHALL quedar atada a la sesión del navegador que la pidió mediante un identificador aleatorio guardado sólo en esa sesión. Sólo esa sesión SHALL poder consultar su resultado y completar el ingreso. Cada verificación SHALL servir para un único ingreso y MUST NOT servir después de su vencimiento.

#### Scenario: Otro navegador
- **WHEN** un navegador distinto del que pidió la verificación consulta su estado o intenta completar el ingreso con su identificador
- **THEN** el plugin responde que la sesión de ingreso no existe y nadie entra

#### Scenario: Un solo uso
- **WHEN** la persona ya entró con una verificación y se intenta completar el ingreso otra vez con la misma
- **THEN** el plugin lo rechaza

#### Scenario: Vencida
- **WHEN** la verificación venció sin presentación
- **THEN** la página muestra que el código venció y ofrece generar uno nuevo
- **AND** un resultado que llegue después del vencimiento no permite entrar

### Requirement: Recepción del resultado
El plugin SHALL aceptar el resultado por el webhook `presentation.verified` / `presentation.failed` firmado con HMAC (`X-Sovra-Signature` sobre el cuerpo crudo) y SHALL también consultarlo con `GET /api/v1/verifier/verifications/{id}` mientras la página espera, para no depender del webhook. Un aviso con firma inválida MUST NOT cambiar nada. Un aviso de una verificación que el plugin no creó, o de otro workspace si el Workspace ID está configurado, SHALL ignorarse. Un mismo resultado recibido dos veces SHALL aplicarse una sola vez.

#### Scenario: Llega el webhook
- **WHEN** llega un `presentation.verified` firmado de una verificación pendiente del plugin
- **THEN** el plugin resuelve la cuenta con los claims del aviso y la página que espera lleva a la persona a terminar el ingreso

#### Scenario: Sin webhook
- **WHEN** el webhook no llega (no configurado o bloqueado) y la verificación se completa en Sovra
- **THEN** la consulta periódica de la página obtiene el mismo resultado por `GET` y el ingreso sigue igual

#### Scenario: Firma inválida
- **WHEN** llega un aviso con una firma que no corresponde al cuerpo
- **THEN** el plugin responde 401, no cambia ninguna sesión de ingreso y lo registra

#### Scenario: Presentación rechazada
- **WHEN** llega `presentation.failed` (o el `GET` informa `failed`)
- **THEN** la página muestra que la credencial no pudo validarse y ofrece reintentar, y nadie entra

#### Scenario: Aviso repetido
- **WHEN** el mismo aviso llega dos veces
- **THEN** el segundo no cambia el resultado ya aplicado

### Requirement: Sólo emisores permitidos
El plugin SHALL aceptar sólo credenciales cuyo emisor (el DID del `iss`, que en Sovra es la ruta de la URL del emisor) esté en la lista de emisores permitidos. Con la lista vacía, o si el resultado no trae `iss`, MUST rechazar el ingreso (falla cerrada). Si hay tipos de credencial configurados, el `vct` presentado SHALL estar entre ellos.

#### Scenario: Emisor permitido
- **WHEN** la credencial presentada la emitió un DID de la lista
- **THEN** el plugin sigue con la búsqueda de la cuenta

#### Scenario: Emisor ajeno
- **WHEN** la credencial presentada es válida pero la emitió un DID que no está en la lista
- **THEN** nadie entra, la página muestra que esa credencial no está aceptada y el registro guarda el motivo sin el DID del ciudadano ni su documento

#### Scenario: Tipo de credencial ajeno
- **WHEN** hay tipos de credencial configurados y el `vct` presentado no está entre ellos
- **THEN** nadie entra

### Requirement: Identificación por número de documento
El plugin SHALL identificar la cuenta comparando el número de documento presentado con el campo configurado (`idnumber` o un campo de perfil), ambos normalizados: sin puntos, espacios ni guiones, y sin distinguir mayúsculas. Si la comparación por tipo está activa, SHALL comparar además el tipo de documento presentado, traducido por la tabla de equivalencias configurada, con el campo de tipo configurado. SHALL considerar sólo cuentas no borradas de este sitio. Con exactamente una coincidencia SHALL iniciar la sesión de esa cuenta con `complete_user_login`; con más de una MUST NOT iniciar sesión.

#### Scenario: Una coincidencia
- **WHEN** el documento presentado es `1.020.304-5` y una sola cuenta tiene `10203045` en el campo configurado
- **THEN** la persona entra con esa cuenta y va a la página que intentaba abrir, o al inicio

#### Scenario: Campo de perfil
- **WHEN** el campo configurado es un campo de perfil y el valor está en ese campo
- **THEN** la cuenta se encuentra igual que con `idnumber`

#### Scenario: Ninguna coincidencia
- **WHEN** ninguna cuenta tiene ese documento
- **THEN** el plugin no crea ninguna cuenta y muestra «No tienes cuenta en <plataforma>» con un enlace a la página de registro configurada (o al registro de Moodle si está habilitado y no se configuró otra)

#### Scenario: El aviso nombra a la plataforma
- **WHEN** ninguna cuenta tiene ese documento y el nombre de la plataforma configurado es «Atenea»
- **THEN** el aviso dice «No tienes cuenta en Atenea» y el botón lleva a la página de registro configurada
- **AND** si el nombre de la plataforma está vacío, el aviso usa el nombre corto del sitio

#### Scenario: Duplicados
- **WHEN** dos cuentas tienen el mismo documento
- **THEN** nadie entra y la página indica que hay un problema con la cuenta y que contacte a soporte

#### Scenario: Tipo de documento distinto
- **WHEN** la comparación por tipo está activa y la única cuenta con ese número tiene otro tipo de documento
- **THEN** se trata como «No tienes cuenta en <plataforma>»

#### Scenario: El tipo desempata
- **WHEN** la comparación por tipo está activa y dos cuentas tienen el mismo número con tipos distintos
- **THEN** entra la que coincide en tipo

### Requirement: Cuentas que no pueden entrar
El plugin MUST NOT iniciar sesión en una cuenta suspendida, con método de autenticación `nologin`, con un método de autenticación fuera de la lista de métodos aceptados, ni (salvo que la configuración lo permita) en la cuenta de un administrador del sitio.

#### Scenario: Cuenta suspendida
- **WHEN** la única cuenta coincidente está suspendida
- **THEN** nadie entra y la página indica que la cuenta está suspendida

#### Scenario: Método no aceptado
- **WHEN** la cuenta coincidente usa un método de autenticación que no está en la lista aceptada (por defecto: `manual`, `email` y `sovra`)
- **THEN** nadie entra y la página indica que esa cuenta no puede entrar con la SuperApp

#### Scenario: Administrador del sitio
- **WHEN** la cuenta coincidente es administradora del sitio y la configuración no lo permite
- **THEN** nadie entra

### Requirement: Registro sin datos personales
El plugin SHALL registrar cada llamada a la API y cada aviso del webhook (qué pasó, estado HTTP, resultado, verificación) y el resultado de cada intento de ingreso, y MUST NOT guardar en el registro ni en sus tablas el número de documento, el tipo de documento ni el DID del ciudadano en claro. El administrador SHALL poder ver ese registro y probar la conexión con la API.

#### Scenario: Ingreso registrado
- **WHEN** una persona entra con la SuperApp, o un intento falla por cualquier motivo
- **THEN** el registro tiene una fila con la verificación, el resultado (por ejemplo `ok`, `notfound`, `duplicate`, `issuernotallowed`) y, si entró, el id de la cuenta
- **AND** ninguna tabla del plugin contiene el número de documento presentado

#### Scenario: Prueba de conexión
- **WHEN** el administrador prueba la conexión
- **THEN** el plugin crea una verificación de prueba y muestra si la API key y la URL funcionan, sin mostrar la API key completa
