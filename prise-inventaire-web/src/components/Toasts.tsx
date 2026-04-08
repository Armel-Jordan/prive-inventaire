import { CheckCircle, XCircle, Info, X } from 'lucide-react';
import type { ToastItem } from '@/hooks/useToast';

const styles: Record<ToastItem['type'], { bar: string; icon: string; bg: string }> = {
  success: { bar: 'bg-green-500', icon: 'text-green-500', bg: 'bg-white dark:bg-gray-800' },
  error:   { bar: 'bg-red-500',   icon: 'text-red-500',   bg: 'bg-white dark:bg-gray-800' },
  info:    { bar: 'bg-blue-500',  icon: 'text-blue-500',  bg: 'bg-white dark:bg-gray-800' },
};

const icons = {
  success: CheckCircle,
  error:   XCircle,
  info:    Info,
};

export default function Toasts({ toasts, onDismiss }: {
  toasts: ToastItem[];
  onDismiss: (id: number) => void;
}) {
  if (toasts.length === 0) return null;

  return (
    <div className="fixed bottom-6 right-6 z-[100] flex flex-col gap-2 pointer-events-none">
      {toasts.map(t => {
        const s = styles[t.type];
        const Icon = icons[t.type];
        return (
          <div
            key={t.id}
            className={`pointer-events-auto flex items-center gap-3 ${s.bg} rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden min-w-64 max-w-sm animate-in slide-in-from-bottom-2`}
          >
            <div className={`w-1 self-stretch ${s.bar}`} />
            <Icon size={18} className={`${s.icon} shrink-0`} />
            <span className="flex-1 text-sm text-gray-700 dark:text-gray-200 py-3 pr-1">{t.message}</span>
            <button
              onClick={() => onDismiss(t.id)}
              className="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
            >
              <X size={14} />
            </button>
          </div>
        );
      })}
    </div>
  );
}
