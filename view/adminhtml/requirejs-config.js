/**
 * Angeo AEO Audit — admin RequireJS configuration.
 *
 * Chart.js is bundled locally (view/adminhtml/web/js/lib/chart.umd.js,
 * Chart.js v4.4.1, MIT license) instead of being loaded from a third-party
 * CDN: avoids a supply-chain attack vector inside the admin panel, satisfies
 * strict Content-Security-Policy, and works in air-gapped environments.
 */
var config = {
    paths: {
        angeoChart: 'Angeo_AeoAudit/js/lib/chart.umd'
    }
};
