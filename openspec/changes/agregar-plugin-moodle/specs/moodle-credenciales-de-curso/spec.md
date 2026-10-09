## ADDED Requirements

### Requirement: Registro del derecho al completar un curso
El plugin SHALL registrar que un estudiante tiene derecho a la credencial de un curso cuando Moodle marca ese curso como completado para él, y SHALL avisarle con una notificación de Moodle que enlaza a «Mis credenciales». El estudiante es el usuario **relacionado** del evento de completitud, no quien lo dispara (que suele ser el cron). El registro SHALL ser único por estudiante y curso.

#### Scenario: Curso habilitado completado
- **WHEN** Moodle registra la completitud de un curso habilitado para emitir, para el estudiante E
- **THEN** existe un derecho de E para ese curso en estado «disponible», con un código de verificación propio
- **AND** E recibe una notificación de Moodle con un enlace a «Mis credenciales»

#### Scenario: Curso no habilitado
- **WHEN** la configuración limita la emisión a una lista de cursos y se completa un curso que no está en la lista
- **THEN** no se registra ningún derecho ni se envía notificación

#### Scenario: Completitud repetida
- **WHEN** el evento de completitud llega dos veces para el mismo estudiante y curso
- **THEN** hay un único derecho y una única notificación

#### Scenario: El estudiante es el relacionado, no el actor
- **WHEN** el evento lo dispara un usuario distinto del estudiante (el cron o un docente que marca la completitud a mano)
- **THEN** el derecho queda a nombre del estudiante que completó el curso
- **AND** la notificación está en el idioma del estudiante, no en el de quien disparó el evento

### Requirement: La oferta se crea cuando el estudiante la pide
El plugin MUST NOT crear ofertas al completarse el curso. SHALL crear la oferta OID4VCI en la API de Sovra recién cuando el estudiante, autenticado y con una acción protegida contra CSRF, toca «Recibir en mi cartera» sobre un derecho propio. Un derecho MUST NOT tener más de una oferta canjeable: mientras Sovra informe que la oferta anterior sigue pendiente, SHALL reutilizarla, y sólo SHALL crear otra cuando Sovra informe que venció. Dos solicitudes simultáneas del mismo derecho MUST NOT crear dos ofertas.

#### Scenario: Primera solicitud
- **WHEN** el estudiante toca «Recibir en mi cartera» sobre un derecho disponible
- **THEN** el plugin crea una oferta con el esquema configurado y los claims del curso y del estudiante
- **AND** guarda el identificador de la credencial, la URI de la oferta y su vencimiento
- **AND** el derecho pasa a «oferta creada»

#### Scenario: Oferta vigente
- **WHEN** el estudiante vuelve a pedirla y Sovra informa que la oferta anterior sigue pendiente, aunque le falten pocos minutos
- **THEN** se muestra la misma oferta y no se crea otra

#### Scenario: Oferta vencida
- **WHEN** el estudiante la pide y Sovra informa que la oferta anterior venció
- **THEN** el plugin crea una nueva

#### Scenario: URI de oferta inesperada
- **WHEN** la API devuelve una `offer_uri` que no empieza con `openid-credential-offer://`
- **THEN** no se guarda ni se muestra, y el error queda registrado

#### Scenario: Ya se emitió aunque no llegó el aviso
- **WHEN** el estudiante la pide y la consulta muestra que la oferta anterior ya fue canjeada
- **THEN** el derecho pasa a «emitida» y no se crea una oferta nueva

#### Scenario: Falta un dato obligatorio
- **WHEN** falta un claim obligatorio del esquema (por ejemplo, el documento del estudiante está vacío)
- **THEN** no se llama a la API, el estudiante ve un mensaje que no expone detalles internos
- **AND** el derecho queda con el error visible para el administrador

#### Scenario: La API rechaza o no responde
- **WHEN** la API responde con error o no responde
- **THEN** el estudiante ve un mensaje para reintentar más tarde y el error queda registrado en el derecho

#### Scenario: Derecho ajeno
- **WHEN** un usuario intenta pedir la oferta de un derecho que no es suyo, o sin la clave de sesión
- **THEN** la solicitud se rechaza y no se crea ninguna oferta

### Requirement: Presentación de la oferta en computadora y teléfono
La página «Mis credenciales» SHALL mostrar cada oferta vigente como un código QR que codifica la URI de la oferta **sin modificarla**, y un botón que abre esa misma URI (`openid-credential-offer://…`) como enlace directo. En un teléfono o tableta el botón SHALL ir primero; en computadora, el QR. El QR SHALL generarse en el servidor, sin recursos de terceros.

#### Scenario: Computadora
- **WHEN** el estudiante abre la página desde una computadora con una oferta vigente
- **THEN** ve el QR primero y el vencimiento de la oferta

#### Scenario: Teléfono
- **WHEN** la abre desde un teléfono
- **THEN** ve primero el botón «Abrir mi cartera», cuyo destino es la URI de la oferta

#### Scenario: Sin recursos externos
- **WHEN** se renderiza el QR
- **THEN** el plugin no agrega scripts, estilos ni imágenes de fuera del propio Moodle (lo que cargue el tema o los filtros del sitio es configuración del sitio)

### Requirement: Los claims de la credencial
Los datos que viajan en la credencial SHALL definirse en código, para que cualquier cambio pase por revisión. Para el esquema de prueba `bogota-formacion` SHALL enviarse `holderName`, `documento`, `programa`, `curso`, `horas`, `entidadFormadora`, `fechaFinalizacion` y `codigoVerificacion`, más `nivel` sólo cuando tiene valor. El campo del perfil del que sale `documento` SHALL ser configurable, y la configuración SHALL advertir que ese campo, el nombre y el apellido no tienen que ser editables por el estudiante, porque la institución firma esos valores. La fecha SHALL viajar como texto ISO 8601 (`AAAA-MM-DD`).

#### Scenario: Claims completos
- **WHEN** se arma la credencial de un estudiante con todos los datos
- **THEN** las claves coinciden carácter por carácter con las del esquema y la fecha es la de completitud del curso en formato `AAAA-MM-DD`

#### Scenario: Nivel vacío
- **WHEN** el curso no tiene nivel
- **THEN** la clave `nivel` no se envía

#### Scenario: Documento desde un campo de perfil propio
- **WHEN** el administrador eligió un campo personalizado del perfil como origen del documento
- **THEN** `documento` toma el valor de ese campo para el estudiante

### Requirement: Recepción de avisos de Sovra (webhook)
El plugin SHALL exponer un endpoint que no use la sesión de Moodle y que valide cada entrega con `X-Sovra-Signature: sha256=<HMAC-SHA256 hex del cuerpo crudo>` en comparación de tiempo constante. SHALL responder 2xx a toda entrega con firma válida —incluidas las repetidas, las desconocidas y las malformadas— porque un 4xx hace que Sovra descarte el evento sin reintentar. SHALL ser idempotente por el `id` de la entrega.

#### Scenario: Credencial emitida
- **WHEN** llega `credential.issued` con firma válida para una oferta creada por el plugin
- **THEN** el derecho pasa a «emitida» con el DID del titular y la fecha de emisión, y se responde 200

#### Scenario: Firma inválida
- **WHEN** llega una entrega con firma ausente o incorrecta
- **THEN** se responde 401, no cambia ningún derecho y queda registrada la hora del último rechazo para el administrador

#### Scenario: Entrega repetida
- **WHEN** llega dos veces la misma entrega (mismo `id`)
- **THEN** la segunda responde 200 sin volver a procesarse

#### Scenario: Credencial ajena al plugin
- **WHEN** llega un evento válido de una credencial que el plugin no creó
- **THEN** se responde 200 sin cambios

#### Scenario: Cambios de estado posteriores
- **WHEN** llega `credential.revoked`, `credential.suspended` o `credential.unsuspended` con firma válida
- **THEN** el derecho refleja «revocada», «suspendida» o «emitida» respectivamente

#### Scenario: Evento desordenado
- **WHEN** llega un evento cuyo `timestamp` es anterior al del último cambio aplicado a ese derecho
- **THEN** no pisa el estado actual

#### Scenario: Falla interna al aplicar el evento
- **WHEN** la entrega tiene firma válida pero guardarla falla (por ejemplo, un error de la base)
- **THEN** se responde 500, para que Sovra reintente, y la entrega no queda marcada como procesada

#### Scenario: Plugin sin secreto configurado
- **WHEN** llega una entrega y el secreto del webhook no está configurado
- **THEN** se responde 503, para que Sovra reintente cuando la configuración esté completa

### Requirement: Reconciliación sin depender del webhook
Una tarea programada SHALL consultar a la API el estado de las ofertas creadas y no confirmadas, y una vez por día el de las credenciales emitidas o suspendidas, de modo que el flujo —incluidas revocaciones y suspensiones— funcione aunque los avisos de Sovra no lleguen. Con más candidatos que los que entran en una corrida, SHALL consultar primero los consultados hace más tiempo.

#### Scenario: Oferta canjeada sin aviso
- **WHEN** la tarea consulta una oferta que la API informa como `issued`
- **THEN** el derecho pasa a «emitida»

#### Scenario: Oferta vencida sin canjear
- **WHEN** la API informa `expired`
- **THEN** el derecho vuelve a «disponible» para que el estudiante pueda pedir una nueva

#### Scenario: Oferta todavía pendiente
- **WHEN** la API informa `pending`
- **THEN** el derecho no cambia

#### Scenario: API inaccesible
- **WHEN** la consulta falla
- **THEN** ningún derecho cambia y la próxima ejecución vuelve a intentarlo

#### Scenario: Más ofertas abiertas que las de una corrida
- **WHEN** hay más ofertas abiertas que las que entran en una corrida
- **THEN** las corridas siguientes consultan las que quedaron afuera

#### Scenario: Revocación sin webhook
- **WHEN** una credencial emitida fue revocada en Sovra y el aviso no llegó
- **THEN** a más tardar al día siguiente el derecho figura como «revocada»

### Requirement: Conexión con la API de Sovra
Toda llamada a la API SHALL autenticarse con `Authorization: Bearer sovra_sk_…` y SHALL salir por el cliente HTTP del núcleo de Moodle, que respeta la lista de hosts y puertos bloqueados de la seguridad HTTP del sitio. El plugin MUST NOT usar `x-api-key`, `did:quarkid` ni DIDComm. La API key y el secreto del webhook MUST NOT aparecer en registros, mensajes de error ni páginas.

#### Scenario: Autenticación
- **WHEN** el plugin crea una oferta
- **THEN** la petición lleva `Authorization: Bearer` con la API key configurada y ningún otro mecanismo de autenticación

#### Scenario: Host bloqueado por la seguridad HTTP del sitio
- **WHEN** el host de la API está bloqueado en la seguridad HTTP de Moodle
- **THEN** la llamada no sale y el error registrado lo indica

### Requirement: Reporte para el administrador
El plugin SHALL ofrecer a quien tenga la capacidad de verlo un reporte con los derechos por estado, el último error de cada uno, la hora del último webhook rechazado, una prueba de conexión que no crea ofertas, y acciones para consultar el estado de una oferta y para reenviar el aviso al estudiante.

#### Scenario: Acceso restringido
- **WHEN** un usuario sin la capacidad abre el reporte
- **THEN** se le niega el acceso

#### Scenario: Prueba de conexión
- **WHEN** el administrador prueba la conexión
- **THEN** el plugin llama a la API con el esquema configurado y sin claims, y el resultado distingue «llave inválida», «esquema inexistente» y «conexión correcta»
- **AND** si el esquema tiene campos obligatorios no se crea ninguna oferta; si no los tiene, el resultado avisa que se creó una oferta de prueba que vence sola

#### Scenario: Reintento
- **WHEN** el administrador pide consultar el estado de una oferta o reenviar el aviso
- **THEN** se ejecuta esa acción para ese derecho y el reporte muestra el resultado

### Requirement: Privacidad
El plugin SHALL declarar a la API de privacidad de Moodle qué datos personales guarda y cuáles envía a Sovra, y SHALL permitir exportarlos y borrarlos por usuario.

#### Scenario: Exportación y borrado
- **WHEN** se procesa un pedido de exportación o de borrado de datos de un estudiante
- **THEN** sus derechos se exportan o se borran, respectivamente

### Requirement: Compatibilidad
El plugin SHALL instalarse y funcionar en Moodle 4.5 LTS y en Moodle 5.x sin cambios de código.

#### Scenario: Instalación en 4.5
- **WHEN** se instala en un Moodle 4.5
- **THEN** la instalación termina sin errores y las pruebas automáticas del plugin pasan
