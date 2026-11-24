import { BadRequestException, Injectable } from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { User } from '../users/entities/user.entity';
import { Verification } from '../verifications/entities/verification.entity';
import { SovraService } from '../sovra/sovra.service';
import { VerificationsService } from '../verifications/verifications.service';
import { CredentialsService } from '../credentials/credentials.service';
import { Payload } from './types/webhook.types';
import { UsersService } from 'src/users/users.service';

@Injectable()
export class WebhooksService {
  constructor(
    @InjectRepository(User)
    private usersRepository: Repository<User>,
    @InjectRepository(Verification)
    private verificationRepository: Repository<Verification>,
    private sovraService: SovraService,
    private verificationsService: VerificationsService,
    private credentialsService: CredentialsService,
    private jwtService: JwtService,
    private usersService: UsersService,
  ) {}

  async handleWebhook(
    payload: Payload<any>,
    signature: string,
  ) {
    try {
      switch (payload.eventType) {
        case 'credential-issued':
          return await this.handleCredentialIssued(payload.eventData);

        case 'verifiable-presentation-finished':
          return await this.handleVerifiablePresentationFinished(
            payload.eventData,
          );

        default:
          return { success: true, message: 'Event type not handled' };
      }
    } catch (error) {
      throw error;
    }
  }

  private async handleCredentialIssued(
    eventData: any,
  ) {
    const invitationId = eventData.invitationId;
    const userId = eventData.vc?.credentialSubject?.userId;
    const holderDid = eventData.holderDID;

    if (!invitationId) {
      return { success: false, message: 'invitationId is required' };
    }

    if (!userId) {
      return {
        success: false,
        message: 'userId is required in credentialSubject',
      };
    }

    if (!holderDid) {
      return { success: false, message: 'holderDid is required' };
    }

    const credential = await this.credentialsService.findByInvitationId(
      invitationId,
    );

    if (!credential) {
      throw new BadRequestException('Credential not found for invitationId: ${invitationId}');
    }

    await this.usersService.updateInvitationIdAndHolderDid(invitationId, holderDid);
    await this.credentialsService.updateHolderDid(invitationId, holderDid);

    return { success: true, message: 'Credential and User updated successfully' };
  }

  private async handleVerifiablePresentationFinished(eventData: any) {
    const presentationId = eventData.invitationId;
    const holderDid = eventData.holderDID;
    const verified = eventData.verified;
    const userId = eventData.verifiableCredentials[0].credentialSubject?.userId;

    if (!presentationId) {
      return { success: false, message: 'presentationId is required' };
    }

    if (!holderDid) {
      return { success: false, message: 'holderDid is required' };
    }

    if (!userId) {
      return { success: false, message: 'userId is required' };
    }

    const verification = await this.verificationsService.findByPresentationId(
      presentationId,
    );

    if (!verification) {
      throw new BadRequestException('Verification not found');
    }
    
    await this.verificationsService.markAsVerifiedFromWebhook(
      presentationId,
      holderDid,
      userId,
      verified,
    );

    return { success: true, message: 'Verification completed successfully', verification };
  }
}

