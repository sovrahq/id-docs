import {
  Injectable,
  BadRequestException,
  UnauthorizedException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { Verification } from './entities/verification.entity';
import { SovraService } from '../sovra/sovra.service';
import { sovraTemplateVerification } from 'src/sovra/sovra.template';

@Injectable()
export class VerificationsService {
  private readonly DEFAULT_EXPIRATION_MINUTES = 5;

  constructor(
    @InjectRepository(Verification)
    private verificationRepository: Repository<Verification>,
    private sovraService: SovraService,
    private configService: ConfigService,
  ) {}

  async createLoginVerification() {
    const workspaceId = this.configService.get<string>('SOVRA_ID_WORKSPACE_ID');

    if (!workspaceId) {
      throw new BadRequestException('SOVRA_ID_WORKSPACE_ID is not configured');
    }

    const verificationResponse = await this.sovraService.createVerification(
      workspaceId,
      sovraTemplateVerification(),
    );

    const presentationId = verificationResponse.presentation_wallet?.presentationId;
    
    if (!presentationId) {
      throw new BadRequestException('presentationId not found in response');
    }

    const expiresAt = new Date();

    expiresAt.setMinutes(expiresAt.getMinutes() + this.DEFAULT_EXPIRATION_MINUTES);

    const verification = this.verificationRepository.create({
      presentation_id: presentationId,
      expires_at: expiresAt,
    });

    await this.verificationRepository.save(verification);

    return {
      presentationId: presentationId,
      presentationContent: verificationResponse.presentation_wallet?.presentationContent,
    };
  }

  async findByPresentationId(presentationId: string): Promise<Verification | null> {
    return this.verificationRepository.findOne({
      where: { presentation_id: presentationId },
    });
  }

  async findByPresentationIdAndUserId(presentationId: string, userId: string): Promise<Verification | null> {
    return this.verificationRepository.findOne({
      where: { presentation_id: presentationId, user_id: userId },
    });
  }

  async markAsVerifiedFromWebhook(
    presentationId: string,
    holderDid: string,
    userId: string,
    verified: boolean,
  ): Promise<Verification> {
    const verification = await this.findByPresentationId(presentationId);
    if (!verification) {
      throw new BadRequestException('Verification not found');
    }

    verification.verified = verified;
    verification.holder_did = holderDid;
    verification.user_id = userId;

    return this.verificationRepository.save(verification);
  }

  async markAsUsed(presentationId: string, userId: string): Promise<Verification> {
    const verification = await this.findByPresentationIdAndUserId(presentationId, userId);
    if (!verification) {
      throw new BadRequestException('Verification not found');
    }

    if (new Date() > verification.expires_at) {
      throw new UnauthorizedException('Verification has expired');
    }

    if (verification.used_at) {
      throw new UnauthorizedException('Verification has already been used');
    }

    if (!verification.verified) {
      throw new BadRequestException('Verification not completed yet');
    }

    verification.used_at = new Date();

    return this.verificationRepository.save(verification);
  }
}

