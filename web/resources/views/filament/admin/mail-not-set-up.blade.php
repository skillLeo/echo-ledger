{{-- W-INC-1125: the admin dashboard's warning while no email can leave the server (MailSettings::delivers). --}}
<div data-mail-not-set-up role="status">
    <x-filament::callout color="warning" icon="heroicon-o-envelope"
        heading="Alerts can't be emailed: mail isn't set up"
        description="The server writes its emails to its log instead of sending them, so health alerts, sign-in codes and invitations reach nobody. Server problems still show in Activity. Add an SMTP server in Settings › Email and send a test email.">
        <x-slot name="footer">
            <x-filament::link :href="$settings">Open Settings</x-filament::link>
        </x-slot>
    </x-filament::callout>
</div>
