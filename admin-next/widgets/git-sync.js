/**
 * Git Sync — auto-loaded admin-next widget.
 *
 * Registered with `autoLoad: true, showFab: false`, so admin-next pulls
 * this script on every admin page load but never instantiates the custom
 * element (there's no FAB, no panel). The script's job is to:
 *
 *   1. Listen at the window level for the `grav:plugin-page-action` event
 *      that the plugin page header dispatches when the Wizard action is
 *      clicked. (Patched into admin-next's `executeAction` for blueprint
 *      pages with no endpoint / no navigate.)
 *   2. Render the wizard as a centered modal portal'd into document.body
 *      so it's not constrained by the floating-widget panel chrome.
 *
 * The wizard mirrors the four-step flow from the admin-classic Twig
 * partial (`templates/partials/modal-wizard.html.twig`) but talks to the
 * plugin's API endpoints (`/git-sync/wizard/state`, `/git-sync/data`,
 * `/git-sync/test-connection`) instead of admin-classic tasks.
 */

const TAG = window.__GRAV_WIDGET_TAG;

// ─── API helpers ─────────────────────────────────────────────────────────

function apiUrl(path) {
    return (window.__GRAV_API_SERVER_URL || '') +
           (window.__GRAV_API_PREFIX || '/api/v1') + path;
}

function apiHeaders(json = false) {
    const h = {};
    const token = window.__GRAV_API_TOKEN;
    if (token) h['X-API-Token'] = token;
    if (json) h['Content-Type'] = 'application/json';
    return h;
}

async function apiCall(method, path, body) {
    const opts = { method, headers: apiHeaders(!!body) };
    if (body) opts.body = JSON.stringify(body);
    const resp = await fetch(apiUrl(path), opts);
    const text = await resp.text();
    let json = {};
    try { json = text ? JSON.parse(text) : {}; } catch { json = { raw: text }; }
    if (!resp.ok) {
        const msg = (json.errors && json.errors[0] && json.errors[0].detail)
            || json.detail || json.message || `HTTP ${resp.status}`;
        throw new Error(msg);
    }
    return json.data ?? json;
}

// ─── Service catalogue (mirrors admin-classic wizard) ───────────────────

const SERVICES = {
    github:    { host: 'github.com',     branch: 'main',    create: 'https://github.com/join?source=header-home' },
    bitbucket: { host: 'bitbucket.org',  branch: 'master',  create: 'https://bitbucket.org/account/signup/' },
    gitlab:    { host: 'gitlab.com',     branch: 'master',  create: 'https://gitlab.com/users/sign_up' },
    allothers: { host: 'allothers.repo', branch: 'master',  create: null },
};

const GIT_REGEX = /(?:git|ssh|https?|git@[-\w.]+):(\/\/)?(.*?)(\.git)(\/?|#[-\d\w._]+?)$/;

function detectServiceFromUrl(url) {
    if (!url) return null;
    if (url.includes('github.com'))     return 'github';
    if (url.includes('bitbucket.org'))  return 'bitbucket';
    if (url.includes('gitlab.com'))     return 'gitlab';
    return 'allothers';
}

// ─── Wizard modal ───────────────────────────────────────────────────────

class WizardModal {
    constructor() {
        this.host = null;
        this.shadow = null;
        this.step = 0;
        this.maxStep = 4;
        this.state = null;
        this.frontendUrl = '';
        this.draft = {
            service:         '',
            no_user:         false,
            user:            '',
            password:        '',
            repository:      '',
            branch:          'main',
            webhook:         '',
            webhook_enabled: false,
            webhook_secret:  '',
            folders:         ['pages'],
        };
        this.testing = false;
        this.testResult = null;
        this.saving = false;
        this.saveError = '';
    }

    async open() {
        if (this.host) return;
        this.host = document.createElement('div');
        this.host.setAttribute('data-grav-gitsync-wizard', '');
        this.shadow = this.host.attachShadow({ mode: 'open' });
        document.body.appendChild(this.host);

        this._injectStyles();

        // Disable page scroll
        this._prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        // Esc to close
        this._onKeydown = (e) => { if (e.key === 'Escape') this.close(); };
        window.addEventListener('keydown', this._onKeydown);

        // Render skeleton, then load state
        this._render();
        try {
            const state = await apiCall('GET', '/git-sync/wizard/state');
            this.state = state;
            // Server-derived public site URL (Uri::base + Uri::rootUrl) so
            // that Grav installs in a sub-folder render the right webhook URL.
            this.frontendUrl = state.frontend_url || window.location.origin;
            // Pre-fill from saved settings
            const s = state.settings || {};
            if (s.repository) {
                this.draft.service = detectServiceFromUrl(s.repository) || 'allothers';
                this.draft.repository = s.repository;
            }
            if (typeof s.no_user === 'boolean')         this.draft.no_user = s.no_user;
            if (s.user)                                  this.draft.user = s.user;
            if (s.branch)                                this.draft.branch = s.branch;
            if (s.webhook)                               this.draft.webhook = s.webhook;
            if (typeof s.webhook_enabled === 'boolean')  this.draft.webhook_enabled = s.webhook_enabled;
            if (s.webhook_secret)                        this.draft.webhook_secret = s.webhook_secret;
            if (Array.isArray(s.folders) && s.folders.length) this.draft.folders = s.folders.slice();
        } catch (err) {
            console.warn('[git-sync] wizard state load failed:', err);
            this.state = { git_installed: true, settings: {} };
        }
        this._render();
    }

    close() {
        if (!this.host) return;
        document.body.style.overflow = this._prevOverflow || '';
        window.removeEventListener('keydown', this._onKeydown);
        this.host.remove();
        this.host = null;
        this.shadow = null;
        this.step = 0;
        this.testResult = null;
        this.saveError = '';
    }

    _injectStyles() {
        const style = document.createElement('style');
        style.textContent = `
            :host { all: initial; font-family: inherit; }
            * { box-sizing: border-box; }
            .backdrop {
                position: fixed; inset: 0;
                background: rgb(23 23 23 / 0.75);
                backdrop-filter: blur(4px);
                z-index: 100;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1rem;
                animation: fadeIn 150ms ease-out;
            }
            .modal {
                width: 100%;
                max-width: 720px;
                max-height: calc(100vh - 2rem);
                background: var(--card, #fff);
                color: var(--card-foreground, var(--foreground, #0f172a));
                border: 1px solid var(--border, #e2e8f0);
                border-radius: 0.75rem;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                display: flex;
                flex-direction: column;
                overflow: hidden;
                animation: pop 180ms cubic-bezier(0.16, 1, 0.3, 1);
            }
            .header {
                display: flex; align-items: center; justify-content: space-between;
                padding: 1rem 1.25rem;
                border-bottom: 1px solid var(--border, #e2e8f0);
            }
            .title { font-size: 1.05rem; font-weight: 600; }
            .step-pill { font-size: 0.7rem; color: var(--muted-foreground, #64748b); margin-left: 0.5rem; }
            .close-btn {
                background: transparent; border: 0; color: var(--muted-foreground, #64748b);
                cursor: pointer; padding: 0.25rem; border-radius: 0.25rem; line-height: 0;
            }
            .close-btn:hover { background: var(--accent, #f1f5f9); color: var(--foreground); }

            .body {
                padding: 1.25rem;
                overflow-y: auto;
                flex: 1;
                font-size: 0.875rem;
                line-height: 1.5;
            }
            .body p { margin: 0 0 0.75rem 0; }
            .body p:last-child { margin-bottom: 0; }
            .body code {
                background: var(--muted, #f1f5f9);
                border-radius: 0.25rem; padding: 0.05rem 0.3rem;
                font-size: 0.8125rem; font-family: ui-monospace, SFMono-Regular, monospace;
            }
            .body ul, .body ol { padding-left: 1.25rem; margin: 0 0 0.75rem 0; }
            .body li { margin-bottom: 0.25rem; }
            .body h4 { margin: 1rem 0 0.5rem 0; font-size: 0.95rem; }

            .footer {
                display: flex; justify-content: space-between; align-items: center;
                gap: 0.5rem;
                padding: 0.875rem 1.25rem;
                background: var(--muted, #f8fafc);
                border-top: 1px solid var(--border, #e2e8f0);
            }
            .footer-right { display: flex; gap: 0.5rem; }

            .btn {
                font: inherit; font-size: 0.8125rem; font-weight: 500;
                height: 2rem; padding: 0 0.75rem;
                background: var(--background, #fff);
                color: var(--foreground, #0f172a);
                border: 1px solid var(--border, #e2e8f0);
                border-radius: 0.375rem;
                cursor: pointer;
                display: inline-flex; align-items: center; gap: 0.375rem;
                transition: background 120ms ease, border-color 120ms ease;
            }
            .btn:hover { background: var(--accent, #f1f5f9); }
            .btn:disabled { opacity: 0.5; cursor: not-allowed; }
            .btn-primary {
                background: var(--primary, #0ea5e9);
                color: var(--primary-foreground, #fff);
                border-color: var(--primary, #0ea5e9);
            }
            .btn-primary:hover { background: color-mix(in srgb, var(--primary, #0ea5e9) 85%, black); }
            .btn-danger {
                background: #ef4444; color: #fff; border-color: #ef4444;
            }
            .btn-danger:hover { background: #dc2626; border-color: #dc2626; }

            .hosting-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
                margin-top: 0.75rem;
            }
            .host-card {
                display: flex; flex-direction: column; align-items: center;
                gap: 0.5rem;
                padding: 1rem;
                border: 1px solid var(--border, #e2e8f0);
                border-radius: 0.5rem;
                cursor: pointer;
                background: var(--background, #fff);
                transition: border-color 120ms, box-shadow 120ms;
            }
            .host-card:hover { border-color: var(--primary, #0ea5e9); }
            .host-card.selected {
                border-color: var(--primary, #0ea5e9);
                box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary, #0ea5e9) 20%, transparent);
            }
            .host-card .name { font-weight: 600; font-size: 0.875rem; }
            .host-card .small { font-size: 0.75rem; color: var(--muted-foreground, #64748b); }

            label.field {
                display: block;
                margin-bottom: 0.875rem;
            }
            label.field > .lbl {
                display: flex; justify-content: space-between; align-items: center;
                font-size: 0.8125rem; font-weight: 600;
                margin-bottom: 0.375rem;
            }
            label.field input[type="text"],
            label.field input[type="password"] {
                width: 100%;
                height: 2.25rem;
                padding: 0 0.625rem;
                font: inherit;
                font-size: 0.875rem;
                background: var(--background, #fff);
                color: var(--foreground, #0f172a);
                border: 1px solid var(--border, #e2e8f0);
                border-radius: 0.375rem;
            }
            label.field input:focus {
                outline: none;
                border-color: var(--primary, #0ea5e9);
                box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary, #0ea5e9) 20%, transparent);
            }
            label.field input.invalid {
                border-color: #ef4444;
                box-shadow: 0 0 0 3px rgba(239,68,68,0.2);
            }
            .inline-checkbox {
                font-size: 0.75rem; font-weight: 500;
                color: var(--muted-foreground, #64748b);
                display: inline-flex; align-items: center; gap: 0.25rem;
            }
            .creds-row {
                display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;
            }

            .verify-row {
                display: flex; justify-content: center; margin: 0.75rem 0;
            }
            .test-result {
                margin-top: 0.5rem;
                padding: 0.625rem 0.75rem;
                border-radius: 0.375rem;
                font-size: 0.8125rem;
            }
            .test-result.success {
                background: rgba(34, 197, 94, 0.1);
                color: #15803d;
                border: 1px solid rgba(34, 197, 94, 0.3);
            }
            .test-result.error {
                background: rgba(239, 68, 68, 0.1);
                color: #b91c1c;
                border: 1px solid rgba(239, 68, 68, 0.3);
            }

            .folder-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
            }
            .folder-card {
                display: flex; align-items: center; gap: 0.5rem;
                padding: 0.625rem 0.75rem;
                border: 1px solid var(--border, #e2e8f0);
                border-radius: 0.375rem;
                cursor: pointer;
                background: var(--background, #fff);
            }
            .folder-card.selected {
                border-color: var(--primary, #0ea5e9);
                background: color-mix(in srgb, var(--primary, #0ea5e9) 8%, var(--background, #fff));
            }
            .folder-card .warn {
                font-size: 0.7rem; color: #b45309;
            }

            .save-error {
                margin-top: 0.75rem;
                padding: 0.625rem 0.75rem;
                background: rgba(239, 68, 68, 0.1);
                color: #b91c1c;
                border: 1px solid rgba(239, 68, 68, 0.3);
                border-radius: 0.375rem;
                font-size: 0.8125rem;
            }

            @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
            @keyframes pop {
                from { opacity: 0; transform: scale(0.96) translateY(6px); }
                to { opacity: 1; transform: scale(1) translateY(0); }
            }

            .spin {
                display: inline-block;
                width: 0.875rem; height: 0.875rem;
                border: 2px solid currentColor;
                border-right-color: transparent;
                border-radius: 50%;
                animation: spin 0.7s linear infinite;
            }
            @keyframes spin { to { transform: rotate(360deg); } }
        `;
        this.shadow.appendChild(style);
    }

    _render() {
        const container = this.shadow.querySelector('.backdrop') || (() => {
            const el = document.createElement('div');
            el.className = 'backdrop';

            // Backdrop dismissal guard: only close when both mousedown AND
            // mouseup land on the backdrop itself. Without this, dragging
            // a text selection out of an input inside the modal — or any
            // mousedown-inside-mouseup-outside motion — fires a click on
            // the backdrop and snaps the wizard shut. The wizard form is
            // long enough that accidental drags happen often.
            let mouseDownOnBackdrop = false;
            el.addEventListener('mousedown', (e) => {
                mouseDownOnBackdrop = (e.target === el);
            });
            el.addEventListener('mouseup', (e) => {
                if (mouseDownOnBackdrop && e.target === el && !this.saving) {
                    this.close();
                }
                mouseDownOnBackdrop = false;
            });

            this.shadow.appendChild(el);
            return el;
        })();

        const stepLabel = ['Welcome', 'Hosting Service', 'Repository', 'Webhook', 'Folders'][this.step];
        const isReady = this.state !== null;
        const gitInstalled = !this.state || this.state.git_installed !== false;

        container.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="gs-wiz-title">
                <div class="header">
                    <div>
                        <span class="title" id="gs-wiz-title">Git Sync — Wizard</span>
                        ${isReady && gitInstalled ? `<span class="step-pill">Step ${this.step} of ${this.maxStep} · ${stepLabel}</span>` : ''}
                    </div>
                    <button class="close-btn" aria-label="Close" data-close>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
                <div class="body">
                    ${!isReady ? this._renderLoading() :
                      !gitInstalled ? this._renderNoGit() :
                      this._renderStep()}
                </div>
                ${isReady && gitInstalled ? this._renderFooter() : `
                    <div class="footer">
                        <span></span>
                        <div class="footer-right">
                            <button class="btn" data-close>Close</button>
                        </div>
                    </div>
                `}
            </div>
        `;

        // Close handlers
        container.querySelectorAll('[data-close]').forEach((b) => {
            b.addEventListener('click', () => this.close());
        });

        // Wire up step-specific handlers
        if (isReady && gitInstalled) {
            this._wireFooter(container);
            this._wireStep(container);
        }
    }

    _renderLoading() {
        return `<p style="text-align:center; color:var(--muted-foreground);"><span class="spin"></span> Loading wizard…</p>`;
    }

    _renderNoGit() {
        return `
            <p>The <strong>Git Sync</strong> plugin requires the <code>git</code> binary to be installed and reachable on the server's PATH.</p>
            <p>If <code>git</code> is missing, ask your hosting provider to install it, or set a custom <strong>Git Binary Path</strong> on the settings form below the wizard.</p>
        `;
    }

    _renderStep() {
        switch (this.step) {
            case 0: return this._step0();
            case 1: return this._step1();
            case 2: return this._step2();
            case 3: return this._step3();
            case 4: return this._step4();
        }
        return '';
    }

    _step0() {
        return `
            <p>This wizard walks you through setting up <strong>Git Sync</strong> in four steps. When done, your site will keep itself in sync with a remote git repository.</p>
            <ol>
                <li>Pick the hosting service and enter your access credentials.</li>
                <li>Point Git Sync at the repository and verify the connection.</li>
                <li>Optionally configure a webhook so the remote can notify your site of changes.</li>
                <li>Choose which <code>user/</code> folders to keep in sync.</li>
            </ol>
            <p>Press <strong>Next</strong> to begin.</p>
        `;
    }

    _step1() {
        const sel = this.draft.service;
        const services = [
            { id: 'github',    label: 'GitHub' },
            { id: 'bitbucket', label: 'Bitbucket' },
            { id: 'gitlab',    label: 'GitLab' },
            { id: 'allothers', label: 'Other Git' },
        ];
        return `
            <p>Choose the git host you'll be using and enter your username and password (or an access token / app password).</p>
            <div class="hosting-grid">
                ${services.map(s => `
                    <div class="host-card ${sel === s.id ? 'selected' : ''}" data-svc="${s.id}">
                        <span class="name">${s.label}</span>
                        ${SERVICES[s.id].create ? `<a class="small" href="${SERVICES[s.id].create}" target="_blank" rel="noopener">create account</a>` : `<span class="small">any git service with webhooks</span>`}
                    </div>
                `).join('')}
            </div>

            <div style="margin-top:1rem;">
                <label class="field">
                    <span class="lbl">
                        <span>Git User</span>
                        <label class="inline-checkbox">
                            <input type="checkbox" data-no-user ${this.draft.no_user ? 'checked' : ''} />
                            No user (token-only auth)
                        </label>
                    </span>
                    <input
                        type="text"
                        data-user
                        value="${(this.draft.user || '').replace(/"/g, '&quot;')}"
                        placeholder="${this.draft.no_user ? 'username not required' : 'Username, not email'}"
                        ${this.draft.no_user ? 'disabled' : ''}
                    />
                </label>
                <label class="field">
                    <span class="lbl"><span>Git Password or Token</span></span>
                    <input
                        type="password"
                        data-password
                        value="${(this.draft.password || '').replace(/"/g, '&quot;')}"
                        placeholder="${this.state?.settings?.password_stored ? 'Leave blank to reuse stored password' : 'Password or access token'}"
                    />
                </label>
            </div>
        `;
    }

    _step2() {
        const placeholder = this.draft.service && SERVICES[this.draft.service]
            ? `https://${SERVICES[this.draft.service].host}/your-user/your-repo.git`
            : 'https://github.com/your-user/your-repo.git';
        const isValid = !this.draft.repository || GIT_REGEX.test(this.draft.repository);
        return `
            <p>Paste the full <strong>HTTPS</strong> clone URL of your repository. Most hosts list it on the project page next to "Clone".</p>
            <p style="font-size:0.8125rem;color:var(--muted-foreground);">If you're starting from scratch, create the repo on the host first and check "initialize with a README" — Git Sync needs an initial commit to clone from.</p>
            <label class="field">
                <span class="lbl"><span>Git Repository</span></span>
                <input
                    type="text"
                    data-repo
                    class="${!isValid ? 'invalid' : ''}"
                    value="${(this.draft.repository || '').replace(/"/g, '&quot;')}"
                    placeholder="${placeholder}"
                />
            </label>
            <label class="field">
                <span class="lbl"><span>Branch (master / main)</span></span>
                <input
                    type="text"
                    data-branch
                    value="${(this.draft.branch || '').replace(/"/g, '&quot;')}"
                    placeholder="${this.draft.service ? SERVICES[this.draft.service].branch : 'main'}"
                />
            </label>

            <div class="verify-row">
                <button class="btn" data-test ${this.testing ? 'disabled' : ''}>
                    ${this.testing
                        ? `<span class="spin"></span> Testing…`
                        : `Verify Authentication, Connection &amp; Branch`}
                </button>
            </div>
            ${this.testResult ? `
                <div class="test-result ${this.testResult.status === 'success' ? 'success' : 'error'}">
                    ${this.testResult.message}
                </div>
            ` : ''}
        `;
    }

    _step3() {
        const frontendUrl = this.frontendUrl || window.location.origin;
        return `
            <p>A webhook lets the remote repository tell your site about pushes so changes show up immediately. Set the URL below in the repo's webhook settings on your git host.</p>
            <label class="field">
                <span class="lbl"><span>Webhook URL path</span></span>
                <input
                    type="text"
                    data-webhook
                    value="${(this.draft.webhook || '').replace(/"/g, '&quot;')}"
                    placeholder="/_git-sync"
                />
            </label>
            <p style="font-size:0.8125rem;">
                Full URL: <code>${frontendUrl}<span data-webhook-preview>${this.draft.webhook || '/_git-sync'}</span></code>
            </p>

            <label class="inline-checkbox" style="margin: 0.75rem 0; display: block;">
                <input type="checkbox" data-webhook-enabled ${this.draft.webhook_enabled ? 'checked' : ''} />
                Use a webhook secret (recommended; not supported by Bitbucket)
            </label>
            ${this.draft.webhook_enabled ? `
                <label class="field">
                    <span class="lbl"><span>Webhook Secret</span></span>
                    <input
                        type="text"
                        data-webhook-secret
                        value="${(this.draft.webhook_secret || '').replace(/"/g, '&quot;')}"
                        placeholder="random secret"
                    />
                </label>
            ` : ''}
        `;
    }

    _step4() {
        const folders = [
            { id: 'pages',   label: 'Pages',   warn: false, hint: 'Page content for the site.' },
            { id: 'themes',  label: 'Themes',  warn: false, hint: 'Theme files. Manual sync usually required for themes.' },
            { id: 'plugins', label: 'Plugins', warn: false, hint: 'Plugin packages.' },
            { id: 'config',  label: 'Config',  warn: true,  hint: 'Site configuration. May contain sensitive data.' },
            { id: 'data',    label: 'Data',    warn: true,  hint: 'Plugin-stored data. May contain sensitive data.' },
        ];
        return `
            <p>Pick which <code>user/</code> folders to keep under git control. You can change this later from the settings form.</p>
            <div class="folder-grid">
                ${folders.map(f => `
                    <label class="folder-card ${this.draft.folders.includes(f.id) ? 'selected' : ''}">
                        <input type="checkbox" data-folder="${f.id}" ${this.draft.folders.includes(f.id) ? 'checked' : ''} />
                        <div>
                            <div style="font-weight:600;font-size:0.875rem;">${f.label}</div>
                            <div style="font-size:0.75rem;color:var(--muted-foreground);">${f.hint}</div>
                            ${f.warn ? `<div class="warn">⚠ Use a private repo if syncing this folder.</div>` : ''}
                        </div>
                    </label>
                `).join('')}
            </div>
            ${this.saveError ? `<div class="save-error">${this.saveError}</div>` : ''}
        `;
    }

    _renderFooter() {
        const isLast = this.step === this.maxStep;
        const canNext = this._canAdvance();
        return `
            <div class="footer">
                <button class="btn" data-cancel>Cancel</button>
                <div class="footer-right">
                    ${this.step > 0 ? `<button class="btn" data-prev>Previous</button>` : ''}
                    ${!isLast
                        ? `<button class="btn btn-primary" data-next ${!canNext ? 'disabled' : ''}>Next →</button>`
                        : `<button class="btn btn-primary" data-save ${this.saving ? 'disabled' : ''}>
                            ${this.saving ? `<span class="spin"></span> Saving…` : 'Save & Finish'}
                          </button>`}
                </div>
            </div>
        `;
    }

    _canAdvance() {
        switch (this.step) {
            case 0: return true;
            case 1: {
                if (!this.draft.service) return false;
                if (!this.draft.no_user && !this.draft.user) return false;
                return true;
            }
            case 2: {
                if (!this.draft.repository || !GIT_REGEX.test(this.draft.repository)) return false;
                if (!this.draft.branch) return false;
                return true;
            }
            case 3: return true;
            default: return true;
        }
    }

    _wireFooter(root) {
        root.querySelector('[data-cancel]')?.addEventListener('click', () => this.close());
        root.querySelector('[data-prev]')?.addEventListener('click', () => {
            this.step = Math.max(0, this.step - 1);
            this.testResult = null;
            this._render();
        });
        root.querySelector('[data-next]')?.addEventListener('click', () => {
            if (!this._canAdvance()) return;
            this.step = Math.min(this.maxStep, this.step + 1);
            this.testResult = null;
            this._render();
        });
        root.querySelector('[data-save]')?.addEventListener('click', () => this._save());
    }

    _wireStep(root) {
        if (this.step === 1) {
            root.querySelectorAll('[data-svc]').forEach((el) => {
                el.addEventListener('click', () => {
                    this.draft.service = el.dataset.svc;
                    const svc = SERVICES[this.draft.service];
                    if (svc && (!this.draft.branch || ['master', 'main'].includes(this.draft.branch))) {
                        this.draft.branch = svc.branch;
                    }
                    this._render();
                });
            });
            root.querySelector('[data-no-user]')?.addEventListener('change', (e) => {
                this.draft.no_user = e.target.checked;
                if (this.draft.no_user) this.draft.user = '';
                this._render();
            });
            root.querySelector('[data-user]')?.addEventListener('input', (e) => {
                this.draft.user = e.target.value;
                this._updateNextButton();
            });
            root.querySelector('[data-password]')?.addEventListener('input', (e) => {
                this.draft.password = e.target.value;
            });
        }

        if (this.step === 2) {
            root.querySelector('[data-repo]')?.addEventListener('input', (e) => {
                this.draft.repository = e.target.value;
                const isValid = !this.draft.repository || GIT_REGEX.test(this.draft.repository);
                e.target.classList.toggle('invalid', !isValid);
                this.testResult = null;
                this._updateNextButton();
            });
            root.querySelector('[data-branch]')?.addEventListener('input', (e) => {
                this.draft.branch = e.target.value;
                this.testResult = null;
                this._updateNextButton();
            });
            root.querySelector('[data-test]')?.addEventListener('click', () => this._testConnection());
        }

        if (this.step === 3) {
            root.querySelector('[data-webhook]')?.addEventListener('input', (e) => {
                this.draft.webhook = e.target.value;
                const preview = root.querySelector('[data-webhook-preview]');
                if (preview) preview.textContent = e.target.value || '/_git-sync';
            });
            root.querySelector('[data-webhook-enabled]')?.addEventListener('change', (e) => {
                this.draft.webhook_enabled = e.target.checked;
                this._render();
            });
            root.querySelector('[data-webhook-secret]')?.addEventListener('input', (e) => {
                this.draft.webhook_secret = e.target.value;
            });
        }

        if (this.step === 4) {
            root.querySelectorAll('[data-folder]').forEach((cb) => {
                cb.addEventListener('change', (e) => {
                    const id = e.target.dataset.folder;
                    if (e.target.checked) {
                        if (!this.draft.folders.includes(id)) this.draft.folders.push(id);
                    } else {
                        this.draft.folders = this.draft.folders.filter(f => f !== id);
                    }
                    e.target.closest('.folder-card').classList.toggle('selected', e.target.checked);
                });
            });
        }
    }

    _updateNextButton() {
        const next = this.shadow.querySelector('[data-next]');
        if (next) {
            const canAdvance = this._canAdvance();
            next.toggleAttribute('disabled', !canAdvance);
        }
    }

    async _testConnection() {
        if (this.testing) return;
        this.testing = true;
        this.testResult = null;
        this._render();
        try {
            const result = await apiCall('POST', '/git-sync/test-connection', {
                user: this.draft.user,
                password: this.draft.password,
                repository: this.draft.repository,
                branch: this.draft.branch,
                no_user: this.draft.no_user,
            });
            this.testResult = result;
        } catch (err) {
            this.testResult = { status: 'error', message: err.message || String(err) };
        } finally {
            this.testing = false;
            this._render();
        }
    }

    async _save() {
        if (this.saving) return;
        this.saving = true;
        this.saveError = '';
        this._render();
        try {
            const repository = this.draft.repository;
            const payload = {
                repository,
                no_user: this.draft.no_user,
                user: this.draft.no_user ? '' : this.draft.user,
                branch: this.draft.branch,
                webhook: this.draft.webhook || undefined,
                webhook_enabled: this.draft.webhook_enabled,
                webhook_secret: this.draft.webhook_secret || undefined,
                folders: this.draft.folders,
                remote: { branch: this.draft.branch },
            };
            // Only send password if the user actually entered one — empty
            // means "keep existing" on the server side.
            if (this.draft.password) {
                payload.password = this.draft.password;
            }
            await apiCall('PATCH', '/git-sync/data', payload);

            // Notify the page so the form re-fetches its data.
            window.dispatchEvent(new CustomEvent('grav:plugin-data-changed', {
                detail: { plugin: 'git-sync' },
            }));

            this.close();
            // Soft-reload the plugin page so the form reflects the new state.
            // The page +page.svelte $effect on `slug` only fires on slug change,
            // so we trigger an in-place reload via location.reload().
            if (window.location.pathname.includes('/plugin/git-sync')) {
                window.location.reload();
            }
        } catch (err) {
            this.saveError = err.message || String(err);
            this.saving = false;
            this._render();
        }
    }
}

// Single shared instance — reopening the wizard reuses it.
const wizard = new WizardModal();

// ─── Page-action listener ───────────────────────────────────────────────

window.addEventListener('grav:plugin-page-action', (e) => {
    const detail = e.detail || {};
    if (detail.plugin !== 'git-sync') return;
    if (!detail.action || detail.action.id !== 'wizard') return;
    wizard.open();
});

// ─── Custom element (never instantiated, registered for completeness) ───

class GitSyncWidget extends HTMLElement {
    connectedCallback() {
        // The widget is registered with showFab: false, so this should not
        // run. If something does try to mount it, render a tiny pointer
        // back to the wizard so the operator can still get to it.
        this.innerHTML = `<button type="button">Open Wizard</button>`;
        this.querySelector('button').addEventListener('click', () => wizard.open());
    }
}
customElements.define(TAG, GitSyncWidget);
