done = function(summary, latency, requests)
    local errors = summary.errors.connect + summary.errors.read + summary.errors.write
        + summary.errors.status + summary.errors.timeout

    io.write(string.format(
        "WRKX_RESULT {\"durationUs\":%d,\"requests\":%d,\"responses\":%d,\"errors\":%d," ..
        "\"bytes\":%d,\"latencyAvgUs\":%.3f,\"latencyP95Us\":%.3f,\"latencyP99Us\":%.3f}\n",
        summary.duration, summary.requests, summary.requests, errors, summary.bytes,
        latency.mean, latency:percentile(95.0), latency:percentile(99.0)
    ))
end
