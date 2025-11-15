#!/usr/bin/env python3
"""
Quickstart: Turn a YouTube video's transcript into either
(1) a normal summary, or
(2) a structured lesson-style summary using HuggingFace.

Usage examples:
  # Bài học có cấu trúc (mặc định: mode=lesson)
  python quickstart.py --url https://www.youtube.com/watch?v=8Jx6gN7ZFKk --combine

  # Tóm tắt bình thường
  python quickstart.py --url https://www.youtube.com/watch?v=8Jx6gN7ZFKk --mode plain --combine

Requirements (install first):
  pip install youtube-transcript-api transformers torch
"""

import argparse
import re
import sys
from typing import List

from urllib.parse import urlparse, parse_qs

from youtube_transcript_api import (
    YouTubeTranscriptApi,
    TranscriptsDisabled,
    NoTranscriptFound,
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Summarize a YouTube transcript using HuggingFace Transformers"
    )
    src = parser.add_mutually_exclusive_group(required=True)
    src.add_argument("--url", type=str, help="YouTube video URL")
    src.add_argument("--id", dest="video_id", type=str, help="YouTube video ID")

    parser.add_argument(
        "--language",
        "-l",
        default="en",
        help="Preferred transcript language (e.g., en, en-US, vi)",
    )
    parser.add_argument(
        "--model",
        default="sshleifer/distilbart-cnn-12-6",  # Mô hình nhỏ hơn, nhanh hơn
        help="Transformers summarization model (t5-small=fast, sshleifer/distilbart-cnn-12-6=balanced, facebook/bart-large-cnn=best quality but slow)",
    )
    parser.add_argument(
        "--min-length",
        type=int,
        default=30,  # Giảm từ 60 xuống 30 cho nhanh hơn
        help="Minimum tokens for each summary chunk",
    )
    parser.add_argument(
        "--max-length",
        type=int,
        default=120,  # Giảm từ 180 xuống 120 cho nhanh hơn
        help="Maximum tokens for each summary chunk (output length)",
    )
    parser.add_argument(
        "--chunk-words",
        type=int,
        default=300,  # Giảm từ 400 xuống 300 cho nhanh hơn
        help="Approx word count per chunk before summarization (lower=faster but more chunks)",
    )
    parser.add_argument(
        "--combine",
        action="store_true",
        help="Re-summarize the concatenated chunk summaries into a final short summary",
    )
    parser.add_argument(
        "--mode",
        choices=["plain", "lesson"],
        default="plain",  # Plain mode nhanh hơn lesson mode
        help=(
            "plain = generic summary (faster), "
            "lesson = structured lesson-style output (slower, more detailed)"
        ),
    )
    return parser.parse_args()


def extract_video_id(url_or_id: str) -> str:
    # If it already looks like a video ID, return it.
    if re.fullmatch(r"[a-zA-Z0-9_-]{11}", url_or_id):
        return url_or_id

    # Try to parse as URL.
    try:
        parsed = urlparse(url_or_id)
        host = (parsed.netloc or "").lower()
        if "youtube.com" in host or "youtu.be" in host:
            # youtu.be/<id>
            if host.endswith("youtu.be") and parsed.path:
                vid = parsed.path.strip("/")
                if re.fullmatch(r"[a-zA-Z0-9_-]{11}", vid):
                    return vid

            # youtube.com/watch?v=<id>
            qs = parse_qs(parsed.query)
            v = qs.get("v", [None])[0]
            if v and re.fullmatch(r"[a-zA-Z0-9_-]{11}", v):
                return v

            # youtube.com/shorts/<id>
            m = re.search(r"/shorts/([a-zA-Z0-9_-]{11})", parsed.path or "")
            if m:
                return m.group(1)
    except Exception:
        pass

    raise ValueError("Could not extract a valid YouTube video ID from input.")


def fetch_transcript_text(video_id: str, preferred_language: str) -> str:
    """
    Lấy transcript bằng youtube-transcript-api (API mới):
    - Dùng YouTubeTranscriptApi().fetch(video_id, languages=[...])
    - Trả về 1 chuỗi text nối từ các snippet.
    """
    langs: List[str] = []
    if preferred_language:
        langs.append(preferred_language)
    for l in ("vi", "vi-VN", "en", "en-US", "en-GB"):
        if l not in langs:
            langs.append(l)

    api = YouTubeTranscriptApi()

    try:
        fetched = api.fetch(video_id, languages=langs)
        raw_entries = fetched.to_raw_data()
        return " ".join(
            _clean_text(e.get("text", "")) for e in raw_entries if e.get("text")
        )
    except (NoTranscriptFound, TranscriptsDisabled):
        raise
    except Exception as e:
        raise RuntimeError(f"No usable transcript found: {e}")


def _clean_text(s: str) -> str:
    s = re.sub(r"\s+", " ", s).strip()
    return s


def chunk_by_words(text: str, chunk_words: int) -> List[str]:
    words = text.split()
    if not words:
        return []
    chunks: List[str] = []
    for i in range(0, len(words), chunk_words):
        chunks.append(" ".join(words[i : i + chunk_words]))
    return chunks


def _split_text_units(text: str) -> List[str]:
    """
    Breaks a block of text into manageable bullet-sized units by first
    respecting existing line breaks, then falling back to sentence splits.
    """
    if not text:
        return []
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    if lines:
        return lines
    parts = re.split(r"(?<=[.!?])\s+", text.strip())
    return [p.strip() for p in parts if p.strip()]


def _has_list_markup(lines: List[str]) -> bool:
    list_re = re.compile(r"^\s*(?:[-*]|[0-9]+[.)])\s+")
    return any(list_re.match(line) for line in lines)


def _normalize_bullet_block(text: str, bullet_prefix: str = "- ") -> str:
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    if lines and _has_list_markup(lines):
        return "\n".join(lines)
    units = _split_text_units(text)
    if not units:
        return ""
    return "\n".join(f"{bullet_prefix}{unit}" for unit in units)


def _normalize_numbered_block(text: str) -> str:
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    numbered_re = re.compile(r"^\s*\d+[.)]\s+")
    if lines and any(numbered_re.match(line) for line in lines):
        return "\n".join(lines)
    units = _split_text_units(text)
    if not units:
        return ""
    return "\n".join(f"{idx + 1}. {unit}" for idx, unit in enumerate(units))


def _fallback_list_from_notes(notes: str, limit: int = 5, numbered: bool = False) -> str:
    units = _split_text_units(notes)[:limit]
    if not units:
        return ""
    if numbered:
        return "\n".join(f"{idx + 1}. {unit}" for idx, unit in enumerate(units))
    return "\n".join(f"- {unit}" for unit in units)


def _select_units(units: List[str], start: int, limit: int) -> List[str]:
    return [u for u in units[start : start + limit] if u]


def _format_questions(units: List[str], lang_code: str) -> str:
    questions: List[str] = []
    for unit in units:
        base = unit.rstrip("?!.")
        if not base:
            continue
        if lang_code.startswith("vi"):
            question = f"{base}?"
        else:
            question = base
            if not question.endswith("?"):
                question = question + "?"
        questions.append(f"- {question}")
    return "\n".join(questions)


def build_summarizer(model_name: str):
    from transformers import (
        AutoTokenizer,
        AutoModelForSeq2SeqLM,
        pipeline,
    )

    print(f"⏳ Loading model: {model_name}...")
    
    device = -1
    try:
        import torch  # type: ignore

        if torch.cuda.is_available():
            device = 0
            print("✓ Using GPU")
        else:
            print("ℹ Using CPU (slower)")
    except Exception:
        device = -1
        print("ℹ Using CPU (slower)")

    # Tự tải tokenizer + model
    tokenizer = AutoTokenizer.from_pretrained(model_name)
    model = AutoModelForSeq2SeqLM.from_pretrained(model_name)

    # Một số tokenizer set model_max_length rất lớn (int(1e30)),
    # khiến truncation không hoạt động => ta ép về 1024 cho an toàn.
    SAFE_MAX_SOURCE_LEN = 512  # Giảm từ 1024 xuống 512 cho nhanh hơn
    try:
        if (
            not hasattr(tokenizer, "model_max_length")
            or tokenizer.model_max_length is None
            or tokenizer.model_max_length > SAFE_MAX_SOURCE_LEN * 10
        ):
            tokenizer.model_max_length = SAFE_MAX_SOURCE_LEN
    except Exception:
        tokenizer.model_max_length = SAFE_MAX_SOURCE_LEN

    print("✓ Model loaded successfully\n")
    
    return pipeline(
        "summarization",
        model=model,
        tokenizer=tokenizer,
        device=device,
        batch_size=1,  # Xử lý từng batch để tiết kiệm RAM
    )


def summarize_text(
    text: str,
    model_name: str,
    min_length: int,
    max_length: int,
    chunk_words: int,
    combine: bool,
    mode: str = "lesson",
    language: str = "en",
) -> str:
    """
    mode = "plain"  -> tóm tắt bình thường (gần giống code gốc)
    mode = "lesson" -> tạo bài học có cấu trúc từ transcript dạy học
    """
    if not text or not text.strip():
        return ""

    summarizer = build_summarizer(model_name)
    chunks = chunk_by_words(text, chunk_words)
    if not chunks:
        return ""

    is_t5_like = "t5" in model_name.lower()

    # ---------- PLAIN MODE ----------
    if mode == "plain":
        summaries: List[str] = []
        total_chunks = len(chunks)
        print(f"📝 Processing {total_chunks} chunks...")
        
        for idx, chunk in enumerate(chunks, 1):
            print(f"  Chunk {idx}/{total_chunks}...", end=" ", flush=True)
            try:
                prompt = ("summarize: " + chunk) if is_t5_like else chunk
                res = summarizer(
                    prompt,
                    max_length=max_length,   # output summary length
                    min_length=min_length,
                    truncation=True,         # input sẽ bị cắt theo tokenizer.model_max_length
                    num_beams=2,             # Giảm từ 4 xuống 2 cho nhanh hơn
                    early_stopping=True,     # Dừng sớm khi tìm được kết quả tốt
                )
                summaries.append(res[0]["summary_text"].strip())
                print("✓")
            except Exception as e:
                print(f"✗ Error")
                raise RuntimeError(f"Summarization failed on chunk {idx}: {e}")

        if not combine:
            return "\n\n".join(summaries)

        if len(summaries) == 1:
            return summaries[0] if summaries else ""

        print("🔄 Combining summaries into final summary...")
        combined = " ".join(summaries)

        final_max = max(max_length, min(300, max_length * 2))
        final_min = min_length
        try:
            final_prompt = ("summarize: " + combined) if is_t5_like else combined
            res = summarizer(
                final_prompt,
                max_length=final_max,
                min_length=final_min,
                truncation=True,
                num_beams=2,
                early_stopping=True,
            )
            print("✓ Final summary complete\n")
            return res[0]["summary_text"].strip()
        except Exception:
            return combined

    # ---------- LESSON MODE ----------
    summaries: List[str] = []

    total_chunks = len(chunks)
    print(f"📚 Processing {total_chunks} chunks in lesson mode...")
    print("   Creating comprehensive learning material...\n")

    # Bước 1: từ mỗi chunk tạo ra "study notes" chi tiết với steps và examples
    for idx, chunk in enumerate(chunks, 1):
        if not chunk.strip():
            continue

        print(f"  Chunk {idx}/{total_chunks}...", end=" ", flush=True)

        # Enhanced prompt để lấy nhiều chi tiết hơn
        notes_prompt = (
            "You are an expert educator creating detailed learning materials. "
            "Analyze this lecture transcript and extract:\n"
            "1. Key concepts and definitions\n"
            "2. Step-by-step procedures or processes\n"
            "3. Important examples and use cases\n"
            "4. Tips, best practices, and common mistakes to avoid\n"
            "5. Any code snippets, formulas, or technical details\n\n"
            "Format as clear, structured notes with bullet points. "
            "Be detailed but concise. Keep technical terms and examples.\n\n"
            "Lecture transcript:\n"
            f"{chunk}"
        )

        if is_t5_like:
            notes_prompt = "summarize: " + notes_prompt

        try:
            res = summarizer(
                notes_prompt,
                max_length=max_length * 2,  # Tăng gấp đôi để lấy nhiều chi tiết hơn
                min_length=min_length * 2,
                truncation=True,
                num_beams=2,
                early_stopping=True,
            )
            summaries.append(res[0]["summary_text"].strip())
            print("✓")
        except Exception as e:
            print(f"✗ Error")
            raise RuntimeError(f"Summarization failed on chunk {idx}: {e}")

    if not summaries:
        return ""

    if not combine:
        return "\n\n".join(summaries)

    # Bước 2: Combine tất cả ghi chú và nhờ model viết lại thành bài học hoàn chỉnh
    print("🔄 Building structured lesson...")
    combined_notes = " ".join(summaries)

    final_max = max(max_length, min(512, max_length * 3))
    final_min = min_length

    lang_code = (language or "en").lower()

    def adjust_lengths(sec_max: int, desired_min: int) -> tuple[int, int]:
        sec_max = max(32, sec_max)
        sec_min = max(5, min(desired_min, sec_max - 5))
        return sec_max, sec_min

    def run_prompt(template: str, sec_max: int, sec_min: int) -> str:
        prompt = template.format(notes=combined_notes)
        if is_t5_like:
            prompt = "summarize: " + prompt
        try:
            res = summarizer(
                prompt,
                max_length=sec_max,
                min_length=sec_min,
                truncation=True,
                num_beams=2,
                early_stopping=True,
            )
            return res[0]["summary_text"].strip()
        except Exception:
            return ""

    if lang_code.startswith("vi"):
        templates = {
            "title": (
                "Đưa ra một tiêu đề tiếng Việt ngắn gọn, hấp dẫn cho bài học dựa trên các ghi chú sau.\n\n"
                "Ghi chú:\n{notes}"
            ),
            "objectives": (
                "Từ các ghi chú dưới đây, liệt kê 4-6 mục tiêu học tập cụ thể bằng tiếng Việt. "
                "Mỗi mục tiêu viết dạng gạch đầu dòng, mô tả rõ điều người học sẽ đạt được sau bài học.\n\n{notes}"
            ),
            "concepts": (
                "Trích xuất và giải thích chi tiết các khái niệm chính thành danh sách đánh số bằng tiếng Việt. "
                "Mỗi mục nên bao gồm: định nghĩa, giải thích, và ví dụ minh họa nếu có. "
                "Giữ nguyên thuật ngữ kỹ thuật quan trọng.\n\n{notes}"
            ),
            "steps": (
                "Nếu nội dung có các bước thực hiện hoặc quy trình, hãy liệt kê chi tiết từng bước bằng tiếng Việt. "
                "Nếu không có quy trình rõ ràng, liệt kê các điểm quan trọng cần nhớ.\n\n{notes}"
            ),
            "examples": (
                "Trích xuất các ví dụ cụ thể, code snippets, hoặc case studies từ ghi chú. "
                "Format rõ ràng, dễ hiểu bằng tiếng Việt.\n\n{notes}"
            ),
            "summary": (
                "Tóm tắt toàn bộ bài học thành 4-6 điểm chính bằng tiếng Việt. "
                "Mỗi điểm là một takeaway quan trọng người học cần nhớ.\n\n{notes}"
            ),
            "questions": (
                "Tạo 5-7 câu hỏi ôn tập tiếng Việt ở nhiều cấp độ: "
                "hiểu khái niệm, áp dụng, và phân tích. Giúp người học kiểm tra kiến thức.\n\n{notes}"
            ),
        }
        headings = {
            "title": "# 📚 Tiêu đề bài học",
            "objectives": "## 🎯 Mục tiêu học tập",
            "concepts": "## 💡 Các khái niệm chính",
            "steps": "## 📝 Các bước thực hiện / Điểm quan trọng",
            "examples": "## 🔍 Ví dụ minh họa",
            "summary": "## 📌 Tóm tắt",
            "questions": "## ❓ Câu hỏi ôn tập",
        }
        fallback_title = "Bài học công nghệ thông tin"
    else:
        templates = {
            "title": (
                "Create a clear, engaging lesson title in English based on these comprehensive notes.\n\n{notes}"
            ),
            "objectives": (
                "List 4-6 specific learning objectives in English. "
                "Each should clearly describe what the learner will be able to do after this lesson.\n\n{notes}"
            ),
            "concepts": (
                "Extract and explain key concepts in detail using numbered list in English. "
                "Each item should include: definition, explanation, and examples if available. "
                "Keep important technical terms.\n\n{notes}"
            ),
            "steps": (
                "If the content includes procedures or processes, list detailed steps in English. "
                "If no clear process, list important points to remember.\n\n{notes}"
            ),
            "examples": (
                "Extract specific examples, code snippets, or case studies from the notes. "
                "Format clearly in English.\n\n{notes}"
            ),
            "summary": (
                "Summarize the entire lesson into 4-6 key takeaways in English. "
                "Each point should be a crucial insight the learner must remember.\n\n{notes}"
            ),
            "questions": (
                "Create 5-7 review questions in English at multiple levels: "
                "understanding concepts, application, and analysis. Help learners test their knowledge.\n\n{notes}"
            ),
        }
        headings = {
            "title": "# 📚 Lesson Title",
            "objectives": "## 🎯 Learning Objectives",
            "concepts": "## 💡 Key Concepts",
            "steps": "## 📝 Steps / Important Points",
            "examples": "## 🔍 Examples",
            "summary": "## 📌 Summary",
            "questions": "## ❓ Review Questions",
        }
        fallback_title = "Comprehensive Lesson Overview"

    title_max, title_min = 64, 8
    obj_max, obj_min = adjust_lengths(min(final_max, 350), max(30, min_length))
    concept_max, concept_min = adjust_lengths(final_max * 2, min_length * 2)  # Tăng gấp đôi cho chi tiết
    steps_max, steps_min = adjust_lengths(final_max * 2, min_length * 2)
    examples_max, examples_min = adjust_lengths(min(final_max, 300), max(20, min_length))
    summary_max, summary_min = adjust_lengths(min(final_max, 250), max(20, min_length // 2 or 10))
    question_max, question_min = adjust_lengths(min(final_max, 300), max(20, min_length // 2 or 10))

    print("  Generating lesson components...")
    lesson_title = run_prompt(templates["title"], title_max, title_min)
    print("    ✓ Title")
    objectives_block = run_prompt(templates["objectives"], obj_max, obj_min)
    print("    ✓ Objectives")
    concepts_block = run_prompt(templates["concepts"], concept_max, concept_min)
    print("    ✓ Key concepts")
    steps_block = run_prompt(templates["steps"], steps_max, steps_min)
    print("    ✓ Steps/Important points")
    examples_block = run_prompt(templates["examples"], examples_max, examples_min)
    print("    ✓ Examples")
    summary_block = run_prompt(templates["summary"], summary_max, summary_min)
    print("    ✓ Summary")
    questions_block = run_prompt(templates["questions"], question_max, question_min)
    print("    ✓ Review questions\n")

    if not lesson_title:
        units = _split_text_units(combined_notes)
        fallback = units[0] if units else ""
        lesson_title = fallback or fallback_title

    note_units = _split_text_units(combined_notes)
    if not note_units:
        fallback_units: List[str] = []
        for block in summaries:
            fallback_units.extend(
                line.strip(" -*•\t")
                for line in block.splitlines()
                if line.strip()
            )
        note_units = fallback_units

    objectives_text = _normalize_bullet_block(objectives_block) or "\n".join(
        f"- {item}" for item in _select_units(note_units, 0, 5)
    )
    concepts_text = _normalize_numbered_block(concepts_block) or "\n".join(
        f"{idx + 1}. {item}" for idx, item in enumerate(_select_units(note_units, 0, 8))
    )
    steps_text = _normalize_numbered_block(steps_block) or "\n".join(
        f"{idx + 1}. {item}" for idx, item in enumerate(_select_units(note_units, 0, 6))
    )
    examples_text = _normalize_bullet_block(examples_block) or "\n".join(
        f"- {item}" for item in _select_units(note_units, len(note_units)//2, 4)
    )
    summary_slice = _select_units(note_units, max(0, len(note_units) - 5), 5)
    if not summary_slice:
        summary_slice = _select_units(note_units, 0, 5)
    summary_text = _normalize_bullet_block(summary_block) or "\n".join(
        f"- {item}" for item in summary_slice
    )
    questions_seed = _select_units(note_units, 0, 6)
    questions_text = _normalize_bullet_block(questions_block, bullet_prefix="- ") or _format_questions(
        questions_seed, lang_code
    )

    sections = [
        headings["title"],
        f"**{lesson_title}**",
        "",
        headings["objectives"],
        objectives_text,
        "",
        headings["concepts"],
        concepts_text,
        "",
        headings["steps"],
        steps_text,
        "",
        headings["examples"],
        examples_text,
        "",
        headings["summary"],
        summary_text,
        "",
        headings["questions"],
        questions_text,
    ]
    return "\n".join(part for part in sections if part is not None and part.strip() != "")

def main():
    args = parse_args()
    
    print("=" * 60)
    print("YouTube Transcript Summarizer")
    print("=" * 60)
    
    try:
        video_id = extract_video_id(args.url or args.video_id)
        print(f"📹 Video ID: {video_id}")
    except ValueError as e:
        sys.stderr.write(f"Error: {e}\n")
        sys.exit(2)

    print(f"🌐 Fetching transcript (language: {args.language})...")
    try:
        transcript_text = fetch_transcript_text(video_id, args.language)
        word_count = len(transcript_text.split())
        print(f"✓ Got transcript: {word_count} words\n")
    except (TranscriptsDisabled, NoTranscriptFound) as e:
        sys.stderr.write(f"Transcript unavailable: {e}\n")
        sys.exit(1)
    except Exception as e:
        sys.stderr.write(f"Failed to fetch transcript: {e}\n")
        sys.exit(1)

    if not transcript_text:
        sys.stderr.write("Empty transcript or failed to assemble text.\n")
        sys.exit(1)

    try:
        summary = summarize_text(
            transcript_text,
            model_name=args.model,
            min_length=args.min_length,
            max_length=args.max_length,
            chunk_words=args.chunk_words,
            combine=args.combine,
            mode=args.mode,
            language=args.language,
        )
    except Exception as e:
        sys.stderr.write(f"Summarization failed: {e}\n")
        sys.exit(1)

    print("\n" + "=" * 60)
    print("SUMMARY")
    print("=" * 60 + "\n")
    print(summary)
    print("\n" + "=" * 60)


if __name__ == "__main__":
    main()
