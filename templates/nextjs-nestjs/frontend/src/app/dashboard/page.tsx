'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/button';
import { jwtDecode } from 'jwt-decode';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

export default function DashboardPage() {
  const router = useRouter();
  const [userId, setUserId] = useState<string | null>(null);
  const [email, setEmail] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    // Solo ejecutar en el cliente
    const token = localStorage.getItem('token');
    
    if (!token) {
      router.push('/login');
      return;
    }

    try {
      const user = jwtDecode<{ id: string; email: string }>(token);
      setUserId(user.id);
      setEmail(user.email);
    } catch (error) {
      localStorage.removeItem('token');
      router.push('/login');
    } finally {
      setIsLoading(false);
    }
  }, [router]);

  const handleLogout = () => {
    localStorage.removeItem('token');
    router.push('/login');
  };

  // Mostrar loading hasta que se verifique el token en el cliente
  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-muted-foreground">Cargando...</div>
      </div>
    );
  }

  if (!userId) {
    return null; // Ya se está redirigiendo
  }

  return (
    <div className="min-h-screen bg-background p-4">
      <div className="max-w-4xl mx-auto space-y-6">
        <div className="flex justify-between items-center">
          <h1 className="text-3xl font-bold">Dashboard</h1>
          <Button onClick={handleLogout} variant="outline">
            Cerrar Sesión
          </Button>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Bienvenido</CardTitle>
            <CardDescription>
              Has iniciado sesión correctamente
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div>
              <p className="text-sm text-muted-foreground">ID de Usuario:</p>
              <p className="font-mono text-sm break-all">{userId}</p>
            </div>
          </CardContent>
          <CardContent className="space-y-4">
            <div>
              <p className="text-sm text-muted-foreground">Email:
              </p>
              <p className="font-mono text-sm break-all">{email}</p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

