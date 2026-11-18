<?php

declare(strict_types=1);

namespace Tests\Feature\Assist\Support;

use App\Services\Assist\Http\Ai\ModelEvent;
use App\Services\Assist\Http\Ai\ModelFailure;
use App\Services\Assist\Http\Ai\ModelGateway;
use App\Services\Assist\Http\Ai\ModelRequest;

/**
 * The models of HTTP mode, scripted: no network, no keys. Each call of a model takes the next script queued for it
 * (`on()`), or answers a default. A script is a list of steps:
 *
 * - a string: the next piece of the answer;
 * - `['usage' => [input, cached, written, output]]`: the provider's counts;
 * - `['fail' => 'timeout'|'unavailable'|'declined'|'not_configured'|'rate_limited'|'protocol']`: the call fails here;
 * - `['do' => Closure]`: something else happens at this point of the stream (another request of the session, say);
 * - `['wait' => seconds]`.
 */
final class FakeModels implements ModelGateway
{
    /** @var array<string, list<list<mixed>>> */
    private array $scripts = [];

    /** @var list<ModelRequest> every request, in order */
    public array $requests = [];

    /** @param  list<mixed>  $steps */
    public function on(string $model, array $steps): self
    {
        $this->scripts[$model][] = $steps;

        return $this;
    }

    /** @return list<string> the models asked, in order */
    public function asked(): array
    {
        return array_map(fn (ModelRequest $r): string => $r->model, $this->requests);
    }

    public function stream(ModelRequest $request): \Generator
    {
        $this->requests[] = $request;
        $queue = $this->scripts[$request->model] ?? [];
        $steps = array_shift($queue) ?? ['Say yes. ', 'Then explain.', ['usage' => [100, 0, 0, 12]]];
        $this->scripts[$request->model] = $queue;
        foreach ($steps as $step) {
            if (is_string($step)) {
                yield ModelEvent::text($step);
            } elseif (isset($step['usage'])) {
                [$input, $cached, $written, $output] = array_values($step['usage']);
                yield ModelEvent::usage(['input' => $input, 'cached' => $cached, 'written' => $written, 'output' => $output]);
            } elseif (isset($step['fail'])) {
                throw new ModelFailure($step['fail']);
            } elseif (isset($step['do'])) {
                ($step['do'])();
            } elseif (isset($step['wait'])) {
                usleep((int) ($step['wait'] * 1_000_000));
            }
        }
    }
}
