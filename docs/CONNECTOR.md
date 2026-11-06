# Claude and ChatGPT connector

Add EchoLedger to Claude or ChatGPT, then ask them about your calls and meetings in your own words. They read your
recordings the way you can in the apps. They only read: they cannot record, change, share or delete anything.

You need two things:

- **Your server's connector address:** your EchoLedger address with `/mcp` at the end, for example
  `https://ledger.example.com/mcp`. It is the address you open the console on, without `/admin`.
- **The connector in your plan.** If it is not, Claude and ChatGPT answer "The connector is not in your plan."

## Add it in Claude

1. In Claude, open **Customize → Connectors**.
2. Press **+**, then **Add custom connector**.
3. Give it the name **EchoLedger** and paste your connector address (`https://…/mcp`). Press **Add**.
4. Press **Connect**. Your browser opens EchoLedger.
5. Sign in on the console as usual. If you have two-step sign-in, it asks for your code.
6. You see **"Allow Claude to read your EchoLedger recordings?"**. Check the account and the workspace it names, then
   press **Allow**.

You are back in Claude, connected. In a chat, turn EchoLedger on in the connectors menu if it is not on already.

On a Claude Team or Enterprise plan, an owner adds the connector first under **Organization settings → Connectors**;
then each person presses **Connect** and signs in with their own EchoLedger account.

Claude reaches your server from Anthropic's cloud, not from your computer, so the server must be on the public
internet.

## Add it in ChatGPT

ChatGPT lets you add your own connector through **developer mode**. OpenAI moves these menus and changes which plans have
them, so the names can differ a little from what you see here. These steps were written in November 2026.

1. In ChatGPT, open **Settings → Apps** (on some accounts **Connectors**) **→ Advanced settings**, and turn on
   **Developer mode**. On a Business or Enterprise workspace an admin may have to allow it first.
2. Back on that page, press **Create**.
3. Give it the name **EchoLedger**, paste your connector address (`https://…/mcp`), and choose **OAuth** if it asks how
   to sign in. Confirm that you trust it, and press **Create**.
4. Your browser opens EchoLedger. Sign in on the console, with your code if you have two-step sign-in.
5. You see **"Allow ChatGPT to read your EchoLedger recordings?"**. Check the account and the workspace it names, then
   press **Allow**.

To use it, start a chat, open the **+** menu, choose **Developer mode** and pick EchoLedger.

The name in the question is the one the app gave itself when it registered, so it may read a little differently. Below
it, the page says where the browser goes next: `claude.ai` or `chatgpt.com`. If you did not start this yourself just
now, or the address looks wrong, press **Cancel**. EchoLedger asks every time an app connects, also one you allowed
before.

## What the AI can see

Exactly what you can open in the apps: what is yours in your **current workspace** (the one chosen under Profile →
Workspace), and what teammates sent you, whichever workspace you are in:

- your own recordings: title, date, length, people, project and app;
- their transcripts, with what was on the screen at each moment, the text on those screens and a picture of each (the
  picture links stop working after 30 minutes);
- your own projects: brief, notes and instructions, contacts and file names;
- what teammates sent you, read-only;
- memory and call notes of a project, when AI notes are in your plan.

## What the AI cannot see or do

- Anything of anyone else in your workspace, the owner's and admins' included, unless they sent it to you.
- Other workspaces, except what someone there sent you. Switch workspace, and it reads the new one from its next
  question.
- The audio, or the contents of the files in a project (only their names).
- Recordings in the undo bin.
- It cannot change anything: no recording, editing, sharing or deleting. It also never starts anything that costs
  money: when a Roman Urdu view is not made yet, it reads the original words.

What you ask and what it reads goes to Claude or ChatGPT, under their terms and your settings there. Use it only for
recordings you are happy to send them.

## How to disconnect

Open the console, then **Profile → Connected apps**, and press **Disconnect** next to the app. It loses access at once.
The list shows when each app was connected and last used. **Sign out everywhere**, on the same page, disconnects
every app too. To use it again, press **Connect** in Claude or ChatGPT: EchoLedger asks you first, as it did the first
time.

Removing the connector inside Claude or ChatGPT does not end its access on your server: use Disconnect for that.

Every question the AI sends is written in your log under **Security**: which tool it used, which recording or project it
asked for and how much came back. Your words and the transcript are not kept there.

## Questions to try

- "What did Omar ask for on Tuesday's call?"
- "Summarise the Acme project with the screens."
- "What did we decide about the invoice last week? Quote the time."
- "List my last ten recordings."
- "Which page was on the screen when the client said 'click here'?"
- "Show me the Roman Urdu of my latest call with Omar."
- "What should I confirm with Acme before the next call?"

## Something is wrong?

| What you see | What to do |
|---|---|
| "The connector is not in your plan." | Ask whoever runs your server to put it in your plan. |
| "Too many requests: at most 60 a minute." | Wait a minute. The AI asked very quickly; it can try again. |
| "No recording with that id." | It is someone else's, in another workspace or in the undo bin. Check your workspace under Profile → Workspace. |
| "This account cannot connect an app" | The page says why: confirm your email, or choose Keep my account if it is being deleted. |
| A picture link no longer opens | Links last 30 minutes. Ask for the recording again. |
| It stops working after a long break | A connection that is not used for 30 days signs out. Press **Connect** in Claude or ChatGPT and allow it again. |
| It asks you to connect again out of the blue, and **Security** shows "App disconnected: used token came back" | A sign-in token the app had already used came back, so EchoLedger ended the connection in case someone else has a copy. Press **Connect** and allow it again. If it keeps happening, tell whoever runs your server. |
| After pressing **Connect**, a page of code with `invalid_client` | The app's registration is gone: one that never finished connecting is removed after a day. Remove the connector in Claude or ChatGPT and add it again. |
| Claude or ChatGPT says the connector could not register or sign in | Ask whoever runs your server to look at web/DEPLOY.md, "Claude and ChatGPT connector". |
