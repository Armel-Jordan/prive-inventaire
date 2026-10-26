import { useEffect, useState } from 'react';
import { Palette, RotateCcw } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useStatutsDocuments, statutsDocumentsModifies } from '@/hooks/useStatutsDocuments';
import {
  getStatutsDocuments, reglerStatutDocument, statutDocumentParDefaut,
  type DocumentAStatuts, type StatutDocument,
} from '@/services/statutsDocuments';
import { signaler } from '@/lib/erreurs';

const DOCUMENTS: DocumentAStatuts[] = ['devis', 'commande_client', 'facture', 'bon_livraison', 'commande_fournisseur'];

/** Ligne en cours de réglage : libellés et couleurs du statut. */
type Saisie = Pick<StatutDocument, 'libelle_fr' | 'libelle_en' | 'couleur_fond' | 'couleur_texte'>;

/**
 * Paramètres → Statuts des documents (« Statuts transactions » de fripv2) : libellés (fr, en) et couleurs (fond, texte)
 * des statuts système de chaque type de document, avec un aperçu du badge et le retour au défaut. Seul l'affichage
 * change ; les étiquettes libres sont les statuts personnalisés (Référentiels).
 */
export default function StatutsDocumentsPage() {
  const { t } = useLanguage();
  const ts = t.documentStatuses;
  const { peut } = usePermissions();
  const modifiable = peut('configuration', 'modifier');
  const liste = useStatutsDocuments();
  const [document, setDocument] = useState<DocumentAStatuts>('devis');
  const [saisies, setSaisies] = useState<Record<string, Saisie>>({});
  const [enCours, setEnCours] = useState<string | null>(null);

  // Liste fraîche à l'ouverture de l'écran (un autre poste a pu la changer).
  useEffect(() => {
    getStatutsDocuments().then(statutsDocumentsModifies, () => {});
  }, []);

  const statuts = liste?.[document] ?? [];
  const valeur = (s: StatutDocument): Saisie => saisies[`${document}:${s.code}`] ?? s;
  const changer = (s: StatutDocument, champs: Partial<Saisie>) => setSaisies(v => ({ ...v, [`${document}:${s.code}`]: { ...valeur(s), ...champs } }));

  async function enregistrer(s: StatutDocument, retour: boolean) {
    setEnCours(s.code);
    try {
      const v = valeur(s);
      const nouveaux = retour
        ? await statutDocumentParDefaut(document, s.code)
        : await reglerStatutDocument(document, s.code, {
          libelle_fr: v.libelle_fr.trim() || null, libelle_en: v.libelle_en.trim() || null, couleur_fond: v.couleur_fond, couleur_texte: v.couleur_texte,
        });
      if (liste) statutsDocumentsModifies({ ...liste, [document]: nouveaux });
      setSaisies(v2 => { const copie = { ...v2 }; delete copie[`${document}:${s.code}`]; return copie; });
    } catch (erreur) {
      signaler(erreur, ts.error);
    } finally {
      setEnCours(null);
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Palette size={24} /> {ts.title}</h1>
        <p className="text-gray-600 dark:text-gray-300">{ts.subtitle}</p>
        {!modifiable && <p className="text-sm text-gray-500 mt-1">{ts.readOnly}</p>}
      </div>

      <div className="flex flex-wrap gap-2 mb-4" role="tablist">
        {DOCUMENTS.map(d => (
          <button key={d} type="button" role="tab" aria-selected={d === document} onClick={() => setDocument(d)}
            className={`px-3 py-1.5 rounded-lg text-sm border ${d === document ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600'}`}>
            {ts.documents[d]}
          </button>
        ))}
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-gray-600 dark:text-gray-300 border-b dark:border-gray-700">
              <th className="px-4 py-3">{ts.code}</th>
              <th className="px-4 py-3">{ts.labelFr}</th>
              <th className="px-4 py-3">{ts.labelEn}</th>
              <th className="px-4 py-3">{ts.background}</th>
              <th className="px-4 py-3">{ts.text}</th>
              <th className="px-4 py-3">{ts.preview}</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody>
            {statuts.map(s => {
              const v = valeur(s);
              const change = Boolean(saisies[`${document}:${s.code}`]);
              return (
                <tr key={s.code} className="border-b last:border-0 dark:border-gray-700">
                  <td className="px-4 py-2 font-mono text-xs text-gray-500">{s.code}</td>
                  <td className="px-4 py-2">
                    <input value={v.libelle_fr} maxLength={60} disabled={!modifiable} onChange={e => changer(s, { libelle_fr: e.target.value })} aria-label={`${ts.labelFr} — ${s.code}`}
                      placeholder={s.defaut.libelle_fr} className="w-full px-2 py-1 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                  </td>
                  <td className="px-4 py-2">
                    <input value={v.libelle_en} maxLength={60} disabled={!modifiable} onChange={e => changer(s, { libelle_en: e.target.value })} aria-label={`${ts.labelEn} — ${s.code}`}
                      placeholder={s.defaut.libelle_en} className="w-full px-2 py-1 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                  </td>
                  <td className="px-4 py-2">
                    <input type="color" value={v.couleur_fond} disabled={!modifiable} onChange={e => changer(s, { couleur_fond: e.target.value })} aria-label={`${ts.background} — ${s.code}`}
                      className="h-8 w-14 border rounded cursor-pointer" />
                  </td>
                  <td className="px-4 py-2">
                    <input type="color" value={v.couleur_texte} disabled={!modifiable} onChange={e => changer(s, { couleur_texte: e.target.value })} aria-label={`${ts.text} — ${s.code}`}
                      className="h-8 w-14 border rounded cursor-pointer" />
                  </td>
                  <td className="px-4 py-2">
                    <span className="px-2 py-1 text-xs rounded-full whitespace-nowrap" style={{ backgroundColor: v.couleur_fond, color: v.couleur_texte }}>{v.libelle_fr || s.defaut.libelle_fr}</span>
                  </td>
                  <td className="px-4 py-2 whitespace-nowrap text-right">
                    {modifiable && (
                      <>
                        <button type="button" disabled={!change || enCours === s.code} onClick={() => enregistrer(s, false)}
                          className="px-3 py-1 text-sm bg-blue-600 text-white rounded-lg disabled:opacity-40">{t.common.save}</button>
                        {s.regle && (
                          <button type="button" disabled={enCours === s.code} onClick={() => enregistrer(s, true)} title={ts.reset} aria-label={`${ts.reset} — ${s.code}`}
                            className="ml-2 p-1 text-gray-600 hover:bg-gray-100 rounded dark:text-gray-300"><RotateCcw size={16} /></button>
                        )}
                      </>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
