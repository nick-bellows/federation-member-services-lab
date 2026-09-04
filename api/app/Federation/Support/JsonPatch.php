<?php

namespace App\Federation\Support;

use App\Federation\Exceptions\InvalidPatchException;

/**
 * RFC 6902 for a flat resource: operations on top-level attributes only,
 * parsed and validated before anything is authorised or applied. Anything
 * the parser does not understand is refused as a whole; a patch is atomic.
 */
final class JsonPatch
{
    public const MEDIA_TYPE = 'application/json-patch+json';

    public const OPERATIONS = ['add', 'replace', 'remove', 'test'];

    /**
     * @param  mixed  $document  the decoded request body
     * @return list<array{op: string, path: string, field: string, value?: mixed}>
     */
    public static function parse(mixed $document): array
    {
        if (! is_array($document) || $document === [] || array_keys($document) !== range(0, count($document) - 1)) {
            throw new InvalidPatchException('A JSON Patch document is a non-empty array of operations.');
        }

        $operations = [];

        foreach ($document as $index => $operation) {
            if (! is_array($operation) || ! isset($operation['op'], $operation['path'])) {
                throw new InvalidPatchException("Operation {$index} needs \"op\" and \"path\".");
            }

            $op = $operation['op'];
            $path = $operation['path'];

            if (! is_string($op) || ! in_array($op, self::OPERATIONS, true)) {
                throw new InvalidPatchException("Operation {$index}: \"op\" must be one of ".implode(', ', self::OPERATIONS).'.');
            }

            if (! is_string($path) || preg_match('/^\/[A-Za-z][A-Za-z0-9]*$/', $path) !== 1) {
                throw new InvalidPatchException("Operation {$index}: \"path\" must name one top-level field, such as /phone.");
            }

            if (in_array($op, ['add', 'replace', 'test'], true) && ! array_key_exists('value', $operation)) {
                throw new InvalidPatchException("Operation {$index}: \"{$op}\" needs a \"value\".");
            }

            $normalised = ['op' => $op, 'path' => $path, 'field' => substr($path, 1)];
            if (array_key_exists('value', $operation)) {
                $normalised['value'] = $operation['value'];
            }

            $operations[] = $normalised;
        }

        return $operations;
    }
}
