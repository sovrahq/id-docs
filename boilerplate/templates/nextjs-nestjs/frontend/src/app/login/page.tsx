'use client';

import { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { verificationApi, authApi } from '@/lib/api';
import { supabase } from '@/lib/supabase';
import { QRCodeSVG } from 'qrcode.react';
import type { RealtimeChannel } from '@supabase/supabase-js';

export default function LoginPage() {
  const router = useRouter();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [qrData, setQrData] = useState<string | null>(null);
  const [presentationId, setPresentationId] = useState<string | null>(null);
  const [listening, setListening] = useState(false);
  const channelRef = useRef<RealtimeChannel | null>(null);

  const cleanupChannel = () => {
    if (supabase && channelRef.current) {
      supabase.removeChannel(channelRef.current);
      channelRef.current = null;
      setListening(false);
    }
  };

  const startListening = (presId: string) => {
    if (!supabase) return;

    cleanupChannel();

    setListening(true);

    const channel = supabase
      .channel(`verifications-${presId}`)
      .on(
        'postgres_changes',
        {
          event: 'UPDATE',
          schema: `${process.env.NEXT_PUBLIC_DB_SCHEMA}`,
          table: 'verifications',
          filter: `presentation_id=eq.${presId}`,
        },
        async (payload: any) => {
          const verification = payload.new;
          
          if (verification.verified && verification.user_id) {
            try {
              const loginResponse = await authApi.loginByVerification({
                presentation_id: verification.presentation_id,
                user_id: verification.user_id,
              });

              localStorage.setItem('token', loginResponse.token);

              cleanupChannel();

              router.push('/dashboard');
            } catch (err: any) {
              setError(err.response?.data?.message || 'Error al completar el login');
              setListening(false);
            }
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
    setPresentationId(null);
    cleanupChannel();

    try {
      const response = await verificationApi.createLogin();
      setQrData(response.presentationContent);
      setPresentationId(response.presentationId);
      
      if (supabase) {
        startListening(response.presentationId);
      } else {
        setError('Supabase no está configurado. No se pueden escuchar eventos en tiempo real.');
      }
    } catch (err: any) {
      setError(err.response?.data?.message || 'Error al generar código QR');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const token = localStorage.getItem('token');
    if (token) {
      router.push('/dashboard');
      return;
    }
    handleGenerateQR();
    return () => {
      cleanupChannel();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [router]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>Iniciar Sesión con Wallet</CardTitle>
          <CardDescription>
            Escanea el código QR con tu wallet para iniciar sesión
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
          ) : qrData ? (
            <div className="space-y-4">
              <div className="flex justify-center p-4 bg-white rounded-lg">
                <QRCodeSVG value={qrData} size={256} />
              </div>
              <div className="text-center space-y-2">
                {listening && (
                  <div className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
                    <div className="h-2 w-2 bg-green-500 rounded-full animate-pulse"></div>
                    <span>Esperando verificación...</span>
                  </div>
                )}
                <p className="text-sm text-muted-foreground">
                  Escanea este código QR con tu wallet para iniciar sesión.
                </p>
                {presentationId && (
                  <p className="text-xs font-mono break-all text-muted-foreground">
                    ID: {presentationId}
                  </p>
                )}
              </div>
              <Button onClick={handleGenerateQR} variant="outline" className="w-full">
                Generar nuevo QR
              </Button>
            </div>
          ) : null}
          <div className="text-center text-sm text-muted-foreground">
            ¿No tienes cuenta?{' '}
            <button
              type="button"
              onClick={() => router.push('/register')}
              className="text-primary hover:underline"
            >
              Regístrate
            </button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

