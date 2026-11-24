'use client';

import { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { userApi } from '@/lib/api';
import { supabase } from '@/lib/supabase';
import { QRCodeSVG } from 'qrcode.react';
import type { RealtimeChannel } from '@supabase/supabase-js';

export default function LinkWalletPage() {
  const router = useRouter();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [qrData, setQrData] = useState<string | null>(null);
  const [invitationId, setInvitationId] = useState<string | null>(null);
  const [listening, setListening] = useState(false);
  const [connected, setConnected] = useState(false);
  const [isClient, setIsClient] = useState(false);
  const channelRef = useRef<RealtimeChannel | null>(null);

  const cleanupChannel = () => {
    if (supabase && channelRef.current) {
      supabase.removeChannel(channelRef.current);
      channelRef.current = null;
      setListening(false);
    }
  };

  const startListening = (invId: string) => {
    if (!supabase) {
      setError('Supabase no está configurado. No se pueden escuchar eventos en tiempo real.');
      return;
    }

    cleanupChannel();
    setListening(true);

    const channel = supabase
      .channel(`credentials-${invId}`)
      .on(
        'postgres_changes',
        {
          event: 'UPDATE',
          schema: `${process.env.NEXT_PUBLIC_DB_SCHEMA}`,
          table: 'credentials',
          filter: `invitation_id=eq.${invId}`,
        },
        async (payload: any) => {
          const credential = payload.new;
          
          // Cuando se actualiza el holder_did, significa que se conectó la wallet
          if (credential.holder_did) {
            setConnected(true);
            cleanupChannel();
            
            // Mostrar mensaje de éxito por 2 segundos y luego redirigir
            setTimeout(() => {
              router.push('/dashboard');
            }, 2000);
          }
        }
      )
      .subscribe();

    channelRef.current = channel;
  };

  const handleGenerateQR = async () => {
    setLoading(true);
    setError(null);
    setQrData(null);
    setInvitationId(null);
    setConnected(false);
    cleanupChannel();

    try {
      const response = await userApi.linkWallet();
      setQrData(response.invitationContent);
      setInvitationId(response.invitationId);
      
      // Iniciar escucha de cambios en Supabase
      if (response.invitationId) {
        startListening(response.invitationId);
      }
    } catch (err: any) {
      setError(err.response?.data?.message || 'Error al generar código QR');
    } finally {
      setLoading(false);
    }
  };

  const handleSkip = () => {
    cleanupChannel();
    router.push('/dashboard');
  };

  useEffect(() => {
    setIsClient(true);
    const token = localStorage.getItem('token');
    if (!token) {
      router.push('/register');
      return;
    }
    handleGenerateQR();
    
    return () => {
      cleanupChannel();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [router]);

  // Renderizar loading hasta que se monte en el cliente
  if (!isClient) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background p-4">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle>Conectar Wallet</CardTitle>
            <CardDescription>
              Escanea el código QR con tu wallet para conectar tu cuenta
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex items-center justify-center py-12">
              <div className="text-muted-foreground">Cargando...</div>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>Conectar Wallet</CardTitle>
          <CardDescription>
            Escanea el código QR con tu wallet para conectar tu cuenta
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {loading ? (
            <div className="flex items-center justify-center py-12">
              <div className="text-muted-foreground">Generando código QR...</div>
            </div>
          ) : error ? (
            <div className="space-y-4">
              <div className="text-sm text-destructive bg-destructive/10 p-3 rounded-md">
                {error}
              </div>
              <Button onClick={handleGenerateQR} className="w-full">
                Reintentar
              </Button>
            </div>
          ) : connected ? (
            <div className="space-y-4">
              <div className="flex flex-col items-center justify-center py-8 space-y-4">
                <div className="h-16 w-16 bg-green-500 rounded-full flex items-center justify-center">
                  <svg
                    className="h-8 w-8 text-white"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M5 13l4 4L19 7"
                    />
                  </svg>
                </div>
                <div className="text-center">
                  <h3 className="text-lg font-semibold text-green-600">
                    ¡Wallet conectada exitosamente!
                  </h3>
                  <p className="text-sm text-muted-foreground mt-2">
                    Redirigiendo al dashboard...
                  </p>
                </div>
              </div>
            </div>
          ) : qrData ? (
            <div className="space-y-4">
              <div className="flex justify-center p-4 bg-white rounded-lg">
                <QRCodeSVG value={qrData} size={256} />
              </div>
              <div className="text-center space-y-2">
                {listening && (
                  <div className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
                    <div className="h-2 w-2 bg-green-500 rounded-full animate-pulse"></div>
                    <span>Esperando conexión de wallet...</span>
                  </div>
                )}
                <p className="text-sm text-muted-foreground">
                  Escanea este código QR con tu wallet para conectar tu cuenta.
                </p>
                {invitationId && (
                  <p className="text-xs font-mono break-all text-muted-foreground">
                    ID: {invitationId}
                  </p>
                )}
              </div>
              <div className="flex gap-2">
                <Button onClick={handleGenerateQR} variant="outline" className="flex-1">
                  Generar nuevo QR
                </Button>
                <Button onClick={handleSkip} variant="ghost" className="flex-1">
                  Omitir por ahora
                </Button>
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>
    </div>
  );
}

