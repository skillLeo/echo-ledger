<x-mail::message>
# {{ $level >= 100 ? 'The monthly budget is reached' : $level.'% of the monthly budget' }}

The estimated cost this month is ${{ number_format($spent, 2) }} of your ${{ number_format($budget, 2) }} budget. This is an alert only: nothing stops. You hear about each level once a month.

<x-mail::button :url="$url">
Open Server usage
</x-mail::button>
</x-mail::message>
