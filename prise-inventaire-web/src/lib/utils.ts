import { type ClassValue, clsx } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/** Taille de fichier lisible : « 120 Ko », « 2,4 Mo » (ou KB / MB en anglais). */
export function formatTaille(octets: number, anglais = false): string {
  const locale = anglais ? 'en-US' : 'fr-FR';
  if (octets < 1024 * 1024) return `${Math.max(1, Math.round(octets / 1024)).toLocaleString(locale)} ${anglais ? 'KB' : 'Ko'}`;
  return `${(octets / (1024 * 1024)).toLocaleString(locale, { maximumFractionDigits: 1 })} ${anglais ? 'MB' : 'Mo'}`;
}

export function formatDate(dateString: string): string {
  return new Date(dateString).toLocaleDateString('fr-CA', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/** Ajoute un texte à la suite d'une note existante : à la ligne (zone de texte) ou après une espace. */
export function ajouterRemarque(note: string | null | undefined, texte: string, separateur: '\n' | ' ' = '\n'): string {
  return note && note.trim() ? `${note.replace(/\s+$/, '')}${separateur}${texte}` : texte;
}
