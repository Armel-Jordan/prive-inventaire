import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ClipboardCheck } from 'lucide-react';
import { getConfigurationACompleter } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';

/** Rappel discret aux administrateurs tant que des réglages obligatoires de mise en route manquent. */
export default function RappelConfiguration() {
  const { t } = useLanguage();
  const { isAdmin } = usePermissions();
  const admin = isAdmin();
  const [obligatoires, setObligatoires] = useState(0);

  useEffect(() => {
    if (!admin) return;
    let actif = true;
    getConfigurationACompleter().then(d => { if (actif) setObligatoires(d.restantes.obligatoires); }).catch(() => {});
    return () => { actif = false; };
  }, [admin]);

  if (!admin || obligatoires === 0) return null;

  return (
    <div className="mb-6 flex flex-wrap items-center gap-3 px-4 py-3 rounded-lg bg-purple-50 dark:bg-purple-900/20 text-purple-900 dark:text-purple-100 text-sm">
      <ClipboardCheck size={18} className="shrink-0" />
      <span className="flex-1">{t.setup.reminder.replace('{n}', String(obligatoires))}</span>
      <Link to="/configuration-a-completer" className="font-medium underline hover:no-underline">{t.setup.reminderLink}</Link>
    </div>
  );
}
