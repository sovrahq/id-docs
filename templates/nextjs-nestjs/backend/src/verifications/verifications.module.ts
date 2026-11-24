import { Module } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { VerificationsService } from './verifications.service';
import { VerificationsController } from './verifications.controller';
import { Verification } from './entities/verification.entity';
import { SovraModule } from '../sovra/sovra.module';

@Module({
  imports: [TypeOrmModule.forFeature([Verification]), SovraModule],
  controllers: [VerificationsController],
  providers: [VerificationsService],
  exports: [VerificationsService],
})
export class VerificationsModule {}

