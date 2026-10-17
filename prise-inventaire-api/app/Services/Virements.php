<?php

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\Debourse;
use App\Models\FournisseurCompteBancaire;
use App\Models\TenantParametres;
use App\Models\VirementLot;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use XMLWriter;

/**
 * Fichiers de virement aux fournisseurs (plan de parité fripv2, phase 4.5, « Gestion des TFE ») :
 * - les déboursés payés par virement (valides, avec un montant) sont à transmettre tant qu'ils ne sont dans aucun lot,
 *   ou que leur lot a été refusé par la banque ;
 * - un lot part d'un compte bancaire, dans une devise, en un fichier SEPA (pain.001.001.03) ou CSV ; il prend le
 *   prochain numéro du compte (ou celui choisi, s'il est libre) et le fichier est gardé tel quel ;
 * - chaque déboursé crédite le compte principal actif de son fournisseur (sinon le premier actif) ; tout est vérifié
 *   avant d'écrire le fichier (IBAN par sa clé, compte de l'entreprise, devise) et le lot est refusé s'il manque quoi
 *   que ce soit, la liste des problèmes à l'appui ;
 * - des coordonnées bancaires modifiées depuis moins de 30 jours sont signalées : c'est le premier signe d'une fraude
 *   au virement ;
 * - un lot refusé par la banque remet ses déboursés à transmettre.
 */
class Virements
{
    public const FORMATS = ['sepa', 'csv'];

    public const STATUTS = ['a_transmettre', 'emis', 'refuse'];

    public const JOURS_ALERTE = 30;

    public function __construct(private readonly DevisesVente $devises) {}

    /** Déboursés payés par virement selon leur état, avec le compte du fournisseur à créditer et les alertes. */
    public function liste(string $statut, ?int $compteBancaireId): array
    {
        $debourses = Debourse::query()->where('mode', 'virement')->where('statut', Debourse::VALIDE)->where('montant', '>', 0)
            ->when($compteBancaireId, fn ($q, $id) => $q->where('compte_bancaire_id', $id))
            ->when($statut === 'a_transmettre', fn ($q) => $q->where(fn ($q) => $q->whereNull('virement_statut')->orWhere('virement_statut', VirementLot::REFUSE)))
            ->when($statut !== 'a_transmettre', fn ($q) => $q->where('virement_statut', $statut))
            ->with(['fournisseur:id,code,raison_sociale', 'compteBancaire:id,nom,banque,iban,devise_code', 'lignes.facture:id,numero,numero_fournisseur', 'virementLot:id,numero,statut'])
            ->orderBy('date_debourse')->orderBy('id')->limit(500)->get();
        $comptes = $this->comptesFournisseurs($debourses->pluck('fournisseur_id')->unique()->all());
        $utilises = FournisseurCompteBancaire::query()->whereIn('id', $debourses->pluck('virement_compte_id')->filter())->get()->keyBy('id');

        return $debourses->map(function (Debourse $debourse) use ($statut, $comptes, $utilises) {
            // Transmis : le compte qui a été crédité ; sinon celui qui le sera.
            $beneficiaire = $statut === 'emis' ? $utilises->get($debourse->virement_compte_id) : ($comptes[$debourse->fournisseur_id] ?? null);

            return [
                'id' => $debourse->id,
                'numero' => $debourse->numero,
                'date_debourse' => $debourse->date_debourse->toDateString(),
                'fournisseur' => $debourse->fournisseur?->only(['id', 'code', 'raison_sociale']),
                'montant' => (float) $debourse->montant,
                'devise' => $debourse->devise,
                'compte_bancaire' => $debourse->compteBancaire?->only(['id', 'nom', 'banque']),
                'factures' => $this->factures($debourse),
                'beneficiaire' => $beneficiaire ? [
                    'id' => $beneficiaire->id, 'banque' => $beneficiaire->banque, 'titulaire' => $beneficiaire->titulaire,
                    'iban' => $beneficiaire->iban, 'bic' => $beneficiaire->bic, 'numero_compte' => $beneficiaire->numero_compte,
                    'iban_valide' => $this->ibanValide($beneficiaire->iban), 'modifie_le' => $beneficiaire->updated_at?->toIso8601String(),
                ] : null,
                'virement_statut' => $debourse->virement_statut,
                'lot' => $debourse->virementLot?->only(['id', 'numero', 'statut']),
                'alertes' => $statut === 'emis' ? [] : $this->alertes($debourse, $beneficiaire),
            ];
        })->values()->all();
    }

    /** Comptes bancaires de l'entreprise d'où partent les virements, avec leurs réglages de fichier. */
    public function comptesEntreprise(): array
    {
        return CompteBancaire::query()->where('actif', true)->orderByDesc('par_defaut')->orderBy('nom')
            ->get(['id', 'nom', 'banque', 'iban', 'bic', 'devise_code', 'virement_format', 'virement_emetteur', 'prochain_lot'])
            ->map(fn (CompteBancaire $c) => $c->toArray() + ['iban_valide' => $this->ibanValide($c->iban)])->all();
    }

    /**
     * Crée le lot et son fichier pour des déboursés d'un même compte bancaire et d'une même devise.
     *
     * @param  list<int>  $ids
     */
    public function generer(CompteBancaire $compte, array $ids, string $format, string $dateExecution, ?int $numero, ?string $emetteur, ?int $auteur): VirementLot
    {
        $debourses = Debourse::query()->whereIn('id', $ids)->with(['fournisseur', 'lignes.facture', 'virementLot'])->orderBy('date_debourse')->orderBy('id')->get();
        if ($debourses->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['debourses' => 'Déboursé introuvable.']);
        }
        $comptes = $this->comptesFournisseurs($debourses->pluck('fournisseur_id')->unique()->all());
        $problemes = [];
        if ($format === 'sepa' && ! $this->ibanValide($compte->iban)) {
            $problemes[] = "L'IBAN du compte « {$compte->nom} » manque ou n'est pas valide (Paramètres → Référentiels → Comptes bancaires).";
        }
        foreach ($debourses as $debourse) {
            $fournisseur = (string) $debourse->fournisseur?->raison_sociale;
            $beneficiaire = $comptes[$debourse->fournisseur_id] ?? null;
            $refus = match (true) {
                $debourse->statut !== Debourse::VALIDE => 'il est annulé',
                $debourse->mode !== 'virement' => "il n'est pas payé par virement",
                (float) $debourse->montant <= 0 => "il n'a pas de montant à verser",
                $debourse->virement_statut === VirementLot::EMIS => "il est déjà dans le lot n° {$debourse->virementLot?->numero}",
                (int) $debourse->compte_bancaire_id !== (int) $compte->id => "il part d'un autre compte bancaire",
                ! $beneficiaire => "{$fournisseur} n'a pas de compte bancaire actif (fiche du fournisseur, Coordonnées bancaires)",
                $format === 'sepa' && ! $this->ibanValide($beneficiaire->iban) => "l'IBAN de {$fournisseur} manque ou n'est pas valide",
                $format === 'csv' && ! $beneficiaire->iban && ! $beneficiaire->numero_compte => "le compte de {$fournisseur} n'a ni IBAN ni numéro",
                default => null,
            };
            if ($refus) {
                $problemes[] = "Déboursé {$debourse->numero} : {$refus}.";
            }
        }
        if ($debourses->map(fn (Debourse $d) => strtoupper($d->devise ?: $this->devises->reference()))->unique()->count() > 1) {
            $problemes[] = 'Un fichier de virement ne porte qu’une devise : choisissez des déboursés de la même devise.';
        }
        if ($problemes !== []) {
            throw ValidationException::withMessages(['debourses' => $problemes]);
        }

        return DB::transaction(function () use ($compte, $debourses, $comptes, $format, $dateExecution, $numero, $emetteur, $auteur) {
            $compte = CompteBancaire::query()->lockForUpdate()->findOrFail($compte->id);
            $numero ??= (int) $compte->prochain_lot;
            if (VirementLot::query()->where('compte_bancaire_id', $compte->id)->where('numero', $numero)->exists()) {
                throw ValidationException::withMessages(['numero' => "Le lot n° {$numero} de ce compte existe déjà."]);
            }
            $emetteur = trim((string) ($emetteur ?? $compte->virement_emetteur)) ?: null;
            $devise = $debourses->first()->devise ?: null;
            $total = round((float) $debourses->sum(fn (Debourse $d) => (float) $d->montant), 2);
            $lignes = $debourses->map(fn (Debourse $d) => ['debourse' => $d, 'compte' => $comptes[$d->fournisseur_id]]);
            $contenu = $format === 'sepa'
                ? $this->sepa($compte, $lignes, $numero, $dateExecution, $devise ?: $this->devises->reference(), $total, $emetteur)
                : $this->csv($lignes, $dateExecution, $devise ?: $this->devises->reference());
            $lot = VirementLot::create([
                'compte_bancaire_id' => $compte->id, 'numero' => $numero, 'format' => $format, 'date_execution' => $dateExecution,
                'devise' => $devise, 'nombre' => $debourses->count(), 'total' => $total,
                'fichier' => 'virements-'.(Str::slug($compte->nom) ?: 'compte')."-lot-{$numero}.".($format === 'sepa' ? 'xml' : 'csv'),
                'contenu' => $contenu, 'statut' => VirementLot::EMIS, 'cree_par' => $auteur,
            ]);
            foreach ($debourses as $debourse) {
                $debourse->update(['virement_lot_id' => $lot->id, 'virement_statut' => VirementLot::EMIS, 'virement_compte_id' => $comptes[$debourse->fournisseur_id]->id]);
            }
            $compte->update(['prochain_lot' => max((int) $compte->prochain_lot, $numero + 1), 'virement_format' => $format, 'virement_emetteur' => $emetteur]);

            return $lot;
        });
    }

    /** Lot refusé par la banque : ses déboursés redeviennent à transmettre. */
    public function refuser(VirementLot $lot, ?string $motif, ?int $auteur): VirementLot
    {
        if ($lot->statut === VirementLot::REFUSE) {
            throw new DomainException("Le lot n° {$lot->numero} est déjà marqué refusé.");
        }
        DB::transaction(function () use ($lot, $motif, $auteur) {
            $lot->update(['statut' => VirementLot::REFUSE, 'motif_refus' => trim((string) $motif) ?: null, 'refuse_le' => now(), 'refuse_par' => $auteur]);
            $lot->debourses()->where('virement_statut', VirementLot::EMIS)->update(['virement_statut' => VirementLot::REFUSE]);
        });

        return $lot->fresh(['compteBancaire:id,nom,banque', 'auteur:id,nom', 'auteurRefus:id,nom']);
    }

    /** IBAN : pays, clé, puis 10 à 30 caractères ; la clé vérifiée (modulo 97). */
    public function ibanValide(?string $iban): bool
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }
        $chiffres = (string) preg_replace_callback('/[A-Z]/', fn (array $m) => (string) (ord($m[0]) - 55), substr($iban, 4).substr($iban, 0, 4));
        $reste = 0;
        foreach (str_split($chiffres, 7) as $morceau) {
            $reste = (int) ($reste.$morceau) % 97;
        }

        return $reste === 1;
    }

    /** Ce qui manque ou inquiète avant de transmettre un déboursé. */
    private function alertes(Debourse $debourse, ?FournisseurCompteBancaire $beneficiaire): array
    {
        $alertes = [];
        if (! $debourse->compte_bancaire_id) {
            $alertes[] = "Le déboursé n'a pas de compte bancaire de l'entreprise.";
        }
        if (! $beneficiaire) {
            $alertes[] = "Le fournisseur n'a pas de compte bancaire actif.";
        } else {
            if ($beneficiaire->iban && ! $this->ibanValide($beneficiaire->iban)) {
                $alertes[] = "L'IBAN du fournisseur n'est pas valide.";
            }
            if (! $beneficiaire->iban && ! $beneficiaire->numero_compte) {
                $alertes[] = "Le compte du fournisseur n'a ni IBAN ni numéro.";
            }
            if ($beneficiaire->updated_at && $beneficiaire->updated_at->gt(now()->subDays(self::JOURS_ALERTE))) {
                $auteur = $beneficiaire->auteurModification?->nom;
                $alertes[] = 'Coordonnées bancaires modifiées le '.$beneficiaire->updated_at->format('d/m/Y').($auteur ? " par {$auteur}" : '')
                    .' : vérifiez-les auprès du fournisseur avant de payer.';
            }
        }

        return $alertes;
    }

    /** Compte à créditer de chaque fournisseur : le principal actif, sinon le premier actif. */
    private function comptesFournisseurs(array $fournisseurs): array
    {
        return FournisseurCompteBancaire::query()->whereIn('fournisseur_id', $fournisseurs)->where('actif', true)->with('auteurModification:id,nom')
            ->orderByDesc('principal')->orderBy('id')->get()->groupBy('fournisseur_id')->map(fn (Collection $c) => $c->first())->all();
    }

    private function factures(Debourse $debourse): string
    {
        return $debourse->lignes->map(fn ($l) => $l->facture?->numero_fournisseur ?: $l->facture?->numero)->filter()->unique()->implode(', ');
    }

    /** Texte accepté par les banques en SEPA : sans accents, caractères autorisés, longueur limitée. */
    private function texteSepa(?string $texte, int $longueur): string
    {
        $propre = (string) preg_replace("/[^A-Za-z0-9\\/\\-?:().,'+ ]/", ' ', Str::ascii((string) $texte));

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $propre)), 0, $longueur);
    }

    /** Fichier SEPA de virements (pain.001.001.03) : un ordre, un virement par déboursé. */
    private function sepa(CompteBancaire $compte, Collection $lignes, int $numero, string $date, string $devise, float $total, ?string $emetteur): string
    {
        $entreprise = $this->texteSepa(TenantParametres::query()->value('nom_entreprise') ?: $compte->titulaire ?: $compte->nom, 70);
        $euro = strtoupper($devise) === 'EUR';
        $somme = number_format($total, 2, '.', '');
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('Document');
        $xml->writeAttribute('xmlns', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->startElement('CstmrCdtTrfInitn');

        $xml->startElement('GrpHdr');
        $xml->writeElement('MsgId', mb_substr("LOT{$numero}-".now()->format('YmdHis')."-{$compte->id}", 0, 35));
        $xml->writeElement('CreDtTm', now()->format('Y-m-d\TH:i:s'));
        $xml->writeElement('NbOfTxs', (string) $lignes->count());
        $xml->writeElement('CtrlSum', $somme);
        $xml->startElement('InitgPty');
        $xml->writeElement('Nm', $entreprise);
        if ($emetteur) {
            $xml->startElement('Id');
            $xml->startElement('OrgId');
            $xml->startElement('Othr');
            $xml->writeElement('Id', $this->texteSepa($emetteur, 35));
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('PmtInf');
        $xml->writeElement('PmtInfId', "LOT-{$numero}");
        $xml->writeElement('PmtMtd', 'TRF');
        $xml->writeElement('NbOfTxs', (string) $lignes->count());
        $xml->writeElement('CtrlSum', $somme);
        if ($euro) {
            $xml->startElement('PmtTpInf');
            $xml->startElement('SvcLvl');
            $xml->writeElement('Cd', 'SEPA');
            $xml->endElement();
            $xml->endElement();
        }
        $xml->writeElement('ReqdExctnDt', Carbon::parse($date)->toDateString());
        $xml->startElement('Dbtr');
        $xml->writeElement('Nm', $entreprise);
        $xml->endElement();
        $xml->startElement('DbtrAcct');
        $xml->startElement('Id');
        $xml->writeElement('IBAN', strtoupper((string) preg_replace('/\s+/', '', (string) $compte->iban)));
        $xml->endElement();
        $xml->writeElement('Ccy', strtoupper($devise));
        $xml->endElement();
        $this->agent($xml, 'DbtrAgt', $compte->bic);
        $xml->writeElement('ChrgBr', $euro ? 'SLEV' : 'SHAR');

        foreach ($lignes as ['debourse' => $debourse, 'compte' => $beneficiaire]) {
            $xml->startElement('CdtTrfTxInf');
            $xml->startElement('PmtId');
            $xml->writeElement('EndToEndId', $this->texteSepa($debourse->numero, 35));
            $xml->endElement();
            $xml->startElement('Amt');
            $xml->startElement('InstdAmt');
            $xml->writeAttribute('Ccy', strtoupper($devise));
            $xml->text(number_format((float) $debourse->montant, 2, '.', ''));
            $xml->endElement();
            $xml->endElement();
            if ($beneficiaire->bic) {
                $this->agent($xml, 'CdtrAgt', $beneficiaire->bic);
            }
            $xml->startElement('Cdtr');
            $xml->writeElement('Nm', $this->texteSepa($beneficiaire->titulaire ?: $debourse->fournisseur?->raison_sociale, 70));
            $xml->endElement();
            $xml->startElement('CdtrAcct');
            $xml->startElement('Id');
            $xml->writeElement('IBAN', strtoupper((string) preg_replace('/\s+/', '', (string) $beneficiaire->iban)));
            $xml->endElement();
            $xml->endElement();
            $xml->startElement('RmtInf');
            $factures = $this->factures($debourse);
            $xml->writeElement('Ustrd', $this->texteSepa(($factures ? "Factures {$factures} - " : '').$debourse->numero, 140));
            $xml->endElement();
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function agent(XMLWriter $xml, string $element, ?string $bic): void
    {
        $xml->startElement($element);
        $xml->startElement('FinInstnId');
        if ($bic) {
            $xml->writeElement('BIC', strtoupper(trim($bic)));
        } else {
            $xml->startElement('Othr');
            $xml->writeElement('Id', 'NOTPROVIDED');
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
    }

    /** Fichier CSV de virements (portails des banques) : point-virgule, montants au point, sans BOM. */
    private function csv(Collection $lignes, string $date, string $devise): string
    {
        $flux = fopen('php://temp', 'r+');
        fputcsv($flux, ['Date d\'exécution', 'Bénéficiaire', 'Banque', 'BIC', 'IBAN', 'Numéro de compte', 'Montant', 'Devise', 'Référence', 'Libellé'], ';');
        foreach ($lignes as ['debourse' => $debourse, 'compte' => $beneficiaire]) {
            $factures = $this->factures($debourse);
            fputcsv($flux, [
                Carbon::parse($date)->toDateString(), $beneficiaire->titulaire ?: $debourse->fournisseur?->raison_sociale, $beneficiaire->banque,
                $beneficiaire->bic, strtoupper((string) preg_replace('/\s+/', '', (string) $beneficiaire->iban)), $beneficiaire->numero_compte,
                number_format((float) $debourse->montant, 2, '.', ''), strtoupper($devise), $debourse->numero,
                mb_substr(($factures ? "Factures {$factures}" : 'Paiement').' - '.$debourse->numero, 0, 140),
            ], ';');
        }
        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);

        return $contenu;
    }
}
