<?php

namespace Database\Factories\Federation;

use App\Federation\Enums\DocumentReviewStatus;
use App\Federation\Enums\DocumentType;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Models\RegistrationApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationDocument>
 */
class ApplicationDocumentFactory extends Factory
{
    protected $model = ApplicationDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_application_id' => RegistrationApplication::factory(),
            'document_type' => $this->faker->randomElement(DocumentType::cases()),
            'file_name' => $this->faker->slug(2).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(1024, 2 * 1024 * 1024),
            'checksum_sha256' => hash('sha256', $this->faker->uuid()),
            'review_status' => DocumentReviewStatus::PENDING,
        ];
    }
}
