{{-- WebAuthn JSON <-> browser objects for passkeys (B16). Binary fields travel as base64url. --}}
<script>
    window.elPasskeys = window.elPasskeys || (() => {
        const toBuffer = (text) => {
            const b64 = text.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(text.length / 4) * 4, '=');
            return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)).buffer;
        };
        const toText = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        const descriptors = (list) => (list || []).map((d) => ({ ...d, id: toBuffer(d.id) }));
        const supported = () => !!(window.PublicKeyCredential && navigator.credentials);
        const json = (credential) => {
            const r = credential.response;
            const response = { clientDataJSON: toText(r.clientDataJSON) };
            if (r.attestationObject) {
                response.attestationObject = toText(r.attestationObject);
                response.transports = typeof r.getTransports === 'function' ? r.getTransports() : [];
            }
            if (r.authenticatorData) {
                response.authenticatorData = toText(r.authenticatorData);
                response.signature = toText(r.signature);
                response.userHandle = r.userHandle ? toText(r.userHandle) : null;
            }
            return { id: credential.id, rawId: toText(credential.rawId), type: credential.type, response, clientExtensionResults: credential.getClientExtensionResults() };
        };
        return {
            supported,
            async create(options) {
                const o = typeof options === 'string' ? JSON.parse(options) : options;
                o.challenge = toBuffer(o.challenge);
                o.user = { ...o.user, id: toBuffer(o.user.id) };
                o.excludeCredentials = descriptors(o.excludeCredentials);
                return json(await navigator.credentials.create({ publicKey: o }));
            },
            async get(options) {
                const o = typeof options === 'string' ? JSON.parse(options) : options;
                o.challenge = toBuffer(o.challenge);
                o.allowCredentials = descriptors(o.allowCredentials);
                return json(await navigator.credentials.get({ publicKey: o }));
            },
        };
    })();
</script>
