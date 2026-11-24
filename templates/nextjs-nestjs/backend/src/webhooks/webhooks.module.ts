import { Module } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { WebhooksController } from './webhooks.controller';
import { WebhooksService } from './webhooks.service';
import { User } from '../users/entities/user.entity';
import { Verification } from '../verifications/entities/verification.entity';
import { SovraModule } from '../sovra/sovra.module';
import { VerificationsModule } from '../verifications/verifications.module';
import { AuthModule } from '../auth/auth.module';
import { CredentialsModule } from '../credentials/credentials.module';
import { UsersModule } from '../users/users.module';

@Module({
  imports: [
    TypeOrmModule.forFeature([User, Verification]),
    UsersModule,
    SovraModule,
    VerificationsModule,
    AuthModule,
    CredentialsModule,
  ],
  controllers: [WebhooksController],
  providers: [WebhooksService],
})
export class WebhooksModule {}

