import { useEffect, useMemo, useState } from 'react';
import { Copy, Lock, Plus, Save, Shield, Trash2, Users } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import {
  creerGroupe, getCatalogue, getGroupes, modifierGroupe, permissionsDu, supprimerGroupe, valeurDuGroupe, versApi,
  type Groupe, type OngletCatalogue, type PermissionCatalogue,
} from '@/services/permissions';
import MatricePermissions from '@/components/permissions/MatricePermissions';
import ConfirmModal from '@/components/ConfirmModal';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

const classeChamp = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

interface Edition {
  /** null : nouveau groupe. */
  id: number | null;
  nom: string;
  description: string;
  actif: boolean;
  valeurs: Record<string, boolean>;
}

/**
 * Rôles et permissions, sur le modèle de fripv2 : les groupes (rôles système communs à toutes les entreprises,
 * et groupes de l'entreprise) et leurs permissions par onglet et par écran, droits de base et actions nommées.
 */
export default function RolesPage() {
  const { t, language } = useLanguage();
  const tp = t.permissions;
  const langue = language === 'en' ? 'en' : 'fr';
  const { isAdmin } = usePermissions();
  const { toasts, toast, dismiss } = useToast();
  const [catalogue, setCatalogue] = useState<OngletCatalogue[] | null>(null);
  const [groupes, setGroupes] = useState<Groupe[] | null>(null);
  const [version, setVersion] = useState(0);
  const [selection, setSelection] = useState<number | null>(null);
  const [edition, setEdition] = useState<Edition | null>(null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const [aSupprimer, setASupprimer] = useState<Groupe | null>(null);

  useEffect(() => {
    let actif = true;
    getCatalogue().then(r => { if (actif) setCatalogue(r.onglets); }, e => { if (actif) setErreur(messageErreur(e, tp.error)); });
    return () => { actif = false; };
  }, [tp.error]);

  useEffect(() => {
    let actif = true;
    getGroupes().then(r => { if (actif) setGroupes(r); }, e => { if (actif) setErreur(messageErreur(e, tp.error)); });
    return () => { actif = false; };
  }, [version, tp.error]);

  const toutes = useMemo(() => (catalogue ? permissionsDu(catalogue) : []), [catalogue]);
  const groupeChoisi = groupes?.find(g => g.id === selection) ?? null;

  // Rôles système : libellé lisible plutôt que le nom technique (admin, manager…).
  const nomDe = (groupe: Pick<Groupe, 'nom' | 'is_system'>) => (groupe.is_system ? tp.systemRoles[groupe.nom as keyof typeof tp.systemRoles] ?? groupe.nom : groupe.nom);

  const valeursDe = (groupe: Groupe) => Object.fromEntries(toutes.map(p => [p.code, valeurDuGroupe(groupe, p)]));

  const choisir = (groupe: Groupe) => {
    setSelection(groupe.id);
    setEdition(groupe.is_system ? null : { id: groupe.id, nom: groupe.nom, description: groupe.description ?? '', actif: groupe.actif, valeurs: valeursDe(groupe) });
  };

  const nouveau = (modele?: Groupe) => {
    setSelection(null);
    setEdition({
      id: null,
      nom: modele ? `${nomDe(modele)} ${tp.copySuffix}` : '',
      description: modele?.description ?? '',
      actif: true,
      valeurs: modele ? valeursDe(modele) : Object.fromEntries(toutes.map(p => [p.code, false])),
    });
  };

  const changer = (codes: string[], valeur: boolean) => {
    setEdition(e => (e ? { ...e, valeurs: { ...e.valeurs, ...Object.fromEntries(codes.map(c => [c, valeur])) } } : e));
  };

  async function enregistrer() {
    if (!edition || !catalogue) return;
    setOccupe(true);
    try {
      const donnees = { nom: edition.nom.trim(), description: edition.description.trim() || null, actif: edition.actif, ...versApi(catalogue, edition.valeurs) };
      if (edition.id === null) {
        const { role_id } = await creerGroupe(donnees);
        setSelection(role_id);
        setEdition({ ...edition, id: role_id });
        toast(tp.created);
      } else {
        await modifierGroupe(edition.id, donnees);
        toast(tp.saved);
      }
      setVersion(v => v + 1);
    } catch (e) {
      toast(messageErreur(e, tp.error), 'error');
    } finally {
      setOccupe(false);
    }
  }

  async function supprimer() {
    if (!aSupprimer) return;
    const groupe = aSupprimer;
    setASupprimer(null);
    try {
      await supprimerGroupe(groupe.id);
      toast(tp.deleted);
      setSelection(null);
      setEdition(null);
      setVersion(v => v + 1);
    } catch (e) {
      toast(messageErreur(e, tp.error), 'error');
    }
  }

  if (!isAdmin()) {
    return (
      <div className="p-6">
        <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center max-w-xl">
          <Shield className="mx-auto text-red-500 mb-4" size={40} />
          <p className="text-red-700">{tp.adminOnly}</p>
        </div>
      </div>
    );
  }

  const lecture = groupeChoisi?.is_system ? valeursDe(groupeChoisi) : null;
  const caseDe = (permission: PermissionCatalogue) => {
    const coche = lecture ? lecture[permission.code] : Boolean(edition?.valeurs[permission.code]);
    return (
      <input
        type="checkbox"
        className="h-4 w-4 accent-blue-600"
        aria-label={permission[langue]}
        checked={coche}
        disabled={!edition}
        onChange={e => changer([permission.code], e.target.checked)}
      />
    );
  };

  return (
    <div className="p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Shield className="text-blue-600" /> {tp.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-4xl">{tp.subtitle}</p>
      </div>
      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="grid grid-cols-1 lg:grid-cols-[18rem_1fr] gap-5 items-start">
        <aside className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
          <div className="flex items-center justify-between gap-2 p-3 border-b border-gray-100 dark:border-gray-700">
            <h2 className="font-semibold text-gray-800 dark:text-white">{tp.groups}</h2>
            <button type="button" onClick={() => nouveau()} disabled={!catalogue}
              className="inline-flex items-center gap-1 px-2.5 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-medium hover:bg-blue-700 disabled:opacity-50">
              <Plus size={14} /> {tp.newGroup}
            </button>
          </div>
          <ul className="divide-y divide-gray-100 dark:divide-gray-700">
            {(groupes ?? []).map(groupe => (
              <li key={groupe.id}>
                <button type="button" onClick={() => choisir(groupe)}
                  className={`w-full text-left px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/50 ${groupe.id === selection ? 'bg-blue-50 dark:bg-blue-900/30' : ''}`}>
                  <span className="flex items-center gap-1.5 font-medium text-sm text-gray-800 dark:text-gray-100">
                    {groupe.is_system && <Lock size={12} className="text-gray-400" />}
                    {nomDe(groupe)}
                    {groupe.is_system && <span className="ml-auto text-[10px] uppercase text-gray-400">{tp.system}</span>}
                    {!groupe.is_system && !groupe.actif && <span className="ml-auto px-1.5 rounded bg-gray-200 text-gray-600 text-[10px]">{tp.inactive}</span>}
                  </span>
                  {groupe.nb_comptes !== null && (
                    <span className="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400"><Users size={11} /> {tp.accounts.replace('{n}', String(groupe.nb_comptes))}</span>
                  )}
                </button>
              </li>
            ))}
          </ul>
        </aside>

        <section className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 min-w-0">
          {!catalogue || (!groupeChoisi && !edition) ? (
            <p className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">{tp.chooseGroup}</p>
          ) : (
            <>
              {groupeChoisi?.is_system ? (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <h2 className="text-lg font-semibold text-gray-800 dark:text-white">{nomDe(groupeChoisi)}</h2>
                    <p className="text-sm text-gray-500 dark:text-gray-400">{tp.systemNote}</p>
                  </div>
                  <button type="button" onClick={() => nouveau(groupeChoisi)} className="inline-flex items-center gap-1.5 px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    <Copy size={15} /> {tp.duplicate}
                  </button>
                </div>
              ) : edition && (
                <div className="mb-4 grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3 items-end">
                  <label className="text-sm text-gray-700 dark:text-gray-300">{tp.name}
                    <input required maxLength={50} value={edition.nom} onChange={e => setEdition({ ...edition, nom: e.target.value })} className={`${classeChamp} mt-1`} />
                  </label>
                  <label className="text-sm text-gray-700 dark:text-gray-300">{tp.description}
                    <input maxLength={255} value={edition.description} onChange={e => setEdition({ ...edition, description: e.target.value })} className={`${classeChamp} mt-1`} />
                  </label>
                  <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => { void enregistrer(); }} disabled={occupe || !edition.nom.trim()}
                      className="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
                      <Save size={15} /> {tp.save}
                    </button>
                    {edition.id !== null && groupeChoisi && (
                      <>
                        <button type="button" onClick={() => nouveau(groupeChoisi)} title={tp.duplicate} aria-label={tp.duplicate}
                          className="p-2 border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"><Copy size={15} /></button>
                        <button type="button" onClick={() => setASupprimer(groupeChoisi)} title={tp.delete} aria-label={tp.delete}
                          className="p-2 border border-red-200 rounded-lg text-red-600 hover:bg-red-50 dark:border-red-900"><Trash2 size={15} /></button>
                      </>
                    )}
                  </div>
                  <label className="md:col-span-3 flex items-start gap-2 text-sm">
                    <input type="checkbox" className="mt-0.5 h-4 w-4" checked={edition.actif} onChange={e => setEdition({ ...edition, actif: e.target.checked })} />
                    <span>
                      <span className="font-medium text-gray-800 dark:text-gray-100">{tp.active}</span>
                      <span className="block text-xs text-gray-500 dark:text-gray-400">{tp.activeHelp}</span>
                    </span>
                  </label>
                </div>
              )}

              <MatricePermissions
                catalogue={catalogue}
                langue={langue}
                textes={tp}
                rendreCase={caseDe}
                rendreEnTete={(module, visibles) => edition && (
                  <label className="flex items-center gap-1.5 text-xs font-semibold text-gray-600 dark:text-gray-300">
                    {tp.all}
                    <input type="checkbox" className="h-4 w-4 accent-blue-600"
                      checked={visibles.every(p => edition.valeurs[p.code])}
                      onChange={e => changer(visibles.map(p => p.code), e.target.checked)}
                      aria-label={`${tp.all} — ${module[langue]}`} />
                  </label>
                )}
                barreOutils={edition && (
                  <div className="flex gap-2">
                    <button type="button" onClick={() => changer(toutes.map(p => p.code), true)} className="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{tp.grantAll}</button>
                    <button type="button" onClick={() => changer(toutes.map(p => p.code), false)} className="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{tp.removeAll}</button>
                  </div>
                )}
              />
            </>
          )}
        </section>
      </div>

      <ConfirmModal
        open={aSupprimer !== null}
        title={tp.deleteTitle}
        message={aSupprimer ? tp.deleteMessage.replace('{nom}', nomDe(aSupprimer)) : ''}
        confirmLabel={tp.delete}
        onConfirm={() => { void supprimer(); }}
        onCancel={() => setASupprimer(null)}
      />
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
