import Plugin from 'src/plugin-system/plugin.class';
import SafePollingHelper from '../helper/safe-polling.helper';

export default class FibLiveProductPulseStockPlugin extends Plugin {
    static options = {
        stockFeatureEnabled: true,
        stockStateEndpoint: '',
        cartPresenceHeartbeatEndpoint: '',
        cartPresenceLeaveEndpoint: '',
        pollIntervalMs: 4000,
        backgroundPollIntervalMs: 15000,
        requestTimeoutMs: 4000,
        maxBackoffMs: 60000,
        jitterRatio: 0.15,
        statusTexts: {
            available: 'Verfuegbar',
            soldout: 'Vergriffen',
            reserved: 'Bereits reserviert',
            restock: 'Bald wieder verfuegbar',
            preorder: 'Vorbestellung',
            notAvailable: 'Nicht verfuegbar',
        },
    };

    init() {
        this.stockFeatureEnabled = Boolean(this.options.stockFeatureEnabled);
        if (!this.stockFeatureEnabled || !this.options.stockStateEndpoint) {
            return;
        }

        this.deliveryWrapper = this.el;
        this.isDestroyed = false;
        this.stockStateEtag = null;
        this._cartPresenceLeaveSent = false;
        this._cartPresencePollingActive = false;

        this.stockPoller = new SafePollingHelper({
            intervalMs: Number(this.options.pollIntervalMs),
            backgroundIntervalMs: Number(this.options.backgroundPollIntervalMs),
            requestTimeoutMs: Number(this.options.requestTimeoutMs),
            maxBackoffMs: Number(this.options.maxBackoffMs),
            jitterRatio: Number(this.options.jitterRatio),
            task: ({signal}) => this._fetchStockState(signal),
            onResult: (payload) => this._handleStockPollingResult(payload),
        });
        this.stockPoller.start();

        if (this.options.cartPresenceHeartbeatEndpoint) {
            this._setCartPresencePollingEnabled(true);
        }

        this._boundPageHide = this._handlePageHide.bind(this);
        window.addEventListener('pagehide', this._boundPageHide);
        window.addEventListener('beforeunload', this._boundPageHide);
    }

    destroy() {
        this.isDestroyed = true;

        if (this.stockPoller) {
            this.stockPoller.stop();
            this.stockPoller = null;
        }

        if (this.cartPresencePoller) {
            this.cartPresencePoller.stop();
            this.cartPresencePoller = null;
        }

        if (this._boundPageHide) {
            window.removeEventListener('pagehide', this._boundPageHide);
            window.removeEventListener('beforeunload', this._boundPageHide);
        }

        this._sendCartPresenceLeave();
    }

    _handlePageHide() {
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

        return fetch(this.options.stockStateEndpoint, requestOptions).then((response) => {
            if (response.status === 304) {
                return {notModified: true};
            }

            if (!response.ok) {
                throw new Error('Polling request failed');
            }

            const nextEtag = response.headers.get('ETag');
            if (nextEtag) {
                this.stockStateEtag = nextEtag;
            }

            return response.json();
        }).then((payload) => {
            if (payload?.notModified === true) {
                return payload;
            }

            if (!payload?.success || !payload?.data) {
                throw new Error('Polling payload invalid');
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

        return fetch(
            this.options.cartPresenceHeartbeatEndpoint,
            requestOptions
        ).then((response) => {
            if (!response.ok) {
                throw new Error('Cart presence heartbeat failed');
            }

            return response;
        });
    }

    _handleStockPollingResult(payload) {
        if (this.isDestroyed || payload?.notModified === true || !payload?.data) {
            return;
        }

        this._syncSmartPollingMode(payload.data);
        this._updateDelivery(payload.data.statusCode);
        this._updateBuyFormVisibility(payload.data);
    }

    _syncSmartPollingMode(data) {
        if (!this.options.cartPresenceHeartbeatEndpoint) {
            return;
        }

        const smartPollingActive = Boolean(data?.smartPollingActive ?? true);
        this._setCartPresencePollingEnabled(smartPollingActive);
    }

    _setCartPresencePollingEnabled(shouldEnable) {
        const nextState = Boolean(shouldEnable && this.options.cartPresenceHeartbeatEndpoint);

        if (nextState === this._cartPresencePollingActive) {
            return;
        }

        this._cartPresencePollingActive = nextState;

        if (!nextState) {
            if (this.cartPresencePoller) {
                this.cartPresencePoller.stop();
                this.cartPresencePoller = null;
            }

            this._sendCartPresenceLeave();

            return;
        }

        this._cartPresenceLeaveSent = false;
        this.cartPresencePoller = new SafePollingHelper({
            intervalMs: Number(this.options.pollIntervalMs),
            backgroundIntervalMs: Number(this.options.backgroundPollIntervalMs),
            requestTimeoutMs: Number(this.options.requestTimeoutMs),
            maxBackoffMs: Number(this.options.maxBackoffMs),
            jitterRatio: Number(this.options.jitterRatio),
            task: ({signal}) => this._sendCartPresenceHeartbeat(signal),
        });
        this.cartPresencePoller.start();
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
        if (shippingFreeLine?.parentNode) {
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

    _statusText(statusCode) {
        const texts = this.options.statusTexts;

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

        return texts.notAvailable;
    }

    _statusConfig(statusCode) {
        switch (statusCode) {
            case 'available':
                return {
                    lineClass: 'delivery-available',
                    indicatorClass: 'bg-success',
                    schemaHref: 'https://schema.org/InStock'
                };
            case 'preorder':
                return {
                    lineClass: 'delivery-preorder',
                    indicatorClass: 'bg-warning',
                    schemaHref: 'https://schema.org/PreOrder'
                };
            case 'restock':
                return {
                    lineClass: 'delivery-restock',
                    indicatorClass: 'bg-warning',
                    schemaHref: 'https://schema.org/LimitedAvailability'
                };
            case 'soldout':
                return {
                    lineClass: 'delivery-soldout',
                    indicatorClass: 'bg-danger',
                    schemaHref: 'https://schema.org/OutOfStock'
                };
            case 'reserved':
                return {
                    lineClass: 'delivery-reserved',
                    indicatorClass: 'bg-warning',
                    schemaHref: 'https://schema.org/LimitedAvailability'
                };
            default:
                return {
                    lineClass: 'delivery-not-available',
                    indicatorClass: 'bg-danger',
                    schemaHref: 'https://schema.org/LimitedAvailability'
                };
        }
    }

    _updateBuyFormVisibility(data) {
        const shouldLock = Boolean(data?.lockReservedProducts);
        const isReserved = Boolean((data?.isReservedByOtherCart || data?.statusCode === 'reserved'));
        const buyFormContainer = this._findBuyFormContainer();

        if (!buyFormContainer) {
            return;
        }

        buyFormContainer.hidden = shouldLock && isReserved;
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

    _sendCartPresenceLeave() {
        if (this._cartPresenceLeaveSent || !this.options.cartPresenceLeaveEndpoint) {
            return;
        }

        this._cartPresenceLeaveSent = true;

        if (navigator.sendBeacon) {
            navigator.sendBeacon(
                this.options.cartPresenceLeaveEndpoint,
                new Blob(['{}'],
                    {type: 'application/json'}
                ));

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
        }).catch(() => {
        });
    }
}
