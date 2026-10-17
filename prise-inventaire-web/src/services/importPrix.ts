import { apiFetch } from '@/services/http';

/** Import d'une grille de prix fournisseur (simulation puis import) et profils d'import mémorisés (module Achats). */

/** Réglages d'un import : colonnes du fichier numérotées à partir de 1. */
export interface ReglagesImportPrix {
  fournisseur_id?: number | null;
  colonne_fournisseur?: number | null;
  colonne_code: number;
  colonne_cout: number;
  ligne_debut: number;
}

export interface RapportImportPrix {
  simulation: boolean;
  message: string;
  lignes: number;
  mises_a_jour: number;
  inchangees: number;
  changements: { ligne: number; fournisseur: string; produit: string | null; ancien: number | null; nouveau: number }[];
  ignorees: { ligne: number; fournisseur: string; code: string; motif: string }[];
}

export interface ProfilImport {
  id: number;
  nom: string;
  reglages: ReglagesImportPrix;
}

export function importerGrillePrix(fichier: File, reglages: ReglagesImportPrix, simuler: boolean): Promise<RapportImportPrix> {
  const donnees = new FormData();
  donnees.append('fichier', fichier);
  donnees.append('simuler', simuler ? '1' : '0');
  for (const [cle, valeur] of Object.entries(reglages)) {
    if (valeur !== null && valeur !== undefined && valeur !== '') donnees.append(cle, String(valeur));
  }
  return apiFetch('/fournisseurs/import-prix', { method: 'POST', body: donnees, retries: 0, timeoutMs: 60000 });
}

export function getProfilsImport(): Promise<ProfilImport[]> {
  return apiFetch('/profils-import?type=prix_fournisseur');
}

export function enregistrerProfilImport(nom: string, reglages: ReglagesImportPrix): Promise<ProfilImport> {
  return apiFetch('/profils-import', { method: 'POST', body: JSON.stringify({ type: 'prix_fournisseur', nom, reglages }), retries: 0 });
}

export function supprimerProfilImport(id: number): Promise<void> {
  return apiFetch(`/profils-import/${id}`, { method: 'DELETE', retries: 0 });
}

/** Lignes d'un fichier CSV (séparateur deviné : point-virgule, tabulation ou virgule ; guillemets respectés). */
export function lireCsv(texte: string): string[][] {
  const lignes = texte.replace(/^\uFEFF/, '').split(/\r\n|\r|\n/);
  const premiere = lignes.find(l => l.trim() !== '') ?? '';
  const separateur = [';', '\t', ','].sort((a, b) => premiere.split(b).length - premiere.split(a).length)[0];
  return lignes.map(ligne => {
    const cellules: string[] = [];
    let courante = '';
    let entreGuillemets = false;
    for (let i = 0; i < ligne.length; i++) {
      const c = ligne[i];
      if (c === '"') {
        if (entreGuillemets && ligne[i + 1] === '"') { courante += '"'; i++; } else entreGuillemets = !entreGuillemets;
      } else if (c === separateur && !entreGuillemets) {
        cellules.push(courante);
        courante = '';
      } else {
        courante += c;
      }
    }
    cellules.push(courante);
    return cellules;
  });
}
