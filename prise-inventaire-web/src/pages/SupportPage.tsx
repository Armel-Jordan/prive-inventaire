import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { useSearchParams } from 'react-router-dom';
import { LifeBuoy, Lightbulb, Plus, Send, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import { messageErreur } from '@/lib/erreurs';
import {
  creerDemandeSupport, fermerDemandeSupport, getDemandeSupport, getDemandesSupport, repondreDemandeSupport,
  PRIORITES_DEMANDE, STATUTS_DEMANDE,
  type DemandeSupport, type DetailDemande, type PrioriteDemande, type StatutDemande, type TypeDemande,
} from '@/services/support';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

const COULEURS_STATUT: Record<StatutDemande, string> = {
  ouverte: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
  en_cours: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
  en_attente: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
  resolue: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
  fermee: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
};

/**
 * Support et suggestions (plan de parité fripv2, phase 6.7) : écrire à l'éditeur du logiciel (une demande d'aide ou
 * une suggestion), suivre ses demandes et échanger avec lui. Ouverte depuis l'en-tête, la nouvelle demande retient
 * l'écran d'où elle part.
 */
export default function SupportPage() {
  const { t, language } = useLanguage();
  const ts = t.support;
  const { peut } = usePermissions();
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const [recherche, setRecherche] = useSearchParams();
  const [demandes, setDemandes] = useState<DemandeSupport[] | null>(null);
  const [toutes, setToutes] = useState(false);
  const [statut, setStatut] = useState('');
  const [choisie, setChoisie] = useState<DetailDemande | null>(null);
  const [reponse, setReponse] = useState('');
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [version, setVersion] = useState(0);
  // Ouverte depuis l'en-tête (« ?depuis=/scans ») : la nouvelle demande retient l'écran.
  const [nouvelle, setNouvelle] = useState<{ ecran: string | null } | null>(() => (recherche.get('depuis') !== null ? { ecran: recherche.get('depuis') } : null));
  const date = (iso: string | null) => (iso ? new Date(iso).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' }) : '');

  useEffect(() => {
    let actif = true;
    getDemandesSupport({ statut }).then(r => {
      if (!actif) return;
      setDemandes(r.demandes);
      setToutes(r.toutes);
    }, e => { if (actif) setErreur(messageErreur(e, ts.error)); });
    return () => { actif = false; };
  }, [statut, version, ts.error]);

  // Demande ouverte (« ?demande=12 », aussi le lien des notifications d'une réponse de l'éditeur).
  const demandeDuLien = Number(recherche.get('demande')) || null;
  useEffect(() => {
    if (!demandeDuLien) return;
    let actif = true;
    getDemandeSupport(demandeDuLien).then(d => { if (actif) setChoisie(d); }, e => { if (actif) setErreur(messageErreur(e, ts.error)); });
    return () => { actif = false; };
  }, [demandeDuLien, ts.error]);

  function ouvrir(id: number) {
    setErreur('');
    setReponse('');
    setRecherche({ demande: String(id) }, { replace: true });
  }

  async function agir(action: () => Promise<DetailDemande>) {
    setEnCours(true);
    setErreur('');
    try {
      setChoisie(await action());
      setReponse('');
      setVersion(v => v + 1);
    } catch (e) {
      setErreur(messageErreur(e, ts.error));
    } finally {
      setEnCours(false);
    }
  }

  const pastille = (s: StatutDemande) => <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${COULEURS_STATUT[s]}`}>{ts.statuses[s]}</span>;

  return (
    <div className="p-4 sm:p-6 space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><LifeBuoy className="text-indigo-600" /> {ts.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{ts.subtitle}</p>
        </div>
        {peut('support', 'creer') && (
          <button type="button" onClick={() => setNouvelle({ ecran: null })} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm hover:bg-indigo-700">
            <Plus size={16} /> {ts.new}
          </button>
        )}
      </div>

      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</p>}

      <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
        <section className="lg:col-span-2 space-y-2" aria-label={ts.list}>
          <div className="flex items-center justify-between gap-2">
            <p className="text-xs text-gray-500 dark:text-gray-400">{toutes ? ts.allCompany : ts.mine}</p>
            <label className="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
              {ts.status}
              <select value={statut} onChange={e => setStatut(e.target.value)} className="px-2 py-1 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm">
                <option value="">{ts.allStatuses}</option>
                {STATUTS_DEMANDE.map(s => <option key={s} value={s}>{ts.statuses[s]}</option>)}
              </select>
            </label>
          </div>
          {demandes === null ? (
            <p className="text-sm text-gray-500">{ts.loading}</p>
          ) : demandes.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">{ts.none}</p>
          ) : (
            <ul className="space-y-2">
              {demandes.map(d => (
                <li key={d.id}>
                  <button type="button" onClick={() => ouvrir(d.id)} aria-current={choisie?.id === d.id}
                    className={`w-full text-left p-3 rounded-xl border text-sm ${choisie?.id === d.id ? 'border-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 dark:border-indigo-700' : 'border-gray-200 bg-white hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-700 dark:hover:bg-gray-700'}`}>
                    <span className="flex items-center justify-between gap-2">
                      <span className="font-medium text-gray-900 dark:text-white truncate">
                        {d.type === 'suggestion' ? <Lightbulb size={14} className="inline mr-1 text-amber-500" aria-label={ts.types.suggestion} /> : null}
                        n° {d.id} · {d.sujet}
                      </span>
                      {pastille(d.statut)}
                    </span>
                    <span className="block mt-1 text-xs text-gray-500 dark:text-gray-400">
                      {date(d.dernier_message_le)}{toutes && d.auteur ? ` · ${d.auteur}` : ''}{d.dernier_auteur === 'editeur' && d.statut === 'en_attente' ? ` · ${ts.waitingYou}` : ''}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="lg:col-span-3" aria-label={ts.detail}>
          {!choisie ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">{ts.choose}</p>
          ) : (
            <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 space-y-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h2 className="text-lg font-semibold text-gray-900 dark:text-white">n° {choisie.id} · {choisie.sujet}</h2>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    {ts.types[choisie.type]} · {ts.priority} : {ts.priorities[choisie.priorite]} · {date(choisie.created_at)}{choisie.auteur ? ` · ${choisie.auteur}` : ''}
                    {choisie.ecran ? ` · ${ts.screen} : ${choisie.ecran}` : ''}
                  </p>
                </div>
                {pastille(choisie.statut)}
              </div>
              <p className="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-line">{choisie.description}</p>
              <ul className="space-y-2">
                {choisie.messages.map(m => (
                  <li key={m.id} className={`p-3 rounded-xl text-sm ${m.auteur === 'editeur' ? 'bg-indigo-50 dark:bg-indigo-900/30 mr-8' : 'bg-gray-50 dark:bg-gray-700/50 ml-8'}`}>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">{m.auteur === 'editeur' ? ts.publisher : (m.nom ?? '')}{m.auteur === 'editeur' && m.nom ? ` (${m.nom})` : ''} · {date(m.created_at)}</p>
                    <p className="whitespace-pre-line text-gray-800 dark:text-gray-200">{m.message}</p>
                  </li>
                ))}
              </ul>
              {peut('support', 'creer') && (
                <form onSubmit={e => { e.preventDefault(); if (reponse.trim()) void agir(() => repondreDemandeSupport(choisie.id, reponse)); }} className="space-y-2">
                  <label htmlFor="reponse-support" className="block text-xs text-gray-500 dark:text-gray-400">{choisie.statut === 'fermee' || choisie.statut === 'resolue' ? ts.replyReopens : ts.reply}</label>
                  <textarea id="reponse-support" rows={3} value={reponse} onChange={e => setReponse(e.target.value)} maxLength={5000} className={classeChamp} />
                  <div className="flex flex-wrap justify-end gap-2">
                    {choisie.statut !== 'fermee' && (
                      <button type="button" disabled={enCours} onClick={() => { void agir(() => fermerDemandeSupport(choisie.id)); }}
                        className="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50">
                        {ts.close}
                      </button>
                    )}
                    <button type="submit" disabled={enCours || !reponse.trim()} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm hover:bg-indigo-700 disabled:opacity-50">
                      <Send size={16} /> {ts.send}
                    </button>
                  </div>
                </form>
              )}
            </div>
          )}
        </section>
      </div>

      {nouvelle && (
        <NouvelleDemande ecran={nouvelle.ecran} onClose={() => setNouvelle(null)}
          onCree={d => { setNouvelle(null); setChoisie(d); setVersion(v => v + 1); setRecherche({ demande: String(d.id) }, { replace: true }); }} />
      )}
    </div>
  );
}

/** Nouvelle demande : aide ou suggestion, sujet, description, priorité (pour une demande d'aide). */
function NouvelleDemande({ ecran, onClose, onCree }: { ecran: string | null; onClose: () => void; onCree: (demande: DetailDemande) => void }) {
  const { t } = useLanguage();
  const ts = t.support;
  const [type, setType] = useState<TypeDemande>('support');
  const [sujet, setSujet] = useState('');
  const [description, setDescription] = useState('');
  const [priorite, setPriorite] = useState<PrioriteDemande>('normale');
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);

  async function envoyer(e: React.FormEvent) {
    e.preventDefault();
    setEnCours(true);
    setErreur('');
    try {
      onCree(await creerDemandeSupport({ type, sujet: sujet.trim(), description: description.trim(), priorite: type === 'support' ? priorite : 'normale', ecran }));
    } catch (err) {
      setErreur(messageErreur(err, ts.error));
      setEnCours(false);
    }
  }

  return createPortal(
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="titre-nouvelle-demande" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-xl max-h-[92vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h3 id="titre-nouvelle-demande" className="text-lg font-bold dark:text-white">{ts.new}</h3>
          <button type="button" onClick={onClose} aria-label={ts.cancel} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-gray-300"><X size={18} /></button>
        </div>
        <form onSubmit={e => { void envoyer(e); }} className="space-y-3">
          <fieldset className="flex flex-wrap gap-4">
            <legend className="sr-only">{ts.type}</legend>
            {(['support', 'suggestion'] as const).map(valeur => (
              <label key={valeur} className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="radio" name="type-demande" value={valeur} checked={type === valeur} onChange={() => setType(valeur)} />
                {ts.types[valeur]}
              </label>
            ))}
          </fieldset>
          <div>
            <label htmlFor="sujet-demande" className="block mb-1 text-xs text-gray-500 dark:text-gray-400">{ts.subject} *</label>
            <input id="sujet-demande" value={sujet} onChange={e => setSujet(e.target.value)} required maxLength={200} className={classeChamp} />
          </div>
          <div>
            <label htmlFor="description-demande" className="block mb-1 text-xs text-gray-500 dark:text-gray-400">{type === 'support' ? ts.descriptionSupport : ts.descriptionSuggestion} *</label>
            <textarea id="description-demande" rows={6} value={description} onChange={e => setDescription(e.target.value)} required maxLength={5000} className={classeChamp} />
          </div>
          {type === 'support' && (
            <div>
              <label htmlFor="priorite-demande" className="block mb-1 text-xs text-gray-500 dark:text-gray-400">{ts.priority}</label>
              <select id="priorite-demande" value={priorite} onChange={e => setPriorite(e.target.value as PrioriteDemande)} className={classeChamp}>
                {PRIORITES_DEMANDE.map(p => <option key={p} value={p}>{ts.priorities[p]}</option>)}
              </select>
            </div>
          )}
          {ecran && <p className="text-xs text-gray-500 dark:text-gray-400">{ts.fromScreen.replace('{ecran}', ecran)}</p>}
          {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</p>}
          <div className="flex justify-end gap-2">
            <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{ts.cancel}</button>
            <button type="submit" disabled={enCours} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm hover:bg-indigo-700 disabled:opacity-50">
              <Send size={16} /> {ts.send}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  );
}
