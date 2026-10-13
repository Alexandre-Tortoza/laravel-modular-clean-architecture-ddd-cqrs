import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location(
    'benchmark', pathlib.Path(__file__).with_name('runtime-benchmark-client.py')
)
benchmark = importlib.util.module_from_spec(spec)
spec.loader.exec_module(benchmark)


class MetricsContractTest(unittest.TestCase):
    def test_percentiles_and_throughput_from_known_sample_durations(self):
        result = benchmark.latency_summary(list(range(1, 101)), 2)
        self.assertEqual(result['requests'], 100)
        self.assertEqual(result['requests_per_second'], 50)
        self.assertEqual(result['p50_ms'], 50)
        self.assertEqual(result['p95_ms'], 95)
        self.assertEqual(result['mean_ms'], 50.5)

    def test_rejects_invalid_measurements(self):
        with self.assertRaises(AssertionError):
            benchmark.latency_summary([], 2)
        with self.assertRaises(AssertionError):
            benchmark.latency_summary([1], 0)

    def test_refuses_requests_outside_the_disposable_loopback_service(self):
        with self.assertRaises(ValueError):
            benchmark.Client('https://example.com')


if __name__ == '__main__':
    unittest.main()
