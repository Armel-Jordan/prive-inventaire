import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Landmark, Save } from 'lucide-react';
import { getSoldesOuverture, enregistrerSoldesOuverture, montant, type SoldesOuverture } from '@/services/comptabilite';
import { ApiError } from '@/services/http';
import { useAuth } from '@/contexts/AuthContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';

const classeChamp = 'w-full px-2 py-1.5 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm text-right disabled:opacity-60';
const nombre = (texte: string) => Math.round((Number(texte.replace(',', '.')) || 0) * 100) / 100;

/** Soldes d'ouverture du premier exercice : un solde par compte de bilan, enregistrés dans le journal des à-nouveaux. */
export default function SoldesOuverturePage() {
  const { t, language } = useLanguage();
  const to = t.accounting.opening;
  const { user } = useAuth();
  const peutModifier = user?.role === 'admin' || user?.role === 'manager';
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [etat, setEtat] = useState<SoldesOuverture | null>(null);
  const [saisies, setSaisies] = useState<Record<number, { debit: string; credit: string }>>({});
  const [recherche, setRecherche] = useState('');
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [version, setVersion] = useState(0);
  const [enCours, setEnCours] = useState(false);

  useEffect(() => {
    let actif = true;
    getSoldesOuverture().then(e => {
      if (!actif) return;
      setEtat(e);
      setSaisies(Object.fromEntries(e.comptes.map(c => [c.id, { debit: c.debit ? String(c.debit) : '', credit: c.credit ? String(c.credit) : '' }])));
    }).catch(e => { if (actif) setMessage({ erreur: true, texte: messageErreur(e, to.error) }); });
    return () => { actif = false; };
  }, [version, to.error]);

  const totaux = useMemo(() => Object.values(saisies).reduce((t, s) => ({ debit: t.debit + nombre(s.debit), credit: t.credit + nombre(s.credit) }), { debit: 0, credit: 0 }), [saisies]);
  const ecart = Math.round((totaux.debit - totaux.credit) * 100) / 100;
  const verrouille = !peutModifier || !!etat?.exercice?.verrouille;
  const terme = recherche.trim().toLowerCase();
  const visibles = (etat?.comptes ?? []).filter(c => !terme || c.numero.toLowerCase().includes(terme) || c.libelle.toLowerCase().includes(terme));
  const modifier = (id: number, valeurs: Partial<{ debit: string; credit: string }>) => setSaisies(s => ({ ...s, [id]: { ...s[id], ...valeurs } }));

  async function enregistrer() {
    setEnCours(true);
    setMessage(null);
    try {
      const lignes = Object.entries(saisies)
        .map(([id, s]) => ({ compte_id: Number(id), debit: nombre(s.debit), credit: nombre(s.credit) }))
        .filter(l => l.debit || l.credit);
      const { message: texte } = await enregistrerSoldesOuverture(lignes);
      setMessage({ erreur: false, texte });
      setVersion(v => v + 1);
    } catch (e) {
      const erreurs = e instanceof ApiError ? Object.values((e.data as { errors?: Record<string, string[]> } | null)?.errors ?? {}).flat() : [];
      setMessage({ erreur: true, texte: erreurs[0] ?? messageErreur(e, to.error) });
    } finally {
      setEnCours(false);
    }
  }

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Landmark className="text-purple-600" /> {to.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{to.subtitle}</p>
        </div>
        {etat?.exercice && !verrouille && (
          <button onClick={enregistrer} disabled={enCours || ecart !== 0} className="flex items-center gap-2 px-3 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">
            <Save size={16} /> {to.save}
          </button>
        )}
      </div>

      {message && <p role="status" className={`mb-4 px-3 py-2 rounded-lg text-sm max-w-5xl ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>}
      {etat && !etat.exercice && (
        <p className="text-sm text-gray-600 dark:text-gray-300">{to.noYear} <Link to="/exercices-comptables" className="text-purple-600 hover:underline dark:text-purple-400">{to.yearsLink}</Link></p>
      )}

      {etat?.exercice && (
        <>
          <div className="flex flex-wrap items-center gap-3 mb-4 max-w-5xl text-sm">
            <span className="text-gray-700 dark:text-gray-300">{to.year.replace('{libelle}', etat.exercice.libelle).replace('{date}', new Date(`${etat.exercice.date_debut}T00:00:00`).toLocaleDateString(locale))}</span>
            {etat.ecriture && <span className="px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-800">{to.entry.replace('{numero}', etat.ecriture.numero)}</span>}
            {etat.exercice.verrouille && <span className="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-700">{to.locked}</span>}
            <input value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={to.search} aria-label={to.search}
              className="ml-auto px-3 py-1.5 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm w-64 max-w-full" />
          </div>
          <div className={`mb-4 max-w-5xl px-3 py-2 rounded-lg text-sm ${ecart === 0 ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-900'}`}>
            {to.totals.replace('{debit}', montant(totaux.debit, locale)).replace('{credit}', montant(totaux.credit, locale))}
            {' '}{ecart === 0 ? to.balanced : to.difference.replace('{ecart}', montant(Math.abs(ecart), locale))}
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-5xl">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                  <th className="px-4 py-3 font-medium">{to.account}</th>
                  <th className="px-4 py-3 font-medium">{to.nature}</th>
                  <th className="px-4 py-3 font-medium text-right w-40">{to.debit}</th>
                  <th className="px-4 py-3 font-medium text-right w-40">{to.credit}</th>
                </tr>
              </thead>
              <tbody>
                {visibles.map(compte => (
                  <tr key={compte.id} className="border-t dark:border-gray-700">
                    <td className="px-4 py-1.5"><span className="font-mono text-gray-900 dark:text-white">{compte.numero}</span> <span className="text-gray-700 dark:text-gray-300">{compte.libelle}</span></td>
                    <td className="px-4 py-1.5 text-gray-600 dark:text-gray-300 whitespace-nowrap">{t.accounting.natures[compte.nature]}</td>
                    <td className="px-4 py-1.5">
                      <input inputMode="decimal" disabled={verrouille} aria-label={`${to.debit} ${compte.numero}`} className={classeChamp} value={saisies[compte.id]?.debit ?? ''}
                        onChange={e => modifier(compte.id, { debit: e.target.value, ...(e.target.value ? { credit: '' } : {}) })} />
                    </td>
                    <td className="px-4 py-1.5">
                      <input inputMode="decimal" disabled={verrouille} aria-label={`${to.credit} ${compte.numero}`} className={classeChamp} value={saisies[compte.id]?.credit ?? ''}
                        onChange={e => modifier(compte.id, { credit: e.target.value, ...(e.target.value ? { debit: '' } : {}) })} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
