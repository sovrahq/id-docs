import axios from 'axios';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost:4000';

export const api = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
});

// Add auth token to requests
api.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    const token = localStorage.getItem('token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
  }
  return config;
});

export interface CreateUserDto {
  first_name: string;
  last_name: string;
  email: string;
  password: string;
}

export interface UserResponse {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
  token: string;
  invitation_id?: string;
  credential_id?: string;
  holder_did?: string;
  created_at: string;
  updated_at: string;
}

export interface LinkWalletResponse {
  invitationId: string;
  invitationContent: string;
}

export interface LoginVerificationResponse {
  presentationId: string;
  presentationContent: string;
}

export interface LoginByVerificationDto {
  presentation_id: string;
  user_id: string;
}

export interface LoginByVerificationResponse {
  token: string;
}

export const userApi = {
  create: async (data: CreateUserDto): Promise<UserResponse> => {
    const response = await api.post<UserResponse>('/users', data);
    return response.data;
  },

  linkWallet: async (): Promise<LinkWalletResponse> => {
    const response = await api.post<LinkWalletResponse>('/users/link-wallet');
    return response.data;
  },
};

export const verificationApi = {
  createLogin: async (): Promise<LoginVerificationResponse> => {
    const response = await api.post<LoginVerificationResponse>('/verifications/login');
    return response.data;
  },
};

export const authApi = {
  loginByVerification: async (data: LoginByVerificationDto): Promise<LoginByVerificationResponse> => {
    const response = await api.post<LoginByVerificationResponse>('/auth/login/by-verification', data);
    return response.data;
  },
};

