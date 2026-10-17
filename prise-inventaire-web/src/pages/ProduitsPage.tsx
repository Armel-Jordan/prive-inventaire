import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { useEffect, useState, useRef } from 'react';
import BoutonChampsPersonnalises from '@/components/BoutonChampsPersonnalises';
import { Link } from 'react-router-dom';
import { Search, Plus, X, Edit2, Trash2, Download, Upload, Filter, RotateCcw, ShoppingCart, Barcode, Tags, Boxes, Link2 } from 'lucide-react';
import FenetreCodesBarres from '@/components/produits/FenetreCodesBarres';
import FenetreUnitesProduit from '@/components/produits/FenetreUnitesProduit';
import FenetreLiensProduit from '@/components/produits/FenetreLiensProduit';
import ChampsFicheProduit, { type ValeursFiche } from '@/components/produits/ChampsFicheProduit';
import { LANGUES_TRADUCTION } from '@/lib/ficheProduit';
import SuggererAchat from '@/components/achats/SuggererAchat';
import BalisesFiche from '@/components/BalisesFiche';
import { getProduits, createProduit, updateProduit, deleteProduit, getSecteurs, getConfiguration, getReferentiel } from '@/services/api';
import type { Produit, Secteur } from '@/types';
import { useToast } from '@/hooks/useToast';
import { messageErreur } from '@/lib/erreurs';
import Toasts from '@/components/Toasts';
import ConfirmModal from '@/components/ConfirmModal';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';

interface UniteChoix {
  code: string;
  libelle: string;
}

/** Unités proposées tant que l'entreprise n'a pas rempli son référentiel des unités. */
const UNITES_ORIGINE: UniteChoix[] = [
  { code: 'UN', libelle: 'Unité' }, { code: 'KG', libelle: 'Kilogramme' }, { code: 'L', libelle: 'Litre' },
  { code: 'M', libelle: 'Mètre' }, { code: 'M2', libelle: 'Mètre carré' }, { code: 'M3', libelle: 'Mètre cube' },
];

/** Le formulaire porte aussi la fiche détaillée (fabricant, description longue, traductions, image). */
interface ProduitForm extends ValeursFiche {
  numero: string;
  description: string;
  mesure: string;
  unite_achat: string;
  qte_par_unite_achat: number;
  type: string;
  secteur_id: string;
  // Transport : saisis comme du texte, envoyés vides (null) ou en nombre.
  numero_onu: string;
  classe_danger: string;
  groupe_emballage: string;
  poids_kg: string;
  volume_m3: string;
}

const emptyForm: ProduitForm = {
  numero: '', description: '', mesure: 'UN', unite_achat: '', qte_par_unite_achat: 1, type: '', secteur_id: '',
  numero_onu: '', classe_danger: '', groupe_emballage: '', poids_kg: '', volume_m3: '',
  fabricant_id: '', reference_fabricant: '', description_longue: '', traductions: {}, image_id: null,
};

/** Matière dangereuse : numéro ONU ou classe renseignés (et pas seulement des zéros), comme côté API. */
const estDangereux = (p: Produit) => /[1-9]/.test(`${p.numero_onu ?? ''}${p.classe_danger ?? ''}`);

interface ConfigNumero {
  auto_increment: boolean;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur: string;
  prochain_numero: number;
}

export default function ProduitsPage() {
  const { t } = useLanguage();
  const th = t.hazmat;
  const [produits, setProduits] = useState<Produit[]>([]);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [filterType, setFilterType] = useState('');
  const [filterSecteur, setFilterSecteur] = useState('');
  const [filterFabricant, setFilterFabricant] = useState('');
  // Produits désactivés : masqués par défaut, retrouvés par ce filtre pour être réactivés.
  const [filterStatut, setFilterStatut] = useState<'actifs' | 'inactifs' | 'tous'>('actifs');
  const [showModal, setShowModal] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<ProduitForm>(emptyForm);
  const [importing, setImporting] = useState(false);
  const [configNumero, setConfigNumero] = useState<ConfigNumero | null>(null);
  const [confirmId, setConfirmId] = useState<number | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const { toasts, toast, dismiss } = useToast();
  // Boutons selon les droits du rôle sur les produits (l'API refuse de toute façon les autres écritures).
  const { canCreate, canEdit, canDelete, peut } = usePermissions();
  // Produit pour lequel on signale un besoin d'achat (suggestion d'achat).
  const [aSuggerer, setASuggerer] = useState<Produit | null>(null);
  const [codesDe, setCodesDe] = useState<Produit | null>(null);
  const [unitesDe, setUnitesDe] = useState<Produit | null>(null);
  const [liensDe, setLiensDe] = useState<Produit | null>(null);
  const [unites, setUnites] = useState<UniteChoix[]>([]);

  async function loadData() {
    try {
      const [produitsData, secteursData] = await Promise.all([getProduits(filterStatut), getSecteurs()]);
      setProduits(produitsData);
      setSecteurs(secteursData);
    } catch {
      toast('Erreur lors du chargement', 'error');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    getConfiguration('produit').then(setConfigNumero).catch(() => {});
    // Unités du référentiel de l'entreprise (Paramètres → Référentiels) ; sans référentiel, la liste d'origine.
    getReferentiel('unites', true)
      .then(liste => setUnites(liste.map(u => ({ code: String(u.code), libelle: String(u.libelle ?? u.code) }))))
      .catch(() => {});
  }, []);

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterStatut]);

  async function handleReactiver(produit: Produit) {
    try {
      await updateProduit(produit.id!, { actif: true });
      toast('Produit réactivé');
      loadData();
    } catch {
      toast('Erreur lors de la réactivation', 'error');
    }
  }

  // Unique types for filter
  const types = [...new Set(produits.map(p => p.type).filter(Boolean))].sort();
  // Fabricants des produits listés, pour le filtre.
  const fabricants = [...new Map(produits.flatMap(p => (p.fabricant ? [[p.fabricant.id, p.fabricant.nom] as const] : []))).entries()]
    .sort((a, b) => a[1].localeCompare(b[1]));

  const filtered = produits.filter(p => {
    const q = search.toLowerCase();
    // Le numéro, la description, la référence fabricant ou l'un des codes-barres.
    const matchSearch = p.numero.toLowerCase().includes(q) || p.description.toLowerCase().includes(q)
      || (p.reference_fabricant ?? '').toLowerCase().includes(q)
      || (p.codes_barres ?? []).some(c => c.code.toLowerCase().includes(q));
    const matchType = !filterType || p.type === filterType;
    const matchSecteur = !filterSecteur || String(p.secteur_id) === filterSecteur;
    const matchFabricant = !filterFabricant || String(p.fabricant_id) === filterFabricant;
    return matchSearch && matchType && matchSecteur && matchFabricant;
  });

  function openCreate() {
    setForm(emptyForm);
    setEditingId(null);
    setShowModal(true);
  }

  function openEdit(produit: Produit) {
    setForm({
      numero: produit.numero,
      description: produit.description,
      mesure: produit.mesure,
      unite_achat: produit.unite_achat || '',
      qte_par_unite_achat: produit.qte_par_unite_achat ?? 1,
      type: produit.type || '',
      secteur_id: produit.secteur_id?.toString() || '',
      numero_onu: produit.numero_onu ?? '',
      classe_danger: produit.classe_danger ?? '',
      groupe_emballage: produit.groupe_emballage ?? '',
      poids_kg: produit.poids_kg != null ? String(Number(produit.poids_kg)) : '',
      volume_m3: produit.volume_m3 != null ? String(Number(produit.volume_m3)) : '',
      fabricant_id: produit.fabricant_id ? String(produit.fabricant_id) : '',
      reference_fabricant: produit.reference_fabricant ?? '',
      description_longue: produit.description_longue ?? '',
      traductions: Object.fromEntries((produit.traductions ?? []).map(tr => [tr.langue, { description: tr.description ?? '', description_longue: tr.description_longue ?? '' }])),
      image_id: produit.image_id ?? null,
    });
    setEditingId(produit.id!);
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      const payload = {
        ...form,
        secteur_id: form.secteur_id ? parseInt(form.secteur_id) : undefined,
        unite_achat: form.unite_achat || null,
        qte_par_unite_achat: form.qte_par_unite_achat,
        numero_onu: form.numero_onu.trim() || null,
        classe_danger: form.classe_danger.trim() || null,
        groupe_emballage: form.groupe_emballage || null,
        poids_kg: form.poids_kg === '' ? null : Number(form.poids_kg),
        volume_m3: form.volume_m3 === '' ? null : Number(form.volume_m3),
        fabricant_id: form.fabricant_id ? Number(form.fabricant_id) : null,
        reference_fabricant: form.reference_fabricant.trim() || null,
        description_longue: form.description_longue.trim() || null,
        // Toutes les langues : une traduction vidée est retirée par l'API.
        traductions: LANGUES_TRADUCTION.map(langue => ({
          langue,
          description: form.traductions[langue]?.description.trim() || null,
          description_longue: form.traductions[langue]?.description_longue.trim() || null,
        })),
        image_id: form.image_id,
      };
      if (editingId) {
        await updateProduit(editingId, payload);
        toast('Produit modifié avec succès');
      } else {
        await createProduit(payload);
        toast('Produit créé avec succès');
      }
      setShowModal(false);
      loadData();
    } catch (e) {
      // Raison donnée par l'API (numéro déjà pris, champ invalide) plutôt qu'un message générique.
      toast(messageErreur(e, 'Erreur lors de la sauvegarde'), 'error');
    }
  }

  async function handleDelete(id: number) {
    try {
      await deleteProduit(id);
      toast('Produit supprimé');
      loadData();
    } catch (e) {
      toast(messageErreur(e, 'Erreur lors de la suppression'), 'error');
    } finally {
      setConfirmId(null);
    }
  }

  function exportToCSV() {
    const headers = ['numero', 'description', 'secteur_code', 'mesure', 'type'];
    const csv = [
      headers.join(';'),
      ...produits.map(p => [p.numero, p.description, p.secteur?.code || '', p.mesure, p.type || ''].join(';'))
    ].join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `produits_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast('Export téléchargé');
  }

  async function handleImport(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;
    setImporting(true);
    try {
      const text = await file.text();
      const lines = text.split('\n').filter(l => l.trim());
      const headers = lines[0].split(';').map(h => h.trim().toLowerCase());
      let imported = 0, errors = 0;
      // Lignes refusées, avec leur numéro et la raison donnée par l'API (seul un total était affiché).
      const refus: string[] = [];
      for (let i = 1; i < lines.length; i++) {
        const values = lines[i].split(';').map(v => v.trim());
        const row: Record<string, string> = {};
        headers.forEach((h, idx) => { row[h] = values[idx] || ''; });
        if (row.numero && row.description) {
          try {
            const secteur = secteurs.find(s => s.code === row.secteur_code);
            await createProduit({ numero: row.numero, description: row.description, mesure: row.mesure || 'UN', type: row.type || '', secteur_id: secteur?.id });
            imported++;
          } catch (e) {
            errors++;
            refus.push(`ligne ${i + 1} (${row.numero}) : ${messageErreur(e, 'refusée')}`);
          }
        }
      }
      const detail = refus.length ? ` — ${refus.slice(0, 3).join(' ; ')}${refus.length > 3 ? ' ; …' : ''}` : '';
      toast(`${imported} produits importés${errors ? `, ${errors} erreurs${detail}` : ''}`, errors ? 'info' : 'success');
      loadData();
    } catch {
      toast('Erreur lors de l\'import', 'error');
    } finally {
      setImporting(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  }

  const hasFilters = filterType || filterSecteur || filterFabricant;

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Produits</h1>
          {!loading && (
            <p className="text-sm text-gray-500 mt-0.5">
              {filtered.length} produit{filtered.length !== 1 ? 's' : ''}{filtered.length !== produits.length ? ` sur ${produits.length}` : ''}
            </p>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          {peut('etiquettes', 'voir') && filtered.some(p => p.actif !== false) && (
            <Link to={`/etiquettes?produits=${filtered.filter(p => p.actif !== false).slice(0, 200).map(p => p.id).join(',')}`}
              title={t.labels.printShownHelp}
              className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 dark:border-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
              <Tags size={18} /> {t.labels.printShown}
            </Link>
          )}
          <button onClick={exportToCSV} disabled={produits.length === 0}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 dark:border-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
            <Download size={18} /> Exporter
          </button>
          {canCreate('produits') && (
            <>
              <label className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 dark:border-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors cursor-pointer">
                <Upload size={18} />
                {importing ? 'Import...' : 'Importer'}
                <input ref={fileInputRef} type="file" accept=".csv" onChange={handleImport} className="hidden" disabled={importing} />
              </label>
              <button onClick={openCreate}
                className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <Plus size={20} /> Ajouter
              </button>
            </>
          )}
        </div>
      </div>

      {/* Filtres */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 mb-6">
        <div className="flex flex-wrap gap-3">
          <div className="relative flex-1 min-w-48">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input type="text" placeholder="Rechercher par numéro, description ou code-barres..."
              value={search} onChange={(e) => setSearch(e.target.value)}
              className="w-full pl-9 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500" />
          </div>
          <div className="relative">
            <Filter size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <select value={filterType} onChange={e => setFilterType(e.target.value)}
              className="pl-8 pr-8 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 appearance-none">
              <option value="">Tous les types</option>
              {types.map(t => <option key={t} value={t}>{t}</option>)}
            </select>
          </div>
          <div className="relative">
            <Filter size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <select value={filterSecteur} onChange={e => setFilterSecteur(e.target.value)}
              className="pl-8 pr-8 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 appearance-none">
              <option value="">Tous les secteurs</option>
              {secteurs.map(s => <option key={s.id} value={String(s.id)}>{s.code} — {s.nom}</option>)}
            </select>
          </div>
          {fabricants.length > 0 && (
            <div className="relative">
              <Filter size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
              <select value={filterFabricant} onChange={e => setFilterFabricant(e.target.value)} aria-label={t.productRecord.manufacturer}
                className="pl-8 pr-8 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 appearance-none">
                <option value="">{t.productRecord.allManufacturers}</option>
                {fabricants.map(([id, nom]) => <option key={id} value={String(id)}>{nom}</option>)}
              </select>
            </div>
          )}
          <select value={filterStatut} onChange={e => setFilterStatut(e.target.value as 'actifs' | 'inactifs' | 'tous')}
            aria-label="Statut"
            className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 dark:text-white text-sm">
            <option value="actifs">Actifs</option>
            <option value="inactifs">Désactivés</option>
            <option value="tous">Tous</option>
          </select>
          {hasFilters && (
            <button onClick={() => { setFilterType(''); setFilterSecteur(''); setFilterFabricant(''); }}
              className="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors flex items-center gap-1">
              <X size={14} /> Réinitialiser
            </button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : filtered.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun produit trouvé</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Numéro</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Description</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Secteur</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Unité</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Conditionnement</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Type</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700">
                {filtered.map((produit) => (
                  <tr key={produit.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                    <td className="px-4 py-3 text-sm font-mono font-medium dark:text-gray-200">{produit.numero}</td>
                    <td className="px-4 py-3 text-sm dark:text-gray-200">
                      {produit.description}
                      {(produit.composants?.length ?? 0) > 0 && (
                        <span className="ml-2 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">{t.productLinks.kit}</span>
                      )}
                      {estDangereux(produit) && (
                        <span className="ml-2 px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300" title={th.dangerous}>
                          {[produit.numero_onu, produit.classe_danger && `${th.classShort} ${produit.classe_danger}`].filter(Boolean).join(' · ')}
                        </span>
                      )}
                      {produit.actif === false && (
                        <span className="ml-2 px-2 py-0.5 rounded text-xs font-medium bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">Désactivé</span>
                      )}
                      <BalisesFiche entite="produit" entiteId={produit.id!} />
                      {(produit.fabricant || produit.reference_fabricant) && (
                        <span className="block text-xs text-gray-500 dark:text-gray-400 mt-0.5" title={t.productRecord.manufacturer}>
                          {[produit.fabricant?.nom, produit.reference_fabricant].filter(Boolean).join(' · ')}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm">
                      {produit.secteur
                        ? <span className="bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 px-2 py-1 rounded text-xs font-medium">{produit.secteur.code}</span>
                        : <span className="text-gray-400 text-xs">-</span>}
                    </td>
                    <td className="px-4 py-3 text-sm dark:text-gray-300">{produit.mesure}</td>
                    <td className="px-4 py-3 text-sm">
                      {produit.unite_achat && (produit.qte_par_unite_achat ?? 1) > 1 ? (
                        <span className="bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300 px-2 py-1 rounded text-xs font-medium">
                          1 {produit.unite_achat} = {produit.qte_par_unite_achat} {produit.mesure}
                        </span>
                      ) : (
                        <span className="text-gray-400 text-xs">-</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm">
                      {produit.type && (
                        <span className="bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 px-2 py-1 rounded text-xs font-medium">{produit.type}</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <BoutonPiecesJointes entite="produit" entiteId={produit.id!} titre={produit.numero} taille={16} className="mr-2" />
                      {produit.actif !== false && peut('suggestions_achat', 'suggerer') && (
                        <button onClick={() => setASuggerer(produit)} className="p-1 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/30 rounded mr-2"
                          title={t.purchaseSuggestions.suggestTitle} aria-label={`${t.purchaseSuggestions.suggestTitle} — ${produit.numero}`}>
                          <ShoppingCart size={16} />
                        </button>
                      )}
                      <button onClick={() => setCodesDe(produit)} className="p-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded mr-2"
                        title={t.barcodes.button} aria-label={`${t.barcodes.button} — ${produit.numero}`}>
                        <Barcode size={16} />
                      </button>
                      <button onClick={() => setUnitesDe(produit)} className="p-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded mr-2"
                        title={t.productUnits.button} aria-label={`${t.productUnits.button} — ${produit.numero}`}>
                        <Boxes size={16} />
                      </button>
                      <button onClick={() => setLiensDe(produit)} className="p-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded mr-2"
                        title={t.productLinks.button} aria-label={`${t.productLinks.button} — ${produit.numero}`}>
                        <Link2 size={16} />
                      </button>
                      {produit.actif !== false && peut('etiquettes', 'voir') && (
                        <Link to={`/etiquettes?produits=${produit.id}`} className="inline-block p-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded mr-2 align-middle"
                          title={t.labels.buttonHelp.replace('{numero}', produit.numero)} aria-label={`${t.labels.button} — ${produit.numero}`}>
                          <Tags size={16} />
                        </Link>
                      )}
                      {canEdit('produits') && (
                        <button onClick={() => openEdit(produit)} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded mr-2" title="Modifier">
                          <Edit2 size={16} />
                        </button>
                      )}
                      {produit.actif === false ? (
                        canEdit('produits') && (
                          <button onClick={() => handleReactiver(produit)} className="p-1 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/30 rounded" title="Réactiver" aria-label="Réactiver">
                            <RotateCcw size={16} />
                          </button>
                        )
                      ) : canDelete('produits') && (
                        <button onClick={() => setConfirmId(produit.id!)} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded" title="Désactiver" aria-label="Désactiver">
                          <Trash2 size={16} />
                        </button>
                      )}
                      <BoutonChampsPersonnalises entite="produit" entiteId={produit.id!} titre={produit.numero} taille={16} className="ml-2" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Modal créer/modifier */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div role="dialog" aria-modal="true" aria-labelledby="produit-titre"
            className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl mx-4 max-h-[92vh] flex flex-col">
            <div className="flex items-center justify-between p-4 border-b dark:border-gray-700">
              <h2 id="produit-titre" className="text-lg font-semibold dark:text-white">{editingId ? 'Modifier le produit' : 'Nouveau produit'}</h2>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label="Fermer"><X size={20} /></button>
            </div>
            <form onSubmit={handleSubmit} className="p-4 space-y-4 overflow-y-auto">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Numéro {!editingId && configNumero?.auto_increment ? '(auto-généré)' : '*'}
                </label>
                <input type="text" value={form.numero} onChange={e => setForm({ ...form, numero: e.target.value })}
                  className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white disabled:bg-gray-100 dark:disabled:bg-gray-600"
                  required={!!editingId || !configNumero?.auto_increment} disabled={!!editingId}
                  placeholder={!editingId && configNumero?.auto_increment ? 'Généré automatiquement' : ''} />
                {!editingId && configNumero?.auto_increment && (
                  <p className="text-xs text-gray-500 mt-1">Format: {configNumero.prefixe}{configNumero.separateur || ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')}{configNumero.suffixe ? (configNumero.separateur || '') + configNumero.suffixe : ''}</p>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description *</label>
                <input type="text" value={form.description} onChange={e => setForm({ ...form, description: e.target.value })}
                  className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Secteur *</label>
                <select value={form.secteur_id} onChange={e => setForm({ ...form, secteur_id: e.target.value })}
                  className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" required>
                  <option value="">Sélectionner un secteur...</option>
                  {secteurs.map(s => <option key={s.id} value={s.id}>{s.code} - {s.nom}</option>)}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Unité de mesure</label>
                  <select value={form.mesure} onChange={e => setForm({ ...form, mesure: e.target.value })}
                    className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                    {/* L'unité du produit reste proposée même si elle n'est pas (ou plus) au référentiel. */}
                    {form.mesure && !(unites.length > 0 ? unites : UNITES_ORIGINE).some(u => u.code === form.mesure) && (
                      <option value={form.mesure}>{form.mesure}</option>
                    )}
                    {(unites.length > 0 ? unites : UNITES_ORIGINE).map(u => (
                      <option key={u.code} value={u.code}>{u.code} ({u.libelle})</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
                  <input type="text" value={form.type} onChange={e => setForm({ ...form, type: e.target.value })}
                    className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"
                    placeholder="ex: PIECE, LIQUIDE"
                    list="types-list" />
                  <datalist id="types-list">
                    {types.map(t => <option key={t} value={t} />)}
                  </datalist>
                </div>
              </div>
              <ChampsFicheProduit key={editingId ?? 'nouveau'} valeurs={form} onChange={modification => setForm(actuel => ({ ...actuel, ...modification }))}
                produitId={editingId} fabricantActuel={produits.find(p => p.id === editingId)?.fabricant ?? null} />
              <div className="border dark:border-gray-600 rounded-lg p-3 bg-gray-50 dark:bg-gray-700/50">
                <p className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Conditionnement fournisseur</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Unité d'achat</label>
                    <input type="text" value={form.unite_achat} onChange={e => setForm({ ...form, unite_achat: e.target.value.toUpperCase() })}
                      className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm"
                      placeholder="ex: PACK, CARTON" list="unites-achat" />
                    <datalist id="unites-achat">
                      {unites.map(u => <option key={u.code} value={u.code}>{u.libelle}</option>)}
                    </datalist>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Unités par paquet</label>
                    <input type="number" value={form.qte_par_unite_achat} onChange={e => setForm({ ...form, qte_par_unite_achat: Math.max(1, parseInt(e.target.value) || 1) })}
                      className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm"
                      min={1} />
                  </div>
                </div>
                {form.unite_achat && form.qte_par_unite_achat > 1 && (
                  <p className="text-xs text-blue-600 dark:text-blue-400 mt-2">
                    1 {form.unite_achat} = {form.qte_par_unite_achat} {form.mesure}
                  </p>
                )}
              </div>
              <div className="border dark:border-gray-600 rounded-lg p-3 bg-gray-50 dark:bg-gray-700/50">
                <p className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">{th.transport}</p>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label htmlFor="produit-poids" className="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{th.weight}</label>
                    <input id="produit-poids" type="number" min={0} step="any" value={form.poids_kg} onChange={e => setForm({ ...form, poids_kg: e.target.value })}
                      className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm" />
                  </div>
                  <div>
                    <label htmlFor="produit-volume" className="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{th.volume}</label>
                    <input id="produit-volume" type="number" min={0} step="any" value={form.volume_m3} onChange={e => setForm({ ...form, volume_m3: e.target.value })}
                      className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm" />
                  </div>
                  <div>
                    <label htmlFor="produit-onu" className="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{th.unNumber}</label>
                    <input id="produit-onu" value={form.numero_onu} onChange={e => setForm({ ...form, numero_onu: e.target.value.toUpperCase() })} maxLength={10}
                      placeholder="UN1203" className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm" />
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <label htmlFor="produit-classe" className="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{th.hazardClass}</label>
                      <input id="produit-classe" value={form.classe_danger} onChange={e => setForm({ ...form, classe_danger: e.target.value.toUpperCase() })} maxLength={10}
                        placeholder="3" className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm" />
                    </div>
                    <div>
                      <label htmlFor="produit-groupe" className="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{th.packingGroup}</label>
                      <select id="produit-groupe" value={form.groupe_emballage} onChange={e => setForm({ ...form, groupe_emballage: e.target.value })}
                        className="w-full px-2 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm">
                        <option value="">—</option>
                        <option value="I">I</option>
                        <option value="II">II</option>
                        <option value="III">III</option>
                      </select>
                    </div>
                  </div>
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">{th.help}</p>
              </div>
              <div className="flex gap-3 pt-2">
                <button type="button" onClick={() => setShowModal(false)}
                  className="flex-1 px-4 py-2 border dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-300">Annuler</button>
                <button type="submit" className="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                  {editingId ? 'Modifier' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <ConfirmModal
        open={confirmId !== null}
        title="Désactiver le produit"
        message="Le produit n'apparaîtra plus dans les listes de choix ni dans l'application mobile. Son historique (scans, mouvements, documents) est conservé, et vous pourrez le réactiver depuis le filtre « Désactivés »."
        confirmLabel="Désactiver"
        onConfirm={() => confirmId !== null && handleDelete(confirmId)}
        onCancel={() => setConfirmId(null)}
      />

      {codesDe && (
        <FenetreCodesBarres produit={{ id: codesDe.id!, numero: codesDe.numero, description: codesDe.description }} modifiable={canEdit('produits')}
          onClose={() => setCodesDe(null)} onModifie={loadData} />
      )}
      {liensDe && (
        <FenetreLiensProduit produit={{ id: liensDe.id!, numero: liensDe.numero, description: liensDe.description }} produits={produits}
          modifiable={canEdit('produits')} onClose={() => setLiensDe(null)} onModifie={loadData} />
      )}
      {unitesDe && (
        <FenetreUnitesProduit produit={{ id: unitesDe.id!, numero: unitesDe.numero, description: unitesDe.description, prix_unitaire: unitesDe.prix_unitaire }}
          modifiable={canEdit('produits')} onClose={() => setUnitesDe(null)} onModifie={loadData} />
      )}
      {aSuggerer && (
        <SuggererAchat produit={{ id: aSuggerer.id!, numero: aSuggerer.numero, description: aSuggerer.description }}
          onClose={() => setASuggerer(null)} onFait={() => { setASuggerer(null); toast(t.purchaseSuggestions.suggested_, 'success'); }} />
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
