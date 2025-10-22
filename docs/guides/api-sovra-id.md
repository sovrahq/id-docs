# Documentación Sovra ID API

Esta documentación te guiará paso a paso para configurar tu cuenta, acceder a la API y utilizar todas las funcionalidades disponibles de Sovra ID.

## **¿Qué podrás hacer?**

Con esta guía aprenderás a:

- Obtener el ID del Workspace y verificar la configuración de tu cuenta.
- Crear credenciales verificables con datos personalizados.
- Consultar los detalles de cualquier credencial emitida.
- Gestionar el estado de las credenciales (suspender, revocar o activar).
- Crear verificaciones para validar credenciales de terceros.
- Monitorear el estado de las verificaciones en tiempo real.

## **Estructura de la Guía**

La documentación está organizada en los siguientes pasos:

1. **Configuración inicial** - Registro de usuario y obtención de la `x-api-key`
    1. Pasos para crear cuenta
    2. Verificación de la cuenta
2. **Endpoints** 
    1. **Verificación del Workspace** - Consulta del estado del workspace.
    2. **Creación de credenciales** - Estructura de datos y ejemplos de solicitud.
    3. **Gestión de credenciales** - Consulta de detalles y control de estados.
    4. **Proceso de verificación** - Creación y configuración de verificaciones.

Cada sección incluye ejemplos prácticos, códigos de respuesta y tablas de referencia para facilitar la integración con tu aplicación.

**Nota:** Esta guía está diseñada para el entorno Sandbox.

---

## **Configuración inicial**

Para comenzar a utilizar la API de Sovra ID, necesitas crear una cuenta en el entorno **Sandbox**. Este entorno te permite probar todas las funcionalidades sin afectar datos de producción.

 [Acceder a Sovra Sandbox](https://id.sandbox.sovra.io/auth/signin)

### Pasos para crear cuenta

**Paso 1: Crear Nueva Cuenta**

1. Haz clic en **"¿Don't have an account?"** en la página de inicio de sesión
2. Completa el formulario de registro con la siguiente información:

| Campo | Valor de Ejemplo | Descripción |
| --- | --- | --- |
| **Organization Name** | Integration 01 | Nombre de tu organización |
| **Workspace Name** | Integration 01 | Nombre del workspace inicial |
| **First Name** | John | Tu nombre |
| **Last Name** | Doe | Tu apellido |
| **Email** | name@yourbusiness.com | Email corporativo válido |
| **Password** | ******* | Contraseña segura sobre 6 caracteres |

**Paso 2: Acceder a la Configuración del Workspace**

Una vez completado el registro:

1. **Inicia sesión** con tus credenciales
2. Navega a la sección **"Workspace"** desde el menú principal
3. Encontrarás la información de configuración de tu workspace

**Paso 3: Obtener Credenciales de Acceso**

En la sección de Workspace podrás ver:

- **DID (Decentralized Identifier)**: Tu identificador único descentralizado
- **API Key**: Tu clave de acceso para la API (formato x-api-key)

<aside>
💡

 **Importante**: El DID puede tardar entre **3 y 5 minutos** en generarse después del registro. Si no aparece inmediatamente, espera unos minutos y recarga la página.

</aside>

### Verificación de la cuenta

Una vez que tengas tu `x-api-key`, puedes verificar que todo esté funcionando correctamente haciendo una solicitud de prueba al endpoint de estado del workspace (ver sección siguiente).

---
