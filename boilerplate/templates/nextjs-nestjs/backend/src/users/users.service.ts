import {
  Injectable,
  ConflictException,
  BadRequestException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { JwtService } from '@nestjs/jwt';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import * as bcrypt from 'bcrypt';
import { CreateUserDto } from './dto/create-user.dto';
import { User } from './entities/user.entity';
import { SovraService } from '../sovra/sovra.service';
import { sovraTemplateCredential } from '../sovra/sovra.template';
import { CredentialsService } from '../credentials/credentials.service';

@Injectable()
export class UsersService {
  constructor(
    @InjectRepository(User)
    private usersRepository: Repository<User>,
    private jwtService: JwtService,
    private sovraService: SovraService,
    private configService: ConfigService,
    private credentialsService: CredentialsService,
  ) {}

  async create(createUserDto: CreateUserDto) {
    const existingUser = await this.usersRepository.findOne({
      where: { email: createUserDto.email },
    });

    if (existingUser) {
      throw new ConflictException('User with this email already exists');
    }

    const hashedPassword = await bcrypt.hash(createUserDto.password, 10);

    const user = this.usersRepository.create({
      ...createUserDto,
      password: hashedPassword,
    });

    const savedUser = await this.usersRepository.save(user);

    const payload = { sub: savedUser.id, email: savedUser.email, id: savedUser.id };
    const token = this.jwtService.sign(payload);
    
    return {
      token,
    };
  }

  async linkWallet(user: User) {
    const workspaceId = this.configService.get<string>('SOVRA_ID_WORKSPACE_ID');
    if (!workspaceId) {
      throw new BadRequestException('SOVRA_ID_WORKSPACE_ID is not configured');
    }

    const credentialData = sovraTemplateCredential({
      givenName: user.first_name,
      familyName: user.last_name,
      userId: user.id,
    });

    const credentialResponse = await this.sovraService.createCredential(
      workspaceId,
      credentialData,
    );

    await this.credentialsService.create(
      credentialResponse.invitation_wallet.invitationId,
      credentialResponse.id,
    );

    user.invitation_id = credentialResponse.invitation_wallet.invitationId;
    await this.usersRepository.save(user);

    return {
      invitationId: credentialResponse.invitation_wallet.invitationId,
      invitationContent: credentialResponse.invitation_wallet.invitationContent,
    };
  }

  async updateInvitationIdAndHolderDid(invitationId: string, holderDid: string) {
    const user = await this.usersRepository.findOne({
      where: { invitation_id: invitationId },
    });

    if (!user) {
      throw new BadRequestException('User not found');
    }

    user.holder_did = holderDid;
    await this.usersRepository.save(user);
  }
}
