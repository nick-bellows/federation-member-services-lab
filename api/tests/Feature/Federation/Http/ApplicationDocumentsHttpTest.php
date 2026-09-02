<?php

namespace Tests\Feature\Federation\Http;

use App\Federation\Models\ApplicationDocument;

class ApplicationDocumentsHttpTest extends FederationHttpTestCase
{
    private function documentDocument(string $applicationId, array $overrides = []): array
    {
        return $this->resource(
            'application-documents',
            array_merge([
                'documentType' => 'proof_of_age',
                'fileName' => 'passport.pdf',
                'mimeType' => 'application/pdf',
                'sizeBytes' => 240000,
                'checksumSha256' => hash('sha256', 'passport'),
            ], $overrides),
            ['application' => ['type' => 'registration-applications', 'id' => $applicationId]],
        );
    }

    public function test_the_applicant_attaches_metadata_and_replacing_resets_the_review(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $first = $this->request($this->applicant, 'POST', self::BASE.'/application-documents', $this->documentDocument($id));
        $first->assertStatus(201)->assertJsonPath('data.attributes.reviewStatus', 'pending');

        $document = ApplicationDocument::query()->findOrFail($first->json('data.id'));
        $document->forceFill(['review_status' => 'accepted', 'reviewed_at' => now()])->save();

        $second = $this->request($this->applicant, 'POST', self::BASE.'/application-documents', $this->documentDocument($id, ['fileName' => 'passport-v2.pdf', 'checksumSha256' => hash('sha256', 'passport-v2')]));
        // Replacing answers 200 (the resource already existed), not 201.
        $second->assertStatus(200)
            ->assertJsonPath('data.id', (string) $document->getKey())
            ->assertJsonPath('data.attributes.fileName', 'passport-v2.pdf')
            ->assertJsonPath('data.attributes.reviewStatus', 'pending');

        $this->assertSame(1, ApplicationDocument::query()->where('registration_application_id', $id)->count());
    }

    public function test_only_the_applicant_attaches_and_only_while_editable(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $this->request($this->otherApplicant, 'POST', self::BASE.'/application-documents', $this->documentDocument($id))
            ->assertStatus(403);

        $this->attachRequiredDocumentsOverHttp($this->applicant, $id);
        $this->submitOverHttp($this->applicant, $id)->assertOk();

        $this->request($this->applicant, 'POST', self::BASE.'/application-documents', $this->documentDocument($id, ['fileName' => 'late.pdf']))
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'application_not_editable');
    }

    public function test_format_size_and_checksum_are_validated(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);

        $this->request($this->applicant, 'POST', self::BASE.'/application-documents', $this->documentDocument($id, ['mimeType' => 'application/x-msdownload']))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/mimeType');

        $this->request($this->applicant, 'POST', self::BASE.'/application-documents', $this->documentDocument($id, ['sizeBytes' => 50 * 1024 * 1024]))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/sizeBytes');

        $this->request($this->applicant, 'POST', self::BASE.'/application-documents', $this->documentDocument($id, ['checksumSha256' => 'not-a-hash']))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data/attributes/checksumSha256');
    }

    public function test_a_reviewer_accepts_or_rejects_documents_under_review(): void
    {
        $id = $this->startApplicationOverHttp($this->applicant);
        $this->attachRequiredDocumentsOverHttp($this->applicant, $id);
        $documentId = (string) ApplicationDocument::query()->where('registration_application_id', $id)->firstOrFail()->getKey();

        $review = fn ($as, array $attributes) => $this->request($as, 'PATCH', self::BASE."/application-documents/{$documentId}", $this->resource('application-documents', $attributes, id: $documentId));

        // Not under review yet.
        $this->submitOverHttp($this->applicant, $id)->assertOk();
        $review($this->organizationAdmin, ['reviewStatus' => 'accepted'])->assertStatus(409);

        $this->request($this->organizationAdmin, 'POST', self::BASE."/registration-applications/{$id}/-actions/start-review")->assertOk();

        // The applicant cannot review their own documents.
        $review($this->applicant, ['reviewStatus' => 'accepted'])->assertStatus(403);

        $review($this->organizationAdmin, ['reviewStatus' => 'rejected'])->assertStatus(422)->assertJsonPath('errors.0.code', 'reason_required');

        $review($this->organizationAdmin, ['reviewStatus' => 'rejected', 'reviewNote' => 'Expired document.'])
            ->assertOk()
            ->assertJsonPath('data.attributes.reviewStatus', 'rejected')
            ->assertJsonPath('data.attributes.reviewNote', 'Expired document.');

        // The applicant sees the note but cannot change review fields.
        $this->request($this->applicant, 'GET', self::BASE."/application-documents/{$documentId}")
            ->assertOk()
            ->assertJsonPath('data.attributes.reviewNote', 'Expired document.');
    }
}
