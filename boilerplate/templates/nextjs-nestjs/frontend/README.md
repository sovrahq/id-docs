# Frontend - Sovra ID Boilerplate

Frontend Next.js con integración a Sovra ID para gestión de credenciales verificables y autenticación con wallet.

## Características

- ✅ Registro de usuarios
- ✅ Conexión de wallet mediante QR
- ✅ Inicio de sesión con wallet mediante QR
- ✅ Dashboard básico
- ✅ Escucha de eventos en tiempo real con Supabase

## Configuración

### 1. Variables de Entorno

Crea un archivo `.env.local` en la raíz del proyecto:

```bash
# API Configuration
NEXT_PUBLIC_API_BASE_URL=http://localhost:3001

# Supabase Configuration (para eventos en tiempo real)
NEXT_PUBLIC_SUPABASE_URL=your_supabase_url_here
NEXT_PUBLIC_SUPABASE_ANON_KEY=your_supabase_anon_key_here
```

**Nota:** Para que funcione la escucha de eventos en tiempo real, necesitas configurar Supabase para conectarse a tu base de datos PostgreSQL o usar la base de datos de Supabase.

### 2. Instalación

```bash
pnpm install
```

## Desarrollo

Ejecuta el servidor de desarrollo:

```bash
pnpm dev
```

Abre [http://localhost:3000](http://localhost:3000) en tu navegador.

## Vistas

### 1. Registro de Usuario (`/register`)
- Formulario para crear una cuenta
- Campos: nombre, apellido, email, contraseña
- Después del registro, redirige a la página de conexión de wallet

### 2. Conexión de Wallet (`/link-wallet`)
- Genera un código QR para conectar la wallet del usuario
- El usuario escanea el QR con su wallet
- Opción de omitir y continuar al dashboard

### 3. Inicio de Sesión (`/login`)
- Genera un código QR para iniciar sesión
- Escucha eventos de Supabase para detectar cuando la verificación se completa
- Redirige automáticamente al dashboard cuando la verificación es exitosa

### 4. Dashboard (`/dashboard`)
- Vista básica del usuario autenticado
- Muestra el ID del usuario
- Opción de cerrar sesión

## Flujo de Autenticación

1. **Registro:**
   - Usuario se registra con email y contraseña
   - Se crea el usuario en el backend
   - Se obtiene un token JWT

2. **Conexión de Wallet:**
   - Usuario solicita conectar su wallet
   - Se genera un QR con la invitación
   - Usuario escanea el QR con su wallet
   - El backend recibe el webhook cuando se asocia la credencial

3. **Inicio de Sesión:**
   - Usuario solicita iniciar sesión
   - Se genera un QR de verificación
   - Usuario escanea el QR con su wallet
   - El frontend escucha eventos de Supabase
   - Cuando la verificación se completa, se obtiene el token JWT
   - Usuario es redirigido al dashboard

## Componentes UI

El proyecto usa componentes de [shadcn/ui](https://ui.shadcn.com/) con el estilo "new-york":
- Button
- Input
- Card
- Label

## Tecnologías

- **Next.js 16** - Framework React
- **TypeScript** - Tipado estático
- **Tailwind CSS** - Estilos
- **shadcn/ui** - Componentes UI
- **Supabase** - Eventos en tiempo real
- **Axios** - Cliente HTTP
- **qrcode.react** - Generación de códigos QR
