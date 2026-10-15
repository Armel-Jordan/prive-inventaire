import { type ClassValue, clsx } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
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
