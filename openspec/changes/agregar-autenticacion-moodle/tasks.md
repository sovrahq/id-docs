## 1. Base del plugin

- [x] 1.1 Esqueleto `auth_sovra` (`version.php`, `auth.php`, `settings.php`, `lang/en`, `lang/es`, `db/install.xml`, `db/tasks.php`) y verificar que se instala en Moodle 4.5 y 5.1
- [x] 1.2 `config`, `api_client` (verificador), `hmac` y `log` adaptados de `local_sovracred`

## 2. Ingreso

- [x] 2.1 `resolver`: emisor permitido, `vct`, normalización, búsqueda por campo, tipo, cuentas que no pueden entrar; verificar los escenarios de «Sólo emisores permitidos», «Identificación por número de documento» y «Cuentas que no pueden entrar» con PHPUnit
- [x] 2.2 `login_session`: crear, consultar con freno, aplicar veredicto una sola vez, consumir, vencer; verificar «Sesión de ingreso atada al navegador» con PHPUnit
- [x] 2.3 `login.php` (QR + enlace + cuenta regresiva), `status.php`, `finish.php`, el aviso de cuenta inexistente (`login.php?result=notfound`); botón en `loginpage_idp_list`
- [x] 2.4 `webhook.php` + procesador; verificar «Recepción del resultado por webhook» y «por consulta» con PHPUnit y con avisos firmados por `curl`

## 3. Administración y privacidad

- [x] 3.1 Página de registro y prueba de conexión; tarea de limpieza; proveedor de privacidad
- [x] 3.2 Verificar que ninguna tabla guarda documento, tipo ni DID del holder

## 4. Pruebas y entrega

- [x] 4.1 PHPUnit verde en 4.5 y 5.1
- [x] 4.2 Punta a punta en navegador contra el entorno de test de Sovra (QR real); si no hay credencial de test a mano, dejar el paso listo para escanear
  - Hecho 19/11 en 4.5 y 5.1 con resultados simulados (entra / no encontrada / duplicada / suspendida / emisor ajeno / otro navegador) y, en 4.5, escaneo con una Credencial de Identidad real de Bogotá Preview contra test: entra a la cuenta del documento y, sin esa cuenta, muestra el aviso con el enlace al registro.
- [x] 4.3 README de instalación en español para Atenea + entrada en `boilerplate/README.md`
- [x] 4.4 El aviso de cuenta inexistente nombra a la plataforma («No tienes cuenta en Atenea»): ajuste «Nombre de la plataforma», con el nombre corto del sitio si está vacío

## 5. Revisión del PR #14

- [x] 5.1 Textos `matchdoctype` en en y es; cuentas sin confirmar no entran
- [x] 5.2 Abrir verificaciones sólo con `sesskey`, reutilizar el código vigente y tope por navegador
- [x] 5.3 La página de espera nunca va a una URL vacía, avisa sin conexión y vence sola; 401/403/404 cierran el intento
- [x] 5.4 Un solo veredicto registrado (lock por sesión); excepciones del webhook, sesiones inválidas, configuración rota y secretos ilegibles a la vista del administrador
- [x] 5.5 Pruebas de `status.php`/`finish.php` (`poll`/`finish`), privacidad, limpieza y bordes; PHPUnit verde en 4.5 y 5.1
  - Hecho 23/11: 95 pruebas (266 aserciones) en Moodle 4.5 y 5.1 con PHP 8.3 y PostgreSQL, con `scripts/probar-en-docker.sh`

## 6. Segunda revisión

- [x] 6.1 Cuentas con método deshabilitado no entran; aviso si el campo del documento es editable, y opciones de bloqueo de campos
- [x] 6.2 Webhook: 503 ante firma inválida, 500 con evento y verificación ante un error al aplicar
- [x] 6.3 La consulta nunca lanza: una excepción cierra el intento y queda registrada; sin API key también cierra; un lock que no se obtiene se reintenta
- [x] 6.4 Detalle de las credenciales rechazadas, `throttled` registrado, el tope no cuenta las caídas de Sovra, el log de PHP de secretos ilegibles una vez por minuto
- [x] 6.5 `login_session::open()` con pruebas de las puertas de `login.php`; pruebas del lock y de los huecos de la revisión
  - Hecho 23/11: 112 pruebas (340 aserciones) en Moodle 4.5 y 5.1; la página de configuración arma sus 38 ajustes, con el bloqueo de campos

## 7. Documento no editable

- [x] 7.1 La persona no cambia su propio número ni tipo de documento (hook `before_user_updated`), sin bloquear los campos ni tocar el registro; reemplaza los avisos de campo editable
