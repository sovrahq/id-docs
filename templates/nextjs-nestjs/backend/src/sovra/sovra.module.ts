import { Module } from '@nestjs/common';
import { SovraService } from './sovra.service';

@Module({
  providers: [SovraService],
  exports: [SovraService],
})
export class SovraModule {}

