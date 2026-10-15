import { useEffect, useRef, useState } from 'react';
import { MessageSquarePlus } from 'lucide-react';
import { remarquesRapides, type RemarqueRapide } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';

/** Liste des remarques rapides de l'entreprise ; un clic insère la remarque dans la note. */
export default function BoutonRemarquesRapides({ onInserer }: { onInserer: (texte: string) => void }) {
  const { t } = useLanguage();
  const tb = t.tags;
  const [ouvert, setOuvert] = useState(false);
  const [remarques, setRemarques] = useState<RemarqueRapide[] | null>(null);
  const [filtre, setFiltre] = useState('');
  const zone = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!ouvert) return;
    let actif = true;
    remarquesRapides().then(liste => { if (actif) setRemarques(liste); }).catch(() => { if (actif) setRemarques([]); });
    // Un clic en dehors referme la liste.
    const fermer = (e: MouseEvent) => { if (zone.current && !zone.current.contains(e.target as Node)) setOuvert(false); };
    document.addEventListener('mousedown', fermer);
    return () => { actif = false; document.removeEventListener('mousedown', fermer); };
  }, [ouvert]);

  const recherche = filtre.trim().toLowerCase();
  const visibles = (remarques ?? []).filter(r => !recherche || r.texte.toLowerCase().includes(recherche) || (r.categorie ?? '').toLowerCase().includes(recherche));
  const groupes = visibles.reduce<Record<string, RemarqueRapide[]>>((acc, r) => {
    const cle = r.categorie ?? tb.uncategorized;
    (acc[cle] ??= []).push(r);
    return acc;
  }, {});

  return (
    <div ref={zone} className="relative inline-block mt-1">
      <button type="button" onClick={() => { setFiltre(''); setOuvert(o => !o); }} aria-expanded={ouvert}
        className="flex items-center gap-1 text-xs text-purple-700 dark:text-purple-300 hover:underline">
        <MessageSquarePlus size={14} /> {tb.insertRemark}
      </button>
      {ouvert && (
        <div className="absolute left-0 z-50 mt-1 w-80 max-h-72 overflow-y-auto bg-white dark:bg-gray-800 border dark:border-gray-600 rounded-lg shadow-lg p-2">
          {remarques === null ? null : remarques.length === 0 ? (
            <p className="text-xs text-gray-500 p-2">{tb.noRemarksHint}</p>
          ) : (
            <>
              <input value={filtre} onChange={e => setFiltre(e.target.value)} placeholder={tb.searchRemarks} aria-label={tb.searchRemarks} autoFocus
                className="w-full px-2 py-1 mb-2 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-xs" />
              {Object.entries(groupes).map(([categorie, liste]) => (
                <div key={categorie} className="mb-2">
                  <p className="px-2 text-[11px] uppercase tracking-wide text-gray-400">{categorie}</p>
                  {liste.map(remarque => (
                    <button key={remarque.id} type="button" onClick={() => { onInserer(remarque.texte); setOuvert(false); }}
                      className="block w-full text-left px-2 py-1.5 rounded text-sm text-gray-700 dark:text-gray-200 hover:bg-purple-50 dark:hover:bg-purple-900/30">
                      {remarque.texte.length > 120 ? `${remarque.texte.slice(0, 120)}…` : remarque.texte}
                    </button>
                  ))}
                </div>
              ))}
            </>
          )}
        </div>
      )}
    </div>
  );
}
