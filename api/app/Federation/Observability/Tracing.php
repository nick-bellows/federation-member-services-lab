<?php

namespace App\Federation\Observability;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Builds the tracer provider from configuration. Instrumented code asks the
 * container for a TracerInterface and never learns which exporter is behind
 * it: OTLP to Jaeger in Compose, memory in tests, a no-op elsewhere.
 */
final class Tracing
{
    public const TRACEPARENT = 'traceparent';

    private static ?InMemoryExporter $memory = null;

    /**
     * @param  array{exporter: string, endpoint: string, service_name: string, service_version: string}  $config
     */
    public static function provider(array $config): TracerProviderInterface
    {
        return match ($config['exporter']) {
            'otlp' => self::otlp($config),
            'memory' => self::inMemory($config),
            default => new NoopTracerProvider,
        };
    }

    /**
     * The spans recorded in this process when the memory exporter is active (tests).
     *
     * @return list<ImmutableSpan>
     */
    public static function recordedSpans(): array
    {
        return self::$memory?->getSpans() ?? [];
    }

    public static function resetRecordedSpans(): void
    {
        self::$memory = null;
    }

    /**
     * W3C trace context for the current span, or null when nothing is recording.
     * Carried on outbox rows and sent to the Learning Center (docs/OBSERVABILITY.md).
     */
    public static function traceparent(SpanInterface $span): ?string
    {
        $context = $span->getContext();

        if (! $context->isValid()) {
            return null;
        }

        return sprintf('00-%s-%s-%02x', $context->getTraceId(), $context->getSpanId(), $context->getTraceFlags());
    }

    public static function traceId(SpanContextInterface $context): ?string
    {
        return $context->isValid() ? $context->getTraceId() : null;
    }

    /**
     * @param  array{exporter: string, endpoint: string, service_name: string, service_version: string}  $config
     */
    private static function otlp(array $config): TracerProviderInterface
    {
        $transport = (new OtlpHttpTransportFactory)->create(
            rtrim($config['endpoint'], '/').'/v1/traces',
            'application/x-protobuf',
        );

        return new TracerProvider(
            new BatchSpanProcessor(new SpanExporter($transport), ClockFactory::getDefault()),
            null,
            self::resource($config),
        );
    }

    /**
     * @param  array{exporter: string, endpoint: string, service_name: string, service_version: string}  $config
     */
    private static function inMemory(array $config): TracerProviderInterface
    {
        self::$memory ??= new InMemoryExporter;

        return new TracerProvider(new SimpleSpanProcessor(self::$memory), null, self::resource($config));
    }

    /**
     * @param  array{exporter: string, endpoint: string, service_name: string, service_version: string}  $config
     */
    private static function resource(array $config): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $config['service_name'],
            ResourceAttributes::SERVICE_VERSION => $config['service_version'],
        ])));
    }
}
