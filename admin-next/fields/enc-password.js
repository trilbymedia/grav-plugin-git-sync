/**
 * Git Sync — enc-password custom field for admin-next.
 *
 * The plugin's git-sync/data endpoint never returns the stored password to
 * the client (it's encrypted at rest and only the boolean `password_stored`
 * flag is sent back). The field renders a normal password input whose
 * placeholder reflects whether a password is on file:
 *
 *   - empty + nothing stored  → "Your Git Password or Token"
 *   - empty + stored encrypted → "Your password is securely stored."
 *   - empty + stored unencrypted → "Your password is stored but not encrypted."
 *
 * Submitting an empty value tells the server "keep what's already there";
 * any non-empty value replaces and re-encrypts.
 */
const TAG = window.__GRAV_FIELD_TAG;

class EncPasswordField extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this._field = null;
        this._value = '';
        this._show = false;
        this._stored = false;
        this._encrypted = false;
    }

    set field(v) {
        this._field = v || {};
        // Server annotates the resolved blueprint with the current
        // password storage state (see onApiBlueprintResolved in git-sync.php).
        this._stored = !!(this._field.password_stored);
        this._encrypted = !!(this._field.password_encrypted);
        this._render();
    }
    get field() { return this._field; }

    set value(v) {
        const next = (v == null) ? '' : String(v);
        if (next !== this._value) {
            this._value = next;
            // Re-render only if we haven't already mounted, to avoid stomping
            // on the user's in-flight typing.
            const input = this.shadowRoot.querySelector('input');
            if (!input) this._render();
        }
    }
    get value() { return this._value; }

    connectedCallback() {
        this._render();
    }

    _placeholder() {
        if (this._value) return this._field.placeholder || '';
        if (this._stored && this._encrypted) return 'Your password is securely stored.';
        if (this._stored && !this._encrypted) return 'Your password is stored but not encrypted.';
        return this._field.placeholder || 'Your Git Password or Token';
    }

    _onInput(e) {
        this._value = e.target.value;
        this.dispatchEvent(new CustomEvent('change', {
            detail: this._value,
            bubbles: true,
        }));
    }

    _toggleVisibility() {
        this._show = !this._show;
        const input = this.shadowRoot.querySelector('input');
        const btn = this.shadowRoot.querySelector('.toggle');
        if (input) input.type = this._show ? 'text' : 'password';
        if (btn) btn.setAttribute('aria-pressed', String(this._show));
        if (btn) btn.innerHTML = this._show ? this._eyeOff() : this._eye();
    }

    _eye() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>';
    }
    _eyeOff() {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-10-7-10-7a17.36 17.36 0 0 1 4.06-5.94"/><path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 10 7 10 7a17.36 17.36 0 0 1-3.17 4.55"/><line x1="2" y1="2" x2="22" y2="22"/></svg>';
    }

    _render() {
        const placeholder = this._placeholder();
        const value = this._value;
        const autocomplete = (this._field && this._field.autocomplete) || 'new-password';

        this.shadowRoot.innerHTML = `
            <style>
                :host { display: block; font-family: inherit; }
                .wrap {
                    position: relative;
                    display: flex;
                    align-items: stretch;
                    width: 100%;
                    height: 2.5rem;
                    border: 1px solid var(--input, var(--border));
                    background: color-mix(in srgb, var(--muted) 50%, transparent);
                    border-radius: 0.5rem;
                    transition: box-shadow 120ms ease, border-color 120ms ease;
                }
                .wrap:focus-within {
                    outline: none;
                    box-shadow: 0 0 0 1px var(--ring);
                    border-color: var(--ring);
                }
                input {
                    flex: 1;
                    min-width: 0;
                    height: 100%;
                    padding: 0.5rem 0.75rem;
                    font-size: 0.875rem;
                    line-height: 1.25rem;
                    color: var(--foreground);
                    background: transparent;
                    border: 0;
                    border-radius: 0.5rem 0 0 0.5rem;
                    box-sizing: border-box;
                    font-family: inherit;
                }
                input:focus { outline: none; }
                input::placeholder {
                    color: var(--muted-foreground);
                    opacity: 1;
                }
                .toggle {
                    flex: 0 0 auto;
                    width: 2.25rem;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    background: transparent;
                    border: 0;
                    color: var(--muted-foreground);
                    cursor: pointer;
                    border-radius: 0 0.5rem 0.5rem 0;
                    padding: 0;
                    font: inherit;
                }
                .toggle:hover {
                    color: var(--foreground);
                    background: color-mix(in srgb, var(--accent) 60%, transparent);
                }
                .stored-hint {
                    margin-top: 0.375rem;
                    font-size: 0.75rem;
                    color: #b45309;
                }
            </style>
            <div class="wrap">
                <input
                    type="password"
                    autocomplete="${autocomplete}"
                    placeholder="${placeholder.replace(/"/g, '&quot;')}"
                    value="${value.replace(/"/g, '&quot;')}"
                />
                <button type="button" class="toggle" aria-label="Show password" aria-pressed="false" tabindex="-1">
                    ${this._eye()}
                </button>
            </div>
            ${this._stored && !this._encrypted ? '<div class="stored-hint">Existing password is stored unencrypted — saving the form will encrypt it.</div>' : ''}
        `;

        const input = this.shadowRoot.querySelector('input');
        input.addEventListener('input', (e) => this._onInput(e));
        const toggle = this.shadowRoot.querySelector('.toggle');
        toggle.addEventListener('click', () => this._toggleVisibility());
    }
}

customElements.define(TAG, EncPasswordField);
