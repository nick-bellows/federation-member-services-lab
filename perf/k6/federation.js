// Synthetic load on three endpoints (B6, docs/PERFORMANCE.md). Run from the
// repository root with the stack up and the performance seed loaded:
//
//   docker run --rm --network vereinfacht_stack -v "$PWD/perf/k6:/scripts" -v "$PWD/docs/baseline:/results" \
//     -e BASE_URL=http://api -e SANCTUM_TOKEN=... -e OIDC_TOKEN=... -e LABEL=before \
//     grafana/k6:0.54.0 run /scripts/federation.js
//
// Numbers from a laptop compare only with numbers from the same laptop, same
// seed, same settings; the document says so. Tokens come from the environment
// and never from this file.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.4/index.js';

const BASE = __ENV.BASE_URL || 'http://api';
const LABEL = __ENV.LABEL || 'run';
const VUS = Number(__ENV.VUS || 10);
const DURATION = __ENV.DURATION || '30s';

const jsonApi = (token) => ({
    headers: { Accept: 'application/vnd.api+json', Authorization: `Bearer ${token}` },
    tags: {},
});

export const options = {
    scenarios: {
        // Upstream's memberships listing for a club administrator: tenant-scoped
        // on club_id, one member count and one fee lookup per row (M0 finding).
        memberships_listing: {
            executor: 'constant-vus', vus: VUS, duration: DURATION, exec: 'membershipsListing', startTime: '0s',
        },
        // The federation review queue for a reviewer: scoped index with eager loads.
        applications_index: {
            executor: 'constant-vus', vus: VUS, duration: DURATION, exec: 'applicationsIndex', startTime: '35s',
        },
        // The identity endpoint: token verification and one user lookup, the floor.
        identity_me: {
            executor: 'constant-vus', vus: VUS, duration: DURATION, exec: 'identityMe', startTime: '70s',
        },
    },
    // Thresholds per scenario also make k6 keep per-scenario sub-metrics for the summary.
    thresholds: {
        'http_req_failed{scenario:memberships_listing}': ['rate<0.01'],
        'http_req_failed{scenario:applications_index}': ['rate<0.01'],
        'http_req_failed{scenario:identity_me}': ['rate<0.01'],
        'http_req_duration{scenario:memberships_listing}': ['p(95)<10000'],
        'http_req_duration{scenario:applications_index}': ['p(95)<10000'],
        'http_req_duration{scenario:identity_me}': ['p(95)<10000'],
        'http_reqs{scenario:memberships_listing}': ['count>0'],
        'http_reqs{scenario:applications_index}': ['count>0'],
        'http_reqs{scenario:identity_me}': ['count>0'],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'max'],
};

export function membershipsListing() {
    const res = http.get(`${BASE}/api/v1/memberships?page[size]=50`, {
        ...jsonApi(__ENV.SANCTUM_TOKEN), tags: { name: 'GET /api/v1/memberships' },
    });
    check(res, { 'memberships 200': (r) => r.status === 200 });
    sleep(0.2);
}

export function applicationsIndex() {
    const res = http.get(`${BASE}/api/v1/federation/registration-applications?filter[status]=approved`, {
        ...jsonApi(__ENV.OIDC_TOKEN), tags: { name: 'GET /api/v1/federation/registration-applications' },
    });
    check(res, { 'applications 200': (r) => r.status === 200 });
    sleep(0.2);
}

export function identityMe() {
    const res = http.get(`${BASE}/api/v1/federation-identity/me`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${__ENV.OIDC_TOKEN}` },
        tags: { name: 'GET /api/v1/federation-identity/me' },
    });
    check(res, { 'me 200': (r) => r.status === 200 });
    sleep(0.2);
}

export function handleSummary(data) {
    const scenarios = ['memberships_listing', 'applications_index', 'identity_me'];
    const table = {};
    for (const s of scenarios) {
        const d = data.metrics[`http_req_duration{scenario:${s}}`];
        const f = data.metrics[`http_req_failed{scenario:${s}}`];
        const reqs = data.metrics[`http_reqs{scenario:${s}}`];
        table[s] = d ? {
            p50_ms: Math.round(d.values.med), p95_ms: Math.round(d.values['p(95)']), max_ms: Math.round(d.values.max),
            rps: reqs ? Number(reqs.values.rate.toFixed(1)) : null, failed_rate: f ? f.values.rate : null,
        } : null;
    }
    const out = { label: LABEL, vus: VUS, duration: DURATION, generated_at: new Date().toISOString(), scenarios: table };
    return {
        [`/results/perf_${LABEL}.json`]: JSON.stringify(out, null, 2) + '\n',
        stdout: textSummary(data, { indent: ' ', enableColors: false }),
    };
}
