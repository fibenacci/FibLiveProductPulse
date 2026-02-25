import Plugin from 'src/plugin-system/plugin.class';
import SafePollingHelper from '../helper/safe-polling.helper';

export default class FibLiveProductPulsePlugin extends Plugin {
    static options = {
        stockStateEndpoint: '',
        viewerEndpoint: '',
        viewerLeaveEndpoint: '',
        cartPresenceHeartbeatEndpoint: '',
        cartPresenceLeaveEndpoint: '',
        pollIntervalMs: 4000,
        backgroundPollIntervalMs: 15000,
        requestTimeoutMs: 4000,
        maxBackoffMs: 60000,
        jitterRatio: 0.15,
        clientTokenStorageKey: 'fib-live-product-pulse-client-token',
        statusTexts: {
            available: 'Verfuegbar',
            soldout: 'Vergriffen',
            reserved: 'Bereits reserviert',
            restock: 'Bald wieder verfuegbar',
            preorder: 'Vorbestellung',
            notAvailable: 'Nicht verfuegbar',
        },
        viewerTexts: {
            zero: 'Gerade keine aktiven Betrachter',
            one: '1 aktiver Betrachter',
            many: '%count% aktive Betrachter',
        },
    };

    init() {
        this.deliveryWrapper = this.el.querySelector('[data-fib-live-product-pulse-delivery]');
        this.viewerLine = this.el.querySelector('[data-fib-live-product-pulse-viewers]');
        this.viewerLineText = this.el.querySelector('[data-fib-live-product-pulse-viewers-text]');

        if (!this.deliveryWrapper || !this.options.stockStateEndpoint) {
            return;
        }

        this.clientToken = this._resolveClientToken();
        this.isDestroyed = false;
        this.stockStateEtag = null;
        this._leaveSent = false;

        this.stockPoller = new SafePollingHelper({
            intervalMs: Number(this.options.pollIntervalMs),
            backgroundIntervalMs: Number(this.options.backgroundPollIntervalMs),
            requestTimeoutMs: Number(this.options.requestTimeoutMs),
            maxBackoffMs: Number(this.options.maxBackoffMs),
            jitterRatio: Number(this.options.jitterRatio),
            task: ({ signal }) => this._fetchStockState(signal),
            onResult: (payload) => this._handleStockPollingResult(payload),
        });

        this.stockPoller.start();

        if (this.options.viewerEndpoint) {
            this.viewerPoller = new SafePollingHelper({
                intervalMs: Number(this.options.pollIntervalMs),
                backgroundIntervalMs: Number(this.options.backgroundPollIntervalMs),
                requestTimeoutMs: Number(this.options.requestTimeoutMs),
                maxBackoffMs: Number(this.options.maxBackoffMs),
                jitterRatio: Number(this.options.jitterRatio),
                task: ({ signal }) => this._fetchViewerState(signal),
                onResult: (payload) => this._handleViewerPollingResult(payload),
            });

            this.viewerPoller.start();
        }

        if (this.options.cartPresenceHeartbeatEndpoint) {
            this.cartPresencePoller = new SafePollingHelper({
                intervalMs: Number(this.options.pollIntervalMs),
                backgroundIntervalMs: Number(this.options.backgroundPollIntervalMs),
                requestTimeoutMs: Number(this.options.requestTimeoutMs),
                maxBackoffMs: Number(this.options.maxBackoffMs),
                jitterRatio: Number(this.options.jitterRatio),
                task: ({ signal }) => this._sendCartPresenceHeartbeat(signal),
            });

            this.cartPresencePoller.start();
        }

        this._boundPageHide = this._handleViewerLeave.bind(this);
        window.addEventListener('pagehide', this._boundPageHide);
        window.addEventListener('beforeunload', this._boundPageHide);
    }

    destroy() {
        this.isDestroyed = true;

        if (this.stockPoller) {
            this.stockPoller.stop();
            this.stockPoller = null;
        }

        if (this.viewerPoller) {
            this.viewerPoller.stop();
            this.viewerPoller = null;
        }

        if (this.cartPresencePoller) {
            this.cartPresencePoller.stop();
            this.cartPresencePoller = null;
        }

        if (this._boundPageHide) {
            window.removeEventListener('pagehide', this._boundPageHide);
            window.removeEventListener('beforeunload', this._boundPageHide);
        }

        this._sendViewerLeave();
        this._sendCartPresenceLeave();
    }

    _fetchStockState(signal) {
        const requestOptions = {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            cache: 'no-cache',
        };

        if (this.stockStateEtag) {
            requestOptions.headers['If-None-Match'] = this.stockStateEtag;
        }

        if (signal) {
            requestOptions.signal = signal;
        }

        return fetch(this.options.stockStateEndpoint, requestOptions)
            .then((response) => {
                if (response.status === 304) {
                    return {
                        notModified: true,
                    };
                }

                if (!response.ok) {
                    throw new Error('Polling request failed');
                }

                const nextEtag = response.headers.get('ETag');
                if (nextEtag) {
                    this.stockStateEtag = nextEtag;
                }

                return response.json();
            })
            .then((payload) => {
                if (payload && payload.notModified === true) {
                    return payload;
                }

                if (!payload || payload.success !== true || !payload.data) {
                    throw new Error('Polling payload invalid');
                }

                return payload;
            });
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

        return fetch(this.options.viewerEndpoint, requestOptions)
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Viewer polling request failed');
                }

                return response.json();
            })
            .then((payload) => {
                if (!payload || payload.success !== true || !payload.data) {
                    throw new Error('Viewer polling payload invalid');
                }

                return payload;
            });
    }

    _sendCartPresenceHeartbeat(signal) {
        const requestOptions = {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            cache: 'no-store',
        };

        if (signal) {
            requestOptions.signal = signal;
        }

        return fetch(this.options.cartPresenceHeartbeatEndpoint, requestOptions)
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Cart presence heartbeat failed');
                }

                return response;
            });
    }

    _handleStockPollingResult(payload) {
        if (this.isDestroyed || !payload || payload.notModified === true) {
            return;
        }

        if (!payload.data) {
            return;
        }

        this._applyStockState(payload.data);
    }

    _handleViewerPollingResult(payload) {
        if (this.isDestroyed || !payload || !payload.data) {
            return;
        }

        this._applyViewerState(payload.data);
    }

    _applyStockState(data) {
        this._updateDelivery(data.statusCode);
        this._updateBuyFormVisibility(data);
    }

    _applyViewerState(data) {
        this._updateViewers(Number(data.viewerCount || 0));
    }

    _updateDelivery(statusCode) {
        const deliveryInfo = this.deliveryWrapper.querySelector('.product-delivery-information');
        if (!deliveryInfo) {
            return;
        }

        const statusLine = this._findOrCreateStatusLine(deliveryInfo);
        if (!statusLine) {
            return;
        }

        const statusConfig = this._statusConfig(statusCode);

        statusLine.classList.remove(
            'delivery-not-available',
            'delivery-preorder',
            'delivery-available',
            'delivery-soldout',
            'delivery-reserved',
            'delivery-restock'
        );
        statusLine.classList.add('delivery-information', statusConfig.lineClass);

        let indicator = statusLine.querySelector('.delivery-status-indicator');
        if (!indicator) {
            indicator = document.createElement('span');
            indicator.className = 'delivery-status-indicator';
            statusLine.prepend(indicator);
        }

        indicator.classList.remove('bg-info', 'bg-danger', 'bg-warning', 'bg-success');
        indicator.classList.add(statusConfig.indicatorClass);

        const text = this._statusText(statusCode);
        statusLine.textContent = '';
        statusLine.appendChild(indicator);
        statusLine.appendChild(document.createTextNode(' ' + text));

        this._updateAvailabilityLink(deliveryInfo, statusConfig.schemaHref, statusLine);
    }

    _findOrCreateStatusLine(deliveryInfo) {
        const selector = [
            '.delivery-information.delivery-not-available',
            '.delivery-information.delivery-preorder',
            '.delivery-information.delivery-available',
            '.delivery-information.delivery-soldout',
            '.delivery-information.delivery-reserved',
            '.delivery-information.delivery-restock',
        ].join(', ');

        let statusLine = deliveryInfo.querySelector(selector);
        if (statusLine) {
            return statusLine;
        }

        statusLine = document.createElement('p');
        statusLine.className = 'delivery-information delivery-available';

        const shippingFreeLine = deliveryInfo.querySelector('.delivery-information.delivery-shipping-free');
        if (shippingFreeLine && shippingFreeLine.parentNode) {
            shippingFreeLine.insertAdjacentElement('afterend', statusLine);
        } else {
            deliveryInfo.appendChild(statusLine);
        }

        return statusLine;
    }

    _updateAvailabilityLink(deliveryInfo, schemaHref, statusLine) {
        let link = deliveryInfo.querySelector('link[itemprop="availability"]');

        if (!link) {
            link = document.createElement('link');
            link.setAttribute('itemprop', 'availability');

            if (statusLine.parentNode) {
                statusLine.insertAdjacentElement('beforebegin', link);
            } else {
                deliveryInfo.appendChild(link);
            }
        }

        link.setAttribute('href', schemaHref);
    }

    _updateViewers(count) {
        if (!this.viewerLine || !this.viewerLineText) {
            return;
        }

        this.viewerLine.hidden = false;
        this.viewerLineText.textContent = this._viewerText(count);
    }

    _statusText(statusCode) {
        const texts = this.options.statusTexts || {};

        if (statusCode === 'available' && texts.available) {
            return texts.available;
        }

        if (statusCode === 'soldout' && texts.soldout) {
            return texts.soldout;
        }

        if (statusCode === 'reserved' && texts.reserved) {
            return texts.reserved;
        }

        if (statusCode === 'restock' && texts.restock) {
            return texts.restock;
        }

        if (statusCode === 'preorder' && texts.preorder) {
            return texts.preorder;
        }

        return texts.notAvailable || 'Nicht verfuegbar';
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

    _statusConfig(statusCode) {
        switch (statusCode) {
            case 'available':
                return {
                    lineClass: 'delivery-available',
                    indicatorClass: 'bg-success',
                    schemaHref: 'https://schema.org/InStock',
                };
            case 'preorder':
                return {
                    lineClass: 'delivery-preorder',
                    indicatorClass: 'bg-warning',
                    schemaHref: 'https://schema.org/PreOrder',
                };
            case 'restock':
                return {
                    lineClass: 'delivery-restock',
                    indicatorClass: 'bg-warning',
                    schemaHref: 'https://schema.org/LimitedAvailability',
                };
            case 'soldout':
                return {
                    lineClass: 'delivery-soldout',
                    indicatorClass: 'bg-danger',
                    schemaHref: 'https://schema.org/OutOfStock',
                };
            case 'reserved':
                return {
                    lineClass: 'delivery-reserved',
                    indicatorClass: 'bg-warning',
                    schemaHref: 'https://schema.org/LimitedAvailability',
                };
            default:
                return {
                    lineClass: 'delivery-not-available',
                    indicatorClass: 'bg-danger',
                    schemaHref: 'https://schema.org/LimitedAvailability',
                };
        }
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

    _handleViewerLeave() {
        this._sendViewerLeave();
        this._sendCartPresenceLeave();
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
            const blob = new Blob([body], { type: 'application/json' });
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
        }).catch(() => {});
    }

    _sendCartPresenceLeave() {
        if (this._cartPresenceLeaveSent || !this.options.cartPresenceLeaveEndpoint) {
            return;
        }

        this._cartPresenceLeaveSent = true;

        if (navigator.sendBeacon) {
            navigator.sendBeacon(this.options.cartPresenceLeaveEndpoint, new Blob(['{}'], { type: 'application/json' }));

            return;
        }

        fetch(this.options.cartPresenceLeaveEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '{}',
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
    }

    _updateBuyFormVisibility(data) {
        const shouldLock = Boolean(data && data.lockReservedProducts);
        const isReserved = Boolean(data && (data.isReservedByOtherCart || data.statusCode === 'reserved'));
        const buyFormContainer = this._findBuyFormContainer();

        if (!buyFormContainer) {
            return;
        }

        if (shouldLock && isReserved) {
            buyFormContainer.hidden = true;

            return;
        }

        buyFormContainer.hidden = false;
    }

    _findBuyFormContainer() {
        if (this.buyFormContainer) {
            return this.buyFormContainer;
        }

        const buyWidget = this.el.closest('.product-detail-buy');
        if (!buyWidget) {
            return null;
        }

        this.buyFormContainer = buyWidget.querySelector('.product-detail-form-container');

        return this.buyFormContainer;
    }
}
