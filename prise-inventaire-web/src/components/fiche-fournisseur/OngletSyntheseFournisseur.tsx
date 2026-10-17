import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { getSyntheseFournisseur, type SyntheseFournisseur } from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import { formatDate, formatMontant } from '@/components/fiche/format';

function Carte({ titre, valeur, detail }: { titre: string; valeur: string; detail?: string }) {
  return (
    <div className="p-4 rounded-lg border dark:border-gray-700 bg-white dark:bg-gray-800">
      <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{titre}</p>
      <p className="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{valeur}</p>
      {detail && <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{detail}</p>}
    </div>
  );
}

/**
 * Synthèse des achats au fournisseur : montants, commandes, reste à recevoir, qualité des livraisons, retours et
 * crédits obtenus (avoirs).
 */
export default function OngletSyntheseFournisseur({ fournisseurId, code, devise }: { fournisseurId: number; code: string; devise: string }) {
  const { t, language } = useLanguage();
  const { peut } = usePermissions();
  const tr = t.supplierReturns;
  const ts = t.supplierFile.summary;
  const [synthese, setSynthese] = useState<SyntheseFournisseur | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getSyntheseFournisseur(fournisseurId)
      .then(s => { if (actif) setSynthese(s); })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : t.supplierFile.error); });
    return () => { actif = false; };
  }, [fournisseurId, t.supplierFile.error]);

  if (erreur) return <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>;
  if (!synthese) return null;

  const montant = (v: number) => formatMontant(v, devise, language);
  const pourcentage = (v: number | null) => (v === null ? '—' : `${v.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR')} %`);
  const { achats, commandes, a_recevoir: aRecevoir, livraisons } = synthese;
  const nomMois = (mois: string) => new Date(`${mois}-01T00:00:00`).toLocaleDateString(language === 'en' ? 'en-US' : 'fr-FR', { month: 'short', year: '2-digit' });

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <Carte titre={ts.purchasesThisYear} valeur={montant(achats.annee_courante)} detail={ts.previousYear.replace('{montant}', montant(achats.annee_precedente))} />
        <Carte titre={ts.totalPurchases} valeur={montant(achats.total)} detail={ts.orderCount.replace('{n}', String(commandes.nombre))} />
        <Carte titre={ts.averageOrder} valeur={montant(commandes.montant_moyen)}
          detail={commandes.brouillons > 0 ? ts.drafts.replace('{n}', String(commandes.brouillons)) : undefined} />
        <Carte titre={ts.toReceive} valeur={montant(aRecevoir.montant)}
          detail={aRecevoir.commandes > 0
            ? ts.toReceiveDetail.replace('{quantite}', String(aRecevoir.quantite)).replace('{n}', String(aRecevoir.commandes))
            : ts.nothingToReceive} />
        <Carte titre={ts.leadTime}
          valeur={livraisons.delai_moyen_jours === null ? '—' : ts.days.replace('{n}', livraisons.delai_moyen_jours.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR'))}
          detail={ts.deliveredOrders.replace('{n}', String(livraisons.commandes_livrees))} />
        <Carte titre={ts.onTime} valeur={pourcentage(livraisons.a_temps)} detail={ts.onTimeDetail} />
        <Carte titre={ts.receptionRate} valeur={pourcentage(livraisons.taux_reception)} detail={ts.receptionRateDetail} />
        <Carte titre={ts.lastOrder} valeur={commandes.derniere ? formatDate(commandes.derniere.date, language) : '—'}
          detail={commandes.derniere
            ? `${commandes.derniere.numero} · ${formatMontant(commandes.derniere.montant, commandes.derniere.devise ?? devise, language)}`
            : commandes.premiere ? `${ts.supplierSince} ${formatDate(commandes.premiere, language)}` : undefined} />
      </div>

      {synthese.retours && peut('retours_fournisseurs', 'voir') && (synthese.retours.en_cours > 0 || synthese.retours.credits_obtenus > 0 || synthese.retours.credits_attendus > 0) && (
        <section>
          <div className="flex flex-wrap items-baseline justify-between gap-2 mb-2">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200">{tr.supplierRecordTitle}</h3>
            <Link to={`/retours-fournisseurs?recherche=${encodeURIComponent(code)}`} className="text-sm text-orange-700 hover:underline dark:text-orange-300">{tr.supplierRecordLink}</Link>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <Carte titre={tr.creditsExpected} valeur={montant(synthese.retours.credits_attendus)} />
            <Carte titre={tr.creditsObtained} valeur={montant(synthese.retours.credits_obtenus)} />
            <Carte titre={tr.inProgress} valeur={String(synthese.retours.en_cours)} />
          </div>
        </section>
      )}

      {commandes.nombre === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">{ts.noPurchases}</p>
      ) : (
        <>
          <section>
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">{ts.monthlyPurchases}</h3>
            <div className="h-56">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={synthese.achats_mensuels.map(m => ({ ...m, nom: nomMois(m.mois) }))}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="nom" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} width={70} />
                  <Tooltip formatter={(v) => montant(Number(v))} />
                  <Bar dataKey="montant" name={ts.amount} fill="#0d9488" radius={[3, 3, 0, 0]} />
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
                    <th className="px-3 py-2 text-right font-medium">{ts.orders}</th>
                    <th className="px-3 py-2 text-right font-medium">{ts.lastPurchase}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                  {synthese.produits_les_plus_achetes.map(p => (
                    <tr key={p.produit_id} className="text-gray-800 dark:text-gray-200">
                      <td className="px-3 py-2"><span className="font-mono text-xs text-gray-500 mr-2">{p.numero ?? `#${p.produit_id}`}</span>{p.description}</td>
                      <td className="px-3 py-2 text-right">{p.quantite}</td>
                      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(p.montant)}</td>
                      <td className="px-3 py-2 text-right">{p.commandes}</td>
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
