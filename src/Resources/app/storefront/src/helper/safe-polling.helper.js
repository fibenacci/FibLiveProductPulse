export default class SafePollingHelper {
    constructor(options) {
        this.options = Object.assign({
            intervalMs: 4000,
            backgroundIntervalMs: 15000,
            maxBackoffMs: 60000,
            jitterRatio: 0.15,
            requestTimeoutMs: 4000,
            task: null,
            onResult: null,
            onError: null,
        }, options || {});

        this.timer = null;
        this.timeoutTimer = null;
        this.abortController = null;
        this.inFlight = false;
        this.stopped = true;
        this.errorCount = 0;
        this.sequence = 0;
        this.latestAppliedSequence = 0;

        this._boundVisibilityChange = this._onVisibilityChange.bind(this);
    }

    start() {
        if (this.stopped === false || typeof this.options.task !== 'function') {
            return;
        }

        this.stopped = false;
        document.addEventListener('visibilitychange', this._boundVisibilityChange);
        this.runNow();
    }

    stop() {
        this.stopped = true;

        if (this.timer) {
            window.clearTimeout(this.timer);
            this.timer = null;
        }

        if (this.timeoutTimer) {
            window.clearTimeout(this.timeoutTimer);
            this.timeoutTimer = null;
        }

        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }

        this.inFlight = false;
        document.removeEventListener('visibilitychange', this._boundVisibilityChange);
    }

    runNow() {
        if (this.stopped || this.inFlight || typeof this.options.task !== 'function') {
            return;
        }

        this.inFlight = true;
        this.sequence += 1;
        const sequence = this.sequence;

        if (typeof AbortController !== 'undefined') {
            this.abortController = new AbortController();
            this.timeoutTimer = window.setTimeout(() => {
                if (this.abortController) {
                    this.abortController.abort();
                }
            }, Number(this.options.requestTimeoutMs));
        } else {
            this.abortController = null;
        }

        Promise.resolve()
            .then(() => this.options.task({
                sequence,
                signal: this.abortController ? this.abortController.signal : null,
            }))
            .then((result) => {
                if (this.stopped || sequence < this.latestAppliedSequence) {
                    return;
                }

                this.latestAppliedSequence = sequence;
                this.errorCount = 0;

                if (typeof this.options.onResult === 'function') {
                    this.options.onResult(result, { sequence });
                }

                this._schedule(this._normalIntervalWithJitter());
            })
            .catch((error) => {
                if (this.stopped) {
                    return;
                }

                this.errorCount += 1;

                if (typeof this.options.onError === 'function') {
                    this.options.onError(error, { sequence, errorCount: this.errorCount });
                }

                this._schedule(this._backoffIntervalWithJitter());
            })
            .finally(() => {
                this.inFlight = false;

                if (this.timeoutTimer) {
                    window.clearTimeout(this.timeoutTimer);
                    this.timeoutTimer = null;
                }

                this.abortController = null;
            });
    }

    reschedule() {
        if (this.stopped) {
            return;
        }

        if (this.timer) {
            window.clearTimeout(this.timer);
            this.timer = null;
        }

        if (!this.inFlight) {
            this._schedule(this._normalIntervalWithJitter());
        }
    }

    _schedule(delayMs) {
        if (this.stopped) {
            return;
        }

        if (this.timer) {
            window.clearTimeout(this.timer);
        }

        this.timer = window.setTimeout(() => {
            this.timer = null;
            this.runNow();
        }, delayMs);
    }

    _normalIntervalWithJitter() {
        const base = document.hidden
            ? Number(this.options.backgroundIntervalMs)
            : Number(this.options.intervalMs);

        return this._applyJitter(base);
    }

    _backoffIntervalWithJitter() {
        const currentBase = document.hidden
            ? Number(this.options.backgroundIntervalMs)
            : Number(this.options.intervalMs);
        const factor = Math.min(Math.max(this.errorCount, 1), 6);
        const interval = Math.min(
            Number(this.options.maxBackoffMs),
            currentBase * Math.pow(2, factor - 1)
        );

        return this._applyJitter(interval);
    }

    _applyJitter(base) {
        const safeBase = Math.max(1000, Number(base) || 1000);
        const jitterRatio = Math.max(0, Math.min(0.5, Number(this.options.jitterRatio) || 0));
        const jitter = safeBase * jitterRatio;
        const randomized = safeBase - jitter + (Math.random() * jitter * 2);

        return Math.round(randomized);
    }

    _onVisibilityChange() {
        this.reschedule();
    }
}
