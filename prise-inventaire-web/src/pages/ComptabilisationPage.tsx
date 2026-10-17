import { useCallback, useEffect, useState } from 'react';
import { Calculator, Save, Sparkles } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  CHAMPS_COMPTES, CHAMPS_JOURNAUX, comptabiliserDocuments, enregistrerReglagesComptabilisation, getApercuComptabilisation, getReglagesComptabilisation,
  type ApercuComptabilisation, type ChampReglage, type ParametresComptables, type ReglagesComptabilisation, type ResultatComptabilisation,
} from '@/services/comptabilite';

const classeChamp = 'w-full px-2 py-1.5 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const classeCarte = 'bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 space-y-3';

/**
 * Comptabilisation automatique (Finance) : documents à comptabiliser d'une période (par type, montant, contre-passations)
 * et lancement ; réglages : date de début, journaux, comptes, correspondances par mode de paiement, taxe et catégorie.
 */
export default function ComptabilisationPage() {
  const { t } = useLanguage();
  const ta = t.autoPosting;
  const { peut } = usePermissions();
  const ecrire = peut('comptabilite', 'ecrire');
  const montant = useMontantDocument();
  const [reglages, setReglages] = useState<ReglagesComptabilisation | null>(null);
  const [saisie, setSaisie] = useState<ParametresComptables | null>(null);
  const [apercu, setApercu] = useState<ApercuComptabilisation | null>(null);
  const [du, setDu] = useState('');
  const [au, setAu] = useState(aujourdhui());
  const [resultat, setResultat] = useState<ResultatComptabilisation | null>(null);
  const [message, setMessage] = useState('');
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);

  const chargerApercu = useCallback(() => getApercuComptabilisation(du || undefined, au || undefined).then(setApercu, e => setErreur(messageErreur(e, ta.error))), [du, au, ta.error]);
  useEffect(() => {
    getReglagesComptabilisation().then(r => { setReglages(r); setSaisie(r.parametres); }, e => setErreur(messageErreur(e, ta.error)));
  }, [ta.error]);
  useEffect(() => { chargerApercu(); }, [chargerApercu]);

  const valeur = (champ: ChampReglage) => saisie?.[champ] ?? null;
  const changer = (champs: Partial<ParametresComptables>) => setSaisie(s => (s ? { ...s, ...champs } : s));
  const correspondance = <G extends 'modes' | 'taxes' | 'categories'>(groupe: G) => (saisie?.correspondances?.[groupe] ?? {}) as NonNullable<NonNullable<ParametresComptables['correspondances']>[G]>;
  const changerCorrespondance = (groupe: 'modes' | 'taxes' | 'categories', cle: string, valeurCle: unknown) =>
    setSaisie(s => (s ? { ...s, correspondances: { ...(s.correspondances ?? {}), [groupe]: { ...((s.correspondances ?? {})[groupe] ?? {}), [cle]: valeurCle } } } : s));

  function proposer() {
    if (!reglages) return;
    const champs = [...CHAMPS_JOURNAUX, ...CHAMPS_COMPTES];
    changer(Object.fromEntries(champs.filter(c => !valeur(c) && reglages.propositions[c]).map(c => [c, reglages.propositions[c]])));
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!saisie) return;
    setEnCours(true);
    setErreur('');
    setMessage('');
    try {
      const r = await enregistrerReglagesComptabilisation(saisie);
      setSaisie(r.parametres);
      setReglages(rg => (rg ? { ...rg, parametres: r.parametres, manquants: r.manquants } : rg));
      setMessage(ta.saved);
      await chargerApercu();
    } catch (erreurApi) {
      setErreur(messageErreur(erreurApi, ta.error));
    } finally {
      setEnCours(false);
    }
  }

  async function comptabiliser() {
    setEnCours(true);
    setErreur('');
    setMessage('');
    try {
      setResultat(await comptabiliserDocuments(du || undefined, au || undefined));
      await chargerApercu();
    } catch (erreurApi) {
      setErreur(messageErreur(erreurApi, ta.error));
    } finally {
      setEnCours(false);
    }
  }

  const selectCompte = (champ: string, actuel: number | null | undefined, onChange: (id: number | null) => void, vide = ta.none) => (
    <select aria-label={champ} value={actuel ?? ''} onChange={e => onChange(e.target.value ? Number(e.target.value) : null)} className={classeChamp} disabled={!ecrire}>
      <option value="">{vide}</option>
      {reglages?.comptes.map(c => <option key={c.id} value={c.id}>{c.numero} — {c.libelle}</option>)}
    </select>
  );
  const manquants = apercu?.manquants ?? reglages?.manquants ?? [];
  const aComptabiliser = (apercu?.types ?? []).reduce((s, t) => s + t.nombre, 0) + (apercu?.contre_passations ?? 0);

  return (
    <div className="space-y-4 max-w-6xl">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Calculator size={24} /> {ta.title}</h1>
        <p className="text-sm text-gray-500 max-w-4xl">{ta.subtitle}</p>
      </div>
      {manquants.length > 0 && (
        <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
          {ta.missing.replace('{liste}', manquants.map(m => ta.fields[m] ?? m).join(', '))}
        </p>
      )}
      {message && <p role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200">{message}</p>}
      {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</p>}

      <section className={classeCarte} aria-label={ta.toPost}>
        <div className="flex flex-wrap items-end justify-between gap-3">
          <h2 className="text-lg font-semibold dark:text-white">{ta.toPost}</h2>
          <div className="flex flex-wrap items-end gap-2">
            <label className="text-xs text-gray-500">{ta.from}<input type="date" value={du} onChange={e => setDu(e.target.value)} className={classeChamp} /></label>
            <label className="text-xs text-gray-500">{ta.to}<input type="date" value={au} onChange={e => setAu(e.target.value)} className={classeChamp} /></label>
            {ecrire && (
              <button type="button" disabled={enCours || manquants.length > 0 || aComptabiliser === 0} onClick={comptabiliser}
                className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">
                <Calculator size={16} /> {enCours ? ta.posting : ta.post}
              </button>
            )}
          </div>
        </div>
        {apercu && (
          <table className="w-full text-sm" aria-label={ta.toPost}>
            <thead className="text-xs text-gray-600 dark:text-gray-300 border-b dark:border-gray-700">
              <tr>
                <th className="py-2 text-left">{ta.document}</th>
                <th className="py-2 text-right">{ta.count}</th>
                <th className="py-2 text-right">{ta.amount}</th>
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              {apercu.types.map(ligne => (
                <tr key={ligne.type} className={ligne.nombre === 0 ? 'text-gray-400' : ''}>
                  <td className="py-1.5">{t.accounting.journal.sources[ligne.type] ?? ligne.libelle}</td>
                  <td className="py-1.5 text-right">{ligne.nombre}</td>
                  <td className="py-1.5 text-right">{montant(ligne.montant)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {apercu && apercu.contre_passations > 0 && <p className="text-sm text-amber-800 dark:text-amber-300">{ta.reversals.replace('{n}', String(apercu.contre_passations))}</p>}
        {apercu && aComptabiliser === 0 && manquants.length === 0 && <p className="text-sm text-gray-500">{ta.nothing}</p>}
        {resultat && (
          <div className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200 space-y-1" role="status">
            <p>{resultat.message}</p>
            {resultat.erreurs.length > 0 && <p className="text-red-700 dark:text-red-300 font-medium">{ta.refused}</p>}
            {resultat.erreurs.map(e => <p key={`${e.type}-${e.document}`} className="text-red-700 dark:text-red-300">{e.document} : {e.message}</p>)}
          </div>
        )}
      </section>

      {saisie && reglages && (
        <form onSubmit={enregistrer} className={classeCarte} aria-label={ta.settings}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-lg font-semibold dark:text-white">{ta.settings}</h2>
            {ecrire && (
              <button type="button" onClick={proposer} className="flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg border border-purple-600 text-purple-700 hover:bg-purple-50 dark:text-purple-300 dark:hover:bg-purple-900/30">
                <Sparkles size={14} /> {ta.propose}
              </button>
            )}
          </div>
          <label className="block text-sm max-w-xs">
            <span className="block text-gray-700 dark:text-gray-300 mb-1">{ta.startDate}</span>
            <input type="date" value={saisie.date_debut ?? ''} onChange={e => changer({ date_debut: e.target.value || null })} className={classeChamp} disabled={!ecrire} aria-label={ta.fields.date_debut} />
            <span className="text-xs text-gray-500">{ta.startHelp}</span>
          </label>

          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 pt-2">{ta.journals}</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            {CHAMPS_JOURNAUX.map(champ => (
              <label key={champ} className="block text-xs text-gray-500">{ta.fields[champ]}
                <select aria-label={ta.fields[champ]} value={valeur(champ) ?? ''} disabled={!ecrire} onChange={e => changer({ [champ]: e.target.value ? Number(e.target.value) : null })} className={classeChamp}>
                  <option value="">{ta.none}</option>
                  {reglages.journaux.map(j => <option key={j.id} value={j.id}>{j.code} — {j.libelle}</option>)}
                </select>
              </label>
            ))}
          </div>

          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 pt-2">{ta.accounts}</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            {CHAMPS_COMPTES.map(champ => (
              <label key={champ} className="block text-xs text-gray-500">{ta.fields[champ]}
                {selectCompte(ta.fields[champ], valeur(champ), id => changer({ [champ]: id }))}
              </label>
            ))}
          </div>

          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 pt-2">{ta.modes}</h3>
          <p className="text-xs text-gray-500">{ta.modesHelp}</p>
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
            {reglages.modes.map(mode => (
              <label key={mode} className="block text-xs text-gray-500">{ta.modeLabels[mode] ?? mode}
                <select aria-label={`${ta.modes} ${ta.modeLabels[mode] ?? mode}`} disabled={!ecrire} value={correspondance('modes')[mode] ?? (mode === 'especes' ? 'caisse' : 'banque')}
                  onChange={e => changerCorrespondance('modes', mode, e.target.value)} className={classeChamp}>
                  <option value="caisse">{ta.treasury.caisse}</option>
                  <option value="banque">{ta.treasury.banque}</option>
                </select>
              </label>
            ))}
          </div>

          {reglages.taxes.length > 0 && (
            <>
              <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 pt-2">{ta.taxes}</h3>
              <p className="text-xs text-gray-500">{ta.taxesHelp}</p>
              <div className="space-y-2">
                {reglages.taxes.map(taxe => (
                  <div key={taxe.id} className="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                    <span className="text-sm dark:text-gray-200">{taxe.nom} ({Number(taxe.taux)} %)</span>
                    <label className="block text-xs text-gray-500">{ta.collected}
                      {selectCompte(`${taxe.nom} ${ta.collected}`, correspondance('taxes')[taxe.id]?.collectee,
                        id => changerCorrespondance('taxes', String(taxe.id), { ...correspondance('taxes')[taxe.id], collectee: id }), ta.defaultAccount)}
                    </label>
                    <label className="block text-xs text-gray-500">{ta.deductible}
                      {selectCompte(`${taxe.nom} ${ta.deductible}`, correspondance('taxes')[taxe.id]?.deductible,
                        id => changerCorrespondance('taxes', String(taxe.id), { ...correspondance('taxes')[taxe.id], deductible: id }), ta.defaultAccount)}
                    </label>
                  </div>
                ))}
              </div>
            </>
          )}

          {reglages.categories.length > 0 && (
            <>
              <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 pt-2">{ta.categories}</h3>
              <p className="text-xs text-gray-500">{ta.categoriesHelp}</p>
              <div className="space-y-2">
                {reglages.categories.map(categorie => (
                  <div key={categorie} className="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                    <span className="text-sm dark:text-gray-200">{categorie}</span>
                    <label className="block text-xs text-gray-500">{ta.sale}
                      {selectCompte(`${categorie} ${ta.sale}`, correspondance('categories')[categorie]?.vente,
                        id => changerCorrespondance('categories', categorie, { ...correspondance('categories')[categorie], vente: id }), ta.defaultAccount)}
                    </label>
                    <label className="block text-xs text-gray-500">{ta.purchase}
                      {selectCompte(`${categorie} ${ta.purchase}`, correspondance('categories')[categorie]?.achat,
                        id => changerCorrespondance('categories', categorie, { ...correspondance('categories')[categorie], achat: id }), ta.defaultAccount)}
                    </label>
                  </div>
                ))}
              </div>
            </>
          )}

          {ecrire && (
            <div className="flex justify-end pt-2">
              <button type="submit" disabled={enCours} className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">
                <Save size={16} /> {ta.save}
              </button>
            </div>
          )}
        </form>
      )}
    </div>
  );
}
