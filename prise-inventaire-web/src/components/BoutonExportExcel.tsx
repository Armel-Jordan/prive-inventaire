import { useState } from 'react';
import { FileSpreadsheet } from 'lucide-react';
import { apiFetch } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';

type Valeur = string | number | boolean | null | undefined;

/**
 * Bouton « Exporter » d'une liste (S5) : toutes les lignes de la liste avec ses filtres du moment, en classeur Excel
 * (`?format=xlsx` sur la route de la liste, mêmes droits que sa lecture).
 */
export default function BoutonExportExcel({ chemin, parametres, fichier, className = '' }: {
  /** Route de la liste, ex. « /devis ». */
  chemin: string;
  /** Filtres de la liste (les vides sont omis ; vrai devient 1). */
  parametres: Record<string, Valeur>;
  /** Début du nom du fichier, suivi de la date. */
  fichier: string;
  className?: string;
}) {
  const { t } = useLanguage();
  const te = t.listExport;
  const [envoi, setEnvoi] = useState(false);

  const exporter = async () => {
    const requete = new URLSearchParams();
    Object.entries(parametres).forEach(([cle, valeur]) => {
      if (valeur === undefined || valeur === null || valeur === '' || valeur === false) return;
      requete.append(cle, valeur === true ? '1' : String(valeur));
    });
    requete.append('format', 'xlsx');
    setEnvoi(true);
    try {
      const classeur = await apiFetch<Blob>(`${chemin}?${requete.toString()}`, { responseType: 'blob', timeoutMs: 120000 });
      const lien = document.createElement('a');
      lien.href = URL.createObjectURL(classeur);
      lien.download = `${fichier}-${new Date().toISOString().slice(0, 10)}.xlsx`;
      lien.click();
      setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
    } catch {
      alert(te.error);
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <button type="button" onClick={exporter} disabled={envoi} title={te.hint}
      className={`inline-flex items-center gap-1 px-3 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 ${className}`}>
      <FileSpreadsheet size={14} /> {envoi ? te.busy : te.button}
    </button>
  );
}
