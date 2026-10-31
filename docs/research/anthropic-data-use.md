# Anthropic API: what happens to the data client workspaces send (checked 2026-10-31)

Client workspaces (v1.4) send a client's brief, memory, file text, PDFs and transcript passages to the Claude API with
the console's own Anthropic key. This is what Anthropic's terms say about that data, quoted from the sources below.

## Training

- Commercial Terms of Service, section B (Customer Content): "Anthropic may not train models on Customer Content from
  Services." Customer Content covers the inputs we send and the outputs we get back.
- Privacy Center, "Is my data used for model training?" (dated August 18, 2026): "By default, we will not use your
  inputs or outputs from our commercial products" to train models. The exceptions are feedback and bug reports a
  person chooses to send, which EchoLedger never sends (it has no feedback button wired to Anthropic).

So nothing EchoLedger sends trains a model. Each answer comes only from what one request carries (the "projects"
approach: files and instructions go into the context; no fine-tuning).

## Ownership and confidentiality

- Commercial Terms, section B: the customer owns its outputs; "Anthropic hereby assigns to Customer its right, title
  and interest (if any) in and to Outputs."
- Section E: Customer Content is the customer's Confidential Information, used by Anthropic only to provide the
  service. Section C: data is processed under Anthropic's Data Processing Addendum.

## Retention

- Privacy Center, "How long do you store my organization's data?" (last updated July 1, 2026): Anthropic
  "automatically delete[s] inputs and outputs on our backend within 30 days of receipt or generation" for API use.
- Exceptions: services with longer retention under the customer's control (the Files API, which EchoLedger does not
  use: PDFs are sent inline), an agreed zero-data-retention arrangement, requests flagged under the Usage Policy
  (inputs and outputs kept up to 2 years, classifier scores up to 7 years), and legal holds.

## What EchoLedger does on its side

- Only the passages that match a question go to Ask (about 12), not the whole library; notes send one transcript;
  the brief sends the client's own files.
- Every request is logged per client (model, tokens, cost) and capped by the monthly AI budget (default $5).
- AI notes can be switched off per client; with them off and no question asked, nothing of that client is sent.
- Files stay encrypted at rest on the server (the recordings key); their extracted text is encrypted in the database.

## Sources

- Anthropic Commercial Terms of Service (effective June 17, 2025): https://www.anthropic.com/legal/commercial-terms
- "Is my data used for model training?": https://privacy.claude.com/en/articles/7996868-is-my-data-used-for-model-training
- "How long do you store my organization's data?": https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data
