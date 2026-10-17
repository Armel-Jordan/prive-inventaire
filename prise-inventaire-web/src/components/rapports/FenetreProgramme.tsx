import { useState } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { PERIODE_PAR_FREQUENCE, aUnePeriode, parametresProgrammables } from '@/lib/programmesRapports';
import ChampParametre, { type ListesParametres } from '@/components/rapports/ChampParametre';
import type { LangueCourriel } from '@/services/api';
import {
  enregistrerProgrammeRapport, FORMATS_RAPPORT,
  type DefinitionRapport, type FormatRapport, type FrequenceProgramme, type ProgrammeRapport, type ProgrammeSaisi,
} from '@/services/bibliothequeRapports';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block mb-1 text-xs text-gray-500 dark:text-gray-400';
const adresses = (texte: string) => texte.split(/[\s,;]+/).map(a => a.trim()).filter(Boolean);
const FREQUENCES: FrequenceProgramme[] = ['quotidienne', 'hebdomadaire', 'mensuelle'];

/** Valeurs des paramètres réglables d'un rapport : celles données, sinon leur valeur par défaut. */
const valeursDe = (rapport: DefinitionRapport | null, donnees?: Record<string, string | number> | null) =>
  Object.fromEntries((rapport ? parametresProgrammables(rapport) : []).map(p => {
    const valeur = donnees?.[p.cle] ?? p.defaut;
    return [p.cle, valeur !== undefined && valeur !== null ? String(valeur) : ''];
  }));

/**
 * Réglage d'un envoi programmé : le rapport et ses paramètres (sa période est relative : la veille, le mois
 * précédent…), la fréquence et le jour, le format et la langue, les destinataires.
 */
export default function FenetreProgramme({ programme, initial, categories, periodes, listes, onClose, onEnregistre }: {
  /** Envoi modifié ; null pour un nouvel envoi. */
  programme: ProgrammeRapport | null;
  /** Nouvel envoi : le rapport ouvert dans la bibliothèque et ses paramètres. */
  initial?: { code: string; parametres: Record<string, string> };
  categories: { code: string; rapports: DefinitionRapport[] }[];
  periodes: { code: string; libelle: string }[];
  listes: ListesParametres;
  onClose: () => void;
  onEnregistre: (programme: ProgrammeRapport) => void;
}) {
  const { t, language } = useLanguage();
  const tr = t.reportsLibrary;
  const ts = tr.schedules;
  const rapports = categories.flatMap(c => c.rapports);
  const codeDepart = programme?.code ?? initial?.code ?? rapports[0]?.code ?? '';
  const rapportDepart = rapports.find(r => r.code === codeDepart) ?? null;

  const [code, setCode] = useState(codeDepart);
  const [nom, setNom] = useState(programme?.nom ?? rapportDepart?.titre ?? '');
  const [parametres, setParametres] = useState<Record<string, string>>(() => valeursDe(rapportDepart, programme?.parametres ?? initial?.parametres));
  const [frequence, setFrequence] = useState<FrequenceProgramme>(programme?.frequence ?? 'mensuelle');
  const [jour, setJour] = useState(programme?.jour ?? 1);
  const [periode, setPeriode] = useState(programme?.periode ?? PERIODE_PAR_FREQUENCE[programme?.frequence ?? 'mensuelle']);
  const [format, setFormat] = useState<FormatRapport>(programme?.format ?? 'pdf');
  const [langue, setLangue] = useState<LangueCourriel>(programme?.langue ?? language);
  const [destinataires, setDestinataires] = useState((programme?.destinataires ?? []).join(', '));
  const [actif, setActif] = useState(programme?.actif ?? true);
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);

  const rapport = rapports.find(r => r.code === code) ?? null;
  const avecPeriode = rapport !== null && aUnePeriode(rapport);

  function changerRapport(nouveau: string) {
    const suivant = rapports.find(r => r.code === nouveau) ?? null;
    // Le nom suit le titre du rapport tant qu'il n'a pas été changé.
    if (!nom.trim() || nom === rapport?.titre) setNom(suivant?.titre ?? '');
    setCode(nouveau);
    setParametres(valeursDe(suivant));
  }

  function changerFrequence(suivante: FrequenceProgramme) {
    // La période suit la fréquence tant qu'elle est celle proposée d'office.
    if (periode === PERIODE_PAR_FREQUENCE[frequence]) setPeriode(PERIODE_PAR_FREQUENCE[suivante]);
    setFrequence(suivante);
    setJour(1);
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!rapport) return;
    setEnCours(true);
    setErreur('');
    const saisi: ProgrammeSaisi = {
      code, nom: nom.trim(), parametres, periode: avecPeriode ? periode : null, format, langue,
      destinataires: adresses(destinataires), frequence, jour: frequence === 'quotidienne' ? null : jour, actif,
    };
    try {
      onEnregistre(await enregistrerProgrammeRapport(programme?.id ?? null, saisi, language));
    } catch (err) {
      setErreur(messageErreur(err, ts.error));
      setEnCours(false);
    }
  }

  return createPortal(
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="titre-programme" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-2xl max-h-[92vh] overflow-y-auto text-left">
        <div className="flex items-center justify-between mb-4">
          <h3 id="titre-programme" className="text-lg font-bold dark:text-white">{programme ? ts.dialogEdit : ts.dialogNew}</h3>
          <button type="button" onClick={onClose} aria-label={ts.cancel} title={ts.cancel} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded dark:text-gray-300">
            <X size={18} />
          </button>
        </div>

        <form onSubmit={enregistrer} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label htmlFor="programme-rapport" className={classeLibelle}>{ts.report} *</label>
              <select id="programme-rapport" value={code} onChange={e => changerRapport(e.target.value)} required className={classeChamp}>
                {categories.map(c => (
                  <optgroup key={c.code} label={tr.categories[c.code] ?? c.code}>
                    {c.rapports.map(r => <option key={r.code} value={r.code}>{r.titre}</option>)}
                  </optgroup>
                ))}
              </select>
            </div>
            <div>
              <label htmlFor="programme-nom" className={classeLibelle}>{ts.name} *</label>
              <input id="programme-nom" value={nom} onChange={e => setNom(e.target.value)} required maxLength={150} className={classeChamp} />
            </div>
          </div>

          {rapport && (avecPeriode || parametresProgrammables(rapport).length > 0) && (
            <fieldset className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-900/40">
              <legend className="sr-only">{ts.parameters}</legend>
              {avecPeriode && (
                <div>
                  <label htmlFor="programme-periode" className={classeLibelle}>{ts.period} *</label>
                  <select id="programme-periode" value={periode} onChange={e => setPeriode(e.target.value)} required className={classeChamp}>
                    {periodes.map(p => <option key={p.code} value={p.code}>{p.libelle}</option>)}
                  </select>
                </div>
              )}
              {parametresProgrammables(rapport).map(p => (
                <div key={p.cle}>
                  <label htmlFor={`programme-parametre-${p.cle}`} className={classeLibelle}>{p.libelle}</label>
                  <ChampParametre id={`programme-parametre-${p.cle}`} parametre={p} valeur={parametres[p.cle] ?? ''} listes={listes} sansFiltre={tr.all}
                    onChange={valeur => setParametres(vs => ({ ...vs, [p.cle]: valeur }))} />
                </div>
              ))}
              {avecPeriode && <p className="sm:col-span-2 text-xs text-gray-500 dark:text-gray-400">{ts.periodHelp}</p>}
            </fieldset>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <div>
              <label htmlFor="programme-frequence" className={classeLibelle}>{ts.frequency} *</label>
              <select id="programme-frequence" value={frequence} onChange={e => changerFrequence(e.target.value as FrequenceProgramme)} className={classeChamp}>
                {FREQUENCES.map(f => <option key={f} value={f}>{ts.frequencies[f]}</option>)}
              </select>
            </div>
            {frequence !== 'quotidienne' && (
              <div>
                <label htmlFor="programme-jour" className={classeLibelle}>{ts.day} *</label>
                <select id="programme-jour" value={jour} onChange={e => setJour(Number(e.target.value))} className={classeChamp}>
                  {frequence === 'hebdomadaire'
                    ? ts.weekdays.map((libelle, index) => <option key={libelle} value={index + 1}>{libelle}</option>)
                    : Array.from({ length: 31 }, (_, index) => index + 1).map(n => <option key={n} value={n}>{n}</option>)}
                </select>
              </div>
            )}
            <div>
              <label htmlFor="programme-format" className={classeLibelle}>{ts.format}</label>
              <select id="programme-format" value={format} onChange={e => setFormat(e.target.value as FormatRapport)} className={classeChamp}>
                {FORMATS_RAPPORT.map(f => <option key={f} value={f}>{tr.formats[f]}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor="programme-langue" className={classeLibelle}>{ts.language}</label>
              <select id="programme-langue" value={langue} onChange={e => setLangue(e.target.value as LangueCourriel)} className={classeChamp}>
                <option value="fr">{ts.languages.fr}</option>
                <option value="en">{ts.languages.en}</option>
              </select>
            </div>
          </div>
          {frequence === 'mensuelle' && <p className="text-xs text-gray-500 dark:text-gray-400">{ts.monthDayHelp}</p>}

          <div>
            <label htmlFor="programme-destinataires" className={classeLibelle}>{ts.recipients} *</label>
            <input id="programme-destinataires" value={destinataires} onChange={e => setDestinataires(e.target.value)} required className={classeChamp} />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{ts.recipientsHelp}</p>
          </div>

          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" checked={actif} onChange={e => setActif(e.target.checked)} className="rounded" />
            {ts.active}
          </label>

          {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</p>}

          <div className="flex justify-end gap-2">
            <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{ts.cancel}</button>
            <button type="submit" disabled={enCours || !rapport} className="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50">
              {enCours ? tr.loading : ts.save}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  );
}
