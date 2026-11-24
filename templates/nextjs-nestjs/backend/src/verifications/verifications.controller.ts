import { Controller, Post, Body, UsePipes, ValidationPipe } from '@nestjs/common';
import { VerificationsService } from './verifications.service';

@Controller('verifications')
@UsePipes(new ValidationPipe({ whitelist: true, forbidNonWhitelisted: true }))
export class VerificationsController {
  constructor(private readonly verificationsService: VerificationsService) {}

  @Post('/login')
  createLoginVerification() {
    return this.verificationsService.createLoginVerification();
  }
}

