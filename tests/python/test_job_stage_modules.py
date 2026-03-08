import sys
from pathlib import Path
import unittest
import importlib.util


ROOT = Path(__file__).resolve().parents[2]
JOBS_DIR = ROOT / 'python' / 'jobs'
if str(JOBS_DIR) not in sys.path:
    sys.path.insert(0, str(JOBS_DIR))


class _Cfg:
    ollama_model = 'test-model'


def _load_module(module_name: str, file_name: str):
    path = JOBS_DIR / file_name
    spec = importlib.util.spec_from_file_location(module_name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f'Unable to load module {module_name}')
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


job_meta_group_0 = _load_module('job_meta_group_0', 'job_meta_group_0.py')
job_meta_group_1 = _load_module('job_meta_group_1', 'job_meta_group_1.py')
job_meta_group_2_llm = _load_module('job_meta_group_2_llm', 'job_meta_group_2_llm.py')
job_meta_group_3_weather = _load_module('job_meta_group_3_weather', 'job_meta_group_3_weather.py')
job_metrics_finalize = _load_module('job_metrics_finalize', 'job_metrics_finalize.py')


class JobStageModuleTests(unittest.TestCase):
    def test_meta_group_0_runner_calls_upsert(self):
        called = {}

        def upsert(cursor, entry_uid, title, created_at, updated_at, content_raw):
            called['cursor'] = cursor
            called['entry_uid'] = entry_uid
            called['title'] = title
            called['created_at'] = created_at
            called['updated_at'] = updated_at
            called['content_raw'] = content_raw

        cursor = object()
        entry = {'title': 'Entry A', 'created_at': '2026-03-01', 'updated_at': '2026-03-02'}
        helpers = {
            'cursor': cursor,
            'upsert_meta_group_0': upsert,
        }

        job_meta_group_0.run_stage('20260301010101-rjournaler-W010000-abc123', entry, 'text', helpers)
        self.assertEqual(called['cursor'], cursor)
        self.assertEqual(called['title'], 'Entry A')
        self.assertEqual(called['content_raw'], 'text')

    def test_meta_group_1_runner_builds_and_upserts(self):
        called = {}

        def build_stats(content_raw):
            called['built_with'] = content_raw
            return {'word_count': 3}

        def upsert(cursor, entry_uid, payload):
            called['cursor'] = cursor
            called['entry_uid'] = entry_uid
            called['payload'] = payload

        cursor = object()
        helpers = {
            'cursor': cursor,
            'build_meta_group_1_stats': build_stats,
            'upsert_meta_group_1': upsert,
        }

        job_meta_group_1.run_stage('uid', 'hello world', helpers)
        self.assertEqual(called['built_with'], 'hello world')
        self.assertEqual(called['payload'], {'word_count': 3})
        self.assertEqual(called['cursor'], cursor)

    def test_meta_group_2_llm_runner_full_flow(self):
        called = {}

        def read_prompt_template(cfg, prompt_file):
            called['prompt_file'] = prompt_file
            return 'template'

        def build_prompt_from_template(template, entry_uid, content_raw):
            called['template'] = template
            called['entry_uid'] = entry_uid
            called['content_raw'] = content_raw
            return 'built-prompt'

        def call_ollama_analysis(cfg, prompt, content_raw):
            called['ollama_prompt'] = prompt
            called['ollama_content'] = content_raw
            return {'summary': 'ok'}

        def upsert(cursor, entry_uid, llm_model, analysis_json):
            called['cursor'] = cursor
            called['upsert_uid'] = entry_uid
            called['llm_model'] = llm_model
            called['analysis_json'] = analysis_json

        cursor = object()
        helpers = {
            'cursor': cursor,
            'read_prompt_template': read_prompt_template,
            'build_prompt_from_template': build_prompt_from_template,
            'call_ollama_analysis': call_ollama_analysis,
            'upsert_meta_group_2': upsert,
        }

        job_meta_group_2_llm.run_stage('uid-2', 'entry text', {'prompt_file': 'x.txt'}, _Cfg(), helpers)
        self.assertEqual(called['prompt_file'], 'x.txt')
        self.assertEqual(called['ollama_prompt'], 'built-prompt')
        self.assertEqual(called['llm_model'], 'test-model')
        self.assertEqual(called['analysis_json'], {'summary': 'ok'})

    def test_meta_group_3_weather_runner_full_flow(self):
        called = {}

        def entry_weather_location(entry):
            called['entry'] = entry
            return {'city': 'Test'}

        def throttle_noaa_requests(cursor):
            called['throttle_cursor'] = cursor

        def fetch_noaa_weather_for_location(location, entry_date=None):
            called['fetch_location'] = location
            called['fetch_entry_date'] = entry_date
            return {'ok': True, 'location': {'label': 'x'}}

        def upsert(cursor, entry_uid, weather_payload):
            called['upsert_cursor'] = cursor
            called['upsert_uid'] = entry_uid
            called['weather_payload'] = weather_payload

        cursor = object()
        entry = {'entry_date': '2026-03-07'}
        helpers = {
            'cursor': cursor,
            'entry_weather_location': entry_weather_location,
            'throttle_noaa_requests': throttle_noaa_requests,
            'fetch_noaa_weather_for_location': fetch_noaa_weather_for_location,
            'upsert_meta_group_3': upsert,
        }

        job_meta_group_3_weather.run_stage('uid-3', entry, helpers)
        self.assertEqual(called['entry'], entry)
        self.assertEqual(called['fetch_location'], {'city': 'Test'})
        self.assertEqual(called['fetch_entry_date'], '2026-03-07')
        self.assertEqual(called['upsert_uid'], 'uid-3')

    def test_metrics_finalize_runner_calls_upsert(self):
        called = {}

        def upsert(cursor, entry_uid, content_raw):
            called['cursor'] = cursor
            called['entry_uid'] = entry_uid
            called['content_raw'] = content_raw

        cursor = object()
        helpers = {
            'cursor': cursor,
            'upsert_entry_metrics': upsert,
        }

        job_metrics_finalize.run_stage('uid-4', 'finalize me', helpers)
        self.assertEqual(called['cursor'], cursor)
        self.assertEqual(called['entry_uid'], 'uid-4')
        self.assertEqual(called['content_raw'], 'finalize me')


if __name__ == '__main__':
    unittest.main()
