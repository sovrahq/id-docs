import {
  Controller,
  Post,
  Body,
  Headers,
  HttpCode,
  HttpStatus,
} from '@nestjs/common';
import { WebhooksService } from './webhooks.service';
import { Payload } from './types/webhook.types';

@Controller('webhooks')
export class WebhooksController {
  constructor(private readonly webhooksService: WebhooksService) {}

  @Post()
  @HttpCode(HttpStatus.OK)
  async handleWebhook(
    @Body() payload: Payload<any>,
    @Headers('x-webhook-signature') signature: string,
  ) {
    console.log('payload', JSON.stringify(payload, null, 2));
    return this.webhooksService.handleWebhook(
      payload,
      signature,
    );
  }
}

