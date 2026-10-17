import { useCallback, useEffect, useMemo, useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import {
  Plus, Pencil, Trash2, History, X, Search,
  Ruler, ArrowLeftRight, FolderTree, Factory, Wallet, CalendarClock, Landmark, Coins,
  Warehouse, MapIcon, Route, UserRound, Truck, ListChecks, Tags, KeyRound,
} from 'lucide-react';
import {
  getReferentiel, createReferentiel, updateReferentiel, deleteReferentiel, getHistoriqueDevise,
  type TypeReferentiel, type FicheReferentiel, type TauxDevise,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { useToast } from '@/hooks/useToast';
import Toasts from '@/components/Toasts';
import ConfirmModal from '@/components/ConfirmModal';

type TypeChamp = 'texte' | 'nombre' | 'booleen' | 'liste' | 'zone' | 'reference' | 'couleur';

interface Champ {
  cle: string;
  type: TypeChamp;
  requis?: boolean;
  colonne?: boolean;       // affiché dans le tableau
  options?: string[];      // valeurs d'une liste
  reference?: TypeReferentiel;
  majuscules?: boolean;
  longueurMax?: number;
  min?: number;
  max?: number;
  pas?: string;
  defaut?: string | number | boolean;
}

type GroupeReferentiel = 'produits' | 'paiement' | 'logistique' | 'commercial' | 'suivi';

interface Onglet {
  type: TypeReferentiel;
  groupe: GroupeReferentiel;
  icone: LucideIcon;
  champs: Champ[];
}

const GROUPES: GroupeReferentiel[] = ['produits', 'paiement', 'logistique', 'commercial', 'suivi'];
const TYPES_PAIEMENT = ['especes', 'cheque', 'virement', 'carte', 'mobile_money', 'prelevement', 'autre'];
const TYPES_ENTREPOT = ['entrepot', 'magasin', 'vehicule', 'depot', 'autre'];
const TYPES_RAISON = ['mouvement', 'ajustement', 'retour_client', 'retour_fournisseur', 'annulation', 'refus', 'autre'];
const TYPES_DOCUMENT = ['devis', 'commande_client', 'bon_livraison', 'facture', 'commande_fournisseur', 'reception', 'autre'];

// Description déclarative des référentiels : une seule logique de liste et de formulaire les sert tous.
const ONGLETS: Onglet[] = [
  {
    type: 'unites', groupe: 'produits', icone: Ruler, champs: [
      { cle: 'code', type: 'texte', requis: true, colonne: true, longueurMax: 20 },
      { cle: 'libelle', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'symbole', type: 'texte', colonne: true, longueurMax: 20 },
      { cle: 'decimales', type: 'nombre', colonne: true, min: 0, max: 6, defaut: 0 },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'unite_conversions', groupe: 'produits', icone: ArrowLeftRight, champs: [
      { cle: 'unite_source_id', type: 'reference', reference: 'unites', requis: true, colonne: true },
      { cle: 'facteur', type: 'nombre', requis: true, colonne: true, min: 0, pas: 'any' },
      { cle: 'unite_cible_id', type: 'reference', reference: 'unites', requis: true, colonne: true },
    ],
  },
  {
    type: 'categories', groupe: 'produits', icone: FolderTree, champs: [
      { cle: 'nom', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'code', type: 'texte', colonne: true, longueurMax: 30 },
      { cle: 'parent_id', type: 'reference', reference: 'categories', colonne: true },
      { cle: 'description', type: 'zone' },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'fabricants', groupe: 'produits', icone: Factory, champs: [
      { cle: 'nom', type: 'texte', requis: true, colonne: true, longueurMax: 150 },
      { cle: 'code', type: 'texte', colonne: true, longueurMax: 30 },
      { cle: 'site_web', type: 'texte', colonne: true, longueurMax: 255 },
      { cle: 'telephone', type: 'texte', longueurMax: 30 },
      { cle: 'email', type: 'texte', colonne: true, longueurMax: 150 },
      { cle: 'notes', type: 'zone' },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'modes_paiement', groupe: 'paiement', icone: Wallet, champs: [
      { cle: 'code', type: 'texte', requis: true, colonne: true, longueurMax: 30 },
      { cle: 'libelle', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'type', type: 'liste', options: TYPES_PAIEMENT, requis: true, colonne: true, defaut: 'autre' },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'conditions_paiement', groupe: 'paiement', icone: CalendarClock, champs: [
      { cle: 'code', type: 'texte', requis: true, colonne: true, longueurMax: 30 },
      { cle: 'libelle', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'delai_jours', type: 'nombre', requis: true, colonne: true, min: 0, max: 3650, defaut: 0 },
      { cle: 'fin_de_mois', type: 'booleen', colonne: true, defaut: false },
      { cle: 'escompte_pourcentage', type: 'nombre', min: 0, max: 100, pas: '0.01' },
      { cle: 'escompte_jours', type: 'nombre', min: 0, max: 3650 },
      { cle: 'par_defaut', type: 'booleen', colonne: true, defaut: false },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'comptes_bancaires', groupe: 'paiement', icone: Landmark, champs: [
      { cle: 'nom', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'banque', type: 'texte', requis: true, colonne: true, longueurMax: 150 },
      { cle: 'agence', type: 'texte', longueurMax: 150 },
      { cle: 'titulaire', type: 'texte', longueurMax: 150 },
      { cle: 'numero_compte', type: 'texte', colonne: true, longueurMax: 50 },
      { cle: 'iban', type: 'texte', colonne: true, majuscules: true, longueurMax: 34 },
      { cle: 'bic', type: 'texte', majuscules: true, longueurMax: 11 },
      { cle: 'devise_code', type: 'texte', colonne: true, majuscules: true, longueurMax: 3 },
      { cle: 'par_defaut', type: 'booleen', colonne: true, defaut: false },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
      { cle: 'notes', type: 'zone' },
    ],
  },
  {
    type: 'devises', groupe: 'paiement', icone: Coins, champs: [
      { cle: 'code', type: 'texte', requis: true, colonne: true, majuscules: true, longueurMax: 3 },
      { cle: 'libelle', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'symbole', type: 'texte', colonne: true, longueurMax: 10 },
      { cle: 'decimales', type: 'nombre', min: 0, max: 4, defaut: 2 },
      { cle: 'taux', type: 'nombre', requis: true, colonne: true, min: 0, pas: 'any', defaut: 1 },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'entrepots', groupe: 'logistique', icone: Warehouse, champs: [
      { cle: 'code', type: 'texte', requis: true, colonne: true, longueurMax: 20 },
      { cle: 'nom', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'type', type: 'liste', options: TYPES_ENTREPOT, requis: true, colonne: true, defaut: 'entrepot' },
      { cle: 'adresse', type: 'texte', longueurMax: 255 },
      { cle: 'ville', type: 'texte', colonne: true, longueurMax: 100 },
      { cle: 'region', type: 'texte', longueurMax: 100 },
      { cle: 'code_postal', type: 'texte', longueurMax: 20 },
      { cle: 'pays', type: 'texte', longueurMax: 100 },
      { cle: 'telephone', type: 'texte', longueurMax: 30 },
      { cle: 'responsable', type: 'texte', colonne: true, longueurMax: 150 },
      { cle: 'par_defaut', type: 'booleen', colonne: true, defaut: false },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'transporteurs', groupe: 'logistique', icone: Truck, champs: [
      { cle: 'nom', type: 'texte', requis: true, colonne: true, longueurMax: 150 },
      { cle: 'code', type: 'texte', colonne: true, longueurMax: 20 },
      { cle: 'telephone', type: 'texte', colonne: true, longueurMax: 30 },
      { cle: 'email', type: 'texte', longueurMax: 150 },
      { cle: 'url_suivi', type: 'texte', longueurMax: 255 },
      { cle: 'numero_compte', type: 'texte', longueurMax: 50 },
      { cle: 'notes', type: 'zone' },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'itineraires', groupe: 'logistique', icone: Route, champs: [
      { cle: 'code', type: 'texte', requis: true, colonne: true, longueurMax: 20 },
      { cle: 'nom', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'territoire_id', type: 'reference', reference: 'territoires', colonne: true },
      { cle: 'description', type: 'zone' },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    // Modèles de contrats de location : durée, rythme et jour des loyers, à l'avance ou après coup.
    type: 'types_location', groupe: 'commercial', icone: KeyRound, champs: [
      { cle: 'code', type: 'texte', requis: true, colonne: true, majuscules: true, longueurMax: 20 },
      { cle: 'nom', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'duree_mois', type: 'nombre', requis: true, colonne: true, min: 1, max: 120, defaut: 12 },
      { cle: 'frequence', type: 'liste', requis: true, colonne: true, options: ['mensuelle', 'trimestrielle', 'semestrielle', 'annuelle'], defaut: 'mensuelle' },
      { cle: 'jour_facturation', type: 'nombre', requis: true, colonne: true, min: 1, max: 28, defaut: 1 },
      { cle: 'a_echoir', type: 'booleen', colonne: true, defaut: true },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'territoires', groupe: 'commercial', icone: MapIcon, champs: [
      { cle: 'code', type: 'texte', requis: true, colonne: true, longueurMax: 20 },
      { cle: 'nom', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'description', type: 'zone' },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'representants', groupe: 'commercial', icone: UserRound, champs: [
      { cle: 'nom', type: 'texte', requis: true, colonne: true, longueurMax: 150 },
      { cle: 'code', type: 'texte', colonne: true, longueurMax: 20 },
      { cle: 'email', type: 'texte', colonne: true, longueurMax: 150 },
      { cle: 'telephone', type: 'texte', longueurMax: 30 },
      { cle: 'taux_commission', type: 'nombre', colonne: true, min: 0, max: 100, pas: '0.01' },
      { cle: 'territoire_id', type: 'reference', reference: 'territoires', colonne: true },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'raisons', groupe: 'suivi', icone: ListChecks, champs: [
      { cle: 'type', type: 'liste', options: TYPES_RAISON, requis: true, colonne: true, defaut: 'mouvement' },
      { cle: 'code', type: 'texte', requis: true, colonne: true, longueurMax: 20 },
      { cle: 'libelle', type: 'texte', requis: true, colonne: true, longueurMax: 150 },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
  {
    type: 'statuts_personnalises', groupe: 'suivi', icone: Tags, champs: [
      { cle: 'document', type: 'liste', options: TYPES_DOCUMENT, requis: true, colonne: true, defaut: 'commande_client' },
      { cle: 'code', type: 'texte', requis: true, colonne: true, longueurMax: 20 },
      { cle: 'libelle', type: 'texte', requis: true, colonne: true, longueurMax: 100 },
      { cle: 'couleur', type: 'couleur', colonne: true, defaut: '#6b7280' },
      { cle: 'ordre', type: 'nombre', colonne: true, min: 0, max: 999, defaut: 0 },
      { cle: 'actif', type: 'booleen', colonne: true, defaut: true },
    ],
  },
];

type Valeurs = Record<string, string | boolean>;

function valeursInitiales(onglet: Onglet, fiche: FicheReferentiel | null): Valeurs {
  const valeurs: Valeurs = {};
  for (const champ of onglet.champs) {
    const brute = fiche ? fiche[champ.cle] : champ.defaut;
    valeurs[champ.cle] = champ.type === 'booleen'
      ? Boolean(brute ?? false)
      : brute === null || brute === undefined ? '' : String(brute);
  }
  return valeurs;
}

/** Valeurs du formulaire -> charge utile de l'API (vide = null, nombres convertis). */
function versApi(onglet: Onglet, valeurs: Valeurs): Record<string, unknown> {
  const donnees: Record<string, unknown> = {};
  for (const champ of onglet.champs) {
    const valeur = valeurs[champ.cle];
    if (champ.type === 'booleen') donnees[champ.cle] = Boolean(valeur);
    else if (valeur === '') donnees[champ.cle] = null;
    else if (champ.type === 'nombre' || champ.type === 'reference') donnees[champ.cle] = Number(valeur);
    else donnees[champ.cle] = valeur;
  }
  return donnees;
}

/** Libellé lisible d'une fiche référencée : « CODE — libellé » ou le nom. */
function libelleFiche(fiche: FicheReferentiel): string {
  const nom = String(fiche.libelle ?? fiche.nom ?? fiche.id);
  return fiche.code ? `${String(fiche.code)} — ${nom}` : nom;
}

export default function ReferentielsPage() {
  const { t, language } = useLanguage();
  const tr = t.referentiels;
  const { canCreate, canEdit, canDelete } = usePermissions();
  const { toasts, toast, dismiss } = useToast();

  const [typeActif, setTypeActif] = useState<TypeReferentiel>('unites');
  const onglet = ONGLETS.find(o => o.type === typeActif) ?? ONGLETS[0];
  const [fiches, setFiches] = useState<FicheReferentiel[]>([]);
  const [references, setReferences] = useState<Partial<Record<TypeReferentiel, FicheReferentiel[]>>>({});
  const [chargement, setChargement] = useState(true);
  const [recherche, setRecherche] = useState('');
  const [edition, setEdition] = useState<{ fiche: FicheReferentiel | null; valeurs: Valeurs } | null>(null);
  const [enregistrement, setEnregistrement] = useState(false);
  const [aSupprimer, setASupprimer] = useState<FicheReferentiel | null>(null);
  const [historique, setHistorique] = useState<{ devise: FicheReferentiel; taux: TauxDevise[] } | null>(null);

  const libelleChamp = (cle: string) => (tr.fields as Record<string, string>)[cle] ?? cle;
  const libelleOption = (valeur: string) => (tr.options as Record<string, string>)[valeur] ?? valeur;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const formatNombre = (valeur: unknown) => Number(valeur).toLocaleString(locale, { maximumFractionDigits: 8 });
  // « AAAA-MM-JJ » lu comme une date locale : new Date('AAAA-MM-JJ') serait minuit UTC, donc la veille ici.
  const formatDate = (iso: string) => {
    const [annee, mois, jour] = iso.slice(0, 10).split('-').map(Number);
    return new Date(annee, mois - 1, jour).toLocaleDateString(locale);
  };

  const charger = useCallback(async () => {
    setChargement(true);
    try {
      const autres = [...new Set(onglet.champs.flatMap(c => (c.reference && c.reference !== onglet.type ? [c.reference] : [])))];
      const [liste, ...listesAutres] = await Promise.all([getReferentiel(onglet.type), ...autres.map(r => getReferentiel(r))]);
      setFiches(liste);
      setReferences({ [onglet.type]: liste, ...Object.fromEntries(autres.map((r, i) => [r, listesAutres[i]])) });
    } catch {
      toast(tr.loadError, 'error');
    } finally {
      setChargement(false);
    }
  }, [onglet, toast, tr.loadError]);

  useEffect(() => { charger(); }, [charger]);

  const colonnes = onglet.champs.filter(c => c.colonne);

  const fichesAffichees = useMemo(() => {
    const terme = recherche.trim().toLowerCase();
    if (!terme) return fiches;
    return fiches.filter(f => onglet.champs.some(c => String(f[c.cle] ?? '').toLowerCase().includes(terme)));
  }, [fiches, recherche, onglet]);

  function afficher(champ: Champ, fiche: FicheReferentiel) {
    const valeur = fiche[champ.cle];
    if (champ.type === 'booleen') {
      return valeur ? <span className="text-green-600 dark:text-green-400 font-medium">{tr.yes}</span> : <span className="text-gray-400">{tr.no}</span>;
    }
    if (valeur === null || valeur === undefined || valeur === '') return <span className="text-gray-400">—</span>;
    if (champ.type === 'reference') {
      const cible = references[champ.reference!]?.find(r => r.id === valeur);
      return cible ? libelleFiche(cible) : '—';
    }
    if (champ.type === 'liste') return libelleOption(String(valeur));
    if (champ.type === 'couleur') {
      return (
        <span className="inline-flex items-center gap-2">
          <span className="w-4 h-4 rounded border border-gray-300 dark:border-gray-600" style={{ backgroundColor: String(valeur) }} />
          <span className="font-mono text-xs">{String(valeur)}</span>
        </span>
      );
    }
    if (champ.type === 'nombre') return formatNombre(valeur);
    return String(valeur);
  }

  function changerOnglet(type: TypeReferentiel) {
    setTypeActif(type);
    setRecherche('');
  }

  function ouvrirFormulaire(fiche: FicheReferentiel | null) {
    setEdition({ fiche, valeurs: valeursInitiales(onglet, fiche) });
  }

  function modifierValeur(champ: Champ, valeur: string | boolean) {
    setEdition(e => e && ({
      ...e,
      valeurs: { ...e.valeurs, [champ.cle]: typeof valeur === 'string' && champ.majuscules ? valeur.toUpperCase() : valeur },
    }));
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!edition) return;
    setEnregistrement(true);
    try {
      const donnees = versApi(onglet, edition.valeurs);
      if (edition.fiche) {
        await updateReferentiel(onglet.type, edition.fiche.id, donnees);
        toast(tr.updated);
      } else {
        await createReferentiel(onglet.type, donnees);
        toast(tr.created);
      }
      setEdition(null);
      charger();
    } catch (erreur) {
      toast(erreur instanceof ApiError ? erreur.message : tr.saveError, 'error');
    } finally {
      setEnregistrement(false);
    }
  }

  async function supprimer() {
    if (!aSupprimer) return;
    try {
      await deleteReferentiel(onglet.type, aSupprimer.id);
      toast(tr.deleted);
      charger();
    } catch (erreur) {
      toast(erreur instanceof ApiError ? erreur.message : tr.saveError, 'error');
    } finally {
      setASupprimer(null);
    }
  }

  async function ouvrirHistorique(devise: FicheReferentiel) {
    try {
      setHistorique({ devise, taux: await getHistoriqueDevise(devise.id) });
    } catch {
      toast(tr.loadError, 'error');
    }
  }

  const classeChamp = 'w-full border dark:border-gray-600 rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white';

  function champFormulaire(champ: Champ) {
    const valeur = edition!.valeurs[champ.cle];
    if (champ.type === 'booleen') {
      return (
        <label key={champ.cle} className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
          <input type="checkbox" checked={Boolean(valeur)} onChange={e => modifierValeur(champ, e.target.checked)}
            className="w-4 h-4 rounded border-gray-300" />
          {libelleChamp(champ.cle)}
        </label>
      );
    }

    let saisie: React.ReactNode;
    if (champ.type === 'liste') {
      saisie = (
        <select value={String(valeur)} onChange={e => modifierValeur(champ, e.target.value)} className={classeChamp} required={champ.requis}>
          {champ.options?.map(o => <option key={o} value={o}>{libelleOption(o)}</option>)}
        </select>
      );
    } else if (champ.type === 'reference') {
      // Une catégorie ne se propose pas elle-même comme parente.
      const choix = (references[champ.reference!] ?? []).filter(r => !(champ.reference === onglet.type && r.id === edition!.fiche?.id));
      saisie = (
        <select value={String(valeur)} onChange={e => modifierValeur(champ, e.target.value)} className={classeChamp} required={champ.requis}>
          <option value="">{champ.requis ? tr.choose : tr.none}</option>
          {choix.map(r => <option key={r.id} value={r.id}>{libelleFiche(r)}</option>)}
        </select>
      );
    } else if (champ.type === 'couleur') {
      saisie = (
        <input type="color" value={String(valeur) || '#6b7280'} onChange={e => modifierValeur(champ, e.target.value)}
          className="h-10 w-20 border dark:border-gray-600 rounded-lg cursor-pointer bg-white dark:bg-gray-700" />
      );
    } else if (champ.type === 'zone') {
      saisie = (
        <textarea value={String(valeur)} onChange={e => modifierValeur(champ, e.target.value)} className={classeChamp} rows={3} />
      );
    } else {
      saisie = (
        <input
          type={champ.type === 'nombre' ? 'number' : 'text'}
          value={String(valeur)}
          onChange={e => modifierValeur(champ, e.target.value)}
          className={classeChamp}
          required={champ.requis}
          maxLength={champ.longueurMax}
          min={champ.min}
          max={champ.max}
          step={champ.type === 'nombre' ? champ.pas ?? '1' : undefined}
        />
      );
    }

    const aides: Record<string, string> = { facteur: tr.conversionHint, taux: tr.rateHint, url_suivi: tr.trackingHint };
    const aide = aides[champ.cle];
    return (
      <div key={champ.cle}>
        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          {libelleChamp(champ.cle)}{champ.requis ? ' *' : ''}
        </label>
        {saisie}
        {aide && <p className="text-xs text-gray-500 mt-1">{aide}</p>}
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">{tr.title}</h1>
        <p className="text-sm text-gray-500 mt-0.5">{tr.subtitle}</p>
      </div>

      <div className="flex flex-col lg:flex-row gap-4 items-start">
        {/* Menu des référentiels, groupés par famille */}
        <nav className="w-full lg:w-60 shrink-0 bg-white dark:bg-gray-800 rounded-xl shadow-sm p-2 space-y-3">
          {GROUPES.map(groupe => (
            <div key={groupe}>
              <p className="px-3 pt-1 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{tr.groups[groupe]}</p>
              {ONGLETS.filter(o => o.groupe === groupe).map(o => {
                const Icone = o.icone;
                const actif = o.type === typeActif;
                return (
                  <button key={o.type} onClick={() => changerOnglet(o.type)}
                    className={`w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-left transition-colors ${actif
                      ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-medium'
                      : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'}`}>
                    <Icone size={16} /> {tr.tabs[o.type]}
                  </button>
                );
              })}
            </div>
          ))}
        </nav>

        <section className="flex-1 min-w-0 w-full">
          {/* Barre d'outils */}
          <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
              <p className="text-sm text-gray-600 dark:text-gray-300">{tr.descriptions[onglet.type]}</p>
              {!chargement && <p className="text-xs text-gray-500 mt-0.5">{fiches.length} {tr.count}</p>}
            </div>
            <div className="flex gap-2">
              <div className="relative">
                <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                <input value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tr.search}
                  className="pl-9 pr-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm" />
              </div>
              {canCreate('referentiels') && (
                <button onClick={() => ouvrirFormulaire(null)}
                  className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                  <Plus size={18} /> {tr.add}
                </button>
              )}
            </div>
          </div>

          {/* Tableau */}
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
            {chargement ? (
              <div className="p-6 text-gray-500">{t.common.loading}</div>
            ) : fichesAffichees.length === 0 ? (
              <div className="p-6 text-gray-500">{tr.empty}</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600">
                    <tr>
                      {colonnes.map(c => (
                        <th key={c.cle} className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">{libelleChamp(c.cle)}</th>
                      ))}
                      <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">{t.common.actions}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y dark:divide-gray-700">
                    {fichesAffichees.map(fiche => (
                      <tr key={fiche.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        {colonnes.map(c => (
                          <td key={c.cle} className="px-4 py-3 text-sm dark:text-gray-200">{afficher(c, fiche)}</td>
                        ))}
                        <td className="px-4 py-3">
                          <div className="flex gap-1">
                            {onglet.type === 'devises' && (
                              <button onClick={() => ouvrirHistorique(fiche)} title={tr.history}
                                className="p-1.5 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded">
                                <History size={16} />
                              </button>
                            )}
                            {canEdit('referentiels') && (
                              <button onClick={() => ouvrirFormulaire(fiche)} title={tr.edit}
                                className="p-1.5 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded">
                                <Pencil size={16} />
                              </button>
                            )}
                            {canDelete('referentiels') && (
                              <button onClick={() => setASupprimer(fiche)} title={tr.delete}
                                className="p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded">
                                <Trash2 size={16} />
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </section>
      </div>

      {/* Formulaire créer / modifier */}
      {edition && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-bold dark:text-white">
                {edition.fiche ? tr.editItem : tr.newItem} — {tr.tabs[onglet.type]}
              </h2>
              <button onClick={() => setEdition(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tr.close}>
                <X size={18} />
              </button>
            </div>
            <form onSubmit={enregistrer} className="space-y-4">
              {onglet.champs.map(champFormulaire)}
              <div className="flex gap-3 justify-end pt-2">
                <button type="button" onClick={() => setEdition(null)}
                  className="px-4 py-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tr.cancel}</button>
                <button type="submit" disabled={enregistrement}
                  className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-60">
                  {edition.fiche ? tr.save : tr.create}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Historique des taux d'une devise */}
      {historique && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" onClick={() => setHistorique(null)}>
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-bold dark:text-white">{tr.history} — {String(historique.devise.code)}</h2>
              <button onClick={() => setHistorique(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tr.close}>
                <X size={18} />
              </button>
            </div>
            {historique.taux.length === 0 ? (
              <p className="text-sm text-gray-500">{tr.historyEmpty}</p>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-gray-600 dark:text-gray-300 border-b dark:border-gray-600">
                    <th className="py-2">{tr.effectiveDate}</th>
                    <th className="py-2 text-right">{libelleChamp('taux')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700">
                  {historique.taux.map(ligne => (
                    <tr key={ligne.id} className="dark:text-gray-200">
                      <td className="py-2">{formatDate(ligne.date_effet)}</td>
                      <td className="py-2 text-right">{formatNombre(ligne.taux)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}

      <ConfirmModal
        open={aSupprimer !== null}
        title={tr.confirmDeleteTitle}
        message={tr.confirmDeleteMessage}
        confirmLabel={tr.delete}
        danger
        onConfirm={supprimer}
        onCancel={() => setASupprimer(null)}
      />

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
