import {
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository, Not, IsNull } from 'typeorm';
import { Credential } from './entities/credential.entity';

@Injectable()
export class CredentialsService {
  constructor(
    @InjectRepository(Credential)
    private credentialsRepository: Repository<Credential>,
  ) {}

  async create(
    invitationId: string,
    credentialId: string,
  ): Promise<Credential> {
    const credential = this.credentialsRepository.create({
      invitation_id: invitationId,
      credential_id: credentialId,
    });

    return await this.credentialsRepository.save(credential);
  }

  async findByInvitationId(invitationId: string): Promise<Credential | null> {
    return await this.credentialsRepository.findOne({
      where: { invitation_id: invitationId },
    });
  }

  async updateHolderDid(
    invitationId: string,
    holderDid: string,
  ): Promise<Credential> {
    const credential = await this.findByInvitationId(invitationId);

    if (!credential) {
      throw new NotFoundException(
        `Credential not found for invitationId: ${invitationId}`,
      );
    }

    credential.holder_did = holderDid;
    
    return await this.credentialsRepository.save(credential);
  }
}

