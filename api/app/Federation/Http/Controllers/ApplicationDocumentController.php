<?php

namespace App\Federation\Http\Controllers;

use App\Federation\Actions\AttachDocumentMetadata;
use App\Federation\Actions\ReviewDocument;
use App\Federation\Enums\DocumentReviewStatus;
use App\Federation\Enums\DocumentType;
use App\Federation\Http\Controllers\Concerns\RendersDomainExceptions;
use App\Federation\Http\Middleware\AssignRequestId;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Models\RegistrationApplication;
use App\Http\Controllers\Controller;
use LaravelJsonApi\Core\Responses\DataResponse;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\FetchMany;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\FetchOne;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\Store;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\Update;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;

class ApplicationDocumentController extends Controller
{
    use FetchMany;
    use FetchOne;
    use RendersDomainExceptions;
    use Store;
    use Update;

    public function creating(ResourceRequest $request): DataResponse
    {
        $attach = app(AttachDocumentMetadata::class);
        $data = $request->validated();
        $application = RegistrationApplication::query()->findOrFail($data['application']['id']);

        $document = $this->domain(fn () => $attach->execute(
            $application,
            $request->user(),
            DocumentType::from($data['documentType']),
            $data['fileName'],
            $data['mimeType'],
            (int) $data['sizeBytes'],
            $data['checksumSha256'],
            AssignRequestId::current($request),
        ));

        return DataResponse::make($document);
    }

    public function updating(ApplicationDocument $document, ResourceRequest $request): DataResponse
    {
        $review = app(ReviewDocument::class);
        $data = $request->validated();

        $document = $this->domain(fn () => $review->execute(
            $document,
            $request->user(),
            DocumentReviewStatus::from($data['reviewStatus']),
            $data['reviewNote'] ?? null,
            AssignRequestId::current($request),
        ));

        return DataResponse::make($document);
    }
}
