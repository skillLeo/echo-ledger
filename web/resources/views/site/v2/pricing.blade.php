{{--
    The v2.0 Pricing: the plans on offer from Admin › Plans, cheapest first, in the console's words: the price as the
    plan shows it, what each adds to the one before, and its limits. One primary action.
--}}
@extends('site.v2.layout')

@section('title', 'Pricing · '.\App\Support\Brand::name())

@section('content')
    <section class="s-page-head">
        <h1 class="s-display">Pricing</h1>
    </section>

    @if ($plans === [])
        <p class="s-text">The plans are not published yet. <a class="s-link" href="{{ route('sign-up') }}">Get started</a> and choose one in the app.</p>
    @else
        <div class="s-plans">
            @foreach ($plans as $plan)
                <section class="s-plan" aria-labelledby="plan-{{ $plan['key'] }}">
                    <h2 id="plan-{{ $plan['key'] }}" class="s-plan-name">{{ $plan['name'] }}</h2>
                    <p class="s-plan-price">{{ $plan['price'] }}</p>
                    @if ($plan['description'] !== null)
                        <p class="s-text">{{ $plan['description'] }}</p>
                    @endif
                    @if ($plan['everything'] !== null)
                        <p class="s-plan-everything">Everything in {{ $plan['everything'] }}, plus:</p>
                    @endif
                    <ul class="s-list">
                        @foreach ($plan['lines'] as $line)
                            <li><x-el.v2.icon name="check" />{{ $line }}</li>
                        @endforeach
                    </ul>
                    <ul class="s-limits" aria-label="Limits">
                        @foreach ($plan['limits'] as $limit)
                            <li>{{ $limit }}</li>
                        @endforeach
                    </ul>
                    <a class="s-btn {{ $loop->first ? 's-btn-primary' : 's-btn-secondary' }}" href="{{ route('sign-up') }}">Get started</a>
                </section>
            @endforeach
        </div>
        <p class="s-note">Choose a plan in the app, under Settings › Plan &amp; usage.</p>
    @endif
@endsection
