import Plugin from 'src/plugin-system/plugin.class';
import ElementLoadingIndicatorUtil from 'src/utility/loading-indicator/element-loading-indicator.util';
import SafePollingHelper from '../helper/safe-polling.helper';

export default class FibLiveProductPulseViewerPlugin extends Plugin {
    static options = {
        viewerFeatureEnabled: true,
        viewerEndpoint: '',
        viewerLeaveEndpoint: '',
        pollIntervalMs: 4000,
        backgroundPollIntervalMs: 15000,
        requestTimeoutMs: 4000,
        maxBackoffMs: 60000,
        jitterRatio: 0.15,
        clientTokenStorageKey: 'fib-live-product-pulse-client-token',
        viewerTexts: {
            zero: 'Gerade keine aktiven Betrachter',
            one: '1 aktiver Betrachter',
            many: '%count% aktive Betrachter',
        },
    };

    init() {
        this.viewerFeatureEnabled = Boolean(this.options.viewerFeatureEnabled);
        if (!this.viewerFeatureEnabled || !this.options.viewerEndpoint) {
            return;
        }

        this.viewerLine = this.el;
        this.viewerLineText = this.el.querySelector('[data-fib-live-product-pulse-viewers-text]');
        if (!this.viewerLineText) {
            return;
        }

        this.clientToken = this._resolveClientToken();
        this.isDestroyed = false;
        this._leaveSent = false;
        this._initialLoadingResolved = false;
        this._showInitialLoading();

        this.viewerPoller = new SafePollingHelper({
            intervalMs: Number(this.options.pollIntervalMs),
            backgroundIntervalMs: Number(this.options.backgroundPollIntervalMs),
            requestTimeoutMs: Number(this.options.requestTimeoutMs),
            maxBackoffMs: Number(this.options.maxBackoffMs),
            jitterRatio: Number(this.options.jitterRatio),
            task: ({signal}) => this._fetchViewerState(signal),
            onResult: (payload) => this._handleViewerPollingResult(payload),
            onError: () => this._handleViewerPollingError(),
        });
        this.viewerPoller.start();

        this._boundPageHide = this._handlePageHide.bind(this);
        window.addEventListener('pagehide', this._boundPageHide);
        window.addEventListener('beforeunload', this._boundPageHide);
    }

    destroy() {
        this.isDestroyed = true;

        if (this.viewerPoller) {
            this.viewerPoller.stop();
            this.viewerPoller = null;
        }

        if (this._boundPageHide) {
            window.removeEventListener('pagehide', this._boundPageHide);
            window.removeEventListener('beforeunload', this._boundPageHide);
        }

        this._sendViewerLeave();
        this._clearInitialLoading();
    }

    _handlePageHide() {
        this._sendViewerLeave();
    }

    _fetchViewerState(signal) {
        const requestOptions = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                clientToken: this.clientToken,
            }),
            credentials: 'same-origin',
            cache: 'no-store',
        };

        if (signal) {
            requestOptions.signal = signal;
        }

        return fetch(this.options.viewerEndpoint, requestOptions).then((response) => {
            if (!response.ok) {
                throw new Error('Viewer polling request failed');
            }

            return response.json();
        }).then((payload) => {
            if (!payload?.success || !payload.data) {
                throw new Error('Viewer polling payload invalid');
            }

            return payload;
        });
    }

    _handleViewerPollingResult(payload) {
        if (this.isDestroyed || !payload?.data) {
            return;
        }

        this._clearInitialLoading();
        this._updateViewers(Number(payload.data.viewerCount || 0));
    }

    _handleViewerPollingError() {
        this._clearInitialLoading();
        this.viewerLine.hidden = true;
    }

    _updateViewers(count) {
        this.viewerLine.hidden = false;
        this.viewerLineText.textContent = this._viewerText(count);
    }

    _viewerText(count) {
        const texts = this.options.viewerTexts || {};

        if (count <= 0) {
            return texts.zero || 'Gerade keine aktiven Betrachter';
        }

        if (count === 1) {
            return texts.one || '1 aktiver Betrachter';
        }

        return (texts.many || '%count% aktive Betrachter').replace('%count%', String(count));
    }

    _resolveClientToken() {
        const storageKey = this.options.clientTokenStorageKey || 'fib-live-product-pulse-client-token';
        const generated = () => {
            if (window.crypto && window.crypto.getRandomValues) {
                const bytes = new Uint8Array(16);
                window.crypto.getRandomValues(bytes);

                return Array.from(bytes).map((value) => value.toString(16).padStart(2, '0')).join('');
            }

            return String(Date.now()) + String(Math.random()).replace('.', '');
        };

        try {
            const existing = window.localStorage.getItem(storageKey);
            if (existing) {
                return existing;
            }

            const token = generated();
            window.localStorage.setItem(storageKey, token);

            return token;
        } catch (_) {
            if (!this._memoryClientToken) {
                this._memoryClientToken = generated();
            }

            return this._memoryClientToken;
        }
    }

    _sendViewerLeave() {
        if (this._leaveSent || !this.options.viewerLeaveEndpoint || !this.clientToken) {
            return;
        }

        this._leaveSent = true;

        const body = JSON.stringify({
            clientToken: this.clientToken,
        });

        if (navigator.sendBeacon) {
            const blob = new Blob([body], {type: 'application/json'});
            navigator.sendBeacon(this.options.viewerLeaveEndpoint, blob);

            return;
        }

        fetch(this.options.viewerLeaveEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {
        });
    }

    _showInitialLoading() {
        if (!this.viewerLine) {
            return;
        }

        this.viewerLine.hidden = false;

        this.viewerLine.classList.add('fib-live-product-pulse-is-loading');
        ElementLoadingIndicatorUtil.create(this.viewerLine);
    }

    _clearInitialLoading() {
        if (this._initialLoadingResolved) {
            return;
        }

        this._initialLoadingResolved = true;

        if (!this.viewerLine) {
            return;
        }

        this.viewerLine.classList.remove('fib-live-product-pulse-is-loading');
        ElementLoadingIndicatorUtil.remove(this.viewerLine);
    }
}
