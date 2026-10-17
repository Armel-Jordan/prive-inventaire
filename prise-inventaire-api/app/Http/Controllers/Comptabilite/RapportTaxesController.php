<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Services\Comptabilite\RapportTaxes;
use App\Support\EntreprisePdf;
use App\Support\FichierCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Rapport des taxes d'une période (détail, par jour ou total) : à l'écran, en CSV ou en PDF. */
class RapportTaxesController extends Controller
{
    public const TYPES = ['vente' => 'Vente', 'avoir' => 'Note de crédit', 'achat' => 'Achat'];

    public const NIVEAUX = ['detail' => 'Détail de la période', 'jour' => 'Total par jour', 'periode' => 'Total de la période'];

    public function __construct(private readonly RapportTaxes $taxes) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        $donnees = $request->validate([
            'du' => ['required', 'date'],
            'au' => ['required', 'date', 'after_or_equal:du'],
            'niveau' => ['nullable', Rule::in(RapportTaxes::NIVEAUX)],
            'format' => ['nullable', 'in:csv,pdf'],
        ], ['au.after_or_equal' => 'La fin de la période ne peut pas précéder son début.']);
        $rapport = $this->taxes->rapport(Carbon::parse($donnees['du'])->toDateString(), Carbon::parse($donnees['au'])->toDateString(), $donnees['niveau'] ?? 'detail');
        $nom = "rapport-taxes-{$rapport['du']}-{$rapport['au']}";

        return match ($donnees['format'] ?? null) {
            'csv' => $this->csv($rapport, "{$nom}.csv"),
            'pdf' => Pdf::loadView('pdf.rapport-taxes', $rapport + ['types' => self::TYPES, 'niveaux' => self::NIVEAUX] + EntreprisePdf::entete())
                ->setPaper('A4', 'landscape')->setOption('isFontSubsettingEnabled', true)->stream("{$nom}.pdf"),
            default => response()->json($rapport),
        };
    }

    /** Le tableau du rapport, sa ligne de total, puis le sommaire par taxe. */
    private function csv(array $rapport, string $nom): Response
    {
        $entetes = ['Date', 'Type', 'Numéro', 'Référence', 'Tiers', 'Ventes HT', 'Achats HT'];
        foreach ($rapport['taxes'] as $taxe) {
            array_push($entetes, "{$taxe['nom']} collectée", "{$taxe['nom']} déductible");
        }
        $colonnes = function (array $ligne) use ($rapport) {
            $valeurs = [FichierCsv::montant($ligne['ventes_ht']), FichierCsv::montant($ligne['achats_ht'])];
            foreach ($rapport['taxes'] as $taxe) {
                array_push($valeurs, FichierCsv::montant($ligne['collectee'][$taxe['cle']] ?? 0), FichierCsv::montant($ligne['deductible'][$taxe['cle']] ?? 0));
            }

            return $valeurs;
        };
        $lignes = array_map(fn (array $l) => [
            $l['date'] ?? '', isset($l['type']) ? self::TYPES[$l['type']] : '', $l['numero'] ?? '', $l['reference'] ?? '', $l['tiers'] ?? '', ...$colonnes($l),
        ], $rapport['lignes']);
        if ($rapport['niveau'] !== 'periode') {
            $lignes[] = ['', 'Total', '', '', '', ...$colonnes($rapport['totaux'])];
        }
        $lignes[] = [];
        $lignes[] = ['Taxe', 'Collectée', 'Déductible', 'Différence'];
        foreach ($rapport['sommaire'] as $taxe) {
            $lignes[] = [$taxe['nom'], FichierCsv::montant($taxe['collectee']), FichierCsv::montant($taxe['deductible']), FichierCsv::montant($taxe['difference'])];
        }
        $lignes[] = ['Total', FichierCsv::montant($rapport['total']['collectee']), FichierCsv::montant($rapport['total']['deductible']), FichierCsv::montant($rapport['total']['difference'])];

        return FichierCsv::reponse($nom, $entetes, $lignes);
    }
}
