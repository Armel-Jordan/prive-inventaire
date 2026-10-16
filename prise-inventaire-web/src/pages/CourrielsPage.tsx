import { useEffect, useState } from 'react';
import { Mail, Eye, RotateCw, X } from 'lucide-react';
import {
  getEnvoisCourriel, getEnvoiCourriel, reessayerEnvoiCourriel,
  type EnvoiCourriel, type PageEnvoisCourriel, type StatutCourriel, type TypeDocumentCourriel,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import BadgeStatutCourriel from '@/components/BadgeStatutCourriel';

const STATUTS: StatutCourriel[] = ['en_attente', 'en_cours', 'envoye', 'echec'];
const TYPES: TypeDocumentCourriel[] = ['devis', 'commande_client', 'facture', 'bon_livraison', 'commande_fournisseur'];

/** Historique et file d'envoi des courriels de l'entreprise, avec relance des échecs. */
export default function CourrielsPage() {
  const { t, language } = useLanguage();
  const tc = t.emails;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const date = (iso: string | null) => (iso ? new Date(iso).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' }) : '—');

  const [page, setPage] = useState(1);
  const [statut, setStatut] = useState<StatutCourriel | ''>('');
  const [type, setType] = useState<TypeDocumentCourriel | ''>('');
  const [resultat, setResultat] = useState<PageEnvoisCourriel | null>(null);
  const [erreur, setErreur] = useState('');
  const [detail, setDetail] = useState<EnvoiCourriel | null>(null);
  const [relance, setRelance] = useState<number | null>(null);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let actif = true;
    getEnvoisCourriel({ page, statut: statut || undefined, type: type || undefined })
      .then(r => { if (actif) setResultat(r); })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : tc.error); });
    return () => { actif = false; };
  }, [page, statut, type, version, tc.error]);

  async function voir(envoi: EnvoiCourriel) {
    try {
      setDetail(await getEnvoiCourriel(envoi.id));
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : tc.error);
    }
  }

  async function reessayer(envoi: EnvoiCourriel) {
    setRelance(envoi.id);
    setErreur('');
    try {
      const nouveau = await reessayerEnvoiCourriel(envoi.id);
      if (nouveau.statut === 'echec') setErreur(`${tc.failed} : ${nouveau.erreur ?? ''}`);
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : tc.error);
    } finally {
      setRelance(null);
      setVersion(v => v + 1);
    }
  }

  const envois = resultat?.data ?? [];
  const classeFiltre = 'px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

  return (
    <div className="p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
          <Mail className="text-purple-600" /> {tc.historyTitle}
        </h1>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tc.historySubtitle}</p>
      </div>

      <div className="flex flex-wrap gap-3 mb-4">
        <select value={statut} onChange={e => { setStatut(e.target.value as StatutCourriel | ''); setPage(1); }} className={classeFiltre} aria-label={tc.status}>
          <option value="">{tc.allStatuses}</option>
          {STATUTS.map(s => <option key={s} value={s}>{tc.statuses[s]}</option>)}
        </select>
        <select value={type} onChange={e => { setType(e.target.value as TypeDocumentCourriel | ''); setPage(1); }} className={classeFiltre} aria-label={tc.type}>
          <option value="">{tc.allTypes}</option>
          {TYPES.map(ty => <option key={ty} value={ty}>{tc.types[ty]}</option>)}
        </select>
      </div>

      {erreur && <p className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-4 py-3 font-medium">{tc.date}</th>
              <th className="px-4 py-3 font-medium">{tc.document}</th>
              <th className="px-4 py-3 font-medium">{tc.recipients}</th>
              <th className="px-4 py-3 font-medium">{tc.subject}</th>
              <th className="px-4 py-3 font-medium">{tc.status}</th>
              <th className="px-4 py-3 font-medium">{tc.sentBy}</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody>
            {resultat && envois.length === 0 && (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-gray-500">{tc.empty}</td></tr>
            )}
            {envois.map(envoi => (
              <tr key={envoi.id} className="border-t dark:border-gray-700">
                <td className="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-300">{date(envoi.envoye_le ?? envoi.created_at)}</td>
                <td className="px-4 py-3 whitespace-nowrap dark:text-white">{tc.types[envoi.type]} {envoi.document_numero}</td>
                <td className="px-4 py-3 text-gray-700 dark:text-gray-300 max-w-xs truncate" title={envoi.destinataires.join(', ')}>{envoi.destinataires.join(', ')}</td>
                <td className="px-4 py-3 text-gray-700 dark:text-gray-300 max-w-sm truncate" title={envoi.objet}>{envoi.objet}</td>
                <td className="px-4 py-3">
                  <BadgeStatutCourriel statut={envoi.statut} titre={envoi.erreur} />
                  {envoi.tentatives > 1 && <span className="ml-2 text-xs text-gray-500">×{envoi.tentatives}</span>}
                </td>
                <td className="px-4 py-3 text-gray-600 dark:text-gray-300 whitespace-nowrap">{envoi.auteur ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-1">
                    <button onClick={() => voir(envoi)} title={tc.view} aria-label={`${tc.view} — ${envoi.objet}`} className="p-1 text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">
                      <Eye size={16} />
                    </button>
                    {envoi.statut === 'echec' && (
                      <button onClick={() => reessayer(envoi)} disabled={relance === envoi.id} title={tc.retry} aria-label={`${tc.retry} — ${envoi.objet}`}
                        className="p-1 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded disabled:opacity-50">
                        <RotateCw size={16} className={relance === envoi.id ? 'animate-spin' : ''} />
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {resultat && resultat.last_page > 1 && (
        <div className="flex items-center justify-end gap-3 mt-4 text-sm">
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1.5 border rounded-lg dark:border-gray-600 dark:text-white disabled:opacity-40">{tc.previous}</button>
          <span className="text-gray-600 dark:text-gray-300">{tc.pageOf.replace('{page}', String(resultat.current_page)).replace('{total}', String(resultat.last_page))}</span>
          <button disabled={page >= resultat.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1.5 border rounded-lg dark:border-gray-600 dark:text-white disabled:opacity-40">{tc.next}</button>
        </div>
      )}

      {detail && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-3xl max-h-[92vh] overflow-y-auto">
            <div className="flex items-start justify-between gap-4 mb-4">
              <h3 className="text-lg font-bold dark:text-white">{detail.objet}</h3>
              <button onClick={() => setDetail(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tc.close}>
                <X size={18} />
              </button>
            </div>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm mb-4">
              <dt className="text-gray-500">{tc.from}</dt>
              <dd className="dark:text-white">{detail.expediteur_nom ? `${detail.expediteur_nom} <${detail.expediteur_adresse}>` : detail.expediteur_adresse}</dd>
              <dt className="text-gray-500">{tc.to}</dt>
              <dd className="dark:text-white">{detail.destinataires.join(', ')}</dd>
              {detail.copies && <><dt className="text-gray-500">{tc.cc}</dt><dd className="dark:text-white">{detail.copies.join(', ')}</dd></>}
              {detail.copies_cachees && <><dt className="text-gray-500">{tc.bcc}</dt><dd className="dark:text-white">{detail.copies_cachees.join(', ')}</dd></>}
              {detail.repondre_a && <><dt className="text-gray-500">{tc.replyTo}</dt><dd className="dark:text-white">{detail.repondre_a}</dd></>}
              {detail.piece_jointe && <><dt className="text-gray-500">{tc.attachment}</dt><dd className="dark:text-white">{detail.piece_jointe}</dd></>}
              <dt className="text-gray-500">{tc.status}</dt>
              <dd className="flex items-center gap-2">
                <BadgeStatutCourriel statut={detail.statut} />
                <span className="text-xs text-gray-500">{tc.attempts} : {detail.tentatives} — {date(detail.envoye_le ?? detail.dernier_essai)}</span>
              </dd>
            </dl>
            {detail.erreur && (
              <p className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700"><strong>{tc.lastError} :</strong> {detail.erreur}</p>
            )}
            {/* Bac à sable sans script : seul le rendu HTML du courriel s'affiche. */}
            <iframe title={detail.objet} sandbox="" srcDoc={detail.corps_html} className="w-full h-96 border dark:border-gray-600 rounded-lg bg-white" />
          </div>
        </div>
      )}
    </div>
  );
}
