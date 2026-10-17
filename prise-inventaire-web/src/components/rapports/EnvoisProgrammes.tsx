import { useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarClock, Pencil, Plus, Send, Trash2 } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { decrireFrequence } from '@/lib/programmesRapports';
import BadgeStatutCourriel from '@/components/BadgeStatutCourriel';
import {
  envoyerProgrammeRapport, supprimerProgrammeRapport, type ListeProgrammes, type ProgrammeRapport,
} from '@/services/bibliothequeRapports';

/**
 * Envois programmés des rapports : fréquence, période, destinataires, prochain et dernier envoi ; envoyer tout de
 * suite, modifier ou supprimer avec le droit de programmer. Prévient quand la tâche planifiée qui les fait partir est
 * désactivée.
 */
export default function EnvoisProgrammes({ liste, peutProgrammer, estAdmin, onNouveau, onModifier, onRecharger }: {
  liste: ListeProgrammes | null;
  peutProgrammer: boolean;
  estAdmin: boolean;
  onNouveau: () => void;
  onModifier: (programme: ProgrammeRapport) => void;
  onRecharger: () => void;
}) {
  const { t, language } = useLanguage();
  const tr = t.reportsLibrary;
  const ts = tr.schedules;
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [enCours, setEnCours] = useState<number | null>(null);
  const periodes = Object.fromEntries((liste?.periodes ?? []).map(p => [p.code, p.libelle]));
  const date = (iso: string | null) => (iso ? formatDateDocument(iso.slice(0, 10), language) : ts.never);

  async function envoyer(programme: ProgrammeRapport) {
    setEnCours(programme.id);
    setMessage(null);
    try {
      const { envoi } = await envoyerProgrammeRapport(programme.id, language);
      setMessage(envoi.statut === 'echec'
        ? { erreur: true, texte: ts.sendFailed.replace('{erreur}', envoi.erreur ?? '') }
        : { erreur: false, texte: ts.sentNow.replace('{adresses}', envoi.destinataires.join(', ')) });
    } catch (e) {
      setMessage({ erreur: true, texte: messageErreur(e, ts.error) });
    } finally {
      setEnCours(null);
      onRecharger();
    }
  }

  async function supprimer(programme: ProgrammeRapport) {
    if (!window.confirm(ts.confirmDelete.replace('{nom}', programme.nom))) return;
    setMessage(null);
    try {
      await supprimerProgrammeRapport(programme.id);
      onRecharger();
    } catch (e) {
      setMessage({ erreur: true, texte: messageErreur(e, ts.error) });
    }
  }

  return (
    <section className="space-y-3" aria-labelledby="titre-envois-programmes">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 id="titre-envois-programmes" className="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
            <CalendarClock size={20} className="text-indigo-600" /> {ts.title}
          </h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{ts.subtitle}</p>
        </div>
        {peutProgrammer && (
          <button type="button" onClick={onNouveau} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm hover:bg-indigo-700">
            <Plus size={16} /> {ts.new}
          </button>
        )}
      </div>

      {liste && !liste.tache_active && (
        <p role="status" className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
          {ts.taskInactive}{' '}
          {estAdmin ? <Link to="/taches-planifiees" className="underline font-medium">{ts.openTasks}</Link> : ts.taskInactiveOther}
        </p>
      )}
      {message && (
        <p role={message.erreur ? 'alert' : 'status'} className={`px-3 py-2 rounded-lg text-sm ${message.erreur ? 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200' : 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-200'}`}>
          {message.texte}
        </p>
      )}

      {liste === null ? (
        <p className="text-sm text-gray-500">{tr.loading}</p>
      ) : liste.programmes.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">{ts.none}</p>
      ) : (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600 text-left text-xs font-medium text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-3 py-2">{ts.columns.name}</th>
                <th className="px-3 py-2">{ts.columns.frequency}</th>
                <th className="px-3 py-2">{ts.columns.recipients}</th>
                <th className="px-3 py-2">{ts.columns.next}</th>
                <th className="px-3 py-2">{ts.columns.last}</th>
                {peutProgrammer && <th className="px-3 py-2 text-right">{ts.columns.actions}</th>}
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700">
              {liste.programmes.map(p => (
                <tr key={p.id} className={`align-top dark:text-gray-200 ${p.actif ? '' : 'opacity-60'}`}>
                  <td className="px-3 py-2">
                    <span className="font-medium text-gray-900 dark:text-white">{p.nom}</span>
                    {!p.actif && <span className="ml-2 px-1.5 py-0.5 rounded text-xs bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{ts.paused}</span>}
                    <span className="block text-xs text-gray-500 dark:text-gray-400">
                      {p.rapport}{p.periode ? ` · ${periodes[p.periode] ?? p.periode}` : ''} · {tr.formats[p.format]}
                    </span>
                    {p.compte && <span className="block text-xs text-gray-400">{ts.owner.replace('{nom}', p.compte)}</span>}
                    {p.derniere_erreur && <span className="block text-xs text-red-600 dark:text-red-300">{p.derniere_erreur}</span>}
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap">{decrireFrequence(p.frequence, p.jour, ts)}</td>
                  <td className="px-3 py-2 break-all">{p.destinataires.join(', ')}</td>
                  <td className="px-3 py-2 whitespace-nowrap">{p.actif ? date(p.prochain_envoi) : '—'}</td>
                  <td className="px-3 py-2 whitespace-nowrap">
                    {p.dernier_envoi ? (
                      <span className="inline-flex items-center gap-2">
                        <BadgeStatutCourriel statut={p.dernier_envoi.statut} titre={p.dernier_envoi.erreur} />
                        <span className="text-xs text-gray-500">{date(p.dernier_envoi_le)}</span>
                      </span>
                    ) : ts.never}
                  </td>
                  {peutProgrammer && (
                    <td className="px-3 py-2">
                      <div className="flex justify-end gap-1">
                        <button type="button" onClick={() => { void envoyer(p); }} disabled={enCours !== null} title={ts.sendNow} aria-label={`${ts.sendNow} — ${p.nom}`}
                          className="p-1.5 rounded text-teal-600 hover:bg-teal-50 dark:hover:bg-teal-900/30 disabled:opacity-50">
                          <Send size={16} />
                        </button>
                        <button type="button" onClick={() => onModifier(p)} title={ts.edit} aria-label={`${ts.edit} — ${p.nom}`}
                          className="p-1.5 rounded text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30">
                          <Pencil size={16} />
                        </button>
                        <button type="button" onClick={() => { void supprimer(p); }} title={ts.delete} aria-label={`${ts.delete} — ${p.nom}`}
                          className="p-1.5 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">
                          <Trash2 size={16} />
                        </button>
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
