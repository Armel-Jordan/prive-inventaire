/**
 * PageSkeleton — état de chargement unifié pour toutes les pages.
 *
 * Usage :
 *   if (loading) return <PageSkeleton />;
 *   if (loading) return <PageSkeleton rows={3} kpis={2} />;
 */
export default function PageSkeleton({
  kpis = 4,
  rows = 6,
}: {
  kpis?: number;
  rows?: number;
}) {
  return (
    <div className="space-y-6 animate-pulse">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="space-y-2">
          <div className="h-7 w-48 bg-gray-200 dark:bg-gray-700 rounded-lg" />
          <div className="h-4 w-72 bg-gray-100 dark:bg-gray-800 rounded" />
        </div>
        <div className="h-9 w-32 bg-gray-200 dark:bg-gray-700 rounded-lg" />
      </div>

      {/* KPI cards */}
      {kpis > 0 && (
        <div className={`grid gap-4 grid-cols-2 lg:grid-cols-${kpis}`}>
          {Array.from({ length: kpis }).map((_, i) => (
            <div
              key={i}
              className="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-sm border border-gray-100 dark:border-gray-700 space-y-3"
            >
              <div className="h-4 w-24 bg-gray-200 dark:bg-gray-700 rounded" />
              <div className="h-8 w-16 bg-gray-200 dark:bg-gray-700 rounded" />
            </div>
          ))}
        </div>
      )}

      {/* Table / list */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
        {/* Table header */}
        <div className="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
          <div className="h-5 w-40 bg-gray-200 dark:bg-gray-700 rounded" />
        </div>

        {/* Rows */}
        <div className="divide-y divide-gray-50 dark:divide-gray-700/50">
          {Array.from({ length: rows }).map((_, i) => (
            <div key={i} className="px-5 py-4 flex items-center gap-4">
              <div className="h-4 w-4 bg-gray-200 dark:bg-gray-700 rounded" />
              <div className="flex-1 space-y-2">
                <div
                  className="h-4 bg-gray-200 dark:bg-gray-700 rounded"
                  style={{ width: `${55 + (i % 4) * 10}%` }}
                />
                <div className="h-3 w-1/3 bg-gray-100 dark:bg-gray-800 rounded" />
              </div>
              <div className="h-6 w-20 bg-gray-100 dark:bg-gray-800 rounded-full" />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
