<?php

namespace App\Federation\JsonApi;

use App\Federation\JsonApi\ApplicationDocuments\ApplicationDocumentSchema;
use App\Federation\JsonApi\Federations\FederationSchema;
use App\Federation\JsonApi\FederationUsers\FederationUserSchema;
use App\Federation\JsonApi\MemberOrganizations\MemberOrganizationSchema;
use App\Federation\JsonApi\RegistrationApplications\RegistrationApplicationSchema;
use App\Federation\JsonApi\RegistrationWindows\RegistrationWindowSchema;
use App\Federation\JsonApi\Seasons\SeasonSchema;
use Illuminate\Support\Facades\Auth;
use LaravelJsonApi\Core\Server\Server as BaseServer;

/**
 * The federation's JSON:API server: same contract style as upstream's v1,
 * its own guard (OIDC bearer tokens), its own schemas and policies, no club
 * scoping. Registered in config/jsonapi.php.
 */
class Server extends BaseServer
{
    protected string $baseUri = '/api/v1/federation';

    public function serving(): void
    {
        Auth::shouldUse('oidc');
    }

    /**
     * @return array<int, class-string>
     */
    protected function allSchemas(): array
    {
        return [
            FederationSchema::class,
            SeasonSchema::class,
            MemberOrganizationSchema::class,
            RegistrationWindowSchema::class,
            RegistrationApplicationSchema::class,
            ApplicationDocumentSchema::class,
            FederationUserSchema::class,
        ];
    }
}
