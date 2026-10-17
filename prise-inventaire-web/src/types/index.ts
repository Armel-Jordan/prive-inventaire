export interface Employe {
  id: number;
  numero: string;
  nom: string;
  prenom?: string;
  email?: string;
  actif?: boolean;
  admin_user_id?: number;
  admin_user?: {
    id: number;
    role: string;
    /** Rôle personnalisé de l'entreprise, dont les droits remplacent ceux du rôle. */
    role_custom_id?: number | null;
    actif: boolean;
  };
}

export interface Produit {
  id?: number;
  numero: string;
  description: string;
  /** false : produit désactivé (masqué des listes de choix, réactivable). */
  actif?: boolean;
  mesure: string;
  unite_achat?: string | null;
  qte_par_unite_achat?: number;
  type: string;
  secteur_id?: number;
  secteur?: Secteur;
  /** Prix de vente du catalogue (chaîne décimale renvoyée par l'API), proposé sur les lignes de documents. */
  prix_unitaire?: number | string | null;
  /** Transport : matière dangereuse (numéro ONU, classe, groupe d'emballage), poids (kg) et volume (m³) unitaires. */
  numero_onu?: string | null;
  classe_danger?: string | null;
  groupe_emballage?: string | null;
  poids_kg?: number | string | null;
  volume_m3?: number | string | null;
}

export interface Secteur {
  id?: number;
  code: string;
  nom: string;
  /** false : secteur désactivé (masqué des listes de choix, réactivable). */
  actif?: boolean;
  description?: string;
}

export interface InventaireScan {
  id: number;
  numero: string;
  type: string;
  quantite: number;
  unite_mesure: string;
  employe: string;
  secteur: string;
  date_saisie: string;
  scanneur?: string;
  created_at?: string;
  updated_at?: string;
}

export interface MouvementInventaire {
  id: number;
  scan_id: number;
  type_mouvement: 'ENTREE' | 'SORTIE' | 'CORRECTION';
  quantite_avant: number;
  quantite_apres: number;
  motif?: string;
  utilisateur: string;
  date_mouvement: string;
}

export interface AdminUser {
  id: number;
  nom: string;
  email: string;
  role: 'super_admin' | 'admin' | 'manager' | 'user';
  actif: boolean;
  derniere_connexion?: string;
  created_at?: string;
}
