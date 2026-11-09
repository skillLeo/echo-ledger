{{--
    The account pages on the public site layout (invitation, Sign in in the browser, the Google confirmation, Allow an
    app, /account/delete and the messages): since v1.6.2 the console's sign-in card, so every auth page looks the same:
    one centred card of 440 px with the title, a muted line, 40 px fields and buttons, the primary one full width.
--}}
<style>
    .account { font-size: 15px; line-height: 1.55; max-width: 440px; margin: clamp(16px, 6vh, 64px) auto 0; padding: clamp(24px, 5vw, 40px); background: var(--card); border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 1px 2px rgba(0, 0, 0, .04); }
    .account h1 { font-size: 24px; line-height: 30px; letter-spacing: -0.3px; margin: 0 0 16px; text-align: center; }
    .account > .card, .account > form.card { background: none; border: 0; padding: 0; border-radius: 0; }
    .account .card { display: flex; flex-direction: column; gap: 16px; }
    .account .card > p:first-child { margin: 0; }
    .account label { display: flex; flex-direction: column; gap: 6px; font-weight: 600; font-size: 14px; }
    .account input[type=password], .account input[type=text], .account input[type=email] { font: inherit; font-weight: 400; min-height: 40px; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--bg); color: var(--text); width: 100%; box-sizing: border-box; }
    .account input:focus-visible { outline: 2px solid var(--brand); outline-offset: 1px; }
    .account .check { flex-direction: row; align-items: flex-start; gap: 10px; font-weight: 400; }
    .account .check input { margin-top: 4px; }
    .account .actions { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .account .actions > .button, .account .actions > button.button { flex: 1 1 0; min-width: 140px; min-height: 40px; box-sizing: border-box; text-align: center; display: inline-flex; align-items: center; justify-content: center; padding: 8px 18px; font-size: 15px; }
    .account button.button { border: 0; cursor: pointer; font: inherit; font-weight: 600; }
    .account button.danger { background: #CE2C31; }
    .account .error { color: #CE2C31; margin: 0; }
    .account .hint { color: var(--muted); font-size: 14px; line-height: 20px; margin: 0; }
    .account + form, .account form + form { text-align: center; }
    @media (max-width: 359.98px) { .account .actions { flex-direction: column; align-items: stretch; } }
</style>
