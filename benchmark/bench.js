import http from 'k6/http';
import { check } from 'k6';

export const options = {
    stages: [
        { duration: '30s', target: 50 },   // warmup: ramp to 50 VUs
        { duration: '60s', target: 200 },  // ramp up to saturate workers
        { duration: '60s', target: 200 },  // hold at 200 VUs
        { duration: '10s', target: 0 },    // ramp down
    ],
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(95)', 'p(99)'],
};

const BASE_URL = __ENV.BASE_URL;

export default function () {
    const res = http.get(BASE_URL + '/');
    check(res, {
        'status is 200': (r) => r.status === 200,
    });
}
