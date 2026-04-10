import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Package,
  Users,
  MapPin,
  ClipboardList,
  ArrowRightLeft,
  RefreshCw,
  Bell,
  AlertTriangle,
  Calendar,
  TrendingUp,
  FileText,
  ShoppingCart,
  Truck,
  Euro,
  CreditCard,
  BarChart2,
  Minus,
} from 'lucide-react';
import { getScans, getProduits, getSecteurs, getEmployes } from '@/services/api';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';
const REFRESH_INTERVAL = 30000; // 30 secondes

function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored) {
    try {
      const data = JSON.parse(stored);
      if (data.token) headers['Authorization'] = `Bearer ${data.token}`;
      if (data.tenant?.slug) headers['X-Tenant-Slug'] = data.tenant.slug;
    } catch {
      /* ignore */
    }
  }
  return headers;
}

// ─── Interfaces ───────────────────────────────────────────────────────────────

interface LegacyStats {
  scans: number;
  produits: number;
  secteurs: number;
  employes: number;
}

interface VentesStats {
  devis: { total: number; en_attente: number; acceptes: number };
  commandes_clients: { total: number; en_cours: number; montant_total: string };
  factures: { total: number; impayees: number; en_retard: number; montant_impaye: string };
  bons_livraison: { total: number; en_attente: number };
  tournees: { total: number; en_cours: number };
}

interface AchatsStats {
  commandes_fournisseurs: { total: number; en_attente: number; montant_total: string };
}

interface FinanceStats {
  chiffre_affaires: string;
  encaisse: string;
  montant_impaye: string;
  depenses_achats: string;
  marge_brute: string;
}

interface InventaireStats {
  produits: { total: number; en_alerte: number; rupture: number };
  mouvements: { total_periode: number; entrees: number; sorties: number };
}

interface AlerteInfo {
  count: number;
}

interface NotificationInfo {
  count: number;
}

interface PlanificationInfo {
  upcoming: number;
}

interface ApprobationInfo {
  en_attente: number;
}

// ─── Valeurs par défaut ───────────────────────────────────────────────────────

const defaultVentes: VentesStats = {
  devis: { total: 0, en_attente: 0, acceptes: 0 },
  commandes_clients: { total: 0, en_cours: 0, montant_total: '0' },
  factures: { total: 0, impayees: 0, en_retard: 0, montant_impaye: '0' },
  bons_livraison: { total: 0, en_attente: 0 },
  tournees: { total: 0, en_cours: 0 },
};

const defaultAchats: AchatsStats = {
  commandes_fournisseurs: { total: 0, en_attente: 0, montant_total: '0' },
};

const defaultFinance: FinanceStats = {
  chiffre_affaires: '0',
  encaisse: '0',
  montant_impaye: '0',
  depenses_achats: '0',
  marge_brute: '0',
};

const defaultInventaire: InventaireStats = {
  produits: { total: 0, en_alerte: 0, rupture: 0 },
  mouvements: { total_periode: 0, entrees: 0, sorties: 0 },
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

const formatCurrency = (value: string | number) =>
  new Intl.NumberFormat('fr-FR', {
    style: 'currency',
    currency: 'EUR',
    maximumFractionDigits: 0,
  }).format(Number(value));

const formatCount = (value: number) => new Intl.NumberFormat('fr-FR').format(value);

// ─── Skeleton ─────────────────────────────────────────────────────────────────

function SkeletonCard() {
  return (
    <div className="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 p-5 animate-pulse">
      <div className="w-10 h-10 bg-gray-200 dark:bg-gray-700 rounded-xl mb-3" />
      <div className="h-3 w-20 bg-gray-200 dark:bg-gray-700 rounded mb-2" />
      <div className="h-7 w-16 bg-gray-200 dark:bg-gray-700 rounded" />
    </div>
  );
}

// ─── KPI Card ─────────────────────────────────────────────────────────────────

interface KpiCardProps {
  label: string;
  value: string;
  subLabel?: string;
  icon: React.ElementType;
  accentBg: string;   // ex: "bg-blue-600"
  iconBg: string;     // ex: "bg-blue-50 dark:bg-blue-900/30"
  iconColor: string;  // ex: "text-blue-600"
  onClick?: () => void;
}

function KpiCard({ label, value, subLabel, icon: Icon, accentBg, iconBg, iconColor, onClick }: KpiCardProps) {
  return (
    <div
      className="group bg-white dark:bg-gray-800 rounded-2xl shadow-sm hover:shadow-md border border-gray-100 dark:border-gray-700 p-5 cursor-pointer hover:scale-[1.02] transition-all duration-200 overflow-hidden relative"
      onClick={onClick}
    >
      <div className={`absolute left-0 top-0 bottom-0 w-1 rounded-l-2xl ${accentBg}`} />
      <div className={`${iconBg} p-2.5 rounded-xl w-fit mb-3`}>
        <Icon className={`${iconColor} w-5 h-5`} />
      </div>
      <p className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">
        {label}
      </p>
      <p className="text-2xl font-bold text-gray-900 dark:text-white">{value}</p>
      {subLabel && <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">{subLabel}</p>}
    </div>
  );
}

// ─── Section Header ───────────────────────────────────────────────────────────

interface SectionHeaderProps {
  title: string;
  dotColor: string; // ex: "bg-blue-600"
}

function SectionHeader({ title, dotColor }: SectionHeaderProps) {
  return (
    <div className="flex items-center gap-2.5 mb-4 mt-8">
      <span className={`w-3 h-3 rounded-full ${dotColor}`} />
      <h2 className="text-base font-semibold text-gray-700 dark:text-gray-200">{title}</h2>
    </div>
  );
}

// ─── Dashboard ────────────────────────────────────────────────────────────────

export default function Dashboard() {
  const navigate = useNavigate();

  // Legacy state (fallback)
  const [legacyStats, setLegacyStats] = useState<LegacyStats>({
    scans: 0,
    produits: 0,
    secteurs: 0,
    employes: 0,
  });

  // Nouveaux états
  const [ventes, setVentes] = useState<VentesStats>(defaultVentes);
  const [achats, setAchats] = useState<AchatsStats>(defaultAchats);
  const [finance, setFinance] = useState<FinanceStats>(defaultFinance);
  const [inventaire, setInventaire] = useState<InventaireStats>(defaultInventaire);

  // Actions / notifications
  const [alertes, setAlertes] = useState<AlerteInfo>({ count: 0 });
  const [notifications, setNotifications] = useState<NotificationInfo>({ count: 0 });
  const [planifications, setPlanifications] = useState<PlanificationInfo>({ upcoming: 0 });
  const [approbations, setApprobations] = useState<ApprobationInfo>({ en_attente: 0 });

  const [loading, setLoading] = useState(true);
  const [lastUpdate, setLastUpdate] = useState<Date>(new Date());
  const [autoRefresh, setAutoRefresh] = useState(true);

  const loadStats = useCallback(async () => {
    try {
      // ── Nouvel endpoint unifié ──────────────────────────────────────────────
      const dashboardRes = await fetch(`${API_BASE_URL}/dashboard?period=month`, {
        headers: getAuthHeaders(),
      });

      if (dashboardRes.ok) {
        const data = await dashboardRes.json();

        // Nouvelles sections
        if (data.ventes) setVentes({ ...defaultVentes, ...data.ventes });
        if (data.achats) setAchats({ ...defaultAchats, ...data.achats });
        if (data.finance) setFinance({ ...defaultFinance, ...data.finance });
        if (data.inventaire) setInventaire({ ...defaultInventaire, ...data.inventaire });

        // Legacy / actions
        if (data.actions) {
          setAlertes({ count: data.actions.alertes ?? 0 });
          setNotifications({ count: data.actions.notifications ?? 0 });
          setPlanifications({ upcoming: data.actions.planifications ?? 0 });
          setApprobations({ en_attente: data.actions.approbations ?? 0 });
        }
      } else {
        // ── Fallback : ancien endpoint /dashboard/stats ─────────────────────
        const legacyRes = await fetch(`${API_BASE_URL}/dashboard/stats`, {
          headers: getAuthHeaders(),
        });

        if (legacyRes.ok) {
          const data = await legacyRes.json();

          setLegacyStats({
            scans: data.inventaire?.scans ?? 0,
            produits: data.inventaire?.produits ?? 0,
            secteurs: data.inventaire?.secteurs ?? 0,
            employes: data.inventaire?.employes ?? 0,
          });

          setInventaire((prev) => ({
            ...prev,
            produits: {
              ...prev.produits,
              total: data.inventaire?.produits ?? 0,
            },
          }));

          if (data.actions) {
            setAlertes({ count: data.actions.alertes ?? 0 });
            setNotifications({ count: data.actions.notifications ?? 0 });
            setPlanifications({ upcoming: data.actions.planifications ?? 0 });
            setApprobations({ en_attente: data.actions.approbations ?? 0 });
          }
        } else {
          // ── Fallback ultime : appels individuels ──────────────────────────
          const [scans, produits, secteurs, employes] = await Promise.all([
            getScans(),
            getProduits(),
            getSecteurs(),
            getEmployes(),
          ]);
          const produitCount = produits.length;
          setLegacyStats({
            scans: scans.length,
            produits: produitCount,
            secteurs: secteurs.length,
            employes: employes.length,
          });
          setInventaire((prev) => ({
            ...prev,
            produits: { ...prev.produits, total: produitCount },
          }));
        }
      }

      // ── Notifications non lues (indépendant) ────────────────────────────────
      const notifRes = await fetch(`${API_BASE_URL}/notifications/unread-count`, {
        headers: getAuthHeaders(),
      });
      if (notifRes.ok) {
        const data = await notifRes.json();
        setNotifications({ count: data.count ?? 0 });
      }

      // ── Transferts planifiés ────────────────────────────────────────────────
      const planifRes = await fetch(`${API_BASE_URL}/transferts-planifies/stats`, {
        headers: getAuthHeaders(),
      });
      if (planifRes.ok) {
        const data = await planifRes.json();
        setPlanifications({ upcoming: data.a_venir_7_jours ?? 0 });
      }

      // ── Approbations en attente ─────────────────────────────────────────────
      const approbRes = await fetch(`${API_BASE_URL}/approbations/stats`, {
        headers: getAuthHeaders(),
      });
      if (approbRes.ok) {
        const data = await approbRes.json();
        setApprobations({ en_attente: data.en_attente ?? 0 });
      }

      setLastUpdate(new Date());
    } catch (error) {
      console.error('Erreur chargement stats:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadStats();
  }, [loadStats]);

  useEffect(() => {
    if (!autoRefresh) return;
    const interval = setInterval(loadStats, REFRESH_INTERVAL);
    return () => clearInterval(interval);
  }, [autoRefresh, loadStats]);

  // Cartes "actions requises" (bannière contextuelle)
  const hasActions =
    alertes.count > 0 || approbations.en_attente > 0 || notifications.count > 0;

  return (
    <div>
      {/* ── Header ────────────────────────────────────────────────────────── */}
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-gray-100">Tableau de bord</h1>
        <div className="flex items-center gap-4">
          <span className="text-sm text-gray-400 dark:text-gray-500">
            Mis à jour : {lastUpdate.toLocaleTimeString('fr-FR')}
          </span>
          <button
            onClick={() => setAutoRefresh(!autoRefresh)}
            className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm transition-colors ${
              autoRefresh
                ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
            }`}
          >
            <RefreshCw
              size={14}
              className={autoRefresh ? 'animate-spin' : ''}
              style={{ animationDuration: '3s' }}
            />
            {autoRefresh ? 'Auto' : 'Manuel'}
          </button>
          <button
            onClick={loadStats}
            className="p-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50 transition-colors"
          >
            <RefreshCw size={18} />
          </button>
        </div>
      </div>

      {/* ── Skeleton ──────────────────────────────────────────────────────── */}
      {loading ? (
        <>
          {[0, 1, 2, 3].map((s) => (
            <div key={s} className="mb-8">
              <div className="h-5 w-32 bg-gray-200 dark:bg-gray-700 rounded mb-4 animate-pulse" />
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {[0, 1, 2, 3].map((c) => (
                  <SkeletonCard key={c} />
                ))}
              </div>
            </div>
          ))}
        </>
      ) : (
        <>
          {/* ── Bannière Actions requises ──────────────────────────────────── */}
          {hasActions && (
            <div className="mb-6 p-4 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-2xl flex flex-wrap gap-3 items-center">
              <AlertTriangle className="text-orange-500 shrink-0" size={18} />
              <span className="text-sm font-medium text-orange-700 dark:text-orange-300 mr-2">
                Actions requises
              </span>
              {alertes.count > 0 && (
                <button
                  onClick={() => navigate('/alertes')}
                  className="flex items-center gap-1.5 px-3 py-1 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded-full text-xs font-medium hover:bg-red-200 dark:hover:bg-red-900/50 transition-colors"
                >
                  <AlertTriangle size={12} />
                  {alertes.count} alerte{alertes.count > 1 ? 's' : ''}
                </button>
              )}
              {notifications.count > 0 && (
                <button
                  onClick={() => navigate('/notifications')}
                  className="flex items-center gap-1.5 px-3 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded-full text-xs font-medium hover:bg-blue-200 dark:hover:bg-blue-900/50 transition-colors"
                >
                  <Bell size={12} />
                  {notifications.count} notification{notifications.count > 1 ? 's' : ''}
                </button>
              )}
              {approbations.en_attente > 0 && (
                <button
                  onClick={() => navigate('/approbations')}
                  className="flex items-center gap-1.5 px-3 py-1 bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 rounded-full text-xs font-medium hover:bg-orange-200 dark:hover:bg-orange-900/50 transition-colors"
                >
                  <RefreshCw size={12} />
                  {approbations.en_attente} approbation{approbations.en_attente > 1 ? 's' : ''}
                </button>
              )}
              {planifications.upcoming > 0 && (
                <button
                  onClick={() => navigate('/planification')}
                  className="flex items-center gap-1.5 px-3 py-1 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded-full text-xs font-medium hover:bg-purple-200 dark:hover:bg-purple-900/50 transition-colors"
                >
                  <Calendar size={12} />
                  {planifications.upcoming} planifié{planifications.upcoming > 1 ? 's' : ''} (7j)
                </button>
              )}
            </div>
          )}

          {/* ── Section Ventes (Blue 600) ──────────────────────────────────── */}
          <SectionHeader title="Ventes" dotColor="bg-blue-600" />
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <KpiCard
              label="CA du mois"
              value={formatCurrency(finance.chiffre_affaires)}
              subLabel="chiffre d'affaires"
              icon={TrendingUp}
              accentBg="bg-blue-600"
              iconBg="bg-blue-50 dark:bg-blue-900/30"
              iconColor="text-blue-600"
              onClick={() => navigate('/ventes/factures')}
            />
            <KpiCard
              label="Devis en cours"
              value={formatCount(ventes.devis.en_attente)}
              subLabel={`${formatCount(ventes.devis.total)} au total`}
              icon={FileText}
              accentBg="bg-blue-600"
              iconBg="bg-blue-50 dark:bg-blue-900/30"
              iconColor="text-blue-600"
              onClick={() => navigate('/ventes/devis')}
            />
            <KpiCard
              label="Commandes"
              value={formatCount(ventes.commandes_clients.en_cours)}
              subLabel="en cours"
              icon={ShoppingCart}
              accentBg="bg-blue-600"
              iconBg="bg-blue-50 dark:bg-blue-900/30"
              iconColor="text-blue-600"
              onClick={() => navigate('/ventes/commandes')}
            />
            <KpiCard
              label="Factures impayées"
              value={formatCount(ventes.factures.impayees)}
              subLabel={ventes.factures.en_retard > 0 ? `dont ${ventes.factures.en_retard} en retard` : undefined}
              icon={CreditCard}
              accentBg="bg-blue-600"
              iconBg="bg-blue-50 dark:bg-blue-900/30"
              iconColor="text-blue-600"
              onClick={() => navigate('/ventes/factures')}
            />
          </div>

          {/* ── Section Achats (Orange 500) ────────────────────────────────── */}
          <SectionHeader title="Achats" dotColor="bg-orange-500" />
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <KpiCard
              label="Commandes fournisseurs"
              value={formatCount(achats.commandes_fournisseurs.total)}
              subLabel="au total"
              icon={Truck}
              accentBg="bg-orange-500"
              iconBg="bg-orange-50 dark:bg-orange-900/30"
              iconColor="text-orange-500"
              onClick={() => navigate('/achats/commandes')}
            />
            <KpiCard
              label="En attente"
              value={formatCount(achats.commandes_fournisseurs.en_attente)}
              subLabel="à réceptionner"
              icon={ClipboardList}
              accentBg="bg-orange-500"
              iconBg="bg-orange-50 dark:bg-orange-900/30"
              iconColor="text-orange-500"
              onClick={() => navigate('/achats/commandes')}
            />
            <KpiCard
              label="Montant engagé"
              value={formatCurrency(achats.commandes_fournisseurs.montant_total)}
              subLabel="commandes en cours"
              icon={Euro}
              accentBg="bg-orange-500"
              iconBg="bg-orange-50 dark:bg-orange-900/30"
              iconColor="text-orange-500"
              onClick={() => navigate('/achats/commandes')}
            />
            <KpiCard
              label="Dépenses achats"
              value={formatCurrency(finance.depenses_achats)}
              subLabel="ce mois"
              icon={BarChart2}
              accentBg="bg-orange-500"
              iconBg="bg-orange-50 dark:bg-orange-900/30"
              iconColor="text-orange-500"
              onClick={() => navigate('/achats')}
            />
          </div>

          {/* ── Section Finance (Green 600) ────────────────────────────────── */}
          <SectionHeader title="Finance" dotColor="bg-green-600" />
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <KpiCard
              label="Encaissé"
              value={formatCurrency(finance.encaisse)}
              subLabel="ce mois"
              icon={Euro}
              accentBg="bg-green-600"
              iconBg="bg-green-50 dark:bg-green-900/30"
              iconColor="text-green-600"
              onClick={() => navigate('/finance')}
            />
            <KpiCard
              label="Impayé"
              value={formatCurrency(finance.montant_impaye)}
              subLabel="à recouvrer"
              icon={CreditCard}
              accentBg="bg-green-600"
              iconBg="bg-green-50 dark:bg-green-900/30"
              iconColor="text-green-600"
              onClick={() => navigate('/ventes/factures')}
            />
            <KpiCard
              label="Dépenses achats"
              value={formatCurrency(finance.depenses_achats)}
              subLabel="ce mois"
              icon={Minus}
              accentBg="bg-green-600"
              iconBg="bg-green-50 dark:bg-green-900/30"
              iconColor="text-green-600"
              onClick={() => navigate('/achats')}
            />
            <KpiCard
              label="Marge brute"
              value={formatCurrency(finance.marge_brute)}
              subLabel="ce mois"
              icon={TrendingUp}
              accentBg="bg-green-600"
              iconBg="bg-green-50 dark:bg-green-900/30"
              iconColor="text-green-600"
              onClick={() => navigate('/finance')}
            />
          </div>

          {/* ── Section Inventaire (Purple 600) ───────────────────────────── */}
          <SectionHeader title="Inventaire" dotColor="bg-purple-600" />
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <KpiCard
              label="Produits"
              value={formatCount(inventaire.produits.total || legacyStats.produits)}
              subLabel={`${formatCount(legacyStats.secteurs || 0)} secteurs`}
              icon={Package}
              accentBg="bg-purple-600"
              iconBg="bg-purple-50 dark:bg-purple-900/30"
              iconColor="text-purple-600"
              onClick={() => navigate('/produits')}
            />
            <KpiCard
              label="Alertes stock"
              value={formatCount(inventaire.produits.en_alerte || alertes.count)}
              subLabel="sous le seuil critique"
              icon={AlertTriangle}
              accentBg="bg-purple-600"
              iconBg="bg-purple-50 dark:bg-purple-900/30"
              iconColor="text-purple-600"
              onClick={() => navigate('/alertes')}
            />
            <KpiCard
              label="Mouvements"
              value={formatCount(inventaire.mouvements.total_periode || legacyStats.scans)}
              subLabel="ce mois"
              icon={ArrowRightLeft}
              accentBg="bg-purple-600"
              iconBg="bg-purple-50 dark:bg-purple-900/30"
              iconColor="text-purple-600"
              onClick={() => navigate('/relocalisation')}
            />
            <KpiCard
              label="Ruptures"
              value={formatCount(inventaire.produits.rupture)}
              subLabel="en rupture de stock"
              icon={MapPin}
              accentBg="bg-purple-600"
              iconBg="bg-purple-50 dark:bg-purple-900/30"
              iconColor="text-purple-600"
              onClick={() => navigate('/produits')}
            />
          </div>

          {/* ── Ligne Employés / Planification (discret) ──────────────────── */}
          <div className="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <KpiCard
              label="Employés"
              value={formatCount(legacyStats.employes)}
              subLabel="actifs"
              icon={Users}
              accentBg="bg-gray-400"
              iconBg="bg-gray-50 dark:bg-gray-700"
              iconColor="text-gray-500"
              onClick={() => navigate('/employes')}
            />
            <KpiCard
              label="Planifiés (7j)"
              value={formatCount(planifications.upcoming)}
              subLabel="transferts à venir"
              icon={Calendar}
              accentBg="bg-gray-400"
              iconBg="bg-gray-50 dark:bg-gray-700"
              iconColor="text-gray-500"
              onClick={() => navigate('/planification')}
            />
          </div>
        </>
      )}
    </div>
  );
}
