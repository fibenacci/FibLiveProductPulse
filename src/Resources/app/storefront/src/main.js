import './scss/base.scss';
import FibLiveProductPulsePlugin from './plugin/fib-live-product-pulse.plugin';

const PluginManager = window.PluginManager;

PluginManager.register(
    'FibLiveProductPulse',
    FibLiveProductPulsePlugin,
    '[data-fib-live-product-pulse]'
);
