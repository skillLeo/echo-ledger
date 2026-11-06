{{-- Form controls for the account pages on the public site layout (the console's colours, phone-sized first). --}}
<style>
    .account { max-width: 440px; margin: 8px auto 0; }
    .account .card { display: flex; flex-direction: column; gap: 14px; }
    .account label { display: flex; flex-direction: column; gap: 6px; font-weight: 600; font-size: 15px; }
    .account input[type=password], .account input[type=text], .account input[type=email] { font: inherit; font-weight: 400; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--bg); color: var(--text); width: 100%; }
    .account input:focus-visible { outline: 2px solid var(--brand); outline-offset: 1px; }
    .account .check { flex-direction: row; align-items: flex-start; gap: 10px; font-weight: 400; }
    .account .check input { margin-top: 4px; }
    .account .actions { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .account button.button { border: 0; cursor: pointer; font: inherit; font-weight: 600; }
    .account button.danger { background: #CE2C31; }
    .account .error { color: #CE2C31; margin: 0; }
    .account .hint { color: var(--muted); font-size: 14px; margin: 0; }
</style>
