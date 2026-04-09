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
    actif: boolean;
  };
}

export interface Produit {
  id?: number;
  numero: string;
  description: string;
  mesure: string;
  unite_achat?: string | null;
  qte_par_unite_achat?: number;
  type: string;
  secteur_id?: number;
  secteur?: Secteur;
}

export interface Secteur {
  id?: number;
  code: string;
  nom: string;
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
