<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Services\Assist\Http\Prompt\Transcript;

/**
 * The final turns of a call, replayed in order into the relay's transcript (HTTP mode keeps nothing in memory between
 * requests, and the blocks come out the same every time). In Practice the questions the server asked are Them's turns
 * `q-N`, as the relay names them.
 */
class CallTranscript
{
    public function of(AssistSession $session): Transcript
    {
        $transcript = Transcript::fromConfig();
        $question = 0;
        foreach (AssistTurn::query()->where('assist_session_id', $session->id)->orderBy('id')->cursor() as $turn) {
            $asked = $session->practice && $turn->is_question && $turn->channel === 'other';
            $transcript->push(['id' => $asked ? 'q-'.(++$question) : $turn->client_ref, 'channel' => $turn->channel, 'text' => $turn->text, 'start_ms' => $turn->start_ms]);
        }

        return $transcript;
    }
}
