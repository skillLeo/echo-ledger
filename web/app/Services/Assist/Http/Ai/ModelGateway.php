<?php

declare(strict_types=1);

namespace App\Services\Assist\Http\Ai;

/**
 * The models behind Live Assist's HTTP mode: one streaming completion at a time. Tests bind a scripted one, so nothing
 * here needs a network or a key.
 */
interface ModelGateway
{
    /**
     * The answer as it streams. Stopping the iteration closes the request at the provider, so generation and billing
     * stop.
     *
     * @return \Generator<int, ModelEvent>
     *
     * @throws ModelFailure when the provider is not set up, refuses, cannot be reached or does not answer in time
     */
    public function stream(ModelRequest $request): \Generator;
}
