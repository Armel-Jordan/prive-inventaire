import { useEffect, useState } from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { getSyntheseClient, type SyntheseClient } from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDate, formatMontant } from './format';

function Carte({ titre, valeur, detail, alerte = false }: { titre: string; valeur: string; detail?: string; alerte?: boolean }) {
  return (
    <div className="p-4 rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800">
      <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{titre}</p>
      <p className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{valeur}</p>
      {detail && <p className={`mt-0.5 text-xs ${alerte ? 'text-red-600 dark:text-red-400 font-medium' : 'text-gray-500 dark:text-gray-400'}`}>{detail}</p>}
    </div>
  );
}

export default function OngletSynthese({ clientId, devise }: { clientId: number; devise: string }) {
  const { t, language } = useLanguage();
  const ts = t.clientFile.summary;
  const [synthese, setSynthese] = useState<SyntheseClient | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getSyntheseClient(clientId)
      .then(s => { if (actif) setSynthese(s); })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : t.clientFile.error); });
    return () => { actif = false; };
  }, [clientId, t.clientFile.error]);

  if (erreur) return <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>;
  if (!synthese) return null;

  const montant = (v: number) => formatMontant(v, devise, language);
  const nombre = (gabarit: string, n: number) => gabarit.replace('{n}', String(n));
  const { chiffre_affaires: ca, factures, encours } = synthese;
  const nomMois = (mois: string) => new Date(`${mois}-01T00:00:00`).toLocaleDateString(language === 'en' ? 'en-US' : 'fr-FR', { month: 'short', year: '2-digit' });

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <Carte titre={ts.salesThisYear} valeur={montant(ca.annee_courante)} detail={ts.previousYear.replace('{montant}', montant(ca.annee_precedente))} />
        <Carte titre={ts.totalSales} valeur={montant(ca.total)} detail={nombre(ts.invoiceCount, factures.nombre)} />
        <Carte titre={ts.averageBasket} valeur={montant(factures.panier_moyen)} />
        <Carte titre={ts.outstanding} valeur={montant(encours.montant)}
          detail={encours.en_retard.nombre > 0
            ? ts.overdue.replace('{n}', String(encours.en_retard.nombre)).replace('{montant}', montant(encours.en_retard.montant))
            : encours.maximum !== null ? ts.outstandingMax.replace('{montant}', montant(encours.maximum)) : ts.noOverdue}
          alerte={encours.en_retard.nombre > 0} />
        <Carte titre={ts.ordersInProgress} valeur={String(synthese.commandes.en_cours)} detail={nombre(ts.ofTotal, synthese.commandes.nombre)} />
        <Carte titre={ts.quotesInProgress} valeur={String(synthese.devis.en_cours)} detail={nombre(ts.ofTotal, synthese.devis.nombre)} />
        <Carte titre={ts.customerSince} valeur={formatDate(factures.premiere, language)} />
        <Carte titre={ts.lastInvoice} valeur={factures.derniere ? formatDate(factures.derniere.date, language) : '—'}
          detail={factures.derniere ? `${factures.derniere.numero} · ${montant(factures.derniere.montant_ttc)}` : undefined} />
      </div>

      {factures.nombre === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">{ts.noSales}</p>
      ) : (
        <>
          <section>
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">{ts.monthlySales}</h3>
            <div className="h-56">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={synthese.ventes_mensuelles.map(m => ({ ...m, nom: nomMois(m.mois) }))}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="nom" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} width={70} />
                  <Tooltip formatter={(v) => montant(Number(v))} />
                  <Bar dataKey="montant_ht" name={ts.amount} fill="#9333ea" radius={[3, 3, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </section>

          <section>
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">{ts.topProducts}</h3>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                  <tr>
                    <th className="px-3 py-2 text-left font-medium">{ts.product}</th>
                    <th className="px-3 py-2 text-right font-medium">{ts.quantity}</th>
                    <th className="px-3 py-2 text-right font-medium">{ts.amount}</th>
                    <th className="px-3 py-2 text-right font-medium">{ts.invoices}</th>
                    <th className="px-3 py-2 text-right font-medium">{ts.lastPurchase}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                  {synthese.produits_les_plus_achetes.map(p => (
                    <tr key={p.produit_id} className="text-gray-800 dark:text-gray-200">
                      <td className="px-3 py-2"><span className="font-mono text-xs text-gray-500 mr-2">{p.numero ?? `#${p.produit_id}`}</span>{p.description}</td>
                      <td className="px-3 py-2 text-right">{p.quantite}</td>
                      <td className="px-3 py-2 text-right">{montant(p.montant_ht)}</td>
                      <td className="px-3 py-2 text-right">{p.factures}</td>
                      <td className="px-3 py-2 text-right">{formatDate(p.dernier_achat, language)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </>
      )}
      <p className="text-xs text-gray-400">{ts.amountsNote}</p>
    </div>
  );
}
