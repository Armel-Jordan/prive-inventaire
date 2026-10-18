import { useEffect, useId, useMemo, useState } from 'react';
import { ArrowLeft, CalendarClock, Download, Plus, X } from 'lucide-react';
import {
  annulerRevisionPrix, apercuRevisionPrix, appliquerRevisionPrix, enregistrerRevisionPrix, getRevisionPrix, getRevisionsPrix,
  planifierRevisionPrix, supprimerRevisionPrix,
  type CibleRevisionPrix, type LigneRevisionPrix, type ListeRevisionsPrix, type RevisionPrix, type RevisionPrixDetail,
  type StatutRevisionPrix, type TypeAjustementPrix,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateHeure } from '@/lib/documents';
import { formatDateCourte, formatPrix } from './format';

const PAR_PAGE = 50;

/** Ligne de la grille : le nouveau prix tel que saisi. */
type Ligne = LigneRevisionPrix & { saisie: string };
type Message = { ok: boolean; texte: string } | null;
type Reglage = { categorie: string; cible: string; type: TypeAjustementPrix; valeur: string; inclureInactifs: boolean };
type Edition = {
  revision: RevisionPrixDetail | null;
  nom: string;
  notes: string;
  dateEffet: string;
  lignes: Ligne[];
  reglage: Reglage;
  /** Dernier ajustement fait, enregistré avec la révision (pour mémoire). */
  ajustement: { categorie: string | null; cible: CibleRevisionPrix; liste_prix_id: number | null; type: TypeAjustementPrix; valeur: number } | null;
};

const cle = (l: { produit_id: number; liste_prix_id: number | null }) => `${l.produit_id}:${l.liste_prix_id ?? 'base'}`;
const versLigne = (l: LigneRevisionPrix): Ligne => ({ ...l, saisie: String(l.nouveau_prix) });
const lireNombre = (texte: string) => (texte.trim() === '' ? Number.NaN : Number(texte.trim().replace(/\s/g, '').replace(',', '.')));

/** Arrondi à n décimales comme l'API (1,005 donne 1,01), pour le prix d'une liste calculé par sa formule. */
function arrondir(valeur: number, decimales: number): number {
  const texte = String(valeur);
  if (texte.includes('e')) return Math.round(valeur * 10 ** decimales) / 10 ** decimales;
  return Number(`${Math.round(Number(`${texte}e${decimales}`))}e-${decimales}`);
}

/** Lignes groupées par produit : le prix de base d'abord, puis les listes. */
function trier(lignes: Ligne[]): Ligne[] {
  return [...lignes].sort((a, b) => (a.numero ?? '').localeCompare(b.numero ?? '', undefined, { numeric: true })
    || (a.liste_prix_id === null ? 0 : 1) - (b.liste_prix_id === null ? 0 : 1)
    || (a.liste ?? '').localeCompare(b.liste ?? ''));
}

/** Champ du CSV : entre guillemets s'il contient le séparateur, un guillemet ou un retour à la ligne. */
function champCsv(valeur: string | number | null | undefined): string {
  const texte = valeur === null || valeur === undefined ? '' : String(valeur);
  return /[;"\n\r]/.test(texte) ? `"${texte.replace(/"/g, '""')}"` : texte;
}

const couleurs: Record<StatutRevisionPrix, string> = {
  brouillon: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
  planifiee: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
  appliquee: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
  annulee: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200',
};

/**
 * Révisions de prix planifiées (plan de parité fripv2, phase 1.2d) : de nouveaux prix préparés en lot (ajustement en
 * pourcentage ou en montant, retouches), appliqués tout de suite ou à leur date d'entrée en vigueur par la tâche
 * planifiée, une seule fois. Les prix réels ne changent qu'à l'application.
 */
export default function RevisionsPrix({ peutReviser, devise, onModifie }: { peutReviser: boolean; devise: string; onModifie: (modifie: boolean) => void }) {
  const { t, language } = useLanguage();
  const tp = t.pricing;
  const tr = tp.revisions;
  const id = useId();
  const [liste, setListe] = useState<ListeRevisionsPrix | null>(null);
  const [version, setVersion] = useState(0);
  const [edition, setEdition] = useState<Edition | null>(null);
  const [modifie, setModifie] = useState(false);
  const [message, setMessage] = useState<Message>(null);
  const [occupe, setOccupe] = useState(false);
  const [filtre, setFiltre] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => {
    let actif = true;
    getRevisionsPrix()
      .then(l => { if (actif) setListe(l); })
      .catch(e => { if (actif) setMessage({ ok: false, texte: e instanceof ApiError ? e.message : tp.error }); });
    return () => { actif = false; };
  }, [version, tp.error]);

  // Fermeture protégée : des changements non enregistrés demandent confirmation (onglet, page, navigateur).
  useEffect(() => { onModifie(modifie); }, [modifie, onModifie]);
  useEffect(() => () => onModifie(false), [onModifie]);
  useEffect(() => {
    if (!modifie) return;
    const avertir = (e: BeforeUnloadEvent) => { e.preventDefault(); };
    window.addEventListener('beforeunload', avertir);
    return () => window.removeEventListener('beforeunload', avertir);
  }, [modifie]);

  const listes = useMemo(() => liste?.listes ?? [], [liste]);
  const nomsListes = useMemo(() => new Map(listes.map(l => [l.id, l.nom])), [listes]);
  const reglageVide: Reglage = { categorie: '', cible: 'base', type: 'pourcentage', valeur: '', inclureInactifs: false };
  const enCours = (statut?: StatutRevisionPrix) => statut === undefined || statut === 'brouillon' || statut === 'planifiee';
  const modifiable = peutReviser && !!edition && enCours(edition.revision?.statut);
  const demain = (() => {
    const [a, m, j] = aujourdhui().split('-').map(Number);
    const d = new Date(a, m - 1, j + 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  })();

  function changer(modif: Partial<Edition>) {
    setEdition(e => (e ? { ...e, ...modif } : e));
    setModifie(true);
  }

  function ouvrirDetail(detail: RevisionPrixDetail) {
    setEdition({
      revision: detail,
      nom: detail.nom,
      notes: detail.notes ?? '',
      dateEffet: detail.date_effet ?? '',
      lignes: trier(detail.lignes.map(versLigne)),
      reglage: {
        ...reglageVide,
        categorie: detail.categorie ?? '',
        cible: detail.cible === 'liste' && detail.liste_prix_id ? String(detail.liste_prix_id) : (detail.cible ?? 'base'),
        type: detail.type_ajustement ?? 'pourcentage',
        valeur: detail.valeur_ajustement !== null ? String(detail.valeur_ajustement) : '',
      },
      ajustement: detail.cible && detail.type_ajustement && detail.valeur_ajustement !== null
        ? { categorie: detail.categorie, cible: detail.cible, liste_prix_id: detail.liste_prix_id, type: detail.type_ajustement, valeur: detail.valeur_ajustement }
        : null,
    });
    setModifie(false);
    setFiltre('');
    setPage(1);
  }

  async function ouvrir(revision: RevisionPrix) {
    setMessage(null);
    try {
      ouvrirDetail(await getRevisionPrix(revision.id));
    } catch (e) {
      setMessage({ ok: false, texte: e instanceof ApiError ? e.message : tp.error });
    }
  }

  function nouvelle() {
    setMessage(null);
    setEdition({ revision: null, nom: '', notes: '', dateEffet: '', lignes: [], reglage: reglageVide, ajustement: null });
    setModifie(false);
    setFiltre('');
    setPage(1);
  }

  function fermer() {
    if (modifie && !confirm(tr.unsaved)) return;
    setEdition(null);
    setModifie(false);
    setMessage(null);
    setVersion(v => v + 1);
  }

  /** Remplit la colonne « Nouveau prix » : remplace les prix touchés (retouches comprises), ajoute les autres. */
  async function ajuster() {
    if (!edition) return;
    const r = edition.reglage;
    const valeur = lireNombre(r.valeur);
    if (Number.isNaN(valeur)) {
      setMessage({ ok: false, texte: tr.adjust.valueRequired });
      return;
    }
    const cible: CibleRevisionPrix = r.cible === 'base' || r.cible === 'toutes' ? r.cible : 'liste';
    const listeId = cible === 'liste' ? Number(r.cible) : null;
    setOccupe(true);
    setMessage(null);
    try {
      const apercu = await apercuRevisionPrix({ categorie: r.categorie || null, cible, liste_prix_id: listeId, type: r.type, valeur, inclure_inactifs: r.inclureInactifs });
      if (apercu.total === 0) {
        setMessage({ ok: false, texte: tr.adjust.none });
        return;
      }
      const nouvelles = new Map(apercu.lignes.map(l => [cle(l), versLigne(l)]));
      const gardees = edition.lignes.filter(l => !nouvelles.has(cle(l)));
      changer({
        lignes: trier([...gardees, ...nouvelles.values()]),
        ajustement: { categorie: r.categorie || null, cible, liste_prix_id: listeId, type: r.type, valeur },
      });
      setPage(1);
      setMessage({ ok: true, texte: tr.adjust.applied.replace('{n}', String(apercu.total)) });
    } catch (e) {
      setMessage({ ok: false, texte: e instanceof ApiError ? e.message : tp.error });
    } finally {
      setOccupe(false);
    }
  }

  /** Enregistre la révision (brouillon, ou planifiée qui le reste) ; retourne son détail, ou null si refusée. */
  async function enregistrer(): Promise<RevisionPrixDetail | null> {
    if (!edition) return null;
    if (!edition.nom.trim()) {
      setMessage({ ok: false, texte: tr.nameRequired });
      return null;
    }
    const lignes = edition.lignes.map(l => ({ produit_id: l.produit_id, liste_prix_id: l.liste_prix_id, nouveau_prix: lireNombre(l.saisie) }));
    if (lignes.some(l => Number.isNaN(l.nouveau_prix) || l.nouveau_prix < 0)) {
      setMessage({ ok: false, texte: tr.invalidPrice });
      return null;
    }
    const a = edition.ajustement;
    const detail = await enregistrerRevisionPrix({
      nom: edition.nom.trim(),
      notes: edition.notes.trim() || null,
      date_effet: edition.dateEffet || null,
      categorie: a?.categorie ?? null,
      cible: a?.cible ?? null,
      liste_prix_id: a?.liste_prix_id ?? null,
      type_ajustement: a?.type ?? null,
      valeur_ajustement: a?.valeur ?? null,
      lignes,
    }, edition.revision?.id);
    ouvrirDetail(detail);

    return detail;
  }

  async function executer(action: () => Promise<string | null>) {
    setOccupe(true);
    setMessage(null);
    try {
      const texte = await action();
      if (texte) setMessage({ ok: true, texte });
    } catch (e) {
      setMessage({ ok: false, texte: e instanceof ApiError ? e.message : tp.error });
    } finally {
      setOccupe(false);
    }
  }

  const sauver = () => executer(async () => ((await enregistrer()) ? tr.saved : null));

  const planifier = () => executer(async () => {
    if (!edition?.dateEffet) {
      setMessage({ ok: false, texte: tr.dateRequired });
      return null;
    }
    const dateEffet = edition.dateEffet;
    const detail = modifie || !edition.revision ? await enregistrer() : edition.revision;
    if (!detail) return null;
    ouvrirDetail(await planifierRevisionPrix(detail.id, dateEffet));
    setVersion(v => v + 1);

    return tr.scheduled.replace('{date}', formatDateCourte(dateEffet, language));
  });

  const appliquer = () => executer(async () => {
    if (!edition || !confirm(tr.confirmApply.replace('{n}', String(edition.lignes.length)).replace('{nom}', edition.nom || '—'))) return null;
    const detail = modifie || !edition.revision ? await enregistrer() : edition.revision;
    if (!detail) return null;
    const applique = await appliquerRevisionPrix(detail.id);
    ouvrirDetail(applique);
    setVersion(v => v + 1);

    return applique.message;
  });

  const annuler = () => executer(async () => {
    if (!edition?.revision || !confirm(tr.confirmCancel.replace('{nom}', edition.revision.nom))) return null;
    ouvrirDetail(await annulerRevisionPrix(edition.revision.id));
    setVersion(v => v + 1);

    return tr.cancelled;
  });

  const supprimer = () => executer(async () => {
    if (!edition?.revision || !confirm(tr.confirmDelete.replace('{nom}', edition.revision.nom))) return null;
    await supprimerRevisionPrix(edition.revision.id);
    setEdition(null);
    setModifie(false);
    setVersion(v => v + 1);

    return tr.deleted;
  });

  function exporter() {
    if (!edition) return;
    const appliquee = edition.revision?.statut === 'appliquee';
    const entete = [tr.product, tr.description, tr.category, tr.price, appliquee ? tr.replacedPrice : tr.currentPrice, tr.newPrice];
    const decimal = (n: number | null) => (n === null || Number.isNaN(n) ? '' : String(n).replace('.', ','));
    const lignes = edition.lignes.map(l => [
      l.numero, l.description, l.categorie, l.liste ?? tr.basePrice, decimal(l.prix_actuel), decimal(lireNombre(l.saisie)),
    ].map(champCsv).join(';'));
    const csv = [entete.map(champCsv).join(';'), ...lignes].join('\r\n');
    const blob = new Blob([String.fromCharCode(0xfeff) + csv], { type: 'text/csv;charset=utf-8;' });
    const lien = document.createElement('a');
    lien.href = URL.createObjectURL(blob);
    lien.download = `revision-prix-${(edition.nom || 'nouvelle').normalize('NFD').replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase()}.csv`;
    lien.click();
    URL.revokeObjectURL(lien.href);
  }

  const libelleCible = (r: RevisionPrix) => {
    if (!r.cible || !r.type_ajustement || r.valeur_ajustement === null) return '—';
    const signe = r.valeur_ajustement > 0 ? '+' : '';
    const valeur = r.type_ajustement === 'pourcentage'
      ? `${signe}${r.valeur_ajustement.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR')} %`
      : `${signe}${formatPrix(r.valeur_ajustement, devise, language)}`;
    const cible = r.cible === 'liste' ? tr.targets.liste.replace('{liste}', r.liste ?? '—') : tr.targets[r.cible];

    return [valeur, cible, r.categorie].filter(Boolean).join(' · ');
  };

  const champ = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white disabled:bg-gray-50 disabled:text-gray-600 dark:disabled:bg-gray-800 dark:disabled:text-gray-300';
  const bouton = 'px-3 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white disabled:opacity-50';
  const avertissementTache = liste && !liste.tache.active && <p role="note" className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{tr.taskInactive}</p>;
  const zoneMessage = message && <p role={message.ok ? 'status' : 'alert'} className={`px-3 py-2 rounded-lg text-sm ${message.ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>{message.texte}</p>;

  // === Liste des révisions ===
  if (!edition) {
    const planifiees = liste?.data.some(r => r.statut === 'planifiee') ?? false;
    return (
      <div className="space-y-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <p className="text-sm text-gray-600 dark:text-gray-300 max-w-3xl">{tr.help}</p>
          {peutReviser && (
            <button onClick={nouvelle} className="inline-flex items-center gap-1 px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">
              <Plus size={16} /> {tr.add}
            </button>
          )}
        </div>
        {!peutReviser && <p className="text-xs text-amber-700 dark:text-amber-400">{tr.noRight}</p>}
        {planifiees && avertissementTache}
        {zoneMessage}
        {liste && (liste.data.length === 0 ? <p className="text-sm text-gray-500">{tr.empty}</p> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-3 py-2 text-left font-medium">{tr.name}</th>
                  <th className="px-3 py-2 text-left font-medium">{tr.status}</th>
                  <th className="px-3 py-2 text-left font-medium">{tr.effectiveDate}</th>
                  <th className="px-3 py-2 text-right font-medium">{tr.prices}</th>
                  <th className="px-3 py-2 text-left font-medium">{tr.adjustmentCol}</th>
                  <th className="px-3 py-2 text-left font-medium">{tr.createdBy}</th>
                  <th className="px-3 py-2"><span className="sr-only">{tr.open}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {liste.data.map(r => (
                  <tr key={r.id} className="text-gray-800 dark:text-gray-200">
                    <td className="px-3 py-2 font-medium">{r.nom}</td>
                    <td className="px-3 py-2">
                      <span className={`px-2 py-0.5 rounded-full text-xs ${couleurs[r.statut]}`}>{tr.statuses[r.statut]}</span>
                      {r.statut === 'appliquee' && (
                        <span className="block text-xs text-gray-500 mt-1">
                          {tr.appliedOn.replace('{date}', formatDateHeure(r.appliquee_le, language)).replace('{par}', r.appliquee_par ?? tr.appliedByTask)}
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2 whitespace-nowrap">{formatDateCourte(r.date_effet, language)}</td>
                    <td className="px-3 py-2 text-right">{r.lignes_count}</td>
                    <td className="px-3 py-2 text-gray-600 dark:text-gray-300">{libelleCible(r)}</td>
                    <td className="px-3 py-2 text-gray-600 dark:text-gray-300">{r.cree_par ?? '—'}</td>
                    <td className="px-3 py-2 text-right">
                      <button onClick={() => ouvrir(r)} aria-label={`${tr.open} — ${r.nom}`} className="px-3 py-1 text-sm border rounded-lg dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700">{tr.open}</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
      </div>
    );
  }

  // === Révision ouverte ===
  const r = edition.revision;
  const appliquee = r?.statut === 'appliquee';
  const recherche = filtre.trim().toLowerCase();
  const visibles = recherche
    ? edition.lignes.filter(l => `${l.numero ?? ''} ${l.description ?? ''}`.toLowerCase().includes(recherche))
    : edition.lignes;
  const pages = Math.max(1, Math.ceil(visibles.length / PAR_PAGE));
  const pageCourante = Math.min(page, pages);
  const affichees = visibles.slice((pageCourante - 1) * PAR_PAGE, pageCourante * PAR_PAGE);
  const champLigne = 'w-28 px-2 py-1 border rounded text-sm text-right dark:bg-gray-700 dark:border-gray-600 dark:text-white';

  function saisirPrix(ligne: Ligne, saisie: string) {
    changer({ lignes: edition!.lignes.map(l => (cle(l) === cle(ligne) ? { ...l, saisie } : l)) });
  }

  function retirer(ligne: Ligne) {
    changer({ lignes: edition!.lignes.filter(l => cle(l) !== cle(ligne)) });
  }

  /** Prix des listes qui suivent la formule, avant et après le nouveau prix de base. */
  function formules(ligne: Ligne): string | null {
    const nouveau = lireNombre(ligne.saisie);
    if (ligne.liste_prix_id !== null || ligne.formules.length === 0 || ligne.prix_actuel === null || Number.isNaN(nouveau) || nouveau === ligne.prix_actuel) return null;
    return ligne.formules.map(listeId => {
      const l = listes.find(x => x.id === listeId);
      if (!l) return null;
      const avant = arrondir(ligne.prix_actuel! * (1 + l.ajustement_pourcentage / 100), l.precision);
      const apres = arrondir(nouveau * (1 + l.ajustement_pourcentage / 100), l.precision);
      return `${l.nom} ${formatPrix(avant, devise, language)} → ${formatPrix(apres, devise, language)}`;
    }).filter(Boolean).join(' · ');
  }

  function ecart(ligne: Ligne): string {
    const nouveau = lireNombre(ligne.saisie);
    if (ligne.prix_actuel === null || ligne.prix_actuel === 0 || Number.isNaN(nouveau)) return '—';
    const pourcentage = ((nouveau - ligne.prix_actuel) / ligne.prix_actuel) * 100;
    const texte = pourcentage.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { maximumFractionDigits: 1 });
    return `${pourcentage > 0 ? '+' : ''}${texte} %`;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <button onClick={fermer} className="inline-flex items-center gap-1 text-sm text-purple-700 dark:text-purple-300 hover:underline">
          <ArrowLeft size={16} /> {tr.back}
        </button>
        {r && <span className={`px-2 py-0.5 rounded-full text-xs ${couleurs[r.statut]}`}>{tr.statuses[r.statut]}</span>}
        {r?.statut === 'planifiee' && r.date_effet && (
          <span className="inline-flex items-center gap-1 text-sm text-blue-800 dark:text-blue-200"><CalendarClock size={16} /> {tr.scheduledOn.replace('{date}', formatDateCourte(r.date_effet, language))}</span>
        )}
        {appliquee && <span className="text-sm text-gray-600 dark:text-gray-300">{tr.appliedOn.replace('{date}', formatDateHeure(r.appliquee_le, language)).replace('{par}', r.appliquee_par ?? tr.appliedByTask)}</span>}
        {r?.statut === 'annulee' && <span className="text-sm text-gray-600 dark:text-gray-300">{tr.cancelledOn.replace('{date}', formatDateHeure(r.annulee_le, language)).replace('{par}', r.annulee_par ?? '—')}</span>}
      </div>

      {r?.statut === 'planifiee' && avertissementTache}
      {!modifiable && r && !enCours(r.statut) && <p className="text-xs text-gray-500 dark:text-gray-400">{tr.readOnly.replace('{statut}', tr.statuses[r.statut].toLowerCase())}</p>}
      {!peutReviser && enCours(r?.statut) && <p className="text-xs text-amber-700 dark:text-amber-400">{tr.noRight}</p>}

      <div className="grid gap-3 md:grid-cols-3">
        <div>
          <label htmlFor={`${id}-nom`} className="block text-xs text-gray-500 mb-1">{tr.name}</label>
          <input id={`${id}-nom`} value={edition.nom} disabled={!modifiable} maxLength={150} placeholder={tr.nameHint}
            onChange={e => changer({ nom: e.target.value })} className={`${champ} w-full`} />
        </div>
        <div>
          <label htmlFor={`${id}-date`} className="block text-xs text-gray-500 mb-1">{tr.effectiveDate}</label>
          <input id={`${id}-date`} type="date" value={edition.dateEffet} min={demain} disabled={!modifiable}
            onChange={e => changer({ dateEffet: e.target.value })} aria-describedby={modifiable ? `${id}-date-aide` : undefined} className={`${champ} w-full`} />
          {modifiable && <p id={`${id}-date-aide`} className="text-xs text-gray-500 mt-1">{tr.effectiveDateHint}</p>}
        </div>
        <div>
          <label htmlFor={`${id}-notes`} className="block text-xs text-gray-500 mb-1">{tr.notes}</label>
          <textarea id={`${id}-notes`} value={edition.notes} disabled={!modifiable} rows={2} maxLength={2000}
            onChange={e => changer({ notes: e.target.value })} className={`${champ} w-full`} />
        </div>
      </div>

      {modifiable && (
        <fieldset className="border rounded-lg p-4 dark:border-gray-700">
          <legend className="px-1 text-sm font-medium text-gray-700 dark:text-gray-200">{tr.adjust.title}</legend>
          <div className="flex flex-wrap items-end gap-3">
            <div>
              <label htmlFor={`${id}-categorie`} className="block text-xs text-gray-500 mb-1">{tr.adjust.category}</label>
              <select id={`${id}-categorie`} value={edition.reglage.categorie} className={champ}
                onChange={e => setEdition({ ...edition, reglage: { ...edition.reglage, categorie: e.target.value } })}>
                <option value="">{tr.adjust.allCategories}</option>
                {liste?.categories.map(c => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor={`${id}-cible`} className="block text-xs text-gray-500 mb-1">{tr.adjust.target}</label>
              <select id={`${id}-cible`} value={edition.reglage.cible} className={champ}
                onChange={e => setEdition({ ...edition, reglage: { ...edition.reglage, cible: e.target.value } })}>
                <option value="base">{tr.targets.base}</option>
                <option value="toutes">{tr.targets.toutes}</option>
                {listes.map(l => <option key={l.id} value={l.id}>{tr.targets.liste.replace('{liste}', l.nom)}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor={`${id}-type`} className="block text-xs text-gray-500 mb-1">{tr.adjust.type}</label>
              <select id={`${id}-type`} value={edition.reglage.type} className={champ}
                onChange={e => setEdition({ ...edition, reglage: { ...edition.reglage, type: e.target.value as TypeAjustementPrix } })}>
                <option value="pourcentage">{tr.adjust.percent}</option>
                <option value="montant">{tr.adjust.amount}</option>
              </select>
            </div>
            <div>
              <label htmlFor={`${id}-valeur`} className="block text-xs text-gray-500 mb-1">{tr.adjust.value}</label>
              <input id={`${id}-valeur`} inputMode="decimal" value={edition.reglage.valeur} placeholder={tr.adjust.valueHint}
                onChange={e => setEdition({ ...edition, reglage: { ...edition.reglage, valeur: e.target.value } })}
                onKeyDown={e => { if (e.key === 'Enter') void ajuster(); }} className={`${champ} w-36`} />
            </div>
            <label className="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 pb-2">
              <input type="checkbox" checked={edition.reglage.inclureInactifs}
                onChange={e => setEdition({ ...edition, reglage: { ...edition.reglage, inclureInactifs: e.target.checked } })} />
              {tr.adjust.inactive}
            </label>
            <button onClick={ajuster} disabled={occupe} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tr.adjust.apply}</button>
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">{tr.adjust.help}</p>
        </fieldset>
      )}

      {zoneMessage}

      <div className="flex flex-wrap items-end gap-3">
        <div className="flex-1 min-w-[14rem]">
          <label htmlFor={`${id}-filtre`} className="block text-xs text-gray-500 mb-1">{tr.search}</label>
          <input id={`${id}-filtre`} type="search" value={filtre} onChange={e => { setFiltre(e.target.value); setPage(1); }} className={`${champ} w-full`} />
        </div>
        <span className="text-sm text-gray-600 dark:text-gray-300 pb-2">{tr.pricesCount.replace('{n}', String(edition.lignes.length))}</span>
        <button onClick={exporter} disabled={edition.lignes.length === 0} className={`${bouton} inline-flex items-center gap-1`}><Download size={16} /> {tr.export}</button>
      </div>

      {edition.lignes.length === 0 ? <p className="text-sm text-gray-500">{tr.noLines}</p> : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-3 py-2 text-left font-medium">{tr.product}</th>
                  <th className="px-3 py-2 text-left font-medium">{tr.price}</th>
                  <th className="px-3 py-2 text-right font-medium">{appliquee ? tr.replacedPrice : tr.currentPrice}</th>
                  <th className="px-3 py-2 text-right font-medium">{tr.newPrice}</th>
                  <th className="px-3 py-2 text-right font-medium">{tr.change}</th>
                  {modifiable && <th className="px-3 py-2"><span className="sr-only">{tr.remove}</span></th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {affichees.map(l => {
                  const suite = formules(l);
                  const libelle = `${l.numero ?? ''} — ${l.liste ?? nomsListes.get(l.liste_prix_id ?? 0) ?? tr.basePrice}`;
                  return (
                    <tr key={cle(l)} className="text-gray-800 dark:text-gray-200 align-top">
                      <td className="px-3 py-2">
                        <span className="font-mono text-xs text-gray-500 mr-2">{l.numero}</span>{l.description}
                        {l.categorie && <span className="ml-2 px-1.5 py-0.5 rounded text-[11px] bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{l.categorie}</span>}
                        {!l.produit_actif && <span className="ml-2 text-[11px] text-amber-700 dark:text-amber-400">{tr.inactiveProduct}</span>}
                        {suite && <span className="block text-xs text-gray-500 dark:text-gray-400 mt-1">{tr.formulas} : {suite}</span>}
                      </td>
                      <td className="px-3 py-2 whitespace-nowrap">{l.liste ?? tr.basePrice}</td>
                      <td className="px-3 py-2 text-right whitespace-nowrap">{l.prix_actuel === null ? <span className="text-gray-400">{tr.noPrice}</span> : formatPrix(l.prix_actuel, devise, language)}</td>
                      <td className="px-3 py-2 text-right whitespace-nowrap">
                        {modifiable ? (
                          <input aria-label={`${tr.newPrice} — ${libelle}`} inputMode="decimal" value={l.saisie} onChange={e => saisirPrix(l, e.target.value)}
                            className={`${champLigne} ${Number.isNaN(lireNombre(l.saisie)) || lireNombre(l.saisie) < 0 ? 'border-red-500' : ''}`} />
                        ) : formatPrix(lireNombre(l.saisie), devise, language)}
                      </td>
                      <td className="px-3 py-2 text-right whitespace-nowrap text-gray-600 dark:text-gray-300">{ecart(l)}</td>
                      {modifiable && (
                        <td className="px-3 py-2 text-right">
                          <button onClick={() => retirer(l)} aria-label={`${tr.remove} — ${libelle}`} title={tr.remove} className="p-1 text-gray-400 hover:text-red-600"><X size={14} /></button>
                        </td>
                      )}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {pages > 1 && (
            <div className="flex items-center justify-end gap-3 text-sm">
              <button disabled={pageCourante <= 1} onClick={() => setPage(pageCourante - 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{tp.previous}</button>
              <span className="text-gray-600 dark:text-gray-300">{tp.page.replace('{page}', String(pageCourante)).replace('{pages}', String(pages))}</span>
              <button disabled={pageCourante >= pages} onClick={() => setPage(pageCourante + 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{tp.next}</button>
            </div>
          )}
        </>
      )}

      {peutReviser && (
        <div className="flex flex-wrap items-center justify-end gap-2 pt-2 border-t dark:border-gray-700">
          {r && r.statut !== 'appliquee' && <button onClick={supprimer} disabled={occupe} className={`${bouton} text-red-700 dark:text-red-400`}>{tr.delete}</button>}
          {r && enCours(r.statut) && <button onClick={annuler} disabled={occupe} className={bouton}>{tr.cancelRevision}</button>}
          {modifiable && (
            <>
              <button onClick={sauver} disabled={occupe} className={bouton}>{tr.save}</button>
              <button onClick={planifier} disabled={occupe || edition.lignes.length === 0} className={`${bouton} inline-flex items-center gap-1`}><CalendarClock size={16} /> {tr.schedule}</button>
              <button onClick={appliquer} disabled={occupe || edition.lignes.length === 0} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tr.applyNow}</button>
            </>
          )}
        </div>
      )}
    </div>
  );
}
