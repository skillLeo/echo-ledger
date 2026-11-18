<?php

declare(strict_types=1);

use App\Services\Assist\Http\SkipGate;
use App\Services\Assist\Http\Text;

/*
 * The text rules HTTP mode shares with the relay (services/assist-relay/src/text.rs and skip.rs): pure functions.
 */

it('normalises speech-engine text as the relay does', function () {
    expect(Text::normalize("  What's  YOUR notice-period?? "))->toBe("what's your notice period ? ?")
        ->and(Text::normalize('كيف حالك؟'))->toBe(Text::normalize('کیف حالک?'))
        ->and(Text::normalize('مُحَمَّد'))->toBe('محمد')
        ->and(Text::normalize('It’s'))->toBe("it's")
        ->and(Text::words(Text::normalize('So, what is it?')))->toBe(['so', 'what', 'is', 'it']);
});

it('knows the short replies that need no answer, in English, Roman Urdu, Urdu and Arabic', function () {
    foreach (['Okay.', 'ok', 'Mm-hmm', 'Yeah right', 'Thanks!', 'theek hai', 'achha ji', 'ٹھیک ہے', 'جی', 'تمام', 'نعم'] as $reply) {
        expect(Text::isBackchannel($reply))->toBeTrue($reply);
    }
    foreach (['What is your notice period?', 'Okay?', 'okay so what about the budget', 'Tell me about yourself', '', 'کیا آپ کل آ سکتے ہیں'] as $question) {
        expect(Text::isBackchannel($question))->toBeFalse($question);
    }
});

it('finds the phrases that make a question hard, as whole words', function () {
    $phrases = (array) require dirname(__DIR__, 2).'/config/assist.php';
    $phrases = $phrases['upgrade']['phrases'];

    expect(Text::hasPhrase('So why did you leave?', $phrases))->toBeTrue()
        ->and(Text::hasPhrase('Walk me through the design.', $phrases))->toBeTrue()
        ->and(Text::hasPhrase('آپ نے یہ کیوں کیا؟', $phrases))->toBeTrue()
        ->and(Text::hasPhrase('What is the difference between the two?', $phrases))->toBeTrue()
        ->and(Text::hasPhrase('Whyte paint is fine.', $phrases))->toBeFalse()
        ->and(Text::hasPhrase('What is your notice period?', $phrases))->toBeFalse();
});

it('holds back words only while they could still be SKIP, in any split', function () {
    foreach ([['SKIP'], ['S', 'KIP'], ['Sk', 'ip', '.'], ["\n", 'SKIP', "\n"]] as $chunks) {
        $gate = new SkipGate;
        foreach ($chunks as $chunk) {
            expect($gate->feed($chunk))->toBeNull();
        }
        expect($gate->finish())->toBeNull();
    }

    $gate = new SkipGate;
    expect($gate->feed('S'))->toBeNull()->and($gate->feed('ure, '))->toBe('Sure, ')->and($gate->feed('I can.'))->toBe('I can.')
        ->and($gate->finish())->toBe('')->and($gate->isOpen())->toBeTrue();
    expect((new SkipGate)->feed('Yes'))->toBe('Yes');

    // An answer that only starts like SKIP is flushed whole at the end.
    $gate = new SkipGate;
    expect($gate->feed('Sk'))->toBeNull()->and($gate->finish())->toBe('Sk')
        ->and(SkipGate::isSkip('SKIP it'))->toBeFalse()->and(SkipGate::couldBeSkip('SKIPPED'))->toBeFalse()->and(SkipGate::isSkip(" skip.\n"))->toBeTrue();
});
