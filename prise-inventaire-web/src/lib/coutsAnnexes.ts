import type { FraisReception, RepartitionFrais, TypeFrais } from '@/services/receptions';

/**
 * Coût annexe d'une réception en cours de saisie (montant et taux en texte, comme les champs). Une devise vide est
 * celle de l'entreprise : la saisie ne dépend pas du chargement de ses paramètres.
 */
export interface FraisSaisi {
  cle: string;
  type: TypeFrais;
  libelle: string;
  fournisseur_id: number | null;
  reference: string;
  montant: string;
  devise: string;
  taux: string;
  repartition: RepartitionFrais;
}

let compteur = 0;

/** Nouveau coût annexe : transport réparti à la valeur, dans la devise de l'entreprise. */
export function nouveauFrais(): FraisSaisi {
  return { cle: `f${++compteur}`, type: 'transport', libelle: '', fournisseur_id: null, reference: '', montant: '', devise: '', taux: '1', repartition: 'valeur' };
}

/** Coûts annexes enregistrés, prêts à être modifiés. */
export function fraisEnSaisie(frais: FraisReception[] | undefined): FraisSaisi[] {
  return (frais ?? []).map(f => ({
    ...nouveauFrais(),
    type: f.type,
    libelle: f.libelle ?? '',
    fournisseur_id: f.fournisseur_id ?? null,
    reference: f.reference ?? '',
    montant: String(Number(f.montant)),
    devise: f.devise || '',
    taux: f.devise ? String(Number(f.taux_change)) : '1',
    repartition: f.repartition,
  }));
}

/** Coût dans une autre devise que celle de l'entreprise : il porte son taux. */
export const etrangere = (f: FraisSaisi, codeEntreprise: string) => f.devise !== '' && f.devise !== codeEntreprise;

/** Coûts annexes à envoyer : une devise étrangère garde son taux, celle de l'entreprise n'en a pas. */
export function fraisPourApi(frais: FraisSaisi[], codeEntreprise: string): FraisReception[] {
  return frais.filter(f => Number(f.montant) > 0).map(f => ({
    type: f.type,
    libelle: f.libelle.trim() || null,
    fournisseur_id: f.fournisseur_id,
    reference: f.reference.trim() || null,
    montant: Number(f.montant),
    devise: etrangere(f, codeEntreprise) ? f.devise : null,
    taux_change: etrangere(f, codeEntreprise) ? Number(f.taux) : null,
    repartition: f.repartition,
  }));
}

/** Total des coûts annexes dans la devise de l'entreprise. */
export function totalFrais(frais: FraisSaisi[], codeEntreprise: string): number {
  return frais.reduce((s, f) => s + (Number(f.montant) || 0) * (etrangere(f, codeEntreprise) ? Number(f.taux) || 0 : 1), 0);
}
