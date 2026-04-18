import http from 'k6/http';
import { Counter } from 'k6/metrics';
import { check } from 'k6';

const BASE_URL = __ENV.BASE_URL;
const TARGET_PATH = __ENV.TARGET_PATH || '/';
const TARGET_NAME = __ENV.TARGET_NAME || TARGET_PATH;
const START_RATE = Number(__ENV.START_RATE || 500);
const TIME_UNIT = __ENV.TIME_UNIT || '1s';
const PREALLOCATED_VUS = Number(__ENV.PREALLOCATED_VUS || 200);
const MAX_VUS = Number(__ENV.MAX_VUS || 2000);
const REQUESTS_ISSUED = new Counter('requests_issued');
const STAGES = JSON.parse(
    __ENV.STAGES ||
        JSON.stringify([
            { target: 5000, duration: '30s' },
            { target: 10000, duration: '30s' },
            { target: 15000, duration: '30s' },
            { target: 20000, duration: '30s' },
            { target: 25000, duration: '30s' },
            { target: 30000, duration: '30s' },
            { target: 40000, duration: '30s' },
            { target: 50000, duration: '30s' },
        ]),
);

export const options = {
    scenarios: {
        target: {
            executor: 'ramping-arrival-rate',
            exec: 'benchmarkTarget',
            startRate: START_RATE,
            timeUnit: TIME_UNIT,
            preAllocatedVUs: PREALLOCATED_VUS,
            maxVUs: MAX_VUS,
            stages: STAGES,
            tags: { endpoint: TARGET_NAME },
        },
    },
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(95)', 'p(99)'],
};

function runRequest(path) {
    REQUESTS_ISSUED.add(1);
    const res = http.get(BASE_URL + path);
    check(res, {
        'status is 200': (r) => r.status === 200,
    });
}

export function benchmarkTarget() {
    runRequest(TARGET_PATH);
}
