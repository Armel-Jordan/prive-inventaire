import { apiFetch } from '@/services/http';

/**
 * Rôles et permissions (sur le modèle de fripv2) : catalogue des permissions par onglet et par écran, groupes
 * (rôles) et accès d'un compte (groupes supplémentaires, exceptions). Réservé aux administrateurs, sauf le
 * catalogue.
 */

export interface PermissionCatalogue {
  /** « module.action » */
  code: string;
  action: string;
  /** Droit de base suivi par une action nommée qu'un groupe ne règle pas (null : droit de base lui-même). */
  base: string | null;
  fr: string;
  en: string;
  aide_fr: string;
  aide_en: string;
}

export interface ModuleCatalogue {
  cle: string;
  fr: string;
  en: string;
  permissions: PermissionCatalogue[];
}

export interface OngletCatalogue {
  cle: string;
  fr: string;
  en: string;
  modules: ModuleCatalogue[];
}

export interface DroitsModule {
  can_view: boolean;
  can_create: boolean;
  can_edit: boolean;
  can_delete: boolean;
}

export interface Groupe {
  id: number;
  nom: string;
  description: string | null;
  is_system: boolean;
  actif: boolean;
  tenant_id: number | null;
  permissions: Record<string, DroitsModule>;
  /** Actions nommées réglées par le groupe (les autres suivent leur droit de base). */
  actions: Record<string, boolean>;
  /** Comptes de l'entreprise qui l'ont (rôle principal ou groupe supplémentaire) ; null pour un rôle système. */
  nb_comptes: number | null;
}

export interface AccesCompte {
  compte: { id: number; nom: string; email: string; role: string; role_custom_id: number | null; actif: boolean };
  administrateur: boolean;
  role_principal: { id: number; nom: string; systeme: boolean } | null;
  groupes: number[];
  exceptions: Record<string, boolean>;
  droits_groupes: Record<string, boolean>;
  effectives: Record<string, boolean>;
}

export const COLONNES: Record<string, keyof DroitsModule> = {
  voir: 'can_view', creer: 'can_create', modifier: 'can_edit', supprimer: 'can_delete',
};

/** Valeur d'une permission pour un groupe : droit de base, action réglée, sinon le droit de base qu'elle suit. */
export function valeurDuGroupe(groupe: Pick<Groupe, 'permissions' | 'actions'>, permission: PermissionCatalogue): boolean {
  const [module, action] = permission.code.split('.');
  if (permission.base === null) return Boolean(groupe.permissions[module]?.[COLONNES[action]]);
  if (permission.code in groupe.actions) return groupe.actions[permission.code];
  const [, actionBase] = permission.base.split('.');
  return Boolean(groupe.permissions[module]?.[COLONNES[actionBase]]);
}

/** Toutes les permissions du catalogue, à plat. */
export function permissionsDu(catalogue: OngletCatalogue[]): PermissionCatalogue[] {
  return catalogue.flatMap(o => o.modules.flatMap(m => m.permissions));
}

/** Valeurs d'un groupe => ce qu'attend l'API : droits de base par module et actions nommées réglées. */
export function versApi(catalogue: OngletCatalogue[], valeurs: Record<string, boolean>) {
  const permissions: Record<string, DroitsModule> = {};
  const actions: Record<string, boolean> = {};
  for (const onglet of catalogue) {
    for (const module of onglet.modules) {
      const droits: DroitsModule = { can_view: false, can_create: false, can_edit: false, can_delete: false };
      for (const permission of module.permissions) {
        if (permission.base === null) droits[COLONNES[permission.action]] = Boolean(valeurs[permission.code]);
        else actions[permission.code] = Boolean(valeurs[permission.code]);
      }
      permissions[module.cle] = droits;
    }
  }
  return { permissions, actions };
}

export const getCatalogue = () => apiFetch<{ onglets: OngletCatalogue[] }>('/permissions/catalogue');
export const getGroupes = () => apiFetch<Groupe[]>('/roles-custom');

type DonneesGroupe = {
  nom: string;
  description: string | null;
  actif: boolean;
  permissions: Record<string, DroitsModule>;
  actions: Record<string, boolean>;
};

export const creerGroupe = (groupe: DonneesGroupe) =>
  apiFetch<{ role_id: number }>('/roles-custom', { method: 'POST', body: JSON.stringify(groupe) });
export const modifierGroupe = (id: number, groupe: DonneesGroupe) =>
  apiFetch<{ message: string }>(`/roles-custom/${id}`, { method: 'PUT', body: JSON.stringify(groupe) });
export const supprimerGroupe = (id: number) =>
  apiFetch<{ message: string }>(`/roles-custom/${id}`, { method: 'DELETE' });

export const getAccesCompte = (id: number) => apiFetch<AccesCompte>(`/acces-comptes/${id}`);
export const enregistrerAccesCompte = (id: number, acces: { groupes: number[]; exceptions: Record<string, boolean> }) =>
  apiFetch<AccesCompte & { message: string }>(`/acces-comptes/${id}`, { method: 'PUT', body: JSON.stringify(acces) });
