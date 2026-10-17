import { useEffect, useRef, useState } from 'react';
import { Eraser } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';

/**
 * Bloc de signature : le client signe au doigt, au stylet ou à la souris. Donne l'image (PNG encodée) à la fin de
 * chaque trait, ou null quand le bloc est effacé.
 */
export default function PadSignature({ onChange, hauteur = 160 }: { onChange: (image: string | null) => void; hauteur?: number }) {
  const { t } = useLanguage();
  const ts = t.deliverySignature;
  const toile = useRef<HTMLCanvasElement>(null);
  const enTrait = useRef(false);
  const [vide, setVide] = useState(true);

  // Toile à la taille affichée et à la densité de l'écran : un trait net, sans flou.
  useEffect(() => {
    const canvas = toile.current;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) return;
    const ratio = window.devicePixelRatio || 1;
    canvas.width = Math.round(canvas.getBoundingClientRect().width * ratio);
    canvas.height = Math.round(hauteur * ratio);
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111827';
  }, [hauteur]);

  const position = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const cadre = e.currentTarget.getBoundingClientRect();
    return { x: e.clientX - cadre.left, y: e.clientY - cadre.top };
  };

  const commencer = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const ctx = e.currentTarget.getContext('2d');
    if (!ctx) return;
    e.currentTarget.setPointerCapture(e.pointerId);
    enTrait.current = true;
    const { x, y } = position(e);
    ctx.beginPath();
    ctx.moveTo(x, y);
    // Un simple appui laisse un point.
    ctx.lineTo(x + 0.1, y + 0.1);
    ctx.stroke();
  };

  const tracer = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const ctx = e.currentTarget.getContext('2d');
    if (!enTrait.current || !ctx) return;
    const { x, y } = position(e);
    ctx.lineTo(x, y);
    ctx.stroke();
  };

  const terminer = (e: React.PointerEvent<HTMLCanvasElement>) => {
    if (!enTrait.current) return;
    enTrait.current = false;
    setVide(false);
    onChange(e.currentTarget.toDataURL('image/png'));
  };

  const effacer = () => {
    const canvas = toile.current;
    const ctx = canvas?.getContext('2d');
    if (canvas && ctx) {
      ctx.save();
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.restore();
    }
    setVide(true);
    onChange(null);
  };

  return (
    <div>
      <div className="relative rounded-lg border-2 border-dashed border-gray-300 bg-white dark:border-gray-600">
        <canvas
          ref={toile}
          role="img"
          aria-label={ts.pad}
          style={{ height: hauteur, touchAction: 'none' }}
          className="block w-full cursor-crosshair rounded-lg"
          onPointerDown={commencer}
          onPointerMove={tracer}
          onPointerUp={terminer}
          onPointerCancel={terminer}
        />
        {vide && <span className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-gray-400">{ts.signHere}</span>}
      </div>
      <button type="button" onClick={effacer} disabled={vide}
        className="mt-1 inline-flex items-center gap-1 text-xs text-gray-600 hover:underline disabled:opacity-40 dark:text-gray-300">
        <Eraser size={12} /> {ts.clear}
      </button>
    </div>
  );
}
