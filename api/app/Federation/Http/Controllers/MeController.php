<?php

namespace App\Federation\Http\Controllers;

use App\Federation\Auth\FederationScopes;
use App\Federation\Auth\OidcIdentity;
use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is signed in, as the federation sees them: the linked identity, the
 * capabilities derived from database roles, and the bodies they administer.
 */
class MeController extends Controller
{
    public function show(Request $request, FederationScopes $scopes): JsonResponse
    {
        $user = $request->user();
        $identity = $request->attributes->get(OidcIdentity::class);

        return response()->json([
            'data' => [
                'type' => 'federation-identities',
                'id' => (string) $user->getKey(),
                'attributes' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'issuer' => $identity?->issuer,
                    'subject' => $identity?->subject,
                    'scopes' => $scopes->for($user),
                    'administeredFederations' => $user->administeredFederations()
                        ->get()
                        ->map(fn (Federation $federation) => [
                            'id' => (string) $federation->getKey(),
                            'code' => $federation->code,
                            'name' => $federation->name,
                        ])
                        ->values(),
                    'administeredMemberOrganizations' => $user->administeredMemberOrganizations()
                        ->get()
                        ->map(fn (MemberOrganization $organization) => [
                            'id' => (string) $organization->getKey(),
                            'code' => $organization->code,
                            'name' => $organization->name,
                            'federationId' => (string) $organization->federation_id,
                        ])
                        ->values(),
                ],
            ],
        ]);
    }
}
