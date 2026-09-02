<?php

namespace App\Federation\JsonApi\RegistrationWindows;

use LaravelJsonApi\Eloquent\Contracts\Filter;
use LaravelJsonApi\Eloquent\Filters\Concerns\DeserializesValue;
use LaravelJsonApi\Eloquent\Filters\Concerns\IsSingular;

/**
 * filter[open]=true keeps windows accepting applications right now.
 */
class OpenWindowsFilter implements Filter
{
    use DeserializesValue;
    use IsSingular;

    public static function make(string $name = 'open'): self
    {
        return new self($name);
    }

    public function __construct(private readonly string $name) {}

    public function key(): string
    {
        return $this->name;
    }

    public function apply($query, $value)
    {
        $wantOpen = filter_var($this->deserialize($value), FILTER_VALIDATE_BOOLEAN);
        $now = now();

        return $wantOpen
            ? $query->where('opens_at', '<=', $now)->where('closes_at', '>', $now)
            : $query->where(fn ($q) => $q->where('opens_at', '>', $now)->orWhere('closes_at', '<=', $now));
    }
}
