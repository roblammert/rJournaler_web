import sys
from pathlib import Path
import unittest
import importlib.util

# Import worker helper functions without requiring package installation.
ROOT = Path(__file__).resolve().parents[2]
module_path = ROOT / 'python' / 'worker' / 'main.py'
spec = importlib.util.spec_from_file_location('worker_main', module_path)
if spec is None or spec.loader is None:
    raise RuntimeError('Unable to load worker main module for tests')
worker_main = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = worker_main
spec.loader.exec_module(worker_main)

count_words = worker_main.count_words
is_valid_entry_uid = worker_main.is_valid_entry_uid
backoff_seconds = worker_main.backoff_seconds
estimate_readability = worker_main.estimate_readability


class WorkerHelperTests(unittest.TestCase):
    def test_count_words(self):
        self.assertEqual(count_words(''), 0)
        self.assertEqual(count_words('hello world'), 2)
        self.assertEqual(count_words(' one   two\nthree '), 3)

    def test_uid_validation(self):
        self.assertTrue(is_valid_entry_uid('20260305083010-rjournaler-W010000-abc123'))
        self.assertFalse(is_valid_entry_uid('20260305083010-rjournaler-040120-abc123'))
        self.assertFalse(is_valid_entry_uid('bad-uid'))

    def test_backoff_seconds(self):
        self.assertEqual(backoff_seconds(1), 10)
        self.assertEqual(backoff_seconds(2), 20)
        self.assertEqual(backoff_seconds(3), 40)
        self.assertEqual(backoff_seconds(10), 300)

    def test_estimate_readability(self):
        self.assertEqual(estimate_readability(0), 0.0)
        self.assertEqual(estimate_readability(50), 4.5)
        self.assertEqual(estimate_readability(120), 6.8)
        self.assertEqual(estimate_readability(250), 9.2)
        self.assertEqual(estimate_readability(800), 11.5)


if __name__ == '__main__':
    unittest.main()
