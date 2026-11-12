import { useEffect, useState } from 'react';
import { getReferentiel, poserNoteBasPage, type DocumentPrioritaire } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';

interface Props {
  document: DocumentPrioritaire;
  documentId: number;
  /** Note retenue : vide, celle par défaut ; 0, aucune. */
  valeur: number | null | undefined;
  /** Document envoyé ou émis : sa note est figée. */
  fige: boolean;
  modifiable: boolean;
  onChange: (noteId: number | null) => void;
}

/**
 * Note de bas de page d'un document (socle S4.4), dans son détail : celle par défaut de son type, une autre ou aucune ;
 * enregistrée tout de suite, tant que le document n'est ni envoyé ni émis.
 */
export default function ChoixNoteBasPage({ document, documentId, valeur, fige, modifiable, onChange }: Props) {
  const { t } = useLanguage();
  const tn = t.footerNotes;
  const [notes, setNotes] = useState<{ id: number; nom: string }[]>([]);
  const [envoi, setEnvoi] = useState(false);

  useEffect(() => {
    let actif = true;
    getReferentiel('notes_bas_page', true).then(liste => {
      if (actif) setNotes(liste.filter(n => n.document === document).map(n => ({ id: n.id, nom: String(n.nom ?? '') })));
    }, () => {});
    return () => { actif = false; };
  }, [document]);

  const choisir = async (texte: string) => {
    const id = texte === '' ? null : Number(texte);
    setEnvoi(true);
    try {
      onChange((await poserNoteBasPage(document, documentId, id)).note_bas_page_id);
    } catch {
      alert(tn.error);
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <div>
      <label htmlFor={`note-bas-page-${documentId}`} className="text-sm text-gray-500">{tn.label} :</label>
      <select id={`note-bas-page-${documentId}`} value={valeur === null || valeur === undefined ? '' : String(valeur)} disabled={fige || !modifiable || envoi}
        onChange={e => choisir(e.target.value)}
        className="mt-1 w-full px-2 py-1 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white disabled:opacity-60">
        <option value="">{tn.default}</option>
        <option value="0">{tn.none}</option>
        {notes.map(n => <option key={n.id} value={n.id}>{n.nom}</option>)}
      </select>
      {fige && <p className="text-xs text-gray-500 mt-1">{tn.frozen}</p>}
    </div>
  );
}
