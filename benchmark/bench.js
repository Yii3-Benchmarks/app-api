import http from 'k6/http';
import { check } from 'k6';

export const options = {
    scenarios: {
        home: {
            executor: 'ramping-vus',
            exec: 'benchmarkHome',
            stages: [
                { duration: '30s', target: 25 },
                { duration: '60s', target: 100 },
                { duration: '60s', target: 100 },
                { duration: '10s', target: 0 },
            ],
            tags: { endpoint: 'home' },
        },
        postgresOrders: {
            executor: 'ramping-vus',
            exec: 'benchmarkPostgresOrders',
            stages: [
                { duration: '30s', target: 25 },
                { duration: '60s', target: 100 },
                { duration: '60s', target: 100 },
                { duration: '10s', target: 0 },
            ],
            tags: { endpoint: 'postgres-orders' },
        },
    },
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(95)', 'p(99)'],
};

const BASE_URL = __ENV.BASE_URL;

function runRequest(path) {
    const res = http.get(BASE_URL + path);
    check(res, {
        'status is 200': (r) => r.status === 200,
    });
}

export function benchmarkHome() {
    runRequest('/');
}

export function benchmarkPostgresOrders() {
    runRequest('/postgres/orders');
}
