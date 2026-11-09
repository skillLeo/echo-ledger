<x-mail::message>
# Unusual use

{{ $name }} ({{ $email }}) recorded {{ $hours }} hours in the last 24 hours. You asked to hear about more than {{ $limit }} hours a day.

If it does not look right, you can suspend the account on the Plans page. You hear about this person at most once a day.

<x-mail::button :url="$url">
Open Plans
</x-mail::button>
</x-mail::message>
