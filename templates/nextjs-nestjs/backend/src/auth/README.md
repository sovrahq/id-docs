# Servicio de Autenticación

Servicio simple para validar tokens JWT sin usar Passport.

## Uso

### 1. Proteger un endpoint con el Guard

```typescript
import { Controller, Get, UseGuards } from '@nestjs/common';
import { AuthGuard } from '../auth/auth.guard';
import { CurrentUser } from '../auth/decorators/current-user.decorator';
import { User } from '../users/entities/user.entity';

@Controller('profile')
export class ProfileController {
  @Get()
  @UseGuards(AuthGuard) // Protege el endpoint
  getProfile(@CurrentUser() user: User) {
    // El usuario está disponible automáticamente
    return {
      id: user.id,
      email: user.email,
    };
  }
}
```

### 2. Validar token manualmente

```typescript
import { AuthService } from '../auth/auth.service';

constructor(private authService: AuthService) {}

async someMethod(token: string) {
  const user = await this.authService.validateToken(token);
  // Usar el usuario...
}
```

### 3. Enviar token en las peticiones

El token debe enviarse en el header `Authorization`:

```
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

## Componentes

- **AuthService**: Valida tokens JWT y retorna el usuario
- **AuthGuard**: Guard que protege endpoints automáticamente
- **CurrentUser**: Decorador para obtener el usuario del request

