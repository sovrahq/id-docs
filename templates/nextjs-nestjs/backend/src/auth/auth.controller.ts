import { Controller, Post, Body, BadRequestException, UnauthorizedException } from '@nestjs/common';
import { AuthService } from './auth.service';
import { VerificationsService } from '../verifications/verifications.service';
import { JwtService } from '@nestjs/jwt';

@Controller('auth')
export class AuthController {
  constructor(
    private authService: AuthService,
    private verificationsService: VerificationsService,
    private jwtService: JwtService,
  ) {}

  @Post('login/by-verification')
  async loginByVerification(@Body() body: { presentation_id: string, user_id: string }) {
    const { presentation_id, user_id } = body;

    if (!presentation_id) {
      throw new BadRequestException('presentationId is required');
    }

    if (!user_id) {
      throw new BadRequestException('userId is required');
    }

    await this.verificationsService.markAsUsed(presentation_id, user_id);

    const user = await this.authService.findUserById(user_id);

    if (!user) {
      throw new BadRequestException('User not found');
    }

    const payload = { sub: user.id, email: user.email, id: user.id };
    const token = this.jwtService.sign(payload);

    return {
      token,
    };
  }
}

