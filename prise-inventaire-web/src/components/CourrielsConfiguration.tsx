import { useCallback, useEffect, useRef, useState } from 'react';
import { Mail, Send, Pencil, RotateCcw, X, Eye, Server, Save } from 'lucide-react';
import {
  getCatalogueCourriels, getModelesCourriel, createModeleCourriel, updateModeleCourriel, deleteModeleCourriel, apercuModeleCourriel,
  getParametresCourriel, updateParametresCourriel, testerParametresCourriel,
  type CatalogueCourriels, type ModeleCourriel, type ParametresCourriel, type ApercuCourriel,
  type TypeCourriel, type LangueCourriel, type ContenuModeleCourriel,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';

/** Variables admises dans un nom de fichier (ni adresse ni montant). */
const VARIABLES_NOM_FICHIER = ['numero', 'date', 'client_nom', 'fournisseur_nom', 'entreprise_nom'];

type ChampModele = 'objet' | 'contenu' | 'nom_fichier';

interface Edition {
  id: number | null;
  type: TypeCourriel;
  langue: LangueCourriel;
  donnees: ContenuModeleCourriel;
}

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm disabled:opacity-60';

/** Champ libellé ; l'aide reste hors du libellé pour ne pas allonger le nom lu par les lecteurs d'écran. */
function Champ({ label, aide, className = '', children }: { label: string; aide?: string; className?: string; children: React.ReactNode }) {
  return (
    <div className={className}>
      <label className="block">
        <span className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{label}</span>
        {children}
      </label>
      {aide && <p className="text-xs text-gray-500 mt-1">{aide}</p>}
    </div>
  );
}

/** Onglet Courriels de la configuration : serveur d'envoi de l'entreprise et modèles par type et par langue. */
export default function CourrielsConfiguration() {
  const { t, language } = useLanguage();
  const tc = t.emails;
  const { isAdmin } = usePermissions();
  const admin = isAdmin();
  const libelleVariable = (v: string) => (tc.variableLabels as Record<string, string>)[v] ?? v;

  const [catalogue, setCatalogue] = useState<CatalogueCourriels | null>(null);
  const [modeles, setModeles] = useState<ModeleCourriel[]>([]);
  const [parametres, setParametres] = useState<ParametresCourriel | null>(null);
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [enregistrement, setEnregistrement] = useState(false);
  const [destinataireTest, setDestinataireTest] = useState('');
  const [testEnCours, setTestEnCours] = useState(false);

  const [edition, setEdition] = useState<Edition | null>(null);
  const [erreurEdition, setErreurEdition] = useState('');
  const [apercu, setApercu] = useState<ApercuCourriel | null>(null);
  const [champActif, setChampActif] = useState<ChampModele>('contenu');
  const champs = useRef<Partial<Record<ChampModele, HTMLInputElement | HTMLTextAreaElement | null>>>({});

  // Un nouveau message remplace le précédent et repart pour sa propre durée d'affichage.
  const minuterie = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const informer = useCallback((texte: string, erreur = false) => {
    setMessage({ texte, erreur });
    clearTimeout(minuterie.current);
    minuterie.current = setTimeout(() => setMessage(null), 5000);
  }, []);
  useEffect(() => () => clearTimeout(minuterie.current), []);

  useEffect(() => {
    let actif = true;
    Promise.all([getCatalogueCourriels(), getModelesCourriel(), getParametresCourriel()])
      .then(([c, m, p]) => {
        if (!actif) return;
        setCatalogue(c);
        setModeles(m);
        setParametres(p);
      })
      .catch(() => { if (actif) informer(tc.error, true); });
    return () => { actif = false; };
  }, [informer, tc.error]);

  const rechargerModeles = () => getModelesCourriel().then(setModeles).catch(() => informer(tc.error, true));
  const messageErreur = (erreur: unknown) => (erreur instanceof ApiError ? erreur.message : tc.error);

  // ── Paramètres d'envoi ─────────────────────────────────────────
  const modifier = (valeurs: Partial<ParametresCourriel>) => setParametres(p => (p ? { ...p, ...valeurs } : p));

  async function enregistrerParametres(e: React.FormEvent) {
    e.preventDefault();
    if (!parametres) return;
    setEnregistrement(true);
    try {
      // La réponse ne contient jamais le mot de passe : le champ se vide après l'enregistrement.
      setParametres(await updateParametresCourriel(parametres));
      informer(tc.saved);
    } catch (erreur) {
      informer(messageErreur(erreur), true);
    } finally {
      setEnregistrement(false);
    }
  }

  async function envoyerTest() {
    setTestEnCours(true);
    try {
      await testerParametresCourriel(destinataireTest, language === 'en' ? 'en' : 'fr');
      informer(tc.testSent.replace('{adresse}', destinataireTest));
    } catch (erreur) {
      informer(messageErreur(erreur), true);
    } finally {
      setTestEnCours(false);
    }
  }

  // ── Modèles ────────────────────────────────────────────────────
  function ouvrir(type: TypeCourriel, langue: LangueCourriel) {
    if (!catalogue) return;
    const modele = modeles.find(m => m.type === type && m.langue === langue);
    const defaut = catalogue.types.find(x => x.type === type)!.defauts[langue];
    setEdition({
      id: modele?.id ?? null,
      type,
      langue,
      donnees: modele
        ? { objet: modele.objet, contenu: modele.contenu, nom_fichier: modele.nom_fichier, repondre_a: modele.repondre_a, accuse_lecture: modele.accuse_lecture }
        : { ...defaut },
    });
    setApercu(null);
    setErreurEdition('');
    setChampActif('contenu');
  }

  const modifierModele = (valeurs: Partial<ContenuModeleCourriel>) => setEdition(e => (e ? { ...e, donnees: { ...e.donnees, ...valeurs } } : e));

  /** Insère {variable} à l'emplacement du curseur dans le dernier champ utilisé. */
  function inserer(variable: string) {
    if (!edition) return;
    const jeton = `{${variable}}`;
    const element = champs.current[champActif];
    const valeur = edition.donnees[champActif] ?? '';
    const debut = element?.selectionStart ?? valeur.length;
    const fin = element?.selectionEnd ?? valeur.length;
    modifierModele({ [champActif]: valeur.slice(0, debut) + jeton + valeur.slice(fin) });
    requestAnimationFrame(() => {
      element?.focus();
      element?.setSelectionRange(debut + jeton.length, debut + jeton.length);
    });
  }

  async function previsualiser() {
    if (!edition) return;
    setErreurEdition('');
    try {
      const { type, langue, donnees } = edition;
      setApercu(await apercuModeleCourriel({ type, langue, objet: donnees.objet, contenu: donnees.contenu, nom_fichier: donnees.nom_fichier || null }));
    } catch (erreur) {
      setErreurEdition(messageErreur(erreur));
    }
  }

  async function enregistrerModele(e: React.FormEvent) {
    e.preventDefault();
    if (!edition) return;
    setErreurEdition('');
    const donnees = { ...edition.donnees, nom_fichier: edition.donnees.nom_fichier || null, repondre_a: edition.donnees.repondre_a || null };
    try {
      if (edition.id) {
        await updateModeleCourriel(edition.id, donnees);
        informer(tc.updated);
      } else {
        await createModeleCourriel({ type: edition.type, langue: edition.langue, ...donnees });
        informer(tc.created);
      }
      setEdition(null);
      rechargerModeles();
    } catch (erreur) {
      setErreurEdition(messageErreur(erreur));
    }
  }

  async function supprimer(modele: ModeleCourriel) {
    if (!confirm(tc.confirmReset)) return;
    try {
      await deleteModeleCourriel(modele.id);
      informer(tc.deleted);
      rechargerModeles();
    } catch (erreur) {
      informer(messageErreur(erreur), true);
    }
  }

  const typeEdite = edition && catalogue ? catalogue.types.find(x => x.type === edition.type) : undefined;
  const variablesAffichees = (typeEdite?.variables ?? []).filter(v => champActif !== 'nom_fichier' || VARIABLES_NOM_FICHIER.includes(v));
  const suivreChamp = (champ: ChampModele) => ({
    ref: (element: HTMLInputElement | HTMLTextAreaElement | null) => { champs.current[champ] = element; },
    onFocus: () => setChampActif(champ),
  });

  return (
    <div className="space-y-6 max-w-4xl">
      {message && (
        <p className={`px-3 py-2 rounded-lg text-sm ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>
      )}

      {/* ── Paramètres d'envoi ── */}
      {parametres && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
          <h2 className="text-lg font-semibold dark:text-white flex items-center gap-2">
            <Server size={20} className="text-purple-600" /> {tc.sendingTitle}
          </h2>
          <p className="text-sm text-gray-500 mt-1 mb-4">{tc.sendingSubtitle}</p>
          {!admin && <p className="mb-4 px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">{tc.adminOnly}</p>}

          <form onSubmit={enregistrerParametres}>
            <fieldset disabled={!admin} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                {(['plateforme', 'smtp'] as const).map(mode => (
                  <label key={mode} className={`flex gap-3 p-3 border rounded-lg cursor-pointer ${parametres.transport === mode ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20' : 'dark:border-gray-600'}`}>
                    <input type="radio" name="transport" checked={parametres.transport === mode} onChange={() => modifier({ transport: mode })} className="mt-1" />
                    <span>
                      <span className="block text-sm font-medium dark:text-white">{mode === 'smtp' ? tc.smtp : tc.platform}</span>
                      <span className="block text-xs text-gray-500 dark:text-gray-400">
                        {mode === 'smtp' ? tc.smtpHelp : tc.platformHelp.replace('{adresse}', parametres.adresse_plateforme || '—')}
                      </span>
                    </span>
                  </label>
                ))}
              </div>

              {parametres.transport === 'smtp' && (
                <div className="grid grid-cols-1 md:grid-cols-6 gap-3">
                  <Champ label={`${tc.host} *`} className="md:col-span-3">
                    <input value={parametres.smtp_hote ?? ''} onChange={e => modifier({ smtp_hote: e.target.value })} required maxLength={255} className={classeChamp} placeholder="smtp.exemple.com" />
                  </Champ>
                  <Champ label={tc.port} className="md:col-span-1">
                    <input type="number" min={1} max={65535} value={parametres.smtp_port ?? ''} className={classeChamp}
                      onChange={e => modifier({ smtp_port: e.target.value === '' ? null : Number(e.target.value) })} />
                  </Champ>
                  <Champ label={tc.encryption} className="md:col-span-2">
                    <select value={parametres.smtp_chiffrement ?? 'tls'} onChange={e => modifier({ smtp_chiffrement: e.target.value as ParametresCourriel['smtp_chiffrement'] })} className={classeChamp}>
                      {(['tls', 'ssl', 'aucun'] as const).map(c => <option key={c} value={c}>{tc.encryptions[c]}</option>)}
                    </select>
                  </Champ>
                  <Champ label={tc.username} className="md:col-span-3">
                    <input value={parametres.smtp_utilisateur ?? ''} onChange={e => modifier({ smtp_utilisateur: e.target.value })} autoComplete="off" maxLength={255} className={classeChamp} />
                  </Champ>
                  <Champ label={tc.password} className="md:col-span-3">
                    <input type="password" value={parametres.smtp_mot_de_passe ?? ''} onChange={e => modifier({ smtp_mot_de_passe: e.target.value })}
                      autoComplete="new-password" maxLength={255} className={classeChamp} placeholder={parametres.smtp_mot_de_passe_defini ? tc.passwordKept : ''} />
                  </Champ>
                  <Champ label={`${tc.fromAddress} *`} className="md:col-span-6">
                    <input type="email" value={parametres.expediteur_adresse ?? ''} onChange={e => modifier({ expediteur_adresse: e.target.value })} required maxLength={255} className={classeChamp} />
                  </Champ>
                </div>
              )}

              <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                <Champ label={tc.fromName} aide={tc.fromNameHelp}>
                  <input value={parametres.expediteur_nom ?? ''} onChange={e => modifier({ expediteur_nom: e.target.value })} maxLength={255} className={classeChamp} />
                </Champ>
                <Champ label={tc.replyTo} aide={tc.replyToHelp}>
                  <input type="email" value={parametres.repondre_a ?? ''} onChange={e => modifier({ repondre_a: e.target.value })} maxLength={255} className={classeChamp} />
                </Champ>
                <Champ label={tc.alwaysBcc} aide={tc.alwaysBccHelp}>
                  <input type="email" value={parametres.copie_cachee ?? ''} onChange={e => modifier({ copie_cachee: e.target.value })} maxLength={255} className={classeChamp} />
                </Champ>
              </div>
              <Champ label={tc.signature} aide={tc.signatureHelp}>
                <textarea rows={3} value={parametres.signature ?? ''} onChange={e => modifier({ signature: e.target.value })} maxLength={5000} className={classeChamp} />
              </Champ>
              <label className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" checked={parametres.devis_reponse_en_ligne ?? true} onChange={e => modifier({ devis_reponse_en_ligne: e.target.checked })} className="mt-1 rounded" />
                <span>
                  <span className="font-medium">{tc.quoteOnlineAnswer}</span>
                  <span className="block text-xs text-gray-500 dark:text-gray-400">{tc.quoteOnlineAnswerHelp}</span>
                </span>
              </label>

              {admin && (
                <button type="submit" disabled={enregistrement} className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 disabled:opacity-50 text-sm">
                  <Save size={14} /> {tc.save}
                </button>
              )}
            </fieldset>
          </form>

          {admin && (
            <div className="border-t dark:border-gray-700 pt-4 mt-6">
              <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{tc.testTitle}</p>
              <div className="flex flex-wrap gap-2">
                <input type="email" value={destinataireTest} onChange={e => setDestinataireTest(e.target.value)} placeholder={tc.testPlaceholder}
                  aria-label={tc.testTitle} className={`${classeChamp} max-w-xs`} />
                <button type="button" onClick={envoyerTest} disabled={!destinataireTest || testEnCours}
                  className="flex items-center gap-2 border border-purple-600 text-purple-700 dark:text-purple-300 px-4 py-2 rounded-lg hover:bg-purple-50 dark:hover:bg-purple-900/30 disabled:opacity-50 text-sm">
                  <Send size={14} /> {tc.sendTest}
                </button>
              </div>
              <p className="text-xs text-gray-500 mt-1">{tc.testHelp}</p>
            </div>
          )}
        </div>
      )}

      {/* ── Modèles ── */}
      {catalogue && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
          <h2 className="text-lg font-semibold dark:text-white flex items-center gap-2">
            <Mail size={20} className="text-purple-600" /> {tc.templatesTitle}
          </h2>
          <p className="text-sm text-gray-500 mt-1 mb-4">{tc.templatesSubtitle}</p>

          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs uppercase text-gray-500">
                <th className="py-2 font-medium">{tc.type}</th>
                {catalogue.langues.map(l => <th key={l} className="py-2 font-medium">{tc.languages[l]}</th>)}
              </tr>
            </thead>
            <tbody>
              {catalogue.types.map(({ type }) => (
                <tr key={type} className="border-t dark:border-gray-700">
                  <td className="py-2 font-medium text-gray-900 dark:text-white">{tc.types[type]}</td>
                  {catalogue.langues.map(langue => {
                    const modele = modeles.find(m => m.type === type && m.langue === langue);
                    return (
                      <td key={langue} className="py-2">
                        <div className="flex items-center gap-2">
                          <span className={`px-2 py-0.5 rounded text-xs font-medium ${modele ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'}`}>
                            {modele ? tc.custom : tc.byDefault}
                          </span>
                          <button onClick={() => ouvrir(type, langue)} title={modele ? tc.edit : tc.customize} aria-label={`${modele ? tc.edit : tc.customize} — ${tc.types[type]} (${tc.languages[langue]})`}
                            className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded">
                            <Pencil size={14} />
                          </button>
                          {modele && (
                            <button onClick={() => supprimer(modele)} title={tc.resetToDefault} aria-label={`${tc.resetToDefault} — ${tc.types[type]} (${tc.languages[langue]})`}
                              className="p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 rounded">
                              <RotateCcw size={14} />
                            </button>
                          )}
                        </div>
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* ── Édition d'un modèle ── */}
      {edition && typeEdite && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-5xl max-h-[92vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-bold dark:text-white">{tc.types[edition.type]} — {tc.languages[edition.langue]}</h3>
              <button onClick={() => setEdition(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tc.close}>
                <X size={18} />
              </button>
            </div>

            <form onSubmit={enregistrerModele} className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2 space-y-4">
                <Champ label={`${tc.subject} *`}>
                  <input value={edition.donnees.objet} onChange={e => modifierModele({ objet: e.target.value })} required maxLength={255} className={classeChamp} {...suivreChamp('objet')} />
                </Champ>
                <Champ label={`${tc.content} *`} aide={tc.formattingHelp}>
                  <textarea rows={12} value={edition.donnees.contenu} onChange={e => modifierModele({ contenu: e.target.value })} required maxLength={20000}
                    className={`${classeChamp} font-mono`} {...suivreChamp('contenu')} />
                </Champ>
                {typeEdite.piece_jointe && (
                  <Champ label={tc.fileName} aide={tc.fileNameHelp}>
                    <input value={edition.donnees.nom_fichier ?? ''} onChange={e => modifierModele({ nom_fichier: e.target.value })} maxLength={255} className={classeChamp} {...suivreChamp('nom_fichier')} />
                  </Champ>
                )}
                <Champ label={tc.replyTo} aide={tc.templateReplyToHelp}>
                  <input type="email" value={edition.donnees.repondre_a ?? ''} onChange={e => modifierModele({ repondre_a: e.target.value })} maxLength={255} className={classeChamp} />
                </Champ>
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                  <input type="checkbox" checked={edition.donnees.accuse_lecture} onChange={e => modifierModele({ accuse_lecture: e.target.checked })} className="rounded" />
                  {tc.readReceipt}
                </label>
              </div>

              <aside>
                <p className="text-sm font-medium text-gray-700 dark:text-gray-300">{tc.variables}</p>
                <p className="text-xs text-gray-500 mb-2">{tc.variablesHelp}</p>
                <div className="flex flex-wrap gap-1.5">
                  {variablesAffichees.map(v => (
                    <button key={v} type="button" title={`{${v}}`} onMouseDown={e => e.preventDefault()} onClick={() => inserer(v)}
                      className="px-2 py-1 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-purple-100 hover:text-purple-700">
                      {libelleVariable(v)}
                    </button>
                  ))}
                </div>
              </aside>

              {erreurEdition && <p className="lg:col-span-3 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreurEdition}</p>}

              <div className="lg:col-span-3 flex flex-wrap justify-between gap-2">
                <div className="flex flex-wrap gap-2">
                  <button type="button" onClick={previsualiser} className="flex items-center gap-1 px-3 py-2 text-sm rounded-lg border dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
                    <Eye size={14} /> {tc.preview}
                  </button>
                  <button type="button" onClick={() => modifierModele({ objet: typeEdite.defauts[edition.langue].objet, contenu: typeEdite.defauts[edition.langue].contenu })}
                    className="flex items-center gap-1 px-3 py-2 text-sm rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                    <RotateCcw size={14} /> {tc.restoreDefault}
                  </button>
                </div>
                <div className="flex gap-2">
                  <button type="button" onClick={() => setEdition(null)} className="px-4 py-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg text-sm">{tc.cancel}</button>
                  <button type="submit" className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">{tc.save}</button>
                </div>
              </div>
            </form>

            {apercu && (
              <div className="mt-6 border-t dark:border-gray-700 pt-4 space-y-2">
                <p className="text-xs text-gray-500">{apercu.document ? `${tc.previewWith} ${apercu.document}` : tc.previewSample}</p>
                <p className="text-sm dark:text-white"><strong>{tc.subject} :</strong> {apercu.objet}</p>
                {apercu.nom_fichier && <p className="text-sm dark:text-white"><strong>{tc.attachment} :</strong> {apercu.nom_fichier}</p>}
                {apercu.inconnues.length > 0 && (
                  <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">
                    {tc.unknownVariables} {apercu.inconnues.map(v => `{${v}}`).join(', ')}
                  </p>
                )}
                {/* Bac à sable sans script : seul le rendu HTML du courriel s'affiche. */}
                <iframe title={tc.preview} sandbox="" srcDoc={apercu.html} className="w-full h-96 border dark:border-gray-600 rounded-lg bg-white" />
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
