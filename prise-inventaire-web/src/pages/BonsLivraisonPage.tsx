import { usePermissions } from '@/contexts/PermissionsContext';
import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { useState, useEffect, useCallback } from 'react';
import { useRechercheAdresse } from '@/hooks/useRechercheAdresse';
import BoutonChampsPersonnalises from '@/components/BoutonChampsPersonnalises';
import { Eye, Package, CheckCircle, Truck, XCircle, PenLine, Boxes, Search } from 'lucide-react';
import PadSignature from '@/components/PadSignature';
import ColisBon from '@/components/livraison/ColisBon';
import ExpeditionBon from '@/components/livraison/ExpeditionBon';
import { DetailLivraison } from '@/components/ChoixLivraison';
import ChoixTransporteur from '@/components/ChoixTransporteur';
import BadgeStatut from '@/components/BadgeStatut';
import { ChoixStatutPersonnalise, FiltreStatutPersonnalise, PastilleStatutPersonnalise } from '@/components/StatutPersonnalise';
import { EtoilePrioritaire, FiltrePrioritaires } from '@/components/Prioritaire';
import { transporteursActifs, useTransporteurs } from '@/hooks/useTransporteurs';
import { useLanguage } from '@/i18n/useLanguage';
import BalisesFiche from '@/components/BalisesFiche';
import BoutonRemarquesRapides from '@/components/BoutonRemarquesRapides';
import { ajouterRemarque } from '@/lib/utils';
import {
  getBonsLivraison,
  getBonLivraison,
  demarrerPreparation,
  updateLignesBL,
  marquerBLPret,
  enregistrerLivraison,
  getExemplairesBL,
  annulerBL,
} from '../services/api';
import type { BonLivraison, BonLivraisonLigne, ExemplairesLigneBL } from '../services/api';
import ExemplairesLivraison from '@/components/livraison/ExemplairesLivraison';
import { numerosSaisis, premiersSortis } from '@/lib/lots';
import BoutonPdf from '@/components/BoutonPdf';
import BoutonCourriel from '@/components/BoutonCourriel';
import Pagination from '@/components/Pagination';
import { signaler } from '@/lib/erreurs';
import { formatDateHeure } from '@/lib/documents';
import { quantiteAvecUnite } from '@/lib/unitesVente';

/** Statuts où les colis se préparent : avant le départ en livraison. */
const STATUTS_COLIS = ['cree', 'en_preparation', 'pret'];

/** Produit d'une ligne de bon : sa désignation (et son numéro) plutôt que son identifiant interne. */
function nomProduit(ligne: BonLivraisonLigne): string {
  if (!ligne.produit) return `Produit #${ligne.produit_id}`;
  return ligne.produit.numero ? `${ligne.produit.numero} — ${ligne.produit.description}` : ligne.produit.description;
}

export default function BonsLivraisonPage() {
  // Boutons d'action selon les permissions (rôles et permissions).
  const { peut } = usePermissions();
  const { t } = useLanguage();
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [bons, setBons] = useState<BonLivraison[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  // Bons d'un transporteur (phase 1.11b) ; filtre masqué sans transporteur actif.
  const transporteurs = useTransporteurs();
  const [filterTransporteur, setFilterTransporteur] = useState<number | null>(null);
  // Statut personnalisé (étiquette de repérage, phase 1.11c).
  const [filterStatutPerso, setFilterStatutPerso] = useState<number | null>(null);
  // Prioritaires seulement (l'étoile, socle S4.1).
  const [filtrePrioritaires, setFiltrePrioritaires] = useState(false);
  // Recherche par numéro du bon ou de sa facture, ou par client, pré-remplie par « ?recherche= » (recherche globale).
  const [search, setSearch] = useRechercheAdresse();
  const [recherche, setRecherche] = useState(() => search.trim());
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showPreparationModal, setShowPreparationModal] = useState(false);
  const [showLivraisonModal, setShowLivraisonModal] = useState(false);
  const [selectedBon, setSelectedBon] = useState<BonLivraison | null>(null);
  const [lignesPreparation, setLignesPreparation] = useState<{ id: number; quantite_preparee: number }[]>([]);
  const [lignesLivraison, setLignesLivraison] = useState<{ id: number; quantite_livree: number }[]>([]);
  // Produits suivis (lots, numéros de série) : exemplaires disponibles par ligne, lots qui sortent, numéros livrés.
  const [exemplaires, setExemplaires] = useState<Record<number, ExemplairesLigneBL>>({});
  const [allocations, setAllocations] = useState<Record<number, Record<number, number>>>({});
  const [seriesLivrees, setSeriesLivrees] = useState<Record<number, string>>({});
  const [notesLivraison, setNotesLivraison] = useState('');
  // Signature électronique du client, recueillie à la livraison.
  const [signature, setSignature] = useState<string | null>(null);
  const [signataire, setSignataire] = useState('');
  // Bon dont on prépare ou consulte les colis.
  const [bonColis, setBonColis] = useState<number | null>(null);

  // La recherche part 300 ms après la dernière frappe, et non à chaque caractère.
  useEffect(() => {
    const minuterie = setTimeout(() => { setRecherche(search.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [search]);

  const loadBons = useCallback(async () => {
    try {
      setLoading(true);
      const response = await getBonsLivraison({ statut: filterStatut || undefined, recherche: recherche || undefined, transporteur_id: filterTransporteur ?? undefined, statut_personnalise_id: filterStatutPerso ?? undefined, prioritaire: filtrePrioritaires || undefined, page });
      setBons(response.data || []);
      setPagination({ dernierePage: response.last_page ?? 1, total: response.total ?? 0 });
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [filterStatut, recherche, filterTransporteur, filterStatutPerso, filtrePrioritaires, page]);

  useEffect(() => {
    loadBons();
  }, [loadBons]);

  const handleVoirDetail = async (id: number) => {
    try {
      const bon = await getBonLivraison(id);
      setSelectedBon(bon);
      setShowDetailModal(true);
    } catch (error) {
      signaler(error, 'Impossible de charger le bon de livraison');
    }
  };

  const handleDemarrerPreparation = async (id: number) => {
    try {
      await demarrerPreparation(id);
      loadBons();
    } catch (error) {
      signaler(error, 'Impossible de démarrer la préparation');
      loadBons();
    }
  };

  const handleOuvrirPreparation = async (id: number) => {
    try {
      const bon = await getBonLivraison(id);
      setSelectedBon(bon);
      setLignesPreparation(
        bon.lignes?.map((l: BonLivraisonLigne) => ({
          id: l.id,
          quantite_preparee: l.quantite_preparee || 0,
        })) || []
      );
      setShowPreparationModal(true);
    } catch (error) {
      signaler(error, 'Impossible de charger le bon de livraison');
    }
  };

  const handleSauvegarderPreparation = async () => {
    if (!selectedBon) return;
    try {
      await updateLignesBL(selectedBon.id, lignesPreparation);
      setShowPreparationModal(false);
      loadBons();
    } catch (error) {
      signaler(error, 'Erreur lors de l\'enregistrement de la préparation');
    }
  };

  const handleMarquerPret = async (id: number) => {
    try {
      await marquerBLPret(id);
      loadBons();
    } catch (error) {
      // Les lignes non préparées partent pour la quantité à livrer : seul un bon qui n'est plus en préparation est refusé.
      signaler(error, "Le bon n'a pas pu être marqué prêt.");
    }
  };

  const handleOuvrirLivraison = async (id: number) => {
    try {
      const bon = await getBonLivraison(id);
      setSelectedBon(bon);
      setLignesLivraison(
        bon.lignes?.map((l: BonLivraisonLigne) => ({
          id: l.id,
          quantite_livree: l.quantite_preparee || l.quantite_a_livrer,
        })) || []
      );
      // Lots proposés : les premiers à périmer, pour la quantité préparée.
      const disponibles = await getExemplairesBL(id).catch(() => [] as ExemplairesLigneBL[]);
      setExemplaires(Object.fromEntries(disponibles.map(e => [e.ligne_id, e])));
      setAllocations(Object.fromEntries(disponibles.filter(e => e.suivi === 'lot').map(e => {
        const ligne = bon.lignes?.find((l: BonLivraisonLigne) => l.id === e.ligne_id);
        return [e.ligne_id, premiersSortis(e.lots, ((ligne?.quantite_preparee || ligne?.quantite_a_livrer) ?? 0) * e.facteur)];
      })));
      setSeriesLivrees({});
      setNotesLivraison('');
      setSignature(null);
      setSignataire('');
      setShowLivraisonModal(true);
    } catch (error) {
      signaler(error, 'Impossible de charger le bon de livraison');
    }
  };

  const handleAnnuler = async (id: number) => {
    if (!confirm('Annuler ce bon de livraison ?')) return;
    try {
      await annulerBL(id);
      loadBons();
    } catch (error) {
      signaler(error, 'Impossible d\'annuler ce bon.');
    }
  };

  const handleEnregistrerLivraison = async () => {
    if (!selectedBon) return;
    try {
      await enregistrerLivraison(selectedBon.id, {
        lignes: lignesLivraison.map(l => {
          const exemplaire = exemplaires[l.id];
          if (exemplaire?.suivi === 'lot') {
            return { ...l, lots: Object.entries(allocations[l.id] ?? {}).filter(([, q]) => q > 0).map(([lot, q]) => ({ lot_id: Number(lot), quantite: q })) };
          }
          return exemplaire?.suivi === 'serie' ? { ...l, numeros_serie: numerosSaisis(seriesLivrees[l.id] ?? '') } : l;
        }),
        notes_livraison: notesLivraison || undefined,
        signature_client: signature ?? undefined,
        signataire_nom: signature ? signataire.trim() || undefined : undefined,
      });
      setShowLivraisonModal(false);
      loadBons();
    } catch (error) {
      signaler(error, 'Erreur lors de l\'enregistrement de la livraison');
    }
  };

  // Libellé et couleurs du statut : ceux de l'entreprise (Paramètres → Statuts des documents) ; ces libellés en attendant.
  const getStatutBadge = (statut: string) => {
    const labels: Record<string, string> = {
      cree: 'Créé',
      en_preparation: 'En préparation',
      pret: 'Prêt',
      en_livraison: 'En livraison',
      livre_complet: 'Livré',
      livre_partiel: 'Partiel',
      annule: 'Annulé',
    };
    return <BadgeStatut document="bon_livraison" statut={statut} libelleDefaut={labels[statut]} />;
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Bons de Livraison</h1>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="relative flex-1 min-w-[200px]">
            <Search size={20} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              placeholder="Rechercher par numéro, facture ou client..."
              aria-label="Rechercher un bon de livraison"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-10 pr-4 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            />
          </div>
          <select
            value={filterStatut}
            onChange={(e) => { setFilterStatut(e.target.value); setPage(1); }}
            aria-label="Filtrer par statut"
            className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          >
            <option value="">Tous les statuts</option>
            <option value="cree">Créé</option>
            <option value="en_preparation">En préparation</option>
            <option value="pret">Prêt</option>
            <option value="en_livraison">En livraison</option>
            <option value="livre_complet">Livré complet</option>
            <option value="livre_partiel">Livré partiel</option>
          </select>
          {transporteursActifs(transporteurs) && (
            <ChoixTransporteur value={filterTransporteur} onChange={id => { setFilterTransporteur(id); setPage(1); }} transporteurs={transporteurs}
              libelleVide={t.carriers.all} ariaLabel={t.carriers.label} className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
          )}
          <FiltreStatutPersonnalise document="bon_livraison" value={filterStatutPerso} onChange={id => { setFilterStatutPerso(id); setPage(1); }}
            className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
          <FiltrePrioritaires actif={filtrePrioritaires} onChange={actif => { setFiltrePrioritaires(actif); setPage(1); }} />
        </div>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : bons.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucun bon de livraison trouvé</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">N°</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Facture</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Client</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Mode</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {bons.map((bon) => (
                <tr key={bon.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">
                    {bon.numero}
                    <EtoilePrioritaire document="bon_livraison" documentId={bon.id} valeur={bon.prioritaire} modifiable={peut('bons_livraison', 'modifier')}
                      onChange={p => setBons(ls => ls.map(d => (d.id === bon.id ? { ...d, prioritaire: p } : d)))} />
                    <BalisesFiche entite="bon_livraison" entiteId={bon.id} />
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{bon.facture?.numero}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{bon.facture?.client?.raison_sociale ?? '—'}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                    {bon.mode_livraison === 'entreprise' ? 'Livraison' : 'Retrait client'}
                  </td>
                  <td className="px-4 py-3 text-center">
                    {getStatutBadge(bon.statut)}
                    {bon.statut_personnalise && <span className="block mt-1"><PastilleStatutPersonnalise statut={bon.statut_personnalise} /></span>}
                    {bon.a_signature && <PenLine size={14} className="inline ml-1 text-green-600" aria-label={t.deliverySignature.signed}><title>{t.deliverySignature.signed}</title></PenLine>}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <BoutonPiecesJointes entite="bon_livraison" entiteId={bon.id} titre={bon.numero} />
                      <button
                        onClick={() => handleVoirDetail(bon.id)}
                        className="p-1 text-gray-600 hover:bg-gray-100 rounded"
                        title="Voir détails"
                      >
                        <Eye size={18} />
                      </button>
                      <BoutonPdf chemin={`/bons-livraison/${bon.id}/pdf`} nomFichier={`bon-livraison-${bon.numero}.pdf`} />
                      <BoutonCourriel type="bon_livraison" documentId={bon.id} />
                      {((STATUTS_COLIS.includes(bon.statut) && peut('bons_livraison', 'preparer')) || (bon.colis_count ?? 0) > 0) && (
                        <button
                          onClick={() => setBonColis(bon.id)}
                          className="relative p-1 text-amber-700 hover:bg-amber-50 rounded"
                          title={t.parcels.button}
                          aria-label={`${t.parcels.button} — ${bon.numero}`}
                        >
                          <Boxes size={18} />
                          {(bon.colis_count ?? 0) > 0 && (
                            <span className="absolute -top-1 -right-1 min-w-4 px-0.5 rounded-full bg-amber-600 text-[10px] leading-4 text-white">{bon.colis_count}</span>
                          )}
                        </button>
                      )}
                      {bon.statut === 'cree' && (
                        <>
                          {peut('bons_livraison', 'preparer') && (
                            <button
                              onClick={() => handleDemarrerPreparation(bon.id)}
                              className="p-1 text-yellow-600 hover:bg-yellow-50 rounded"
                              title="Démarrer préparation"
                            >
                              <Package size={18} />
                            </button>
                          )}
                          {peut('bons_livraison', 'annuler') && (
                            <button
                              onClick={() => handleAnnuler(bon.id)}
                              className="p-1 text-red-600 hover:bg-red-50 rounded"
                              title="Annuler"
                            >
                              <XCircle size={18} />
                            </button>
                          )}
                        </>
                      )}
                      {bon.statut === 'en_preparation' && (
                        <>{peut('bons_livraison', 'annuler') && (
                          <button
                            onClick={() => handleAnnuler(bon.id)}
                            className="p-1 text-red-600 hover:bg-red-50 rounded"
                            title="Annuler"
                          >
                            <XCircle size={18} />
                          </button>
                        )}</>
                      )}
                      {bon.statut === 'en_preparation' && (
                        <>
                          <button
                            onClick={() => handleOuvrirPreparation(bon.id)}
                            className="p-1 text-yellow-600 hover:bg-yellow-50 rounded"
                            title="Modifier préparation"
                          >
                            <Package size={18} />
                          </button>
                          {peut('bons_livraison', 'preparer') && (
                            <button
                              onClick={() => handleMarquerPret(bon.id)}
                              className="p-1 text-blue-600 hover:bg-blue-50 rounded"
                              title="Marquer prêt"
                            >
                              <CheckCircle size={18} />
                            </button>
                          )}
                        </>
                      )}
                      {(bon.statut === 'pret' || bon.statut === 'en_livraison') && (
                        <>{peut('bons_livraison', 'livrer') && (
                          <button
                            onClick={() => handleOuvrirLivraison(bon.id)}
                            className="p-1 text-green-600 hover:bg-green-50 rounded"
                            title="Enregistrer livraison"
                          >
                            <Truck size={18} />
                          </button>
                        )}</>
                      )}
                      <BoutonChampsPersonnalises entite="bon_livraison" entiteId={bon.id} titre={bon.numero} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />
      </div>

      {bonColis !== null && (
        <ColisBon bonId={bonColis} peutPreparer={peut('bons_livraison', 'preparer')} onClose={() => { setBonColis(null); loadBons(); }} />
      )}

      {/* Modal détail */}
      {showDetailModal && selectedBon && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              Bon de livraison {selectedBon.numero}
            </h2>
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <span className="text-sm text-gray-500">Facture:</span>
                  <p className="font-medium dark:text-white">{selectedBon.facture?.numero}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Client:</span>
                  <p className="font-medium dark:text-white">{selectedBon.facture?.client?.raison_sociale ?? '—'}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Statut:</span>
                  <p>{getStatutBadge(selectedBon.statut)}</p>
                </div>
                <ChoixStatutPersonnalise document="bon_livraison" documentId={selectedBon.id} statut={selectedBon.statut_personnalise} modifiable={peut('bons_livraison', 'modifier')}
                  onChange={statut => { setSelectedBon(d => (d ? { ...d, statut_personnalise: statut, statut_personnalise_id: statut?.id ?? null } : d)); loadBons(); }} />
                <div>
                  <span className="text-sm text-gray-500">Mode:</span>
                  <p className="dark:text-white">
                    {selectedBon.mode_livraison === 'entreprise' ? 'Livraison entreprise' : 'Retrait client'}
                  </p>
                </div>
                {selectedBon.numero_commande_client && (
                  <div>
                    <span className="text-sm text-gray-500">{t.customerPo.label} :</span>
                    <p className="dark:text-white">{selectedBon.numero_commande_client}</p>
                  </div>
                )}
                <div>
                  <span className="text-sm text-gray-500">Date livraison:</span>
                  <p className="dark:text-white">{formatDateHeure(selectedBon.date_livraison)}</p>
                </div>
                <DetailLivraison document={selectedBon} />
              </div>

              <ExpeditionBon key={selectedBon.id} bon={selectedBon} modifiable={peut('bons_livraison', 'modifier')}
                onChange={bon => { setSelectedBon(bon); loadBons(); }} />

              {selectedBon.signature_client && (
                <div className="border-t pt-4 dark:border-gray-700">
                  <h3 className="font-medium mb-2 dark:text-white">{t.deliverySignature.title}</h3>
                  <img src={selectedBon.signature_client} alt={t.deliverySignature.title} className="max-h-28 rounded border bg-white p-1 dark:border-gray-600" />
                  <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {[selectedBon.signataire_nom, selectedBon.signe_le && formatDateHeure(selectedBon.signe_le)].filter(Boolean).join(' — ')}
                  </p>
                </div>
              )}

              {selectedBon.lignes && selectedBon.lignes.length > 0 && (
                <div className="border-t pt-4 dark:border-gray-700">
                  <h3 className="font-medium mb-2 dark:text-white">Lignes</h3>
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-gray-500">
                        <th className="py-1">Produit</th>
                        <th className="py-1 text-right">À livrer</th>
                        <th className="py-1 text-right">Préparé</th>
                        <th className="py-1 text-right">Livré</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedBon.lignes.map((ligne, idx) => (
                        <tr key={idx} className="dark:text-gray-300">
                          <td className="py-1">{nomProduit(ligne)}</td>
                          <td className="py-1 text-right">{quantiteAvecUnite(ligne.quantite_a_livrer, ligne.unite)}</td>
                          <td className="py-1 text-right">{quantiteAvecUnite(ligne.quantite_preparee, ligne.unite)}</td>
                          <td className="py-1 text-right">{quantiteAvecUnite(ligne.quantite_livree, ligne.unite)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
            <div className="flex justify-end mt-6">
              <button
                onClick={() => { setShowDetailModal(false); setSelectedBon(null); }}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Fermer
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal préparation */}
      {showPreparationModal && selectedBon && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Préparation - {selectedBon.numero}</h2>
            <div className="space-y-3">
              {selectedBon.lignes?.map((ligne) => {
                const prep = lignesPreparation.find(l => l.id === ligne.id);
                return (
                  <div key={ligne.id} className="flex items-center justify-between gap-4 p-3 bg-gray-50 dark:bg-gray-700 rounded">
                    <div className="flex-1">
                      <p className="font-medium dark:text-white">{nomProduit(ligne)}</p>
                      <p className="text-sm text-gray-500">À préparer: {quantiteAvecUnite(ligne.quantite_a_livrer, ligne.unite)}</p>
                    </div>
                    <input
                      type="number"
                      min="0"
                      max={ligne.quantite_a_livrer}
                      value={prep?.quantite_preparee || 0}
                      onChange={(e) => {
                        const newLignes = [...lignesPreparation];
                        const index = newLignes.findIndex(l => l.id === ligne.id);
                        if (index >= 0) {
                          newLignes[index].quantite_preparee = parseInt(e.target.value) || 0;
                          setLignesPreparation(newLignes);
                        }
                      }}
                      className="w-20 px-2 py-1 border rounded text-center dark:bg-gray-600 dark:border-gray-500 dark:text-white"
                    />
                  </div>
                );
              })}
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => setShowPreparationModal(false)}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Annuler
              </button>
              <button
                onClick={handleSauvegarderPreparation}
                className="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700"
              >
                Sauvegarder
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal livraison */}
      {showLivraisonModal && selectedBon && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[92vh] overflow-y-auto">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Livraison - {selectedBon.numero}</h2>
            <div className="space-y-3">
              {selectedBon.lignes?.map((ligne) => {
                const liv = lignesLivraison.find(l => l.id === ligne.id);
                const exemplaire = exemplaires[ligne.id];
                return (
                  <div key={ligne.id} className="p-3 bg-gray-50 dark:bg-gray-700 rounded">
                  <div className="flex items-center justify-between gap-4">
                    <div className="flex-1">
                      <p className="font-medium dark:text-white">{nomProduit(ligne)}</p>
                      <p className="text-sm text-gray-500">Préparé: {quantiteAvecUnite(ligne.quantite_preparee, ligne.unite)}</p>
                    </div>
                    <input
                      type="number"
                      min="0"
                      max={ligne.quantite_preparee}
                      value={liv?.quantite_livree || 0}
                      onChange={(e) => {
                        const newLignes = [...lignesLivraison];
                        const index = newLignes.findIndex(l => l.id === ligne.id);
                        if (index >= 0) {
                          newLignes[index].quantite_livree = parseInt(e.target.value) || 0;
                          setLignesLivraison(newLignes);
                          // Lots proposés de nouveau pour la nouvelle quantité.
                          if (exemplaire?.suivi === 'lot') {
                            setAllocations(a => ({ ...a, [ligne.id]: premiersSortis(exemplaire.lots, newLignes[index].quantite_livree * exemplaire.facteur) }));
                          }
                        }
                      }}
                      className="w-20 px-2 py-1 border rounded text-center dark:bg-gray-600 dark:border-gray-500 dark:text-white"
                    />
                  </div>
                  {exemplaire && (
                    <ExemplairesLivraison exemplaire={exemplaire} unites={(liv?.quantite_livree ?? 0) * exemplaire.facteur}
                      allocations={allocations[ligne.id] ?? {}} onAllocations={a => setAllocations(actuelles => ({ ...actuelles, [ligne.id]: a }))}
                      series={seriesLivrees[ligne.id] ?? ''} onSeries={texte => setSeriesLivrees(s => ({ ...s, [ligne.id]: texte }))} />
                  )}
                  </div>
                );
              })}
              <div className="pt-3">
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Notes livraison</label>
                <textarea
                  value={notesLivraison}
                  onChange={(e) => setNotesLivraison(e.target.value)}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  rows={2}
                  placeholder="Remarques, problèmes..."
                />
                <BoutonRemarquesRapides onInserer={texte => setNotesLivraison(n => ajouterRemarque(n, texte))} />
              </div>
              <div className="pt-1">
                <p className="text-sm font-medium mb-1 dark:text-gray-300">{t.deliverySignature.title}</p>
                <input value={signataire} onChange={e => setSignataire(e.target.value)} maxLength={150} aria-label={t.deliverySignature.name}
                  placeholder={t.deliverySignature.name}
                  className="w-full mb-2 px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                <PadSignature onChange={setSignature} />
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => setShowLivraisonModal(false)}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Annuler
              </button>
              <button
                onClick={handleEnregistrerLivraison}
                className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
              >
                Valider livraison
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
