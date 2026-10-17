import { useEffect, useMemo, useState } from 'react';
import { FileUp, Trash2, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { formatMontant } from '@/lib/documents';
import { getFournisseursActifs, type Fournisseur } from '@/services/api';
import {
  enregistrerProfilImport, getProfilsImport, importerGrillePrix, lireCsv, supprimerProfilImport,
  type ProfilImport, type RapportImportPrix, type ReglagesImportPrix,
} from '@/services/importPrix';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-xs text-gray-500 dark:text-gray-400';
const normaliser = (texte: string) => texte.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

/** Colonnes proposées d'après les en-têtes du fichier (fournisseur, article, coût). */
function deviner(entetes: string[]): Partial<ReglagesImportPrix> {
  const trouver = (motifs: RegExp) => {
    const index = entetes.findIndex(e => motifs.test(normaliser(e)));
    return index >= 0 ? index + 1 : undefined;
  };
  return {
    colonne_fournisseur: trouver(/fournisseur|supplier|vendor/),
    colonne_code: trouver(/sku|article|ref|code|produit|product|item|numero/),
    colonne_cout: trouver(/cout|cost|prix|price/),
  };
}

/**
 * Import d'une grille de prix fournisseur : fichier CSV, colonnes associées (ou profil mémorisé), simulation puis import ;
 * le rapport liste les prix changés et les lignes ignorées avec leur motif.
 */
export default function ImportGrillePrix({ onClose }: { onClose: () => void }) {
  const { t, language } = useLanguage();
  const ti = t.supplierPriceImport;
  const prix = (valeur: number | null) => (valeur === null ? '—' : formatMontant(valeur, '', language));
  const [fichier, setFichier] = useState<File | null>(null);
  const [lignes, setLignes] = useState<string[][]>([]);
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [profils, setProfils] = useState<ProfilImport[]>([]);
  const [nomProfil, setNomProfil] = useState('');
  const [modeFournisseur, setModeFournisseur] = useState<'colonne' | 'fixe'>('colonne');
  const [reglages, setReglages] = useState<ReglagesImportPrix>({ colonne_fournisseur: 1, colonne_code: 2, colonne_cout: 3, ligne_debut: 2, fournisseur_id: null });
  const [rapport, setRapport] = useState<RapportImportPrix | null>(null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getFournisseursActifs().then(f => { if (actif) setFournisseurs(f ?? []); }, () => {});
    getProfilsImport().then(p => { if (actif) setProfils(p ?? []); }, () => {});
    return () => { actif = false; };
  }, []);

  const colonnes = useMemo(() => Math.max(0, ...lignes.slice(0, 20).map(l => l.length)), [lignes]);
  const entetes = reglages.ligne_debut > 1 ? lignes[reglages.ligne_debut - 2] ?? [] : [];
  const libelleColonne = (n: number) => `${ti.column} ${n}${entetes[n - 1] ? ` — ${entetes[n - 1]}` : ''}`;

  async function choisirFichier(choisi: File | null) {
    setFichier(choisi);
    setRapport(null);
    setErreur('');
    if (!choisi) { setLignes([]); return; }
    const lues = lireCsv(await choisi.text());
    setLignes(lues);
    // Colonnes devinées d'après les en-têtes de la première ligne.
    const devinees = deviner(lues[0] ?? []);
    setReglages(r => ({ ...r, ...Object.fromEntries(Object.entries(devinees).filter(([, v]) => v !== undefined)) }));
  }

  function appliquerProfil(id: string) {
    const profil = profils.find(p => p.id === Number(id));
    if (!profil) return;
    setReglages({ ...reglages, ...profil.reglages });
    setModeFournisseur(profil.reglages.fournisseur_id ? 'fixe' : 'colonne');
    setNomProfil(profil.nom);
  }

  const envoyes = (): ReglagesImportPrix => (modeFournisseur === 'fixe'
    ? { ...reglages, colonne_fournisseur: null }
    : { ...reglages, fournisseur_id: null });

  async function lancer(simuler: boolean) {
    if (!fichier) return;
    setOccupe(true);
    setErreur('');
    try {
      setRapport(await importerGrillePrix(fichier, envoyes(), simuler));
    } catch (e) {
      setErreur(messageErreur(e, ti.error));
    } finally {
      setOccupe(false);
    }
  }

  async function memoriser() {
    if (!nomProfil.trim()) return;
    try {
      const profil = await enregistrerProfilImport(nomProfil.trim(), envoyes());
      setProfils(p => [...p.filter(x => x.id !== profil.id), profil].sort((a, b) => a.nom.localeCompare(b.nom)));
    } catch (e) {
      setErreur(messageErreur(e, ti.error));
    }
  }

  async function oublier(id: number) {
    try {
      await supprimerProfilImport(id);
      setProfils(p => p.filter(x => x.id !== id));
    } catch (e) {
      setErreur(messageErreur(e, ti.error));
    }
  }

  const choixColonne = (valeur: number | null | undefined, onChange: (v: number) => void, libelle: string) => (
    <label className={classeLibelle}>{libelle}
      <select value={valeur ?? ''} onChange={e => onChange(Number(e.target.value))} className={classeChamp}>
        <option value="" disabled>{ti.choose}</option>
        {Array.from({ length: Math.max(colonnes, 3) }, (_, i) => i + 1).map(n => <option key={n} value={n}>{libelleColonne(n)}</option>)}
      </select>
    </label>
  );

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-label={ti.title} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-4xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2"><FileUp size={20} className="text-blue-600" /> {ti.title}</h2>
            <p className="text-xs text-gray-500 dark:text-gray-400 max-w-2xl">{ti.help}</p>
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-white" aria-label={ti.close}><X size={18} /></button>
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <label className={classeLibelle}>{ti.file}
            <input type="file" accept=".csv,.txt,text/csv" onChange={e => { void choisirFichier(e.target.files?.[0] ?? null); }} className={classeChamp} />
          </label>
          <div className="flex items-end gap-2">
            <label className={`${classeLibelle} flex-1`}>{ti.profile}
              <select value={profils.find(p => p.nom === nomProfil)?.id ?? ''} onChange={e => appliquerProfil(e.target.value)} className={classeChamp}>
                <option value="">{ti.noProfile}</option>
                {profils.map(p => <option key={p.id} value={p.id}>{p.nom}</option>)}
              </select>
            </label>
            {profils.some(p => p.nom === nomProfil) && (
              <button type="button" onClick={() => { const p = profils.find(x => x.nom === nomProfil); if (p && confirm(ti.confirmDelete)) void oublier(p.id); }}
                className="p-2 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" aria-label={ti.deleteProfile}><Trash2 size={16} /></button>
            )}
          </div>
        </div>

        {lignes.length > 0 && (
          <div className="overflow-x-auto border rounded-lg dark:border-gray-700" aria-label={ti.preview}>
            <table className="w-full text-xs">
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-gray-500">
                <tr><th className="px-2 py-1 text-left">#</th>{Array.from({ length: colonnes }, (_, i) => <th key={i} className="px-2 py-1 text-left">{ti.column} {i + 1}</th>)}</tr>
              </thead>
              <tbody>
                {lignes.slice(0, 6).map((ligne, i) => (
                  <tr key={i} className={`border-t dark:border-gray-700 dark:text-gray-200 ${i + 1 < reglages.ligne_debut ? 'text-gray-400' : ''}`}>
                    <td className="px-2 py-1">{i + 1}</td>
                    {Array.from({ length: colonnes }, (_, j) => <td key={j} className="px-2 py-1 whitespace-nowrap">{ligne[j] ?? ''}</td>)}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-2">
            <p className="text-xs text-gray-500 dark:text-gray-400">{ti.supplier}</p>
            <label className="flex items-center gap-2 text-sm dark:text-gray-200"><input type="radio" checked={modeFournisseur === 'colonne'} onChange={() => setModeFournisseur('colonne')} /> {ti.supplierColumn}</label>
            {modeFournisseur === 'colonne' && choixColonne(reglages.colonne_fournisseur, v => setReglages({ ...reglages, colonne_fournisseur: v }), ti.supplierCode)}
            <label className="flex items-center gap-2 text-sm dark:text-gray-200"><input type="radio" checked={modeFournisseur === 'fixe'} onChange={() => setModeFournisseur('fixe')} /> {ti.supplierFixed}</label>
            {modeFournisseur === 'fixe' && (
              <select value={reglages.fournisseur_id ?? ''} aria-label={ti.supplierFixed} onChange={e => setReglages({ ...reglages, fournisseur_id: Number(e.target.value) || null })} className={classeChamp}>
                <option value="">{ti.choose}</option>
                {fournisseurs.map(f => <option key={f.id} value={f.id}>{f.code} — {f.raison_sociale}</option>)}
              </select>
            )}
          </div>
          <div className="space-y-2">
            {choixColonne(reglages.colonne_code, v => setReglages({ ...reglages, colonne_code: v }), ti.code)}
            {choixColonne(reglages.colonne_cout, v => setReglages({ ...reglages, colonne_cout: v }), ti.cost)}
            <label className={classeLibelle}>{ti.firstLine}
              <input type="number" min="1" value={reglages.ligne_debut} onChange={e => setReglages({ ...reglages, ligne_debut: Math.max(1, Number(e.target.value) || 1) })} className={classeChamp} />
            </label>
          </div>
        </div>

        <div className="flex flex-wrap items-end gap-2">
          <label className={`${classeLibelle} w-56`}>{ti.saveAs}
            <input value={nomProfil} maxLength={100} onChange={e => setNomProfil(e.target.value)} className={classeChamp} />
          </label>
          <button type="button" onClick={() => { void memoriser(); }} disabled={!nomProfil.trim()} className="px-3 py-2 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700 disabled:opacity-50">{ti.saveProfile}</button>
          <div className="ml-auto flex gap-2">
            <button type="button" disabled={!fichier || occupe} onClick={() => { void lancer(true); }} className="px-4 py-2 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700 disabled:opacity-50">{ti.simulate}</button>
            <button type="button" disabled={!fichier || occupe} onClick={() => { void lancer(false); }} className="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700 disabled:opacity-50">{ti.import}</button>
          </div>
        </div>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

        {rapport && (
          <div className="space-y-3" aria-label={ti.report}>
            <p className={`px-3 py-2 rounded-lg text-sm ${rapport.simulation ? 'bg-blue-50 text-blue-800' : 'bg-emerald-50 text-emerald-800'}`}>{rapport.message}</p>
            {rapport.changements.length > 0 && (
              <table className="w-full text-sm">
                <thead className="text-left text-xs uppercase text-gray-500 border-b dark:border-gray-700">
                  <tr><th className="py-1 pr-2">{ti.line}</th><th className="py-1 pr-2">{ti.supplier}</th><th className="py-1 pr-2">{ti.product}</th><th className="py-1 pr-2 text-right">{ti.oldPrice}</th><th className="py-1 text-right">{ti.newPrice}</th></tr>
                </thead>
                <tbody>
                  {rapport.changements.map(c => (
                    <tr key={`${c.ligne}`} className="border-b dark:border-gray-700 dark:text-gray-200">
                      <td className="py-1 pr-2">{c.ligne}</td><td className="py-1 pr-2">{c.fournisseur}</td><td className="py-1 pr-2 font-mono">{c.produit}</td>
                      <td className="py-1 pr-2 text-right">{prix(c.ancien)}</td><td className="py-1 text-right font-medium">{prix(c.nouveau)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            {rapport.ignorees.length > 0 && (
              <div>
                <h3 className="text-sm font-semibold text-amber-800 dark:text-amber-300">{ti.ignored.replace('{n}', String(rapport.ignorees.length))}</h3>
                <table className="w-full text-sm">
                  <thead className="text-left text-xs uppercase text-gray-500 border-b dark:border-gray-700">
                    <tr><th className="py-1 pr-2">{ti.line}</th><th className="py-1 pr-2">{ti.supplier}</th><th className="py-1 pr-2">{ti.code}</th><th className="py-1">{ti.reason}</th></tr>
                  </thead>
                  <tbody>
                    {rapport.ignorees.map(i => (
                      <tr key={i.ligne} className="border-b dark:border-gray-700 dark:text-gray-200">
                        <td className="py-1 pr-2">{i.ligne}</td><td className="py-1 pr-2">{i.fournisseur}</td><td className="py-1 pr-2 font-mono">{i.code}</td><td className="py-1">{i.motif}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
