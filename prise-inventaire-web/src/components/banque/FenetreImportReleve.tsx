import { useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { importerReleve, type CompteBanque, type RapportImportReleve, type ReglagesImportReleve } from '@/services/banque';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const LIGNES_APERCU = 6;

type Colonne = 'colonne_date' | 'colonne_libelle' | 'colonne_montant' | 'colonne_debit' | 'colonne_credit' | 'colonne_reference';
type Saisie = Record<Colonne | 'ligne_debut', string> & { format_date: '' | 'jma' | 'amj' | 'mja' };

/** Cellules d'une ligne CSV (guillemets doublés compris). */
function decouper(ligne: string, separateur: string): string[] {
  const cellules: string[] = [];
  let courante = '';
  let entreGuillemets = false;
  for (let i = 0; i < ligne.length; i++) {
    const c = ligne[i];
    if (c === '"') {
      if (entreGuillemets && ligne[i + 1] === '"') {
        courante += '"';
        i++;
      } else {
        entreGuillemets = !entreGuillemets;
      }
    } else if (c === separateur && !entreGuillemets) {
      cellules.push(courante.trim());
      courante = '';
    } else {
      courante += c;
    }
  }
  cellules.push(courante.trim());
  return cellules;
}

const texte = (valeur: number | undefined) => (valeur ? String(valeur) : '');
const nombre = (valeur: string) => (Number(valeur) > 0 ? Math.trunc(Number(valeur)) : undefined);

/**
 * Import d'un relevé bancaire : un fichier OFX ou CAMT se lit tel quel ; pour un CSV, les colonnes de la date, du libellé, du
 * montant (ou du débit et du crédit) et de la référence, la première ligne et le format des dates, repris du dernier
 * import du compte, avec le début du fichier sous les yeux. L'aperçu montre ce qui sera importé, sans rien enregistrer.
 */
export default function FenetreImportReleve({ compte, reglages, onClose, onImporte }: {
  compte: CompteBanque;
  reglages: ReglagesImportReleve | null;
  onClose: () => void;
  onImporte: (rapport: RapportImportReleve) => void;
}) {
  const { t, language } = useLanguage();
  const ts = t.bank.statements;
  const montant = useMontantDocument();
  const [fichier, setFichier] = useState<File | null>(null);
  // Fichier structuré (OFX, CAMT) : lu sans réglage de colonnes.
  const [structure, setStructure] = useState<'OFX' | 'CAMT' | null>(null);
  const [debut, setDebut] = useState<{ numero: number; cellules: string[] }[]>([]);
  const [debitCredit, setDebitCredit] = useState(!reglages?.colonne_montant && Boolean(reglages?.colonne_debit || reglages?.colonne_credit));
  const [saisie, setSaisie] = useState<Saisie>({
    colonne_date: texte(reglages?.colonne_date ?? 1),
    colonne_libelle: texte(reglages?.colonne_libelle ?? 2),
    colonne_montant: texte(reglages?.colonne_montant ?? (reglages?.colonne_debit || reglages?.colonne_credit ? undefined : 3)),
    colonne_debit: texte(reglages?.colonne_debit),
    colonne_credit: texte(reglages?.colonne_credit),
    colonne_reference: texte(reglages?.colonne_reference),
    ligne_debut: texte(reglages?.ligne_debut ?? 2),
    format_date: reglages?.format_date ?? '',
  });
  const [rapport, setRapport] = useState<RapportImportReleve | null>(null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function choisir(choisi: File | null) {
    setFichier(choisi);
    setRapport(null);
    setErreur('');
    setDebut([]);
    setStructure(null);
    if (!choisi) return;
    let contenu = await choisi.slice(0, 65536).text();
    if (contenu.charCodeAt(0) === 0xfeff) contenu = contenu.slice(1);
    // Mêmes détections que l'API.
    if (/OFXHEADER|<OFX>/i.test(contenu.slice(0, 2000))) {
      setStructure('OFX');
      return;
    }
    if (/camt\.05[234]|<(\w+:)?BkToCstmr/i.test(contenu.slice(0, 4000))) {
      setStructure('CAMT');
      return;
    }
    const lignes = contenu.split(/\r\n|\r|\n/).map((ligne, i) => ({ ligne, numero: i + 1 })).filter(l => l.ligne.trim() !== '').slice(0, LIGNES_APERCU);
    // Le séparateur le plus présent sur la première ligne, comme l'API.
    const premiere = lignes[0]?.ligne ?? '';
    const separateur = [';', '\t', ','].map(s => ({ s, n: premiere.split(s).length })).sort((a, b) => b.n - a.n)[0].s;
    setDebut(lignes.map(l => ({ numero: l.numero, cellules: decouper(l.ligne, separateur) })));
  }

  function reglagesSaisis(): ReglagesImportReleve {
    if (structure) return {};
    return {
      colonne_date: nombre(saisie.colonne_date),
      colonne_libelle: nombre(saisie.colonne_libelle),
      colonne_montant: debitCredit ? undefined : nombre(saisie.colonne_montant),
      colonne_debit: debitCredit ? nombre(saisie.colonne_debit) : undefined,
      colonne_credit: debitCredit ? nombre(saisie.colonne_credit) : undefined,
      colonne_reference: nombre(saisie.colonne_reference),
      ligne_debut: nombre(saisie.ligne_debut),
      format_date: saisie.format_date || undefined,
    };
  }

  async function envoyer(simulation: boolean) {
    if (!fichier) return;
    setOccupe(true);
    setErreur('');
    try {
      const resultat = await importerReleve(compte.id, fichier, reglagesSaisis(), simulation);
      if (simulation) setRapport(resultat);
      else onImporte(resultat);
    } catch (e) {
      setErreur(messageErreur(e, t.bank.error));
    } finally {
      setOccupe(false);
    }
  }

  // Rôle de chaque colonne du fichier, pour le début du fichier.
  const roles: Partial<Record<number, string>> = {};
  const marquer = (colonne: string, role: string) => { if (Number(colonne) > 0) roles[Number(colonne)] = role; };
  marquer(saisie.colonne_date, ts.dateColumn);
  marquer(saisie.colonne_libelle, ts.labelColumn);
  if (debitCredit) {
    marquer(saisie.colonne_debit, ts.debitColumn);
    marquer(saisie.colonne_credit, ts.creditColumn);
  } else {
    marquer(saisie.colonne_montant, ts.amountColumn);
  }
  marquer(saisie.colonne_reference, ts.referenceColumn);
  const nbColonnes = Math.max(0, ...debut.map(l => l.cellules.length));
  const champ = (cle: Colonne | 'ligne_debut', libelle: string, requis = false) => (
    <label className="block text-xs text-gray-500 dark:text-gray-400">{libelle}
      <input type="number" min={1} max={cle === 'ligne_debut' ? 1000 : 100} required={requis} value={saisie[cle]}
        onChange={e => { setSaisie({ ...saisie, [cle]: e.target.value }); setRapport(null); }} className={classeChamp} />
    </label>
  );

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <form role="dialog" aria-modal="true" aria-labelledby="import-releve-titre" onSubmit={e => { e.preventDefault(); envoyer(false); }}
        className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-4xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <h2 id="import-releve-titre" className="text-lg font-bold text-gray-900 dark:text-white">{ts.importTitle.replace('{compte}', compte.nom)}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={t.bank.close}><X size={18} /></button>
        </div>

        <label className="block text-xs text-gray-500 dark:text-gray-400">{ts.file}
          <input type="file" required accept=".csv,.txt,.ofx,.qfx,.xml,text/csv" onChange={e => choisir(e.target.files?.[0] ?? null)}
            className="block w-full mt-1 text-sm text-gray-700 dark:text-gray-200" />
        </label>
        {structure && <p className="text-sm text-gray-600 dark:text-gray-300">{ts.structuredDetected.replace('{format}', structure)}</p>}

        {fichier && !structure && (
          <>
            <p className="text-xs text-gray-500">{ts.columnsHelp}</p>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              {champ('colonne_date', ts.dateColumn, true)}
              {champ('colonne_libelle', ts.labelColumn, true)}
              {champ('colonne_reference', ts.referenceColumn)}
              {champ('ligne_debut', ts.firstLine)}
            </div>
            <fieldset className="space-y-2">
              <legend className="text-xs text-gray-500 dark:text-gray-400">{ts.amounts}</legend>
              <div className="flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-200">
                <label className="flex items-center gap-2"><input type="radio" name="montants" checked={!debitCredit} onChange={() => { setDebitCredit(false); setRapport(null); }} /> {ts.signedAmount}</label>
                <label className="flex items-center gap-2"><input type="radio" name="montants" checked={debitCredit} onChange={() => { setDebitCredit(true); setRapport(null); }} /> {ts.debitCredit}</label>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                {debitCredit ? (
                  <>
                    {champ('colonne_debit', ts.debitColumn)}
                    {champ('colonne_credit', ts.creditColumn)}
                  </>
                ) : champ('colonne_montant', ts.amountColumn, true)}
                <label className="block text-xs text-gray-500 dark:text-gray-400">{ts.dateFormat}
                  <select value={saisie.format_date} onChange={e => { setSaisie({ ...saisie, format_date: e.target.value as Saisie['format_date'] }); setRapport(null); }} className={classeChamp}>
                    {(['', 'jma', 'amj', 'mja'] as const).map(f => <option key={f} value={f}>{ts.dateFormats[f]}</option>)}
                  </select>
                </label>
              </div>
            </fieldset>
            {debut.length > 0 && (
              <div className="overflow-x-auto border rounded-lg dark:border-gray-700">
                <table className="w-full text-xs" aria-label={ts.fileStart}>
                  <caption className="text-left px-2 py-1 text-gray-500">{ts.fileStart}</caption>
                  <thead className="bg-gray-50 dark:bg-gray-700/50 text-gray-600 dark:text-gray-300">
                    <tr>
                      <th className="px-2 py-1" />
                      {Array.from({ length: nbColonnes }, (_, i) => (
                        <th key={i} className={`px-2 py-1 text-left whitespace-nowrap ${roles[i + 1] ? 'text-blue-700 dark:text-blue-300' : ''}`}>
                          {ts.columnN.replace('{n}', String(i + 1))}{roles[i + 1] ? <span className="block font-normal">{roles[i + 1]}</span> : null}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                    {debut.map(l => (
                      <tr key={l.numero} className={l.numero < (Number(saisie.ligne_debut) || 1) ? 'text-gray-400' : ''}>
                        <td className="px-2 py-1 text-gray-500 whitespace-nowrap">{ts.lineN.replace('{n}', String(l.numero))}</td>
                        {Array.from({ length: nbColonnes }, (_, i) => <td key={i} className="px-2 py-1 whitespace-nowrap">{l.cellules[i] ?? ''}</td>)}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}

        {rapport && (
          <div className="space-y-2" role="status">
            <p className="text-sm text-gray-700 dark:text-gray-200">
              {ts.report.replace('{lues}', String(rapport.lues)).replace('{nouvelles}', String(rapport.nouvelles)).replace('{doublons}', String(rapport.doublons)).replace('{total}', montant(rapport.total))}
            </p>
            {rapport.ignorees.length > 0 && (
              <div className="text-xs text-amber-800 dark:text-amber-200">
                <p className="font-medium">{ts.ignored}</p>
                <ul className="list-disc pl-5">
                  {rapport.ignorees.slice(0, 20).map(i => <li key={i.ligne}>{ts.lineN.replace('{n}', String(i.ligne))} : {i.raison}</li>)}
                  {rapport.ignorees.length > 20 && <li>…</li>}
                </ul>
              </div>
            )}
            {rapport.apercu.length > 0 && (
              <div className="overflow-x-auto border rounded-lg dark:border-gray-700">
                <table className="w-full text-sm" aria-label={ts.firstOperations}>
                  <caption className="text-left px-3 py-1 text-xs text-gray-500">{ts.firstOperations}</caption>
                  <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                      <th className="px-3 py-1 text-left">{t.bank.date}</th>
                      <th className="px-3 py-1 text-left">{t.bank.label}</th>
                      <th className="px-3 py-1 text-left">{t.bank.reference}</th>
                      <th className="px-3 py-1 text-right">{t.bank.amount}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                    {rapport.apercu.map((o, i) => (
                      <tr key={i}>
                        <td className="px-3 py-1 whitespace-nowrap">{formatDateDocument(o.date, language)}</td>
                        <td className="px-3 py-1">{o.libelle}</td>
                        <td className="px-3 py-1">{o.reference ?? ''}</td>
                        <td className={`px-3 py-1 text-right whitespace-nowrap ${o.montant < 0 ? 'text-red-700 dark:text-red-300' : ''}`}>{montant(o.montant)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
        <div className="flex flex-wrap justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{t.bank.cancel}</button>
          <button type="button" disabled={!fichier || occupe} onClick={e => { if (e.currentTarget.form?.reportValidity()) envoyer(true); }}
            className="px-4 py-2 border border-blue-600 text-blue-700 dark:text-blue-300 rounded-lg text-sm hover:bg-blue-50 dark:hover:bg-blue-900/30 disabled:opacity-40">{ts.preview}</button>
          <button type="submit" disabled={!fichier || occupe} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">{ts.confirmImport}</button>
        </div>
      </form>
    </div>
  );
}
