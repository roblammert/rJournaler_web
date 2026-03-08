from __future__ import annotations

from typing import Any


def run_stage(entry_uid: str, content_raw: str, stage: dict[str, Any], cfg: Any, helpers: dict[str, Any]) -> None:
    read_prompt_template = helpers["read_prompt_template"]
    build_prompt_from_template = helpers["build_prompt_from_template"]
    call_ollama_analysis = helpers["call_ollama_analysis"]
    upsert_meta_group_2 = helpers["upsert_meta_group_2"]

    prompt_template = read_prompt_template(cfg, stage.get("prompt_file"))
    prompt = build_prompt_from_template(prompt_template, entry_uid, content_raw)
    llm_response = call_ollama_analysis(cfg, prompt, content_raw)
    upsert_meta_group_2(helpers["cursor"], entry_uid, cfg.ollama_model, llm_response)
