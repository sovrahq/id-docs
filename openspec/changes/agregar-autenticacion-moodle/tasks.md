## 1. Base del plugin

- [x] 1.1 Esqueleto `auth_sovra` (`version.php`, `auth.php`, `settings.php`, `lang/en`, `lang/es`, `db/install.xml`, `db/tasks.php`) y verificar que se instala en Moodle 4.5 y 5.1
- [x] 1.2 `config`, `api_client` (verificador), `hmac` y `log` adaptados de `local_sovracred`

## 2. Ingreso

- [x] 2.1 `resolver`: emisor permitido, `vct`, normalización, búsqueda por campo, tipo, cuentas que no pueden entrar; verificar los escenarios de «Sólo emisores permitidos», «Identificación por número de documento» y «Cuentas que no pueden entrar» con PHPUnit
- [x] 2.2 `login_session`: crear, consultar con freno, aplicar veredicto una sola vez, consumir, vencer; verificar «Sesión de ingreso atada al navegador» con PHPUnit
- [x] 2.3 `login.php` (QR + enlace + cuenta regresiva), `status.php`, `finish.php`, `notfound`; botón en `loginpage_idp_list`
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
