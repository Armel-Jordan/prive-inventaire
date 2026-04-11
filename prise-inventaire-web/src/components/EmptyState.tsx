/**
 * EmptyState — état vide unifié pour toutes les pages.
 *
 * Usage :
 *   <EmptyState title="Aucun mouvement enregistré" />
 *   <EmptyState icon="📦" title="Aucun produit" subtitle="Commencez par ajouter un produit." />
 */
export default function EmptyState({
  icon,
  title,
  subtitle,
}: {
  icon?: string;
  title: string;
  subtitle?: string;
}) {
  return (
    <div className="flex flex-col items-center justify-center py-16 text-center">
      {icon && <div className="text-5xl mb-4 opacity-60">{icon}</div>}
      <p className="text-gray-500 dark:text-gray-400 font-medium">{title}</p>
      {subtitle && (
        <p className="text-sm text-gray-400 dark:text-gray-500 mt-1">{subtitle}</p>
      )}
    </div>
  );
}
