<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Libellés et couleurs choisis par l'entreprise pour un statut système d'un type de document (voir App\Support\StatutsDocuments). */
class StatutDocumentReglage extends Model
{
    use BelongsToTenant;

    protected $table = 'statuts_documents_reglages';

    protected $fillable = ['tenant_id', 'document', 'code', 'libelle_fr', 'libelle_en', 'couleur_fond', 'couleur_texte'];
}
