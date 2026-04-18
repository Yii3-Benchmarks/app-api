import http from 'k6/http';
import { check } from 'k6';

const BASE_URL = __ENV.BASE_URL;
const TARGET_PATH = __ENV.TARGET_PATH || '/';
const TARGET_NAME = __ENV.TARGET_NAME || TARGET_PATH;
const RATE = Number(__ENV.RATE || 10000);
const DURATION = __ENV.DURATION || '160s';
const PREALLOCATED_VUS = Number(__ENV.PREALLOCATED_VUS || 200);
const MAX_VUS = Number(__ENV.MAX_VUS || 2000);

export const options = {
    scenarios: {
        target: {
            executor: 'constant-arrival-rate',
            exec: 'benchmarkTarget',
            rate: RATE,
            timeUnit: '1s',
            duration: DURATION,
            preAllocatedVUs: PREALLOCATED_VUS,
            maxVUs: MAX_VUS,
            tags: { endpoint: TARGET_NAME },
        },
    },
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(95)', 'p(99)'],
};

function runRequest(path) {
    const res = http.get(BASE_URL + path);
    check(res, {
        'status is 200': (r) => r.status === 200,
    });
}

export function benchmarkTarget() {
    runRequest(TARGET_PATH);
}
