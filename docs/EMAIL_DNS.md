# Email DNS records (SPF, DKIM, DMARC)

Since v1.5 the server sends email: sign-up confirmations, password resets, invitations, sign-in alerts, shares,
exports and account deletions. Mail providers put email in spam (or refuse it) unless the domain of the sender says,
in DNS, which servers may send for it. Add the three records below to the domain of the **From address** set in
Settings → Email, then send a test email from the same page.

Placeholders used below (replace each, including the angle brackets):

| Placeholder | What it is |
|---|---|
| `<your-domain>` | The domain of the From address, e.g. the part after `@` in `echoledger@<your-domain>` |
| `<smtp-provider-spf>` | The SPF include your SMTP provider documents (often `spf.<provider-domain>`) |
| `<server-ipv4>` | The public IPv4 address of this server, only if it sends email itself (no SMTP provider) |
| `<selector>` | The DKIM selector your SMTP provider gives you (e.g. `s1`, `mail`, a date) |
| `<dkim-public-key>` | The DKIM public key (the long `p=` value) your SMTP provider gives you |
| `<reports-address>` | A mailbox that receives DMARC reports, e.g. `dmarc-reports@<your-domain>` |

Using an SMTP provider (a transactional email service, or the mail service of the domain) is recommended over
sending from the server itself: its addresses have a sending reputation and it signs DKIM for you.

## Settings → Email

| Field | Value |
|---|---|
| SMTP server | The host name your SMTP provider gives (e.g. `smtp.<provider-domain>`) |
| Port and Encryption | `587` with STARTTLS (preferred), or `465` with SSL/TLS. "None" only inside a private network |
| User and Password | The SMTP credentials of the provider (the password is stored encrypted, like the API credentials) |
| From address | An address at `<your-domain>`, e.g. `echoledger@<your-domain>` |
| From name | `EchoLedger`, or the name people should see |

An empty SMTP server keeps the mailer of the server's `.env` file (`MAIL_MAILER`, `MAIL_HOST`, …).

## 1. SPF: who may send for the domain

One TXT record on the domain itself. A domain has **one** SPF record: if one exists, add the new `include:` (or
`ip4:`) to it instead of adding a second record.

| Type | Name | Value |
|---|---|---|
| TXT | `<your-domain>` (often written `@`) | `v=spf1 include:<smtp-provider-spf> -all` |

Sending from this server without a provider: `v=spf1 ip4:<server-ipv4> -all`. While you are still testing, `~all`
(soft fail) instead of `-all` is gentler; switch to `-all` once mail passes.

## 2. DKIM: a signature on every email

The SMTP provider signs each email with a private key and tells you the public key to publish. Some providers give a
CNAME instead of a TXT record; use what they give.

| Type | Name | Value |
|---|---|---|
| TXT | `<selector>._domainkey.<your-domain>` | `v=DKIM1; k=rsa; p=<dkim-public-key>` |
| or CNAME | `<selector>._domainkey.<your-domain>` | The target the provider names |

Use a 2048-bit key where the provider offers a choice. Some DNS panels need a long value split into several quoted
strings in one record; that is the same record.

## 3. DMARC: what receivers do when SPF or DKIM fails

| Type | Name | Value |
|---|---|---|
| TXT | `_dmarc.<your-domain>` | `v=DMARC1; p=none; rua=mailto:<reports-address>; adkim=s; aspf=s` |

Start with `p=none` (report only). After a week or two of reports showing that the server's email passes, change it
to `p=quarantine`, and later to `p=reject`.

## 4. If the server sends email itself

Only without an SMTP provider: ask the hosting provider to set the reverse DNS (PTR) of `<server-ipv4>` to a host
name whose A record points back to `<server-ipv4>`, and make sure outgoing port 25 is open (many hosting providers
block it; then a provider is the only way).

## Checking

1. Wait for the records to spread (up to the TTL, usually minutes, at most a day). Look them up:
   ```
   dig +short TXT <your-domain>
   dig +short TXT <selector>._domainkey.<your-domain>
   dig +short TXT _dmarc.<your-domain>
   ```
2. In the console, Settings → Email → **Send a test email** to a mailbox at a large provider.
3. Open the received email's original (headers): `Authentication-Results` should show `spf=pass`, `dkim=pass` and
   `dmarc=pass`. A fail names which record to fix; the From address must be at `<your-domain>` for DMARC to pass.
