import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, LifeBuoy, Lightbulb, Send, Shield } from 'lucide-react';
import { messageErreur } from '@/lib/erreurs';
import {
  getDemandeEditeur, getDemandesEditeur, modifierDemandeEditeur, repondreDemandeEditeur, PRIORITES_DEMANDE, STATUTS_DEMANDE,
  type DemandeEditeur, type PrioriteDemande, type StatutDemande,
} from '@/services/support';

const STORAGE_KEY = 'prise_super_admin';

/** Libellés de la console de l'éditeur (en français, comme ses autres écrans). */
const STATUTS: Record<StatutDemande, string> = {
  ouverte: 'Ouverte', en_cours: 'En cours', en_attente: "En attente de l'usager", resolue: 'Résolue', fermee: 'Fermée',
};
const PRIORITES: Record<PrioriteDemande, string> = { basse: 'Basse', normale: 'Normale', haute: 'Haute', urgente: 'Urgente' };
const COULEURS: Record<StatutDemande, string> = {
  ouverte: 'bg-blue-950 text-blue-300', en_cours: 'bg-indigo-950 text-indigo-300', en_attente: 'bg-amber-950 text-amber-300',
  resolue: 'bg-emerald-950 text-emerald-300', fermee: 'bg-slate-800 text-slate-400',
};

function getAuth(): { token?: string; user?: { nom?: string } } | null {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    return stored ? JSON.parse(stored) : null;
  } catch {
    return null;
  }
}

/**
 * Demandes de support et suggestions des entreprises, côté éditeur (plan de parité fripv2, phase 6.7) : liste filtrée
 * (actives par défaut), échange avec l'usager, statut et priorité. L'usager est prévenu d'une réponse ou d'une
 * résolution par une notification de son entreprise.
 */
export default function SuperAdminSupportPage() {
  const navigate = useNavigate();
  const [jeton] = useState(() => getAuth()?.token ?? null);
  const [demandes, setDemandes] = useState<DemandeEditeur[] | null>(null);
  const [parStatut, setParStatut] = useState<Partial<Record<StatutDemande, number>>>({});
  const [filtre, setFiltre] = useState<'actives' | StatutDemande | ''>('actives');
  const [choisie, setChoisie] = useState<DemandeEditeur | null>(null);
  const [reponse, setReponse] = useState('');
  const [statutApres, setStatutApres] = useState<StatutDemande>('en_attente');
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    if (!jeton) {
      navigate('/super-admin');
      return;
    }
    let actif = true;
    getDemandesEditeur(jeton, filtre === 'actives' ? { actives: true } : { statut: filtre }).then(r => {
      if (!actif) return;
      setDemandes(r.demandes);
      setParStatut(r.par_statut);
    }, e => { if (actif) setErreur(messageErreur(e, 'Chargement impossible.')); });
    return () => { actif = false; };
  }, [jeton, filtre, version, navigate]);

  async function agir(action: () => Promise<DemandeEditeur>) {
    setEnCours(true);
    setErreur('');
    try {
      setChoisie(await action());
      setReponse('');
      setVersion(v => v + 1);
    } catch (e) {
      setErreur(messageErreur(e, "L'action n'a pas abouti."));
    } finally {
      setEnCours(false);
    }
  }

  const date = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '');
  const pastille = (s: StatutDemande) => <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${COULEURS[s]}`}>{STATUTS[s]}</span>;
  const classeChamp = 'px-3 py-2 rounded-lg bg-slate-800 border border-slate-700 text-sm text-white';

  if (!jeton) return null;

  return (
    <div className="min-h-screen bg-slate-950">
      <header className="bg-slate-900 border-b border-slate-800 sticky top-0 z-10">
        <div className="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link to="/super-admin/dashboard" className="flex items-center gap-2 text-slate-400 hover:text-white transition-colors text-sm">
              <ArrowLeft size={18} /> Dashboard
            </Link>
            <span className="text-slate-700">/</span>
            <span className="text-white font-medium flex items-center gap-2"><LifeBuoy size={18} /> Support et suggestions</span>
          </div>
          <div className="flex items-center gap-2">
            <div className="p-2 bg-purple-600 rounded-lg"><Shield size={18} className="text-white" /></div>
            <span className="text-white font-medium text-sm">Super Admin</span>
          </div>
        </div>
      </header>

      <main className="max-w-7xl mx-auto px-6 py-8 space-y-4">
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Filtre des demandes">
          {(['actives', ...STATUTS_DEMANDE] as const).map(f => (
            <button key={f} type="button" onClick={() => setFiltre(f)} aria-pressed={filtre === f}
              className={`px-3 py-1.5 rounded-lg text-sm ${filtre === f ? 'bg-purple-600 text-white' : 'bg-slate-900 text-slate-300 border border-slate-800 hover:bg-slate-800'}`}>
              {f === 'actives' ? 'À traiter' : STATUTS[f]}{f !== 'actives' && parStatut[f] ? ` (${parStatut[f]})` : ''}
            </button>
          ))}
        </div>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-950 text-red-300">{erreur}</p>}

        <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
          <section className="lg:col-span-2 space-y-2" aria-label="Demandes">
            {demandes === null ? <p className="text-slate-400 text-sm">Chargement…</p> : demandes.length === 0 ? (
              <p className="text-slate-400 text-sm">Aucune demande.</p>
            ) : (
              <ul className="space-y-2">
                {demandes.map(d => (
                  <li key={d.id}>
                    <button type="button" onClick={() => { void agir(() => getDemandeEditeur(jeton, d.id)); }} aria-current={choisie?.id === d.id}
                      className={`w-full text-left p-3 rounded-xl border text-sm ${choisie?.id === d.id ? 'border-purple-600 bg-slate-800' : 'border-slate-800 bg-slate-900 hover:bg-slate-800'}`}>
                      <span className="flex items-center justify-between gap-2">
                        <span className="font-medium text-white truncate">
                          {d.type === 'suggestion' && <Lightbulb size={14} className="inline mr-1 text-amber-400" aria-label="Suggestion" />}
                          n° {d.id} · {d.sujet}
                        </span>
                        {pastille(d.statut)}
                      </span>
                      <span className="block mt-1 text-xs text-slate-400">
                        {d.entreprise} · {d.auteur} · {PRIORITES[d.priorite]} · {date(d.dernier_message_le)}{d.dernier_message_le === d.created_at ? ' · nouvelle' : d.dernier_auteur === 'usager' ? " · réponse de l'usager" : ''}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="lg:col-span-3" aria-label="Détail">
            {!choisie ? <p className="text-slate-400 text-sm">Choisissez une demande.</p> : (
              <div className="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <h2 className="text-lg font-semibold text-white">n° {choisie.id} · {choisie.sujet}</h2>
                    <p className="text-xs text-slate-400">
                      {choisie.entreprise} · {choisie.auteur} &lt;{choisie.auteur_email}&gt; · {date(choisie.created_at)}{choisie.ecran ? ` · écran ${choisie.ecran}` : ''}
                    </p>
                  </div>
                  {pastille(choisie.statut)}
                </div>
                <div className="flex flex-wrap gap-3 text-sm">
                  <label className="flex items-center gap-2 text-slate-300">Statut
                    <select value={choisie.statut} disabled={enCours} className={classeChamp}
                      onChange={e => { void agir(() => modifierDemandeEditeur(jeton, choisie.id, { statut: e.target.value as StatutDemande })); }}>
                      {STATUTS_DEMANDE.map(s => <option key={s} value={s}>{STATUTS[s]}</option>)}
                    </select>
                  </label>
                  <label className="flex items-center gap-2 text-slate-300">Priorité
                    <select value={choisie.priorite} disabled={enCours} className={classeChamp}
                      onChange={e => { void agir(() => modifierDemandeEditeur(jeton, choisie.id, { priorite: e.target.value as PrioriteDemande })); }}>
                      {PRIORITES_DEMANDE.map(p => <option key={p} value={p}>{PRIORITES[p]}</option>)}
                    </select>
                  </label>
                </div>
                <p className="text-sm text-slate-200 whitespace-pre-line">{choisie.description}</p>
                <ul className="space-y-2">
                  {choisie.messages.map(m => (
                    <li key={m.id} className={`p-3 rounded-xl text-sm ${m.auteur === 'editeur' ? 'bg-purple-950/60 ml-8' : 'bg-slate-800 mr-8'}`}>
                      <p className="text-xs text-slate-400 mb-1">{m.auteur === 'editeur' ? `Éditeur${m.nom ? ` (${m.nom})` : ''}` : m.nom} · {date(m.created_at)}</p>
                      <p className="whitespace-pre-line text-slate-100">{m.message}</p>
                    </li>
                  ))}
                </ul>
                <form onSubmit={e => { e.preventDefault(); if (reponse.trim()) void agir(() => repondreDemandeEditeur(jeton, choisie.id, reponse, statutApres)); }} className="space-y-2">
                  <label htmlFor="reponse-editeur" className="block text-xs text-slate-400">Réponse à l'usager (il est prévenu par une notification)</label>
                  <textarea id="reponse-editeur" rows={4} value={reponse} onChange={e => setReponse(e.target.value)} maxLength={5000} className={`w-full ${classeChamp}`} />
                  <div className="flex flex-wrap items-center justify-end gap-2">
                    <label className="flex items-center gap-2 text-xs text-slate-400">Puis
                      <select value={statutApres} onChange={e => setStatutApres(e.target.value as StatutDemande)} className={classeChamp}>
                        {STATUTS_DEMANDE.map(s => <option key={s} value={s}>{STATUTS[s]}</option>)}
                      </select>
                    </label>
                    <button type="submit" disabled={enCours || !reponse.trim()} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-purple-600 text-white text-sm hover:bg-purple-500 disabled:opacity-50">
                      <Send size={16} /> Répondre
                    </button>
                  </div>
                </form>
              </div>
            )}
          </section>
        </div>
      </main>
    </div>
  );
}
