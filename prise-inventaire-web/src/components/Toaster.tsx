import { useEffect, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { onNetworkError } from '@/services/http';

interface Toast {
  id: number;
  message: string;
}

let counter = 0;

/**
 * Affiche un toast quand le client HTTP émet une erreur réseau (coupure,
 * timeout, serveur injoignable). Découplé de React via le bus onNetworkError.
 * Dédup court pour éviter 10 toasts si 10 requêtes échouent d'un coup.
 */
export default function Toaster() {
  const [toasts, setToasts] = useState<Toast[]>([]);

  useEffect(() => {
    let lastMsg = '';
    let lastAt = 0;

    return onNetworkError((message) => {
      const now = Date.now();
      if (message === lastMsg && now - lastAt < 3000) {
        return;
      }
      lastMsg = message;
      lastAt = now;

      const id = ++counter;
      setToasts((current) => [...current, { id, message }]);
      setTimeout(() => {
        setToasts((current) => current.filter((t) => t.id !== id));
      }, 5000);
    });
  }, []);

  if (toasts.length === 0) {
    return null;
  }

  return (
    <div className="fixed bottom-4 right-4 z-[100] flex flex-col gap-2">
      {toasts.map((toast) => (
        <div
          key={toast.id}
          className="flex items-center gap-2 bg-red-600 text-white px-4 py-3 rounded-lg shadow-lg max-w-sm"
          role="alert"
        >
          <AlertTriangle size={18} className="shrink-0" />
          <span className="text-sm">{toast.message}</span>
        </div>
      ))}
    </div>
  );
}
