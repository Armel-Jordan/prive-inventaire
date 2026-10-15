import { useCallback, useEffect, useState } from 'react';
import { Plus, Pencil, Trash2, X, Calculator, ArrowUp, ArrowDown } from 'lucide-react';
import {
  getGroupesTaxes, createGroupeTaxe, updateGroupeTaxe, deleteGroupeTaxe, calculerGroupeTaxe,
  type GroupeTaxe, type TenantTaxe, type AppliqueSur, type DetailTaxes, type DonneesGroupeTaxe,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';

const MODES: AppliqueSur[] = ['base', 'base_et_taxes_precedentes', 'taxe_precedente'];

/** Données d'un groupe existant, au format attendu par l'API. */
function donneesDe(groupe: GroupeTaxe): DonneesGroupeTaxe {
  return {
    code: groupe.code,
    nom: groupe.nom,
    par_defaut: groupe.par_defaut,
    actif: groupe.actif,
    lignes: groupe.lignes.map(l => ({ tenant_taxe_id: l.tenant_taxe_id, applique_sur: l.applique_sur })),
  };
}

/** Groupes de taxes de l'entreprise (onglet Devise & TVA) : composition, taux effectif et simulation. */
export default function GroupesTaxes({ taxes }: { taxes: TenantTaxe[] }) {
  const { t, language } = useLanguage();
  const tg = t.taxGroups;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const pourcentage = (v: number | string | undefined) => `${Number(v ?? 0).toLocaleString(locale, { maximumFractionDigits: 4 })} %`;
  const montant = (v: number) => v.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const [groupes, setGroupes] = useState<GroupeTaxe[]>([]);
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [edition, setEdition] = useState<{ id: number | null; donnees: DonneesGroupeTaxe } | null>(null);
  const [erreurEdition, setErreurEdition] = useState('');
  const [simulations, setSimulations] = useState<Record<number, { montant: string; resultat?: DetailTaxes }>>({});

  const informer = useCallback((texte: string, erreur = false) => {
    setMessage({ texte, erreur });
    setTimeout(() => setMessage(null), 3500);
  }, []);

  const charger = useCallback(async () => {
    try {
      setGroupes(await getGroupesTaxes());
    } catch {
      informer(tg.error, true);
    }
  }, [informer, tg.error]);

  // Chargement initial, puis à nouveau quand les taxes changent (leurs noms apparaissent dans la composition).
  useEffect(() => {
    let actif = true;
    getGroupesTaxes()
      .then(liste => { if (actif) setGroupes(liste); })
      .catch(() => { if (actif) informer(tg.error, true); });
    return () => { actif = false; };
  }, [informer, tg.error, taxes.length]);

  function composition(groupe: GroupeTaxe) {
    if (groupe.lignes.length === 0) return tg.exempt;
    return groupe.lignes
      .map(l => `${l.taxe?.nom ?? '?'} ${pourcentage(l.taxe?.taux)}${l.applique_sur === 'base' ? '' : ` (${tg.on} ${tg.modes[l.applique_sur].toLowerCase()})`}`)
      .join(' + ');
  }

  function nouvelleLigne(lignes: DonneesGroupeTaxe['lignes']) {
    const libre = taxes.find(tx => tx.id && !lignes.some(l => l.tenant_taxe_id === tx.id));
    return libre?.id ? [...lignes, { tenant_taxe_id: libre.id, applique_sur: 'base' as AppliqueSur }] : lignes;
  }

  function modifierLignes(transformer: (lignes: DonneesGroupeTaxe['lignes']) => DonneesGroupeTaxe['lignes']) {
    setEdition(e => e && ({ ...e, donnees: { ...e.donnees, lignes: transformer(e.donnees.lignes) } }));
  }

  function deplacer(index: number, sens: -1 | 1) {
    modifierLignes(lignes => {
      const copie = [...lignes];
      [copie[index], copie[index + sens]] = [copie[index + sens], copie[index]];
      // La première taxe ne peut pas s'appliquer sur une taxe précédente.
      if (copie[0]?.applique_sur === 'taxe_precedente') copie[0] = { ...copie[0], applique_sur: 'base' };
      return copie;
    });
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!edition) return;
    setErreurEdition('');
    try {
      if (edition.id) {
        await updateGroupeTaxe(edition.id, edition.donnees);
        informer(tg.updated);
      } else {
        await createGroupeTaxe(edition.donnees);
        informer(tg.created);
      }
      setEdition(null);
      charger();
    } catch (erreur) {
      setErreurEdition(erreur instanceof ApiError ? erreur.message : tg.error);
    }
  }

  async function definirParDefaut(groupe: GroupeTaxe) {
    try {
      await updateGroupeTaxe(groupe.id, { ...donneesDe(groupe), par_defaut: true });
      charger();
    } catch (erreur) {
      informer(erreur instanceof ApiError ? erreur.message : tg.error, true);
    }
  }

  async function supprimer(groupe: GroupeTaxe) {
    if (!confirm(tg.confirmDelete)) return;
    try {
      await deleteGroupeTaxe(groupe.id);
      informer(tg.deleted);
      charger();
    } catch (erreur) {
      informer(erreur instanceof ApiError ? erreur.message : tg.error, true);
    }
  }

  async function simuler(groupe: GroupeTaxe) {
    const saisie = simulations[groupe.id]?.montant ?? '100';
    try {
      const resultat = await calculerGroupeTaxe(groupe.id, Number(saisie));
      setSimulations(s => ({ ...s, [groupe.id]: { montant: saisie, resultat } }));
    } catch (erreur) {
      informer(erreur instanceof ApiError ? erreur.message : tg.error, true);
    }
  }

  const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

  return (
    <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
      <div className="flex items-start justify-between gap-4 mb-4">
        <div>
          <h2 className="text-lg font-semibold dark:text-white">{tg.title}</h2>
          <p className="text-sm text-gray-500 mt-1">{tg.subtitle}</p>
        </div>
        <button onClick={() => { setErreurEdition(''); setEdition({ id: null, donnees: { code: '', nom: '', par_defaut: false, actif: true, lignes: nouvelleLigne([]) } }); }}
          className="flex items-center gap-1 bg-purple-600 text-white px-3 py-2 rounded-lg hover:bg-purple-700 text-sm whitespace-nowrap">
          <Plus size={14} /> {tg.add}
        </button>
      </div>

      {message && (
        <p className={`mb-3 px-3 py-2 rounded-lg text-sm ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>
      )}

      {groupes.length === 0 ? (
        <p className="text-sm text-gray-500">{tg.empty}</p>
      ) : (
        <div className="space-y-2">
          {groupes.map(groupe => {
            const simulation = simulations[groupe.id];
            return (
              <div key={groupe.id} className="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                <div className="flex flex-wrap items-center gap-3">
                  <div className="flex-1 min-w-0">
                    <span className="font-medium text-gray-900 dark:text-white">{groupe.code}</span>
                    <span className="ml-2 text-gray-600 dark:text-gray-300">{groupe.nom}</span>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{composition(groupe)}</p>
                  </div>
                  <span className="px-2 py-0.5 bg-white dark:bg-gray-800 border dark:border-gray-600 rounded text-xs" title={tg.effectiveRate}>
                    {tg.effectiveRate} : <strong>{pourcentage(groupe.taux_effectif)}</strong>
                  </span>
                  {groupe.par_defaut ? (
                    <span className="px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs font-medium">{tg.default}</span>
                  ) : (
                    <button onClick={() => definirParDefaut(groupe)} className="text-xs text-gray-500 hover:text-purple-600 underline">{tg.setDefault}</button>
                  )}
                  <button onClick={() => { setErreurEdition(''); setEdition({ id: groupe.id, donnees: donneesDe(groupe) }); }}
                    className="p-1 text-blue-600 hover:bg-blue-50 rounded" title={tg.edit}>
                    <Pencil size={14} />
                  </button>
                  <button onClick={() => supprimer(groupe)} className="p-1 text-red-500 hover:bg-red-50 rounded" title={tg.delete}>
                    <Trash2 size={14} />
                  </button>
                </div>

                {/* Simulation sur un montant */}
                <div className="flex items-center gap-2 mt-2">
                  <input type="number" min={0} step="0.01" value={simulation?.montant ?? '100'} aria-label={tg.amountHt}
                    onChange={e => setSimulations(s => ({ ...s, [groupe.id]: { montant: e.target.value } }))}
                    className="w-32 px-2 py-1 border rounded dark:bg-gray-800 dark:border-gray-600 dark:text-white text-xs" />
                  <button onClick={() => simuler(groupe)} className="flex items-center gap-1 text-xs text-purple-700 dark:text-purple-300 hover:underline">
                    <Calculator size={12} /> {tg.simulate}
                  </button>
                </div>
                {simulation?.resultat && (
                  <div className="mt-2 text-xs text-gray-600 dark:text-gray-300 space-y-0.5">
                    {simulation.resultat.taxes.map(tx => (
                      <p key={tx.taxe_id}>{tx.nom} {pourcentage(tx.taux)} {tg.on} {montant(tx.base)} = <strong>{montant(tx.montant)}</strong></p>
                    ))}
                    <p>{tg.totalTaxes} : <strong>{montant(simulation.resultat.total_taxes)}</strong> — {tg.totalTtc} : <strong>{montant(simulation.resultat.montant_ttc)}</strong></p>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* Formulaire créer / modifier */}
      {edition && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-bold dark:text-white">{edition.id ? tg.editGroup : tg.newGroup}</h3>
              <button onClick={() => setEdition(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tg.cancel}>
                <X size={18} />
              </button>
            </div>
            <form onSubmit={enregistrer} className="space-y-4">
              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{tg.code} *</label>
                  <input value={edition.donnees.code} maxLength={20} required className={classeChamp}
                    onChange={e => setEdition({ ...edition, donnees: { ...edition.donnees, code: e.target.value.toUpperCase() } })} />
                </div>
                <div className="col-span-2">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{tg.name} *</label>
                  <input value={edition.donnees.nom} maxLength={100} required className={classeChamp}
                    onChange={e => setEdition({ ...edition, donnees: { ...edition.donnees, nom: e.target.value } })} />
                </div>
              </div>

              <div>
                <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{tg.taxes}</p>
                {taxes.length === 0 ? (
                  <p className="text-sm text-gray-500">{tg.declareTaxesFirst}</p>
                ) : (
                  <div className="space-y-2">
                    {edition.donnees.lignes.length === 0 && <p className="text-sm text-gray-500">{tg.exempt}</p>}
                    {edition.donnees.lignes.map((ligne, index) => (
                      <div key={index} className="flex items-center gap-2">
                        <span className="w-5 text-xs text-gray-400">{index + 1}.</span>
                        <select value={ligne.tenant_taxe_id} aria-label={tg.tax} className={classeChamp}
                          onChange={e => modifierLignes(l => l.map((x, i) => (i === index ? { ...x, tenant_taxe_id: Number(e.target.value) } : x)))}>
                          {taxes.map(tx => <option key={tx.id} value={tx.id}>{tx.nom} ({pourcentage(tx.taux)})</option>)}
                        </select>
                        <select value={ligne.applique_sur} aria-label={tg.appliesTo} className={classeChamp}
                          onChange={e => modifierLignes(l => l.map((x, i) => (i === index ? { ...x, applique_sur: e.target.value as AppliqueSur } : x)))}>
                          {MODES.filter(m => index > 0 || m !== 'taxe_precedente').map(m => <option key={m} value={m}>{tg.modes[m]}</option>)}
                        </select>
                        <button type="button" disabled={index === 0} onClick={() => deplacer(index, -1)} title={tg.moveUp}
                          className="p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 rounded disabled:opacity-30"><ArrowUp size={14} /></button>
                        <button type="button" disabled={index === edition.donnees.lignes.length - 1} onClick={() => deplacer(index, 1)} title={tg.moveDown}
                          className="p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 rounded disabled:opacity-30"><ArrowDown size={14} /></button>
                        <button type="button" onClick={() => modifierLignes(l => l.filter((_, i) => i !== index))} title={tg.remove}
                          className="p-1 text-red-500 hover:bg-red-50 rounded"><X size={14} /></button>
                      </div>
                    ))}
                    {edition.donnees.lignes.length < Math.min(5, taxes.length) && (
                      <button type="button" onClick={() => modifierLignes(nouvelleLigne)}
                        className="flex items-center gap-1 text-sm text-purple-700 dark:text-purple-300 hover:underline">
                        <Plus size={14} /> {tg.addTax}
                      </button>
                    )}
                  </div>
                )}
              </div>

              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" checked={edition.donnees.par_defaut} className="rounded"
                  onChange={e => setEdition({ ...edition, donnees: { ...edition.donnees, par_defaut: e.target.checked } })} />
                {tg.default}
              </label>

              {erreurEdition && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreurEdition}</p>}

              <div className="flex gap-3 justify-end pt-2">
                <button type="button" onClick={() => setEdition(null)}
                  className="px-4 py-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tg.cancel}</button>
                <button type="submit" className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                  {edition.id ? tg.save : tg.create}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
