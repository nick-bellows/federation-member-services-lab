<?php

namespace App\Federation\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

/**
 * Stamps every log record with the active trace and span ids, so one trace
 * id finds the lines a request produced in the web process, the worker and
 * the calls between them. Request and user ids arrive through Laravel's
 * shared log context (set by the request and worker instrumentation).
 */
final class LogContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = Span::getCurrent()->getContext();

        if ($context->isValid()) {
            $record->extra['trace_id'] = $context->getTraceId();
            $record->extra['span_id'] = $context->getSpanId();
        }

        $record->extra['service'] = 'federation-api';

        return $record;
    }
}
