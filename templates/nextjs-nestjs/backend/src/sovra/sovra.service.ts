import { Injectable, HttpException, HttpStatus } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import axios, { AxiosInstance } from 'axios';


@Injectable()
export class SovraService {
  private readonly axiosInstance: AxiosInstance;
  private readonly apiKey: string;
  private readonly baseUrl: string;

  constructor(private configService: ConfigService) {
    this.apiKey = this.configService.get<string>('SOVRA_ID_API_KEY');
    this.baseUrl =
      this.configService.get<string>('SOVRA_API_URL') ||
      'https://id.api.sandbox.sovra.io/api';

    this.axiosInstance = axios.create({
      baseURL: this.baseUrl,
      headers: {
        'x-api-key': this.apiKey,
        'Content-Type': 'application/json',
      },
    });
  }

  async createCredential(
    workspaceId: string,
    body: any,
  ): Promise<any> {
    try {
      const response = await this.axiosInstance.post(
        `/credentials/workspace/${workspaceId}`,
        body,
      );

      return response.data;
    } catch (error) {
      if (axios.isAxiosError(error)) {
        throw new HttpException(
          error.response?.data?.message || 'Error creating credential',
          error.response?.status || HttpStatus.INTERNAL_SERVER_ERROR,
        );
      }
      throw new HttpException(
        'Unexpected error creating credential',
        HttpStatus.INTERNAL_SERVER_ERROR,
      );
    }
  }

  async getCredential(credentialId: string): Promise<any> {
    try {
      const response = await this.axiosInstance.get(
        `/credentials/${credentialId}`,
      );
      return response.data;
    } catch (error) {
      if (axios.isAxiosError(error)) {
        throw new HttpException(
          error.response?.data?.message || 'Error fetching credential',
          error.response?.status || HttpStatus.INTERNAL_SERVER_ERROR,
        );
      }
      throw new HttpException(
        'Unexpected error fetching credential',
        HttpStatus.INTERNAL_SERVER_ERROR,
      );
    }
  }

  async createVerification(
    workspaceId: string,
    body: any,
  ): Promise<any> {
    try {
      const response = await this.axiosInstance.post(
        `/verifications/workspace/${workspaceId}`,
        body,
      );
      return response.data;
    } catch (error) {
      if (axios.isAxiosError(error)) {
        throw new HttpException(
          error.response?.data?.message || 'Error creating verification',
          error.response?.status || HttpStatus.INTERNAL_SERVER_ERROR,
        );
      }
      throw new HttpException(
        'Unexpected error creating verification',
        HttpStatus.INTERNAL_SERVER_ERROR,
      );
    }
  }
}

