import { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Check, KeyRound, Minus, Save, Shield, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import {
  enregistrerAccesCompte, getAccesCompte, getCatalogue, getGroupes, permissionsDu, valeurDuGroupe,
  type AccesCompte, type Groupe, type OngletCatalogue, type PermissionCatalogue,
} from '@/services/permissions';
import MatricePermissions from '@/components/permissions/MatricePermissions';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

interface Brouillon {
  groupes: number[];
  /** code => accordée (true) ou refusée (false) ; absent : suit les groupes. */
  exceptions: Record<string, boolean>;
}

/**
 * Accès d'un compte, comme dans fripv2 : son rôle principal (réglé dans la fiche de l'employé), ses groupes
 * supplémentaires et ses exceptions permission par permission. À gauche, ce que donnent ses groupes ; à droite,
 * l'exception (héritée, accordée, refusée : le refus l'emporte).
 */
export default function AccesComptePage() {
  const { compteId } = useParams();
  const id = Number(compteId);
  const { t, language } = useLanguage();
  const tp = t.permissions;
  const langue = language === 'en' ? 'en' : 'fr';
  const { isAdmin } = usePermissions();
  const { toasts, toast, dismiss } = useToast();
  const [catalogue, setCatalogue] = useState<OngletCatalogue[] | null>(null);
  const [groupes, setGroupes] = useState<Groupe[] | null>(null);
  const [acces, setAcces] = useState<AccesCompte | null>(null);
  const [brouillon, setBrouillon] = useState<Brouillon | null>(null);
  const [exceptionsSeules, setExceptionsSeules] = useState(false);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    Promise.all([getCatalogue(), getGroupes(), getAccesCompte(id)]).then(
      ([c, g, a]) => {
        if (!actif) return;
        setCatalogue(c.onglets);
        setGroupes(g);
        setAcces(a);
        setBrouillon({ groupes: a.groupes, exceptions: { ...a.exceptions } });
      },
      e => { if (actif) setErreur(messageErreur(e, tp.error)); },
    );
    return () => { actif = false; };
  }, [id, tp.error]);

  const toutes = useMemo(() => (catalogue ? permissionsDu(catalogue) : []), [catalogue]);
  // Ce que donnent les groupes du brouillon : rôle principal et groupes supplémentaires actifs (droits additionnés).
  const droitsGroupes = useMemo(() => {
    if (!groupes || !acces || !brouillon) return {};
    const ids = new Set([acces.role_principal?.id, ...brouillon.groupes]);
    const retenus = groupes.filter(g => ids.has(g.id) && (g.actif || g.id === acces.role_principal?.id));
    return Object.fromEntries(toutes.map(p => [p.code, retenus.some(g => valeurDuGroupe(g, p))]));
  }, [groupes, acces, brouillon, toutes]);

  if (!isAdmin()) {
    return <div className="p-6"><p className="max-w-xl bg-red-50 border border-red-200 rounded-lg p-4 text-red-700">{tp.adminOnly}</p></div>;
  }

  const supplementaires = (groupes ?? []).filter(g => !g.is_system && g.id !== acces?.compte.role_custom_id);

  const cycle = (code: string) => setBrouillon(b => {
    if (!b) return b;
    const exceptions = { ...b.exceptions };
    // Hérité -> accordé -> refusé -> hérité.
    if (!(code in exceptions)) exceptions[code] = true;
    else if (exceptions[code]) exceptions[code] = false;
    else delete exceptions[code];
    return { ...b, exceptions };
  });

  async function enregistrer() {
    if (!brouillon) return;
    setOccupe(true);
    try {
      const reponse = await enregistrerAccesCompte(id, brouillon);
      setAcces(reponse);
      setBrouillon({ groupes: reponse.groupes, exceptions: { ...reponse.exceptions } });
      toast(tp.accessSaved);
    } catch (e) {
      toast(messageErreur(e, tp.error), 'error');
    } finally {
      setOccupe(false);
    }
  }

  const caseException = (permission: PermissionCatalogue) => {
    const exception = brouillon?.exceptions[permission.code];
    const etat = exception === undefined ? 'herite' : exception ? 'accorde' : 'refuse';
    const libelle = { herite: tp.inherit, accorde: tp.granted, refuse: tp.refused }[etat];
    const effectif = exception ?? droitsGroupes[permission.code];
    return (
      <button
        type="button"
        onClick={() => cycle(permission.code)}
        disabled={acces?.administrateur}
        title={libelle}
        aria-label={`${permission[langue]} : ${libelle}`}
        className={`inline-flex items-center justify-center w-16 gap-1 px-1.5 py-0.5 rounded border text-xs font-medium ${
          etat === 'accorde' ? 'border-green-300 bg-green-50 text-green-700' : etat === 'refuse' ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-200 text-gray-400'
        } ${effectif ? '' : 'opacity-90'}`}
      >
        {etat === 'accorde' ? <Check size={12} /> : etat === 'refuse' ? <X size={12} /> : <Minus size={12} />}
        {libelle}
      </button>
    );
  };

  return (
    <div className="p-6">
      <Link to="/employes" className="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline mb-3"><ArrowLeft size={16} /> {tp.back}</Link>
      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {acces && brouillon && catalogue && (
        <>
          <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
              <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><KeyRound className="text-blue-600" /> {tp.accessTitle.replace('{nom}', acces.compte.nom)}</h1>
              <p className="text-sm text-gray-500 dark:text-gray-400">{acces.compte.email}</p>
              <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl mt-1">{tp.accessSubtitle}</p>
            </div>
            {!acces.administrateur && (
              <button type="button" onClick={() => { void enregistrer(); }} disabled={occupe}
                className="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
                <Save size={16} /> {tp.save}
              </button>
            )}
          </div>

          {acces.administrateur ? (
            <p className="mb-4 flex items-center gap-2 px-4 py-3 rounded-lg bg-blue-50 text-blue-800 text-sm"><Shield size={16} /> {tp.admin}</p>
          ) : (
            <div className="mb-5 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
                <p className="text-xs uppercase text-gray-500 dark:text-gray-400">{tp.mainRole}</p>
                <p className="font-medium text-gray-800 dark:text-white">{acces.role_principal ? (acces.role_principal.systeme ? tp.systemRoles[acces.role_principal.nom as keyof typeof tp.systemRoles] ?? acces.role_principal.nom : acces.role_principal.nom) : '—'}</p>
                <p className="text-xs text-gray-500 dark:text-gray-400">{tp.mainRoleHelp}</p>
              </div>
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
                <p className="text-xs uppercase text-gray-500 dark:text-gray-400 mb-1">{tp.extraGroups}</p>
                {supplementaires.length === 0 ? (
                  <p className="text-sm text-gray-500 dark:text-gray-400">{tp.noGroups}</p>
                ) : (
                  <div className="flex flex-wrap gap-x-4 gap-y-1">
                    {supplementaires.map(g => (
                      <label key={g.id} className="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" className="h-4 w-4"
                          checked={brouillon.groupes.includes(g.id)}
                          onChange={e => setBrouillon({ ...brouillon, groupes: e.target.checked ? [...brouillon.groupes, g.id] : brouillon.groupes.filter(x => x !== g.id) })} />
                        {g.nom}{!g.actif && <span className="text-xs text-gray-400">({tp.inactive})</span>}
                      </label>
                    ))}
                  </div>
                )}
              </div>
            </div>
          )}

          <MatricePermissions
            catalogue={catalogue}
            langue={langue}
            textes={tp}
            filtre={exceptionsSeules ? p => p.code in brouillon.exceptions : undefined}
            rendreGauche={p => (acces.administrateur || droitsGroupes[p.code]
              ? <Check size={14} className="inline text-green-600" aria-label={tp.fromGroups} />
              : <Minus size={14} className="inline text-gray-300" />)}
            rendreCase={caseException}
            barreOutils={!acces.administrateur && (
              <div className="flex flex-wrap items-center gap-2">
                <label className="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1.5">{tp.show}
                  <select value={exceptionsSeules ? 'exceptions' : 'toutes'} onChange={e => setExceptionsSeules(e.target.value === 'exceptions')}
                    className="px-2 py-1.5 border border-gray-300 rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    <option value="toutes">{tp.showAll}</option>
                    <option value="exceptions">{tp.showExceptions}</option>
                  </select>
                </label>
                <button type="button" onClick={() => setBrouillon({ ...brouillon, exceptions: Object.fromEntries(toutes.map(p => [p.code, true])) })}
                  className="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{tp.grantAllExceptions}</button>
                <button type="button" onClick={() => setBrouillon({ ...brouillon, exceptions: {} })}
                  className="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{tp.clearExceptions}</button>
              </div>
            )}
          />
          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400"><span className="font-medium">{tp.fromGroups}</span> · {tp.exceptionLegend}</p>
        </>
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
