import './scss/base.scss';
import FibLiveProductPulseStockPlugin from './plugin/fib-live-product-pulse-stock.plugin';
import FibLiveProductPulseViewerPlugin from './plugin/fib-live-product-pulse-viewer.plugin';

const PluginManager = window.PluginManager;

PluginManager.register(
    'FibLiveProductPulseStock',
    FibLiveProductPulseStockPlugin,
    '[data-fib-live-product-pulse-stock]'
);

PluginManager.register(
    'FibLiveProductPulseViewer',
    FibLiveProductPulseViewerPlugin,
    '[data-fib-live-product-pulse-viewer]'
);
