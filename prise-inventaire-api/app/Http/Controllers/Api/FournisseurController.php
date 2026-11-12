<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuration;
use App\Models\Fournisseur;
use App\Models\Transporteur;
use App\Support\ExportListe;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FournisseurController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $tenantId = auth()->user()->tenant_id;
        $query = Fournisseur::where('tenant_id', $tenantId);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('raison_sociale', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('actif')) {
            $query->where('actif', $request->boolean('actif'));
        }

        $query->orderBy('raison_sociale');
        // Export Excel de toute la liste filtrée (S5), avec les mêmes droits que sa lecture.
        if (ExportListe::demande($request)) {
            return ExportListe::reponse($query, 'fournisseurs', 'Fournisseurs', [
                ['Code', 'Code', fn (Fournisseur $f) => $f->code],
                ['Raison sociale', 'Company name', fn (Fournisseur $f) => $f->raison_sociale],
                ['Adresse', 'Address', fn (Fournisseur $f) => $f->adresse],
                ['Téléphone', 'Phone', fn (Fournisseur $f) => $f->telephone],
                ['Courriel', 'Email', fn (Fournisseur $f) => $f->email],
                ['Contact', 'Contact', fn (Fournisseur $f) => $f->contact_nom],
                ['Téléphone du contact', 'Contact phone', fn (Fournisseur $f) => $f->contact_telephone],
                ['Devise', 'Currency', fn (Fournisseur $f) => $f->devise],
                ['Actif', 'Active', fn (Fournisseur $f) => (bool) $f->actif],
            ]);
        }
        $fournisseurs = $query->paginate($request->get('per_page', 15));

        return response()->json($fournisseurs);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:20', TenantRule::unique('fournisseurs', 'code')],
            'raison_sociale' => 'required|string|max:255',
            'adresse' => 'nullable|string',
            'telephone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'contact_nom' => 'nullable|string|max:100',
            'contact_telephone' => 'nullable|string|max:20',
            'conditions_paiement' => 'nullable|string|max:100',
            'devise' => 'nullable|string|max:10',
            'actif' => 'boolean',
            // Transporteur qui livre ce fournisseur, repris par ses commandes.
            'transporteur_id' => Transporteur::regle(),
        ]);

        if (empty($validated['code'])) {
            $config = Configuration::pourEntite('fournisseur', $tenantId);
            if ($config && $config->auto_increment) {
                $validated['code'] = $config->genererNumero();
                $config->incrementer();
            } else {
                return response()->json(['success' => false, 'message' => __('Numéro requis')], 422);
            }
        }

        $validated['tenant_id'] = $tenantId;
        $fournisseur = Fournisseur::create($validated);

        return response()->json($fournisseur, 201);
    }

    public function show(Fournisseur $fournisseur): JsonResponse
    {
        $fournisseur->load(['produits', 'commandes' => function ($query) {
            $query->latest()->limit(10);
        }]);

        return response()->json($fournisseur);
    }

    public function update(Request $request, Fournisseur $fournisseur): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:20', TenantRule::unique('fournisseurs', 'code', $fournisseur->id)],
            'raison_sociale' => 'required|string|max:255',
            'adresse' => 'nullable|string',
            'telephone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'contact_nom' => 'nullable|string|max:100',
            'contact_telephone' => 'nullable|string|max:20',
            'conditions_paiement' => 'nullable|string|max:100',
            'devise' => 'nullable|string|max:10',
            'actif' => 'boolean',
            'transporteur_id' => Transporteur::regle($fournisseur->transporteur_id),
        ]);

        $fournisseur->update($validated);

        return response()->json($fournisseur);
    }

    public function destroy(Fournisseur $fournisseur): JsonResponse
    {
        if ($fournisseur->commandes()->exists()) {
            return response()->json([
                'message' => __('Impossible de supprimer ce fournisseur car il a des commandes associées.'),
            ], 422);
        }

        $fournisseur->delete();

        return response()->json(['message' => __('Fournisseur supprimé avec succès.')]);
    }

    public function listActifs(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $fournisseurs = Fournisseur::where('tenant_id', $tenantId)->where('actif', true)
            ->orderBy('raison_sociale')
            // La devise sert au formulaire de commande : sans elle, toute commande partait dans la devise de
            // l'entreprise, sans taux de change, même chez un fournisseur qui facture en dollars.
            // Le transporteur aussi : proposé sur la commande (phase 1.11b).
            ->get(['id', 'code', 'raison_sociale', 'devise', 'transporteur_id']);

        return response()->json($fournisseurs);
    }
}
