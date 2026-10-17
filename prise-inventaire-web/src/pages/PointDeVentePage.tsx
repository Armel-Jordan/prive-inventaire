import { useEffect, useMemo, useRef, useState } from 'react';
import { CheckCircle2, FileText, Gift, Minus, Plus, Printer, ScanLine, ShoppingBasket, Trash2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, useMontantDocument } from '@/lib/documents';
import { getClientsActifs, ouvrirPdf, type Client } from '@/services/api';
import { MODES_CAISSE } from '@/services/caisses';
import {
  calculerVente, chercherProduitsPdv, enregistrerVente, imprimerRecu,
  type ApercuVente, type LigneVente, type ModePaiementVente, type PaiementVente, type ProduitPdv, type VenteEnregistree,
} from '@/services/pointDeVente';
import { imprimerCertificat, type CarteCadeau } from '@/services/cartesCadeaux';
import ChampCarteCadeau from '@/components/cartesCadeaux/ChampCarteCadeau';
import VendreCarte from '@/components/cartesCadeaux/VendreCarte';
import { useEncaissementCaisse } from '@/hooks/useEncaissementCaisse';
import CaisseDuPoste from '@/components/caisse/CaisseDuPoste';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-xs text-gray-500 dark:text-gray-400';
const classeCarte = 'bg-white dark:bg-gray-800 rounded-xl shadow p-4';
const arrondi = (valeur: number) => Math.round(valeur * 100) / 100;
type Montant = (valeur: number | string | null | undefined, devise?: string | null) => string;

interface LignePanier {
  produit: ProduitPdv;
  quantite: number;
  /** Prix saisi ; vide : le prix du client. */
  prix: string;
}

/**
 * Point de vente (plan de parité fripv2, phase 1.10b) : on scanne ou cherche les produits, le panier est au prix du
 * client avec les taxes, puis la vente s'encaisse en un ou plusieurs modes (monnaie rendue sur les espèces). La facture
 * est créée, émise et payée d'un coup, sur la caisse du poste ; le reçu s'imprime.
 */
export default function PointDeVentePage() {
  const { t } = useLanguage();
  const tp = t.pointOfSale;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const encaissement = useEncaissementCaisse();
  const saisieRef = useRef<HTMLInputElement>(null);
  const [recherche, setRecherche] = useState('');
  const [resultats, setResultats] = useState<{ pour: string; produits: ProduitPdv[] }>({ pour: '', produits: [] });
  const [panier, setPanier] = useState<LignePanier[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [clientId, setClientId] = useState('');
  const [nomPassage, setNomPassage] = useState('');
  const [apercu, setApercu] = useState<{ cle: string; resultat: ApercuVente } | null>(null);
  const [erreur, setErreur] = useState('');
  const [encaisser, setEncaisser] = useState(false);
  const [vente, setVente] = useState<VenteEnregistree | null>(null);
  // Carte cadeau vendue au comptoir.
  const [venteCarte, setVenteCarte] = useState(false);
  const [carteVendue, setCarteVendue] = useState<CarteCadeau | null>(null);

  useEffect(() => {
    let actif = true;
    getClientsActifs().then(c => { if (actif) setClients((c ?? []).filter(x => !x.de_passage)); }, () => {});
    return () => { actif = false; };
  }, []);

  // Recherche des produits pendant la frappe (une courte pause d'abord).
  const motif = recherche.trim();
  useEffect(() => {
    if (!motif) return;
    let actif = true;
    const minuterie = setTimeout(() => {
      chercherProduitsPdv(motif).then(p => { if (actif) setResultats({ pour: motif, produits: p }); }, () => {});
    }, 200);
    return () => { actif = false; clearTimeout(minuterie); };
  }, [motif]);
  const produitsTrouves = motif && resultats.pour === motif ? resultats.produits : [];

  // Aperçu (prix du client, taxes) recalculé après chaque changement du panier ou du client.
  const lignes = useMemo<LigneVente[]>(() => panier.map(l => ({
    produit_id: l.produit.id, quantite: l.quantite, ...(l.prix.trim() !== '' ? { prix_unitaire_ht: Number(l.prix) } : {}),
  })), [panier]);
  const cle = useMemo(() => JSON.stringify({ clientId, lignes }), [clientId, lignes]);
  useEffect(() => {
    if (lignes.length === 0) return;
    let actif = true;
    const minuterie = setTimeout(() => {
      calculerVente({ client_id: clientId ? Number(clientId) : undefined, lignes, date: aujourdhui() })
        .then(r => { if (actif) { setApercu({ cle, resultat: r }); setErreur(''); } }, e => { if (actif) setErreur(messageErreur(e, tp.error)); });
    }, 250);
    return () => { actif = false; clearTimeout(minuterie); };
  }, [cle, clientId, lignes, tp.error]);
  const aJour = panier.length > 0 && apercu?.cle === cle ? apercu.resultat : null;
  const affiche = panier.length > 0 ? apercu?.resultat ?? null : null;
  const sansPrix = aJour?.lignes.some(l => l.sans_prix) ?? false;

  function ajouter(produit: ProduitPdv) {
    setPanier(p => (p.some(l => l.produit.id === produit.id)
      ? p.map(l => (l.produit.id === produit.id ? { ...l, quantite: l.quantite + 1 } : l))
      : [...p, { produit, quantite: 1, prix: '' }]));
    setRecherche('');
    setVente(null);
    saisieRef.current?.focus();
  }

  // Entrée (ou lecture d'un code) : le produit au numéro exact, ou le seul trouvé.
  async function valider(e: React.FormEvent) {
    e.preventDefault();
    if (!motif) return;
    try {
      const produits = await chercherProduitsPdv(motif);
      const exact = produits.find(p => p.numero.toLowerCase() === motif.toLowerCase()) ?? (produits.length === 1 ? produits[0] : undefined);
      if (exact) ajouter(exact);
      else setResultats({ pour: motif, produits });
      if (!exact && produits.length === 0) setErreur(tp.notFound.replace('{saisie}', motif));
    } catch (err) {
      setErreur(messageErreur(err, tp.error));
    }
  }

  const changerQuantite = (index: number, quantite: number) => setPanier(p => p.map((l, i) => (i === index ? { ...l, quantite: Math.max(1, Math.min(999999, quantite || 1)) } : l)));
  const changerPrix = (index: number, prix: string) => setPanier(p => p.map((l, i) => (i === index ? { ...l, prix } : l)));
  const retirer = (index: number) => setPanier(p => p.filter((_, i) => i !== index));

  function viderPanier() {
    setPanier([]);
    setApercu(null);
    setErreur('');
    setNomPassage('');
    saisieRef.current?.focus();
  }

  function apresVente(resultat: VenteEnregistree) {
    setVente(resultat);
    setEncaisser(false);
    setPanier([]);
    setApercu(null);
    setNomPassage('');
    setClientId('');
  }

  const devise = affiche?.devise ?? null;
  const dePassage = !clientId;

  return (
    <div className="p-4 sm:p-6 space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><ShoppingBasket className="text-emerald-600" /> {tp.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tp.subtitle}</p>
        </div>
        <div className="max-w-sm w-full sm:w-auto space-y-2">
          {encaissement.caisse
            ? <CaisseDuPoste caisse={encaissement.caisse} />
            : <p className="px-3 py-2 rounded-lg text-sm bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">{tp.noRegister}</p>}
          {peut('cartes_cadeaux', 'creer') && (
            <button type="button" onClick={() => setVenteCarte(true)} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">
              <Gift size={16} className="text-pink-600" /> {t.giftCards.sellAtCounter}
            </button>
          )}
        </div>
      </div>
      {carteVendue && (
        <p className="flex flex-wrap items-center gap-2 px-3 py-2 rounded-lg text-sm bg-emerald-50 text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200">
          {t.giftCards.sold.replace('{numero}', carteVendue.numero)}
          <button type="button" onClick={() => { imprimerCertificat(carteVendue).catch(e => setErreur(messageErreur(e, tp.error))); }} className="underline">{t.giftCards.certificate}</button>
          <button type="button" onClick={() => setCarteVendue(null)} className="ml-auto" aria-label={tp.close}><X size={14} /></button>
        </p>
      )}
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="grid gap-4 lg:grid-cols-3 items-start">
        <div className="lg:col-span-2 space-y-4">
          <section className={classeCarte} aria-label={tp.products}>
            <form onSubmit={valider} className="flex gap-2">
              <div className="relative flex-1">
                <ScanLine size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                <input ref={saisieRef} autoFocus value={recherche} onChange={e => { setRecherche(e.target.value); setErreur(''); }}
                  placeholder={tp.searchPlaceholder} aria-label={tp.searchPlaceholder} className={`${classeChamp} pl-10`} />
              </div>
              <button type="submit" className="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm hover:bg-emerald-700">{tp.add}</button>
            </form>
            {produitsTrouves.length > 0 && (
              <ul className="mt-2 max-h-64 overflow-y-auto divide-y dark:divide-gray-700 border rounded-lg dark:border-gray-700" aria-label={tp.results}>
                {produitsTrouves.map(p => (
                  <li key={p.id}>
                    <button type="button" onClick={() => ajouter(p)} className="w-full flex items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-emerald-50 dark:hover:bg-emerald-900/20 dark:text-gray-200">
                      <span><span className="font-mono text-xs text-gray-500 mr-2">{p.numero}</span>{p.description}</span>
                      <span className="text-gray-500 whitespace-nowrap">{p.prix_unitaire !== null ? montant(p.prix_unitaire) : tp.noPrice}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className={classeCarte} aria-label={tp.cart}>
            <div className="flex items-center justify-between mb-2">
              <h2 className="text-lg font-semibold text-gray-800 dark:text-white">{tp.cart}</h2>
              {panier.length > 0 && (
                <button type="button" onClick={viderPanier} className="inline-flex items-center gap-1 text-sm text-red-600 hover:underline"><Trash2 size={14} /> {tp.clear}</button>
              )}
            </div>
            {panier.length === 0 ? <p className="text-sm text-gray-500 py-6 text-center">{tp.empty}</p> : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
                    <tr>
                      <th className="py-2 pr-3 text-left">{tp.product}</th>
                      <th className="py-2 pr-3 text-center">{tp.quantity}</th>
                      <th className="py-2 pr-3 text-right">{tp.unitPrice}</th>
                      <th className="py-2 pr-3 text-right">{tp.lineTotal}</th>
                      <th className="py-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {panier.map((ligne, index) => {
                      const calcul = aJour?.lignes[index] ?? (affiche?.lignes[index]?.produit_id === ligne.produit.id ? affiche.lignes[index] : undefined);
                      return (
                        <tr key={ligne.produit.id} className="border-b dark:border-gray-700 dark:text-gray-200">
                          <td className="py-2 pr-3">
                            <span className="font-mono text-xs text-gray-500 mr-2">{ligne.produit.numero}</span>{ligne.produit.description}
                            {calcul?.sans_prix && <span className="block text-xs text-red-600">{tp.priceRequired}</span>}
                          </td>
                          <td className="py-2 pr-3">
                            <div className="flex items-center justify-center gap-1">
                              <button type="button" onClick={() => changerQuantite(index, ligne.quantite - 1)} className="p-1 rounded border dark:border-gray-600" aria-label={`${tp.less} — ${ligne.produit.numero}`}><Minus size={14} /></button>
                              <input type="number" min="1" value={ligne.quantite} onChange={e => changerQuantite(index, Number(e.target.value))}
                                aria-label={`${tp.quantity} — ${ligne.produit.numero}`} className="w-16 px-2 py-1 border rounded text-center dark:bg-gray-700 dark:border-gray-600" />
                              <button type="button" onClick={() => changerQuantite(index, ligne.quantite + 1)} className="p-1 rounded border dark:border-gray-600" aria-label={`${tp.more} — ${ligne.produit.numero}`}><Plus size={14} /></button>
                            </div>
                          </td>
                          <td className="py-2 pr-3 text-right">
                            <input type="number" min="0" step="0.01" value={ligne.prix} onChange={e => changerPrix(index, e.target.value)}
                              placeholder={calcul?.prix_unitaire_ht !== null && calcul?.prix_unitaire_ht !== undefined ? String(calcul.prix_unitaire_ht) : ''}
                              aria-label={`${tp.unitPrice} — ${ligne.produit.numero}`} className="w-28 px-2 py-1 border rounded text-right dark:bg-gray-700 dark:border-gray-600" />
                          </td>
                          <td className="py-2 pr-3 text-right whitespace-nowrap">{calcul?.montant_ttc !== null && calcul?.montant_ttc !== undefined ? montant(calcul.montant_ttc, devise) : '—'}</td>
                          <td className="py-2 text-right">
                            <button type="button" onClick={() => retirer(index)} className="p-1 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" aria-label={`${tp.remove} — ${ligne.produit.numero}`}><X size={16} /></button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </div>

        <div className="space-y-4">
          <section className={`${classeCarte} space-y-3`} aria-label={tp.customer}>
            <label className={classeLibelle}>{tp.customer}
              <select value={clientId} onChange={e => setClientId(e.target.value)} className={classeChamp}>
                <option value="">{tp.walkIn}</option>
                {clients.map(c => <option key={c.id} value={c.id}>{c.code} - {c.raison_sociale}</option>)}
              </select>
            </label>
            {dePassage && (
              <label className={classeLibelle}>{tp.walkInName}
                <input value={nomPassage} maxLength={150} onChange={e => setNomPassage(e.target.value)} className={classeChamp} />
              </label>
            )}
            {affiche?.devise && <p className="text-xs text-gray-500">{tp.currency.replace('{devise}', affiche.devise)}</p>}
          </section>

          <section className={`${classeCarte} space-y-2`} aria-label={tp.total}>
            <div className={`space-y-1 ${aJour ? '' : 'opacity-60'}`}>
              <div className="flex justify-between text-sm dark:text-gray-300"><span>{tp.subtotal}</span><span>{montant(affiche?.montant_ht ?? 0, devise)}</span></div>
              <div className="flex justify-between text-sm dark:text-gray-300"><span>{tp.taxes}</span><span>{montant(affiche?.montant_tva ?? 0, devise)}</span></div>
              <div className="flex justify-between text-2xl font-bold text-gray-900 dark:text-white"><span>{tp.total}</span><span>{montant(affiche?.montant_ttc ?? 0, devise)}</span></div>
            </div>
            {sansPrix && <p className="text-xs text-red-600">{tp.priceMissing}</p>}
            {peut('point_de_vente', 'vendre') && (
              <button type="button" disabled={!aJour || sansPrix} onClick={() => { setErreur(''); setEncaisser(true); }}
                className="w-full py-3 rounded-lg bg-emerald-600 text-white text-lg font-semibold hover:bg-emerald-700 disabled:opacity-50">
                {tp.checkout}
              </button>
            )}
          </section>
        </div>
      </div>

      {encaisser && aJour && (
        <Encaissement
          apercu={aJour}
          dePassage={dePassage}
          montant={montant}
          onClose={() => setEncaisser(false)}
          onVendre={async (paiements) => {
            const resultat = await encaissement.encaisser(caisse_id => enregistrerVente({
              client_id: clientId ? Number(clientId) : undefined,
              date: aujourdhui(),
              lignes,
              paiements,
              // La caisse n'est concernée que si de l'argent est reçu.
              caisse_id: paiements.length ? caisse_id : undefined,
              passage_nom: dePassage && nomPassage.trim() ? nomPassage.trim() : undefined,
            }));
            apresVente(resultat);
          }}
        />
      )}
      {vente && <VenteTerminee vente={vente} montant={montant} onNouvelle={() => { setVente(null); saisieRef.current?.focus(); }} />}
      {venteCarte && <VendreCarte onClose={() => setVenteCarte(false)} onVendue={carte => { setVenteCarte(false); setCarteVendue(carte); }} />}
      {encaissement.dialogue}
    </div>
  );
}

interface LignePaiement {
  mode: ModePaiementVente;
  montant: string;
  reference: string;
  /** Numéro de la carte cadeau. */
  carte: string;
}

/** Paiement de la vente : un ou plusieurs modes ; seules les espèces peuvent dépasser (monnaie rendue). */
function Encaissement({ apercu, dePassage, montant, onClose, onVendre }: {
  apercu: ApercuVente;
  dePassage: boolean;
  montant: Montant;
  onClose: () => void;
  onVendre: (paiements: PaiementVente[]) => Promise<void>;
}) {
  const { t } = useLanguage();
  const tp = t.pointOfSale;
  const total = arrondi(apercu.montant_ttc);
  const devise = apercu.devise;
  const [lignes, setLignes] = useState<LignePaiement[]>([{ mode: 'especes', montant: String(total), reference: '', carte: '' }]);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  const somme = (filtre: (l: LignePaiement) => boolean) => arrondi(lignes.filter(filtre).reduce((s, l) => s + (Number(l.montant) || 0), 0));
  const recu = somme(() => true);
  const especes = somme(l => l.mode === 'especes');
  const autres = somme(l => l.mode !== 'especes');
  const reste = Math.max(0, arrondi(total - recu));
  const monnaie = Math.max(0, arrondi(recu - total));
  // Les mêmes règles que l'API, pour guider avant l'envoi.
  const probleme = autres > total + 0.005 ? tp.onlyCashOver
    : reste > 0.005 && dePassage ? tp.walkInPaysAll
    : reste > 0.005 && especes > 0 ? tp.cashPaysAll
    : '';

  const modifier = (index: number, changement: Partial<LignePaiement>) => setLignes(ls => ls.map((l, i) => (i === index ? { ...l, ...changement } : l)));
  const ajouterMode = () => setLignes(ls => [...ls, { mode: 'carte', montant: reste > 0 ? String(reste) : '', reference: '', carte: '' }]);
  // Ce qui reste à payer sans compter une ligne (montant proposé pour une carte cadeau, au plus son solde).
  const resteSans = (index: number) => Math.max(0, arrondi(total - lignes.reduce((s, l, i) => s + (i === index ? 0 : Number(l.montant) || 0), 0)));

  async function vendre(paiements: PaiementVente[]) {
    setOccupe(true);
    setErreur('');
    try {
      await onVendre(paiements);
    } catch (err) {
      setErreur(messageErreur(err, tp.error));
      setOccupe(false);
    }
  }

  const paiements: PaiementVente[] = lignes.filter(l => Number(l.montant) > 0).map(l => ({
    mode: l.mode,
    montant: Number(l.montant),
    ...(l.mode === 'carte_cadeau' ? { carte_cadeau: l.carte.trim() } : l.reference.trim() ? { reference: l.reference.trim() } : {}),
  }));

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={e => { e.preventDefault(); void vendre(paiements); }} role="dialog" aria-modal="true" aria-label={tp.payTitle}
        className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <h2 className="text-lg font-bold text-gray-900 dark:text-white">{tp.payTitle} — {montant(total, devise)}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-white" aria-label={tp.close}><X size={18} /></button>
        </div>
        <div className="space-y-2">
          {lignes.map((ligne, index) => (
            <div key={index} className="flex flex-wrap items-end gap-2">
              <label className={`${classeLibelle} w-36`}>{tp.mode}
                <select value={ligne.mode} onChange={e => modifier(index, { mode: e.target.value as ModePaiementVente })} className={classeChamp}>
                  {MODES_CAISSE.map(m => <option key={m} value={m}>{t.cashRegisters.modes[m]}</option>)}
                  <option value="carte_cadeau">{t.giftCards.giftCard}</option>
                </select>
              </label>
              <label className={`${classeLibelle} w-32`}>{tp.amount}
                <input type="number" min="0" step="0.01" value={ligne.montant} onChange={e => modifier(index, { montant: e.target.value })}
                  className={`${classeChamp} text-right`} autoFocus={index === 0} />
              </label>
              {ligne.mode === 'carte_cadeau' && (
                <ChampCarteCadeau className="flex-1 min-w-[12rem]" numero={ligne.carte} onNumero={carte => modifier(index, { carte })}
                  onVerifiee={carte => modifier(index, { montant: String(Math.min(Number(carte.solde), resteSans(index))) })} />
              )}
              {ligne.mode !== 'especes' && ligne.mode !== 'carte_cadeau' && (
                <label className={`${classeLibelle} flex-1 min-w-[8rem]`}>{tp.reference}
                  <input value={ligne.reference} maxLength={100} onChange={e => modifier(index, { reference: e.target.value })} className={classeChamp} />
                </label>
              )}
              {lignes.length > 1 && (
                <button type="button" onClick={() => setLignes(ls => ls.filter((_, i) => i !== index))} className="p-2 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" aria-label={`${tp.removePayment} ${index + 1}`}><Trash2 size={16} /></button>
              )}
            </div>
          ))}
          <button type="button" onClick={ajouterMode} className="inline-flex items-center gap-1 text-sm text-emerald-700 hover:underline dark:text-emerald-400"><Plus size={14} /> {tp.addPayment}</button>
        </div>

        <dl className="grid grid-cols-2 gap-2 text-sm">
          <div className="rounded-lg bg-gray-50 dark:bg-gray-700/50 p-2"><dt className="text-xs text-gray-500">{tp.total}</dt><dd className="font-semibold dark:text-white">{montant(total, devise)}</dd></div>
          <div className="rounded-lg bg-gray-50 dark:bg-gray-700/50 p-2"><dt className="text-xs text-gray-500">{tp.received}</dt><dd className="font-semibold dark:text-white">{montant(recu, devise)}</dd></div>
          <div className="rounded-lg bg-gray-50 dark:bg-gray-700/50 p-2"><dt className="text-xs text-gray-500">{tp.remaining}</dt><dd className={`font-semibold ${reste > 0 ? 'text-amber-700' : 'dark:text-white'}`}>{montant(reste, devise)}</dd></div>
          <div className="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 p-2"><dt className="text-xs text-emerald-800 dark:text-emerald-300">{tp.change}</dt><dd className="text-lg font-bold text-emerald-700 dark:text-emerald-300">{montant(monnaie, devise)}</dd></div>
        </dl>
        {probleme && <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">{probleme}</p>}
        {!probleme && reste > 0.005 && <p className="px-3 py-2 rounded-lg text-sm bg-blue-50 text-blue-800">{tp.onAccount.replace('{montant}', montant(reste, devise))}</p>}
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

        <div className="flex flex-wrap justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tp.cancel}</button>
          {!dePassage && (
            <button type="button" disabled={occupe} onClick={() => { void vendre([]); }} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700 disabled:opacity-50">{tp.chargeAccount}</button>
          )}
          <button type="submit" disabled={occupe || !!probleme || paiements.length === 0} className="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50">{tp.confirm}</button>
        </div>
      </form>
    </div>
  );
}

/** Vente enregistrée : monnaie à rendre, reçu, facture, vente suivante. */
function VenteTerminee({ vente, montant, onNouvelle }: { vente: VenteEnregistree; montant: Montant; onNouvelle: () => void }) {
  const { t } = useLanguage();
  const tp = t.pointOfSale;
  const [erreur, setErreur] = useState('');
  const facture = vente.facture;
  const devise = facture.devise;
  const ouvrir = (action: () => Promise<void>) => { setErreur(''); action().catch(e => setErreur(messageErreur(e, tp.error))); };
  const resteDu = Number(facture.reste_a_payer) || 0;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-label={tp.done} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-4 text-center">
        <CheckCircle2 className="mx-auto text-emerald-600" size={40} />
        <h2 className="text-lg font-bold text-gray-900 dark:text-white">{vente.message}</h2>
        {vente.monnaie > 0 && (
          <p className="text-gray-700 dark:text-gray-200">{tp.giveChange}<span className="block text-3xl font-bold text-emerald-700 dark:text-emerald-300">{montant(vente.monnaie, devise)}</span></p>
        )}
        {resteDu > 0.005 && <p className="text-sm text-amber-800">{tp.leftOnAccount.replace('{montant}', montant(resteDu, devise))}</p>}
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex flex-wrap justify-center gap-2">
          <button type="button" onClick={() => ouvrir(() => imprimerRecu(facture))} className="inline-flex items-center gap-1 px-4 py-2 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700"><Printer size={16} /> {tp.printReceipt}</button>
          <button type="button" onClick={() => ouvrir(() => ouvrirPdf(`/factures/${facture.id}/pdf`, `${facture.numero}.pdf`))} className="inline-flex items-center gap-1 px-4 py-2 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700"><FileText size={16} /> {tp.invoicePdf}</button>
          <button type="button" onClick={onNouvelle} autoFocus className="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700">{tp.newSale}</button>
        </div>
      </div>
    </div>
  );
}
