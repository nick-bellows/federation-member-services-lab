<?php

namespace App\Federation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Produces public/federation_openapi.json: runs the package generator for
 * the "federation" server, then adds the custom actions the generator does
 * not know about (submit, cancel, start-review, request-information,
 * approve, reject), typed with the same resource document as a fetch-one.
 *
 * The frontend's `npm run generate-schema:federation` reads the result.
 */
class GenerateFederationOpenApi extends Command
{
    protected $signature = 'federation:openapi {--output=public/federation_openapi.json}';

    protected $description = 'Generate the federation OpenAPI document including custom actions';

    private const ACTIONS = [
        'submit' => 'Submit a draft or resubmit after an information request (applicant).',
        'cancel' => 'Cancel an open application (applicant).',
        'start-review' => 'Take a submitted application under review (reviewer).',
        'request-information' => 'Send the application back to the applicant with a reason (reviewer).',
        'approve' => 'Approve an application under review (reviewer).',
        'reject' => 'Reject an application under review with a reason (reviewer).',
        'refresh-credentials' => 'Ask the Learning Center for the applicant\'s current credentials and store the snapshot (reviewer, approved applications). 503 when the Learning Center is unavailable.',
    ];

    public function handle(): int
    {
        $exit = $this->call('jsonapi:openapi:generate', ['serverKey' => 'federation']);

        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        $generated = storage_path('app/public/federation_openapi.json');
        $document = json_decode(File::get($generated), true, flags: JSON_THROW_ON_ERROR);

        $fetchOne = $document['paths']['/registration-applications/{registration_application}']['get'] ?? null;

        if ($fetchOne === null) {
            $this->error('The generated document has no fetch-one path for registration applications.');

            return self::FAILURE;
        }

        foreach (self::ACTIONS as $action => $summary) {
            $document['paths']["/registration-applications/{registration_application}/-actions/{$action}"] = [
                'post' => $this->actionOperation($action, $summary, $fetchOne),
            ];
        }

        $document['paths']['/registration-applications/{registration_application}/-actions/fields'] = [
            'patch' => $this->patchFieldsOperation($fetchOne),
        ];

        $document['info']['description'] = ($document['info']['description'] ?? '')
            .' Custom actions on registration applications are added by federation:openapi; the package generator does not describe them.';

        $output = base_path($this->option('output'));
        File::put($output, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->info("Written {$output} with ".count($document['paths']).' paths.');

        return self::SUCCESS;
    }

    /**
     * JSON Patch on the application's fields (ADR-0014): its own media type,
     * its own error codes, no idempotency key (a "test" operation is the
     * client's guard against stale writes).
     *
     * @param  array<string, mixed>  $fetchOne
     * @return array<string, mixed>
     */
    private function patchFieldsOperation(array $fetchOne): array
    {
        $ok = $fetchOne['responses']['200'] ?? ['description' => 'The application after the patch.'];

        return [
            'summary' => 'Apply an RFC 6902 JSON Patch to the application\'s fields. Every operation is authorised for the signed-in person before any is applied; one refused operation refuses the patch. Applicants may change /dateOfBirth, /phone and /applicantNotes while the application is a draft or needs information; reviewers may change /reviewerNotes.',
            'operationId' => 'registration-applications.patchFields',
            'tags' => ['registration-applications'],
            'parameters' => array_merge($fetchOne['parameters'] ?? [], [
                [
                    'name' => 'X-Request-Id',
                    'in' => 'header',
                    'required' => false,
                    'description' => 'Correlation id echoed in the response and stored with the audit entry.',
                    'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{8,64}$'],
                ],
            ]),
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json-patch+json' => [
                        'schema' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['op', 'path'],
                                'properties' => [
                                    'op' => ['type' => 'string', 'enum' => ['add', 'replace', 'remove', 'test']],
                                    'path' => ['type' => 'string', 'pattern' => '^/[A-Za-z][A-Za-z0-9]*$', 'example' => '/phone'],
                                    'value' => ['description' => 'Required for add, replace and test.'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => $ok,
                '403' => ['description' => 'The signed-in person may not perform an operation on that path (code field_not_allowed, meta.path names it). Nothing was applied.'],
                '409' => ['description' => 'A test operation did not match the stored value (code patch_test_failed), or the applicant may no longer edit (code application_not_editable). Nothing was applied.'],
                '415' => ['description' => 'The body was not sent as application/json-patch+json.'],
                '422' => ['description' => 'The document is not a valid JSON Patch, or a value fails validation (code invalid_patch).'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $fetchOne
     * @return array<string, mixed>
     */
    private function actionOperation(string $action, string $summary, array $fetchOne): array
    {
        $ok = $fetchOne['responses']['200'] ?? ['description' => 'The application after the transition.'];

        return [
            'summary' => $summary,
            'operationId' => 'registration-applications.'.str_replace('-', '', ucwords($action, '-')),
            'tags' => ['registration-applications'],
            'parameters' => array_merge($fetchOne['parameters'] ?? [], [
                [
                    'name' => 'Idempotency-Key',
                    'in' => 'header',
                    'required' => false,
                    'description' => 'Retrying with the same key returns the current state instead of failing.',
                    'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{8,64}$'],
                ],
                [
                    'name' => 'X-Request-Id',
                    'in' => 'header',
                    'required' => false,
                    'description' => 'Correlation id echoed in the response and stored with the audit entry.',
                    'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{8,64}$'],
                ],
            ]),
            'requestBody' => [
                'required' => false,
                'content' => [
                    'application/vnd.api+json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'meta' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'reason' => ['type' => 'string', 'description' => 'Required for request-information and reject.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => $ok,
                '403' => ['description' => 'The signed-in user may not perform this transition on this application.'],
                '409' => ['description' => 'The transition is not legal from the current status.'],
                '422' => ['description' => 'A reason is required, or the application is incomplete (meta lists what is missing).'],
            ],
        ];
    }
}
