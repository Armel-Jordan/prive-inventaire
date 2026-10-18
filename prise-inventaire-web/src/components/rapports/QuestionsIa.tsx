import { useEffect, useState, type ReactNode } from 'react';
import { ExternalLink, Send, Sparkles } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { getQuestionsIa, poserQuestionIa, type EtatQuestionsIa, type QuestionIa } from '@/services/bibliothequeRapports';

/** **gras** dans une ligne, en éléments React (jamais de HTML). */
function enLigne(texte: string): ReactNode[] {
  return texte.split(/(\*\*[^*]+\*\*)/g).filter(Boolean).map((morceau, index) =>
    morceau.startsWith('**') && morceau.endsWith('**') ? <strong key={index}>{morceau.slice(2, -2)}</strong> : morceau);
}

/** Mise en forme légère de la réponse : paragraphes, listes « - », **gras**. */
function TexteLeger({ texte }: { texte: string }) {
  const blocs = texte.split(/\n\s*\n/).map(b => b.split('\n').map(l => l.trimEnd()).filter(Boolean)).filter(b => b.length > 0);
  return (
    <div className="space-y-2 text-sm text-gray-800 dark:text-gray-200">
      {blocs.map((lignes, index) => (lignes.every(l => /^\s*[-•]\s+/.test(l)) ? (
        <ul key={index} className="list-disc pl-5 space-y-0.5">
          {lignes.map((l, i) => <li key={i}>{enLigne(l.replace(/^\s*[-•]\s+/, ''))}</li>)}
        </ul>
      ) : (
        <p key={index}>{lignes.map((l, i) => <span key={i}>{i > 0 && <br />}{enLigne(l)}</span>)}</p>
      )))}
    </div>
  );
}

/**
 * Questions à l'IA sur les rapports (plan de parité fripv2, phase 5.4) : l'IA répond en exécutant les rapports que
 * les droits du compte permettent, et cite le rapport à ouvrir pour le détail. Historique des questions du compte.
 */
export default function QuestionsIa({ onOuvrirRapport }: {
  /** Ouvre un rapport de la bibliothèque avec ses paramètres. */
  onOuvrirRapport: (code: string, parametres: Record<string, string | number | null>) => void;
}) {
  const { t, language } = useLanguage();
  const ti = t.reportsLibrary.ai;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const [etat, setEtat] = useState<EtatQuestionsIa | null>(null);
  const [question, setQuestion] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getQuestionsIa(language).then(e => { if (actif) setEtat(e); }, e => { if (actif) setErreur(messageErreur(e, ti.error)); });
    return () => { actif = false; };
  }, [language, ti.error]);

  async function demander(e: React.FormEvent) {
    e.preventDefault();
    const texte = question.trim();
    if (!texte) return;
    setEnCours(true);
    setErreur('');
    try {
      const reponse = await poserQuestionIa(texte, language);
      setEtat(ancien => ancien && { ...ancien, restantes: Math.max(0, ancien.restantes - 1), questions: [reponse, ...ancien.questions] });
      setQuestion('');
    } catch (err) {
      setErreur(messageErreur(err, ti.error));
      // L'échec est aussi dans l'historique.
      getQuestionsIa(language).then(setEtat, () => {});
    } finally {
      setEnCours(false);
    }
  }

  const carte = (q: QuestionIa) => {
    // Rapport à ouvrir pour le détail, si le compte peut encore l'ouvrir.
    const aOuvrir = q.rapport?.permis ? q.rapport : null;
    return (
    <li key={q.id} className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 space-y-2">
      <p className="text-xs text-gray-500 dark:text-gray-400">
        {q.created_at ? new Date(q.created_at).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' }) : ''} · {q.question}
      </p>
      {q.statut === 'echec' ? (
        <p className="text-sm text-red-700 dark:text-red-300">{ti.failed.replace('{erreur}', q.erreur ?? '')}</p>
      ) : (
        <>
          {q.titre && <h3 className="font-semibold text-gray-900 dark:text-white">{q.titre}</h3>}
          {q.reponse && <TexteLeger texte={q.reponse} />}
          <div className="flex flex-wrap items-center gap-2 pt-1">
            {aOuvrir && (
              <button type="button" onClick={() => onOuvrirRapport(aOuvrir.code, aOuvrir.parametres)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-indigo-300 dark:border-indigo-700 text-sm text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30">
                <ExternalLink size={14} /> {ti.openReport.replace('{rapport}', aOuvrir.titre)}
              </button>
            )}
            {q.rapports.length > 0 && (
              <span className="text-xs text-gray-500 dark:text-gray-400">{ti.reportsUsed.replace('{rapports}', [...new Set(q.rapports.map(r => r.titre))].join(', '))}</span>
            )}
          </div>
        </>
      )}
    </li>
    );
  };

  return (
    <section className="space-y-4" aria-labelledby="titre-questions-ia">
      <div>
        <h2 id="titre-questions-ia" className="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
          <Sparkles size={20} className="text-indigo-600" /> {ti.title}
        </h2>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{ti.subtitle}</p>
      </div>

      {etat?.indisponible ? (
        <p role="status" className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
          {etat.indisponible === 'module' ? ti.moduleOff : ti.serverOff}
        </p>
      ) : etat && (
        <form onSubmit={e => { void demander(e); }} className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 space-y-3">
          <label htmlFor="question-ia" className="block text-sm font-medium text-gray-700 dark:text-gray-300">{ti.label}</label>
          <textarea id="question-ia" rows={3} value={question} onChange={e => setQuestion(e.target.value)} maxLength={1000} required
            placeholder={ti.placeholder} disabled={enCours}
            className="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white disabled:opacity-60" />
          <div className="flex flex-wrap gap-2" aria-label={ti.examples}>
            {ti.exampleList.map(exemple => (
              <button key={exemple} type="button" onClick={() => setQuestion(exemple)} disabled={enCours}
                className="px-2.5 py-1 rounded-full text-xs bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 disabled:opacity-60">
                {exemple}
              </button>
            ))}
          </div>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs text-gray-500 dark:text-gray-400 max-w-2xl">{ti.privacy}</p>
            <div className="flex items-center gap-3">
              <span className="text-xs text-gray-500 dark:text-gray-400">{ti.remaining.replace('{n}', String(etat.restantes))}</span>
              <button type="submit" disabled={enCours || etat.restantes === 0 || !question.trim()}
                className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm hover:bg-indigo-700 disabled:opacity-50">
                <Send size={16} /> {enCours ? ti.thinking : ti.ask}
              </button>
            </div>
          </div>
          <p role="status" className="text-sm text-indigo-700 dark:text-indigo-300">{enCours ? ti.thinkingLong : ''}</p>
        </form>
      )}

      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</p>}

      {etat === null && !erreur ? (
        <p className="text-sm text-gray-500">{t.reportsLibrary.loading}</p>
      ) : etat && etat.questions.length > 0 && (
        <div className="space-y-2">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{ti.history}</h3>
          <ul className="space-y-3">{etat.questions.map(carte)}</ul>
        </div>
      )}
    </section>
  );
}
