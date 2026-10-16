import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { useEffect, useState, useCallback } from 'react';
import BoutonChampsPersonnalises from '@/components/BoutonChampsPersonnalises';
import { Search, Plus, X, Eye, Send, Ban, Trash2, Package, FileText } from 'lucide-react';
import BalisesFiche from '@/components/BalisesFiche';
import BoutonRemarquesRapides from '@/components/BoutonRemarquesRapides';
import { ajouterRemarque } from '@/lib/utils';
import Pagination from '@/components/Pagination';
import { signaler } from '@/lib/erreurs';
import { aujourdhui, formatDateDocument, formatMontantIso } from '@/lib/documents';
import {
  getCommandesFournisseur,
  getCommandeFournisseur,
  getFournisseursActifs,
  getProduits,
  createCommandeFournisseur,
  validerCommandeFournisseur,
  annulerCommandeFournisseur,
  deleteCommandeFournisseur,
  getCommandePdfUrl,
  getConfiguration,
  cloturerCommandeFournisseur,
  getTauxChange,
  getParametres,
} from '@/services/api';
import type { ComFourEntete, Fournisseur } from '@/services/api';
import type { Produit } from '@/types';
import BoutonCourriel from '@/components/BoutonCourriel';

const statutLabels: Record<string, { label: string; color: string }> = {
  brouillon: { label: 'Brouillon', color: 'bg-gray-100 text-gray-700' },
  envoyee: { label: 'Envoyée', color: 'bg-blue-100 text-blue-700' },
  partielle: { label: 'Partielle', color: 'bg-yellow-100 text-yellow-700' },
  complete: { label: 'Complète', color: 'bg-green-100 text-green-700' },
  annulee: { label: 'Annulée', color: 'bg-red-100 text-red-700' },
};

interface LigneForm {
  produit_id: number;
  quantite_commandee: number;
  prix_unitaire: number;
}

export default function CommandesFournisseurPage() {
  const [commandes, setCommandes] = useState<ComFourEntete[]>([]);
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [recherche, setRecherche] = useState('');
  const [statutFilter, setStatutFilter] = useState('');
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [showModal, setShowModal] = useState(false);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [selectedCommande, setSelectedCommande] = useState<ComFourEntete | null>(null);

  const [formFournisseur, setFormFournisseur] = useState<number>(0);
  const [formDateCommande, setFormDateCommande] = useState(aujourdhui());
  const [formDateLivraison, setFormDateLivraison] = useState('');
  const [formNotes, setFormNotes] = useState('');
  const [formLignes, setFormLignes] = useState<LigneForm[]>([]);
  const [formDevise, setFormDevise] = useState('EUR');
  const [formTauxChange, setFormTauxChange] = useState(1);
  const [deviseLocale, setDeviseLocale] = useState('EUR');
  const [loadingTaux, setLoadingTaux] = useState(false);
  // Origine du taux affiché : référentiel de l'entreprise, cours du jour, ou saisie (taux introuvable ou corrigé).
  const [sourceTaux, setSourceTaux] = useState<'entreprise' | 'frankfurter' | 'saisie' | null>(null);
  const [configNumero, setConfigNumero] = useState<{ prefixe: string; separateur: string; longueur: number; prochain_numero: number; suffixe: string } | null>(null);

  // La recherche part 300 ms après la dernière frappe, et non à chaque caractère.
  useEffect(() => {
    const minuterie = setTimeout(() => { setRecherche(search.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [search]);

  const loadData = useCallback(async () => {
    try {
      const commandesRes = await getCommandesFournisseur({ search: recherche || undefined, statut: statutFilter || undefined, page });
      setCommandes(commandesRes.data || []);
      setPagination({ dernierePage: commandesRes.last_page ?? 1, total: commandesRes.total ?? 0 });
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [recherche, statutFilter, page]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // Fournisseurs, produits, numérotation et devise : une fois, et non à chaque recherche ou page.
  useEffect(() => {
    getFournisseursActifs().then(f => setFournisseurs(f || [])).catch(() => {});
    getProduits().then(p => setProduits(p || [])).catch(() => {});
    getConfiguration('commande_fournisseur').then(setConfigNumero).catch(() => {});
    getParametres().then(p => setDeviseLocale(p.devise_code || 'EUR')).catch(() => {});
  }, []);

  /** Montant d'une commande dans sa devise (celle du fournisseur), et non en euros d'office. */
  const montantCommande = (valeur: number | string | null | undefined, devise?: string | null) =>
    formatMontantIso(valeur, devise || deviseLocale);

  async function handleFournisseurChange(id: number) {
    setFormFournisseur(id);
    const fournisseur = fournisseurs.find(f => f.id === id);
    const devise = fournisseur?.devise || deviseLocale;
    setFormDevise(devise);
    if (devise !== deviseLocale) {
      setLoadingTaux(true);
      try {
        const res = await getTauxChange(devise, deviseLocale);
        setFormTauxChange(Number(res.taux));
        setSourceTaux(res.source === 'entreprise' ? 'entreprise' : 'frankfurter');
      } catch {
        // Taux introuvable : à saisir. L'enregistrer à 1 faussait en silence tous les montants convertis.
        setFormTauxChange(0);
        setSourceTaux('saisie');
      } finally {
        setLoadingTaux(false);
      }
    } else {
      setFormTauxChange(1);
      setSourceTaux(null);
    }
  }

  function openCreate() {
    setFormFournisseur(0);
    setFormDateCommande(aujourdhui());
    setFormDateLivraison('');
    setFormNotes('');
    setFormLignes([{ produit_id: 0, quantite_commandee: 1, prix_unitaire: 0 }]);
    setFormDevise(deviseLocale);
    setFormTauxChange(1);
    setSourceTaux(null);
    setShowModal(true);
  }

  async function openDetail(commande: ComFourEntete) {
    setSelectedCommande(commande);
    setShowDetailModal(true);
    try {
      const full = await getCommandeFournisseur(commande.id);
      setSelectedCommande(full);
    } catch {
      // garde les données partielles si l'appel échoue
    }
  }

  function addLigne() {
    setFormLignes([...formLignes, { produit_id: 0, quantite_commandee: 1, prix_unitaire: 0 }]);
  }

  function removeLigne(index: number) {
    setFormLignes(formLignes.filter((_, i) => i !== index));
  }

  function updateLigne(index: number, field: keyof LigneForm, value: number) {
    const newLignes = [...formLignes];
    newLignes[index] = { ...newLignes[index], [field]: value };
    setFormLignes(newLignes);
  }

  const libelleSourceTaux = sourceTaux === 'entreprise'
    ? 'taux du référentiel des devises'
    : sourceTaux === 'frankfurter' ? 'cours du jour (Frankfurter)' : 'taux saisi';

  function getProduitConditionnement(produitId: number) {
    const p = produits.find(p => p.id === produitId);
    if (!p?.unite_achat || (p.qte_par_unite_achat ?? 1) <= 1) return null;
    return { unite_achat: p.unite_achat, qte: p.qte_par_unite_achat ?? 1, mesure: p.mesure };
  }

  function getMontantTotal(): number {
    return formLignes.reduce((sum, l) => sum + (l.quantite_commandee * l.prix_unitaire), 0);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!formFournisseur) {
      alert('Veuillez sélectionner un fournisseur');
      return;
    }
    if (formLignes.some(l => !l.produit_id || l.quantite_commandee <= 0)) {
      alert('Veuillez remplir correctement toutes les lignes');
      return;
    }
    if (formDevise !== deviseLocale && !(formTauxChange > 0)) {
      alert(`Indiquez le taux de change ${formDevise} → ${deviseLocale}.`);
      return;
    }

    try {
      await createCommandeFournisseur({
        fournisseur_id: formFournisseur,
        date_commande: formDateCommande,
        date_livraison_prevue: formDateLivraison || undefined,
        notes: formNotes || undefined,
        devise: formDevise,
        taux_change: formTauxChange,
        lignes: formLignes,
      });
      setShowModal(false);
      loadData();
    } catch (error) {
      signaler(error, 'Erreur lors de la création');
    }
  }

  async function handleValider(id: number) {
    if (!confirm('Voulez-vous valider et envoyer cette commande ?')) return;
    try {
      await validerCommandeFournisseur(id);
      loadData();
      setShowDetailModal(false);
    } catch (error) {
      signaler(error, 'Erreur lors de la validation');
    }
  }

  async function handleAnnuler(id: number) {
    if (!confirm('Voulez-vous annuler cette commande ?')) return;
    try {
      await annulerCommandeFournisseur(id);
      loadData();
      setShowDetailModal(false);
    } catch (error) {
      signaler(error, 'Erreur lors de l\'annulation');
    }
  }

  async function handleCloturer(id: number) {
    if (!confirm('Clôturer cette commande ? Le reste non reçu sera annulé et la commande passera en "Complète".')) return;
    try {
      await cloturerCommandeFournisseur(id);
      loadData();
      setShowDetailModal(false);
    } catch (error) {
      signaler(error, 'Erreur lors de la clôture');
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Voulez-vous supprimer cette commande ?')) return;
    try {
      await deleteCommandeFournisseur(id);
      loadData();
    } catch (error) {
      signaler(error, 'Seules les commandes en brouillon peuvent être supprimées');
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Commandes Fournisseur</h1>
        <button
          onClick={openCreate}
          className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
        >
          <Plus size={20} />
          Nouvelle commande
        </button>
      </div>

      <div className="bg-white rounded-xl shadow-sm p-4 mb-6">
        <div className="flex flex-wrap gap-4">
          <div className="relative flex-1 min-w-[200px]">
            <Search size={20} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input
              type="text"
              placeholder="Rechercher par numéro ou fournisseur..."
              aria-label="Rechercher une commande"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-10 pr-4 py-2 border rounded-lg"
            />
          </div>
          <select
            value={statutFilter}
            onChange={(e) => { setStatutFilter(e.target.value); setPage(1); }}
            aria-label="Filtrer par statut"
            className="px-4 py-2 border rounded-lg"
          >
            <option value="">Tous les statuts</option>
            <option value="brouillon">Brouillon</option>
            <option value="envoyee">Envoyée</option>
            <option value="partielle">Partielle</option>
            <option value="complete">Complète</option>
            <option value="annulee">Annulée</option>
          </select>
        </div>
      </div>

      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : commandes.length === 0 ? (
          <div className="p-6 text-gray-500">Aucune commande trouvée</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Numéro</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Fournisseur</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Date</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Livraison prévue</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Montant</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Statut</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {commandes.map((commande) => (
                  <tr key={commande.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-mono font-medium">
                      {commande.numero}
                      <BalisesFiche entite="commande_fournisseur" entiteId={commande.id} />
                    </td>
                    <td className="px-4 py-3 text-sm">{commande.fournisseur?.raison_sociale}</td>
                    <td className="px-4 py-3 text-sm">
                      {formatDateDocument(commande.date_commande)}
                    </td>
                    <td className="px-4 py-3 text-sm">
                      {formatDateDocument(commande.date_livraison_prevue)}
                    </td>
                    <td className="px-4 py-3 text-sm text-right font-medium">
                      {montantCommande(commande.montant_total, commande.devise)}
                    </td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-1 rounded text-xs font-medium ${statutLabels[commande.statut]?.color}`}>
                        {statutLabels[commande.statut]?.label}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <BoutonPiecesJointes entite="commande_fournisseur" entiteId={commande.id} titre={commande.numero} taille={16} className="mr-1" />
                      <button
                        onClick={() => openDetail(commande)}
                        className="p-1 text-blue-600 hover:bg-blue-50 rounded mr-1"
                        title="Voir détails"
                      >
                        <Eye size={16} />
                      </button>
                      <a
                        href={getCommandePdfUrl(commande.id)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="p-1 text-purple-600 hover:bg-purple-50 rounded mr-1 inline-block"
                        title="Télécharger PDF"
                      >
                        <FileText size={16} />
                      </a>
                      <BoutonCourriel type="commande_fournisseur" documentId={commande.id} taille={16} className="mr-1" />
                      {commande.statut === 'brouillon' && (
                        <>
                          <button
                            onClick={() => handleValider(commande.id)}
                            className="p-1 text-green-600 hover:bg-green-50 rounded mr-1"
                            title="Valider"
                          >
                            <Send size={16} />
                          </button>
                          <button
                            onClick={() => handleDelete(commande.id)}
                            className="p-1 text-red-600 hover:bg-red-50 rounded"
                            title="Supprimer"
                          >
                            <Trash2 size={16} />
                          </button>
                        </>
                      )}
                      {commande.statut === 'envoyee' && (
                        <button
                          onClick={() => handleAnnuler(commande.id)}
                          className="p-1 text-red-600 hover:bg-red-50 rounded"
                          title="Annuler"
                        >
                          <Ban size={16} />
                        </button>
                      )}
                      {commande.statut === 'partielle' && (
                        <button
                          onClick={() => handleCloturer(commande.id)}
                          className="p-1 text-orange-600 hover:bg-orange-50 rounded"
                          title="Clôturer le reste"
                        >
                          <Ban size={16} />
                        </button>
                      )}
                      <BoutonChampsPersonnalises entite="commande_fournisseur" entiteId={commande.id} titre={commande.numero} taille={16} className="ml-1" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />
      </div>

      {/* Modal création */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-4xl mx-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between p-4 border-b sticky top-0 bg-white">
              <div>
                <h2 className="text-lg font-semibold">Nouvelle commande fournisseur</h2>
                {configNumero && (
                  <p className="text-xs text-gray-500 mt-0.5">
                    Numéro auto: {configNumero.prefixe}{configNumero.separateur || ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')}{configNumero.suffixe ? (configNumero.separateur || '') + configNumero.suffixe : ''}
                  </p>
                )}
              </div>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-4 space-y-4">
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Fournisseur *</label>
                  <select
                    value={formFournisseur}
                    onChange={(e) => handleFournisseurChange(Number(e.target.value))}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  >
                    <option value={0}>Sélectionner...</option>
                    {fournisseurs.map(f => (
                      <option key={f.id} value={f.id}>
                        {f.raison_sociale}{f.devise && f.devise !== deviseLocale ? ` (${f.devise})` : ''}
                      </option>
                    ))}
                  </select>
                  {formDevise !== deviseLocale && (
                    <div className="mt-1.5 text-xs text-orange-700 bg-orange-50 px-2 py-1.5 rounded space-y-1">
                      {loadingTaux ? (
                        <span>Récupération du taux...</span>
                      ) : (
                        <>
                          <label className="flex items-center gap-1.5">
                            <span>1 {formDevise} =</span>
                            <input
                              type="number"
                              min={0}
                              step="any"
                              value={formTauxChange || ''}
                              onChange={(e) => { setFormTauxChange(Number(e.target.value)); setSourceTaux('saisie'); }}
                              aria-label={`Taux de change ${formDevise} vers ${deviseLocale}`}
                              className="w-28 px-1.5 py-0.5 border border-orange-300 rounded bg-white text-gray-900"
                              required
                            />
                            <span>{deviseLocale}</span>
                          </label>
                          <p>
                            {formTauxChange > 0
                              ? libelleSourceTaux
                              : 'Taux introuvable : saisissez-le (ou renseignez la devise dans le référentiel des devises).'}
                          </p>
                        </>
                      )}
                    </div>
                  )}
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Date commande *</label>
                  <input
                    type="date"
                    value={formDateCommande}
                    onChange={(e) => setFormDateCommande(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Date livraison prévue</label>
                  <input
                    type="date"
                    value={formDateLivraison}
                    onChange={(e) => setFormDateLivraison(e.target.value)}
                    className="w-full px-3 py-2 border rounded-lg"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea
                  value={formNotes}
                  onChange={(e) => setFormNotes(e.target.value)}
                  className="w-full px-3 py-2 border rounded-lg"
                  rows={2}
                />
                <BoutonRemarquesRapides onInserer={texte => setFormNotes(n => ajouterRemarque(n, texte))} />
              </div>

              <div>
                <div className="flex items-center justify-between mb-2">
                  <label className="text-sm font-medium text-gray-700">Lignes de commande</label>
                  <button
                    type="button"
                    onClick={addLigne}
                    className="text-sm text-green-600 hover:text-green-700"
                  >
                    + Ajouter une ligne
                  </button>
                </div>
                <div className="border rounded-lg overflow-hidden">
                  <table className="w-full">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-600">Produit</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-600 w-32">Quantité</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-600 w-32">Prix unitaire</th>
                        <th className="px-3 py-2 text-right text-xs font-medium text-gray-600 w-32">Montant</th>
                        <th className="px-3 py-2 w-10"></th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {formLignes.map((ligne, index) => {
                        const cond = getProduitConditionnement(ligne.produit_id);
                        return (
                        <tr key={index}>
                          <td className="px-3 py-2">
                            <select
                              value={ligne.produit_id}
                              onChange={(e) => updateLigne(index, 'produit_id', Number(e.target.value))}
                              className="w-full px-2 py-1 border rounded text-sm"
                            >
                              <option value={0}>Sélectionner...</option>
                              {produits.map(p => (
                                <option key={p.id} value={p.id}>{p.numero} - {p.description}</option>
                              ))}
                            </select>
                          </td>
                          <td className="px-3 py-2">
                            <input
                              type="number"
                              value={ligne.quantite_commandee}
                              onChange={(e) => updateLigne(index, 'quantite_commandee', Number(e.target.value))}
                              className="w-full px-2 py-1 border rounded text-sm"
                              min={1}
                            />
                            {cond && (
                              <p className="text-xs text-orange-600 mt-0.5 whitespace-nowrap">
                                = {ligne.quantite_commandee * cond.qte} {cond.mesure}
                              </p>
                            )}
                          </td>
                          <td className="px-3 py-2">
                            <input
                              type="number"
                              value={ligne.prix_unitaire}
                              onChange={(e) => updateLigne(index, 'prix_unitaire', Number(e.target.value))}
                              className="w-full px-2 py-1 border rounded text-sm"
                              min={0}
                              step={0.01}
                            />
                          </td>
                          <td className="px-3 py-2 text-right text-sm font-medium">
                            {montantCommande(ligne.quantite_commandee * ligne.prix_unitaire, formDevise)}
                          </td>
                          <td className="px-3 py-2">
                            {formLignes.length > 1 && (
                              <button
                                type="button"
                                onClick={() => removeLigne(index)}
                                className="p-1 text-red-600 hover:bg-red-50 rounded"
                              >
                                <X size={14} />
                              </button>
                            )}
                          </td>
                        </tr>
                      );
                      })}
                    </tbody>
                    <tfoot className="bg-gray-50">
                      <tr>
                        <td colSpan={3} className="px-3 py-2 text-right font-medium">Total:</td>
                        <td className="px-3 py-2 text-right font-bold">
                          {montantCommande(getMontantTotal(), formDevise)}
                          {formDevise !== deviseLocale && formTauxChange > 0 && (
                            <div className="text-xs font-normal text-gray-500">
                              ≈ {montantCommande(getMontantTotal() * formTauxChange, deviseLocale)}
                            </div>
                          )}
                        </td>
                        <td></td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>

              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                >
                  Créer (Brouillon)
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal détail */}
      {showDetailModal && selectedCommande && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between p-4 border-b sticky top-0 bg-white">
              <div>
                <h2 className="text-lg font-semibold">Commande {selectedCommande.numero}</h2>
                <span className={`px-2 py-1 rounded text-xs font-medium ${statutLabels[selectedCommande.statut]?.color}`}>
                  {statutLabels[selectedCommande.statut]?.label}
                </span>
              </div>
              <button onClick={() => setShowDetailModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <div className="p-4 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-sm text-gray-500">Fournisseur</p>
                  <p className="font-medium">{selectedCommande.fournisseur?.raison_sociale}</p>
                  {selectedCommande.devise && selectedCommande.devise !== deviseLocale && (
                    <p className="text-xs text-orange-600 mt-0.5">
                      {selectedCommande.devise} · taux {Number(selectedCommande.taux_change).toFixed(4)} {deviseLocale}
                    </p>
                  )}
                </div>
                <div>
                  <p className="text-sm text-gray-500">Date commande</p>
                  <p className="font-medium">{formatDateDocument(selectedCommande.date_commande)}</p>
                </div>
                <div>
                  <p className="text-sm text-gray-500">Livraison prévue</p>
                  <p className="font-medium">
                    {formatDateDocument(selectedCommande.date_livraison_prevue)}
                  </p>
                </div>
                <div>
                  <p className="text-sm text-gray-500">Montant total</p>
                  <p className="font-bold text-lg">
                    {montantCommande(selectedCommande.montant_total, selectedCommande.devise)}
                  </p>
                  {selectedCommande.devise && selectedCommande.devise !== deviseLocale && Number(selectedCommande.taux_change) > 0 && (
                    <p className="text-xs text-gray-500">
                      ≈ {montantCommande(Number(selectedCommande.montant_total) * Number(selectedCommande.taux_change), deviseLocale)}
                    </p>
                  )}
                </div>
              </div>

              {selectedCommande.notes && (
                <div>
                  <p className="text-sm text-gray-500">Notes</p>
                  <p className="text-sm">{selectedCommande.notes}</p>
                </div>
              )}

              <div>
                <p className="text-sm font-medium text-gray-700 mb-2">Lignes de commande</p>
                <div className="border rounded-lg overflow-hidden">
                  <table className="w-full">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-600">Produit</th>
                        <th className="px-3 py-2 text-right text-xs font-medium text-gray-600">Commandé</th>
                        <th className="px-3 py-2 text-right text-xs font-medium text-gray-600">Reçu</th>
                        <th className="px-3 py-2 text-right text-xs font-medium text-gray-600">Prix unit.</th>
                        <th className="px-3 py-2 text-right text-xs font-medium text-gray-600">Montant</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {selectedCommande.lignes?.map((ligne) => (
                        <tr key={ligne.id}>
                          <td className="px-3 py-2 text-sm">
                            <div className="flex items-center gap-2">
                              <Package size={14} className="text-gray-400" />
                              {ligne.produit?.description || `Produit #${ligne.produit_id}`}
                            </div>
                          </td>
                          <td className="px-3 py-2 text-sm text-right">
                            {ligne.quantite_commandee}
                            {ligne.unite_achat && (ligne.qte_par_unite_achat ?? 1) > 1 && (
                              <div className="text-xs text-orange-600 whitespace-nowrap">
                                {ligne.unite_achat} × {ligne.qte_par_unite_achat} = {ligne.quantite_commandee * (ligne.qte_par_unite_achat ?? 1)} UN
                              </div>
                            )}
                          </td>
                          <td className="px-3 py-2 text-sm text-right">
                            <span className={ligne.quantite_recue >= ligne.quantite_commandee ? 'text-green-600' : 'text-orange-600'}>
                              {ligne.quantite_recue}
                            </span>
                          </td>
                          <td className="px-3 py-2 text-sm text-right">
                            {montantCommande(ligne.prix_unitaire, selectedCommande.devise)}
                          </td>
                          <td className="px-3 py-2 text-sm text-right font-medium">
                            {montantCommande(ligne.montant_ligne, selectedCommande.devise)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              <div className="flex gap-3 pt-2">
                <button
                  onClick={() => setShowDetailModal(false)}
                  className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50"
                >
                  Fermer
                </button>
                {selectedCommande.statut === 'brouillon' && (
                  <button
                    onClick={() => handleValider(selectedCommande.id)}
                    className="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center justify-center gap-2"
                  >
                    <Send size={16} />
                    Valider et envoyer
                  </button>
                )}
                {selectedCommande.statut === 'envoyee' && (
                  <button
                    onClick={() => handleAnnuler(selectedCommande.id)}
                    className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 flex items-center gap-2"
                  >
                    <Ban size={16} />
                    Annuler
                  </button>
                )}
                {selectedCommande.statut === 'partielle' && (
                  <button
                    onClick={() => handleCloturer(selectedCommande.id)}
                    className="px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 flex items-center gap-2"
                  >
                    <Ban size={16} />
                    Clôturer le reste
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
