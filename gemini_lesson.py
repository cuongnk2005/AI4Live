#!/usr/bin/env python3
"""
Generate lesson + title from YouTube using transcript or ASR fallback.
Pipeline:
1. Get YouTube URL
2. Try transcript via youtube-transcript-api
3. If failed -> download audio via yt-dlp -> ASR with faster-whisper
4. Extract key points:
   - With --keybert: KeyBERT -> keywords/phrases -> find sentences -> key points
   - Without: heuristic (length + keywords)
5. (Optional --enrich): Local summarization with BARTpho (VI) or mT5 (EN)
6. Call Gemini:
   - generate_title_with_gemini() for short title
   - generate_lesson_with_gemini() using key points (+ summary if enrich)
"""

import os
import sys
import argparse
import json
import re
import subprocess
import tempfile
import shutil
from urllib.parse import urlparse, parse_qs
from typing import List, Tuple, Optional

from youtube_transcript_api import YouTubeTranscriptApi


# Mặc định lấy từ env, nếu không có sẽ dùng key mặc định như phiên bản cũ.
DEFAULT_GEMINI_API_KEY = os.getenv("GEMINI_API_KEY") or "AIzaSyDgrWF9UqYd4pYMJBKdqrwTexM9vTycO0o"


def extract_video_id(url_or_id: str) -> str:
    if re.fullmatch(r"[a-zA-Z0-9_-]{11}", url_or_id):
        return url_or_id
    parsed = urlparse(url_or_id)
    host = (parsed.netloc or "").lower()
    if "youtube.com" in host or "youtu.be" in host:
        if host.endswith("youtu.be") and parsed.path:
            vid = parsed.path.strip("/")
            if re.fullmatch(r"[a-zA-Z0-9_-]{11}", vid):
                return vid
        qs = parse_qs(parsed.query)
        v = qs.get("v", [None])[0]
        if v and re.fullmatch(r"[a-zA-Z0-9_-]{11}", v):
            return v
        m = re.search(r"/shorts/([a-zA-Z0-9_-]{11})", parsed.path or "")
        if m:
            return m.group(1)
    raise ValueError("Khong lay duoc video ID")


def get_transcript(video_id: str, language: str = "en") -> str:
    print(f"📹 Video ID: {video_id}")
    print(f"🌐 Đang lấy transcript (ngôn ngữ: {language})...")
    if language.startswith("vi"):
        langs = ["vi", "vi-VN", "en", "en-US", "en-GB"]
    else:
        langs = ["en", "en-US", "en-GB", "vi", "vi-VN"]
    api = YouTubeTranscriptApi()
    fetched = api.fetch(video_id, languages=langs)
    raw_entries = fetched.to_raw_data()
    text = " ".join(e.get("text", "") for e in raw_entries if e.get("text"))
    text = re.sub(r"\s+", " ", text).strip()
    wc = len(text.split())
    print(f"✅ Transcript {wc} từ\n")
    return text


def load_transcript_from_json(path: str) -> str:
    """Doc transcript tu file JSON duoc luu san."""
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)
    if isinstance(data, dict):
        entries = data.get("transcript") or []
    elif isinstance(data, list):
        entries = data
    else:
        entries = []
    text = " ".join(e.get("text", "") for e in entries if isinstance(e, dict) and e.get("text"))
    text = re.sub(r"\s+", " ", text).strip()
    print(f"�o. Doc transcript tu {os.path.basename(path)} ({len(text.split())} t���)\n")
    return text


def download_audio(video_id: str) -> str:
    url = f"https://www.youtube.com/watch?v={video_id}"
    tmp_dir = tempfile.mkdtemp(prefix="yt_audio_")
    out_path = os.path.join(tmp_dir, "audio.m4a")
    base_cmd = ["-f", "bestaudio/best", "-x", "--audio-format", "m4a", "-o", out_path, url]
    candidates = [
        ["yt-dlp"],
        [sys.executable, "-m", "yt_dlp"],
        ["py", "-3", "-m", "yt_dlp"],
    ]
    print("🎧 Đang tải audio bằng yt-dlp...")
    last_err = None
    for prefix in candidates:
        cmd = prefix + base_cmd
        try:
            subprocess.run(cmd, check=True, capture_output=True)
            return out_path
        except (FileNotFoundError, subprocess.CalledProcessError) as e:
            last_err = e
            continue
    raise RuntimeError(f"Tải audio thất bại (yt-dlp/ffmpeg?): {last_err}")


def transcribe_with_whisper(audio_path: str, language: str) -> str:
    try:
        from faster_whisper import WhisperModel
    except ImportError:
        raise RuntimeError("Thiếu faster-whisper. Cài: pip install faster-whisper")
    print("🗣️ Đang nhận diện giọng nói (ASR)...")
    model = WhisperModel("base", device="cpu", compute_type="int8")
    segments, _ = model.transcribe(
        audio_path,
        language=language if language.startswith("vi") else "en",
        beam_size=2,
        best_of=2,
        vad_filter=True,
    )
    parts = [seg.text.strip() for seg in segments]
    text = " ".join(parts).strip()
    if not text:
        raise RuntimeError("ASR không trả về nội dung.")
    print(f"✅ ASR ~{len(text.split())} từ\n")
    return text


def fetch_transcript_with_asr(video_id: str, language: str) -> str:
    audio_path = None
    try:
        audio_path = download_audio(video_id)
        return transcribe_with_whisper(audio_path, language)
    finally:
        if audio_path:
            shutil.rmtree(os.path.dirname(audio_path), ignore_errors=True)


def generate_summary_local(transcript: str, language: str, max_length: int = 500) -> str:
    """
    Tóm tắt cục bộ bằng BARTpho (VI) hoặc mT5 (EN)
    """
    try:
        from transformers import pipeline, AutoTokenizer, AutoModelForSeq2SeqLM
        import torch
    except ImportError:
        print("⚠️ Thiếu transformers hoặc torch")
        print("📦 Cài đặt: pip install transformers torch")
        return ""
    
    print("📝 Đang tạo summary cục bộ...")
    print("⏰ Lưu ý: Bước này có thể mất 2-5 phút khi tải model lần đầu...\n")
    
    # Chọn model theo ngôn ngữ
    if language.startswith("vi"):
        model_name = "vinai/bartpho-word"
        print(f"🤖 Sử dụng BARTpho cho tiếng Việt...")
    else:
        model_name = "google/mt5-small"
        print(f"🤖 Sử dụng mT5 cho tiếng Anh...")
    
    try:
        # Load model
        tokenizer = AutoTokenizer.from_pretrained(model_name)
        model = AutoModelForSeq2SeqLM.from_pretrained(model_name)
        
        # Cắt transcript nếu quá dài
        tokens = tokenizer.encode(transcript, max_length=1024, truncation=True)
        truncated_text = tokenizer.decode(tokens, skip_special_tokens=True)
        
        # Tạo summary
        if language.startswith("vi"):
            # BARTpho cần prefix đặc biệt
            inputs = tokenizer(truncated_text, return_tensors="pt", max_length=1024, truncation=True)
        else:
            # mT5 cần prefix "summarize:"
            inputs = tokenizer("summarize: " + truncated_text, return_tensors="pt", max_length=1024, truncation=True)
        
        summary_ids = model.generate(
            inputs["input_ids"],
            max_length=max_length,
            min_length=100,
            length_penalty=2.0,
            num_beams=4,
            early_stopping=True
        )
        
        summary = tokenizer.decode(summary_ids[0], skip_special_tokens=True)
        print(f"✅ Đã tạo summary (~{len(summary.split())} từ)\n")
        return summary
        
    except Exception as e:
        print(f"⚠️ Lỗi khi tạo summary: {e}")
        print("➡️ Tiếp tục không có summary...\n")
        return ""


def extract_key_points_keybert(transcript: str, max_points: int = 50, num_keywords: int = 30) -> Tuple[List[str], List[Tuple[str, float]]]:
    """
    Trích xuất key points bằng KeyBERT:
    1. Dùng KeyBERT để trích keyword/phrase quan trọng
    2. Tìm các câu chứa những keyword đó
    3. Trả về danh sách câu được xếp hạng theo mức độ liên quan
    """
    try:
        from keybert import KeyBERT
        from sentence_transformers import SentenceTransformer
    except ImportError:
        print("⚠️ Thiếu KeyBERT hoặc sentence-transformers")
        print("📦 Cài đặt: pip install keybert sentence-transformers")
        print("➡️ Fallback về phương pháp heuristic...\n")
        return extract_key_points_heuristic(transcript, max_points)
    
    print("🔎 Đang trích xuất key points bằng KeyBERT...")
    print("⏰ Lần đầu: Tải model ~120MB, mất 1-3 phút. Lần sau sẽ nhanh hơn...\n")
    
    # Tách câu
    sentences = re.split(r'[.!?]+', transcript)
    sentences = [s.strip() for s in sentences if len(s.strip()) > 20]
    
    if len(sentences) == 0:
        print("⚠️ Không có câu hợp lệ")
        return []
    
    # Load model embedding
    print("📥 Đang tải model embedding (sentence-transformers/all-MiniLM-L6-v2)...")
    import time
    start_time = time.time()
    embedding_model = SentenceTransformer('sentence-transformers/all-MiniLM-L6-v2')
    elapsed = time.time() - start_time
    print(f"✅ Model đã load ({elapsed:.1f}s)\n")
    kw_model = KeyBERT(model=embedding_model)
    
    # Trích xuất keywords/phrases
    print(f"🔑 Đang trích xuất {num_keywords} keywords...")
    keywords = kw_model.extract_keywords(
        transcript,
        keyphrase_ngram_range=(1, 3),  # Cho phép 1-3 từ
        stop_words='english',
        top_n=num_keywords,
        use_mmr=True,  # Maximal Marginal Relevance để đa dạng hóa
        diversity=0.7
    )
    
    # Hiển thị danh sách keywords với điểm số
    print(f"✅ Đã trích {len(keywords)} keywords quan trọng nhất:\n")
    for i, (keyword, score) in enumerate(keywords, 1):
        print(f"   {i:2d}. {keyword:30s} (điểm: {score:.3f})")
    print()
    
    # Lấy danh sách keyword (bỏ score)
    keyword_list = [kw for kw, score in keywords]
    
    # Trả về cả keywords với score để có thể lưu file
    return keyword_list, keywords
    
    # Tính điểm cho mỗi câu dựa trên số lượng keywords xuất hiện
    scored_sentences = []
    for sentence in sentences:
        lower_sent = sentence.lower()
        score = 0
        matched_keywords = []
        
        for keyword in keyword_list:
            if keyword.lower() in lower_sent:
                score += 1
                matched_keywords.append(keyword)
        
        # Bonus cho câu có độ dài vừa phải
        wc = len(sentence.split())
        if 10 <= wc <= 50:
            score += 0.5
        
        # Bonus cho câu có số (thường là bước, thống kê)
        if re.search(r'\d+', sentence):
            score += 0.3
        
        if score > 0:
            scored_sentences.append((score, sentence, matched_keywords))
    
    # Sắp xếp theo điểm giảm dần
    scored_sentences.sort(reverse=True, key=lambda x: x[0])
    
    # Lấy top N key points
    key_points = [sent for score, sent, kws in scored_sentences[:max_points]]
    
    print(f"✅ Đã trích {len(key_points)} key points từ KeyBERT\n")
    return key_points, keywords


def extract_key_points_heuristic(transcript: str, max_points: int = 50) -> List[str]:
    """
    Phương pháp heuristic cũ (fallback khi không có KeyBERT)
    """
    print("🔎 Đang trích xuất key points (heuristic)...")
    sentences = re.split(r'[.!?]+', transcript)
    sentences = [s.strip() for s in sentences if len(s.strip()) > 20]
    important = [
        'important', 'key', 'main', 'essential', 'critical', 'must', 'should',
        'step', 'first', 'second', 'next', 'then', 'finally',
        'example', 'for instance', 'such as', 'like',
        'because', 'reason', 'why', 'how', 'what', 'when', 'where',
        'define', 'definition', 'means', 'refers to',
        'remember', 'note', 'tip', 'trick', 'advice',
        'quan trọng', 'chính', 'cần', 'phải', 'nên',
        'bước', 'đầu tiên', 'thứ hai', 'tiếp theo', 'cuối cùng',
        'vì', 'tại sao', 'như thế nào', 'cái gì', 'khi nào',
    ]
    scored = []
    for sentence in sentences:
        score = 0
        lower = sentence.lower()
        for kw in important:
            if kw in lower:
                score += 1
        wc = len(sentence.split())
        if 10 <= wc <= 40:
            score += 2
        elif wc < 10:
            score -= 1
        if re.search(r'\d+', sentence):
            score += 1
        scored.append((score, sentence))
    scored.sort(reverse=True, key=lambda x: x[0])
    key_points = [s for sc, s in scored[:max_points] if sc > 0]
    print(f"✅ Đã trích {len(key_points)} key points\n")
    return key_points


def generate_title_with_gemini(key_points: List[str], language: str, api_key: str) -> str:
    import requests
    model_name = 'gemini-2.0-flash'
    key_points_text = "\n".join([f"- {p}" for p in key_points]) or "- (empty)"
    if language.startswith("vi"):
        prompt = f"Tao mot tieu de ngan gon (toi da 12 tu) bang Tieng Viet cho video nay, chi tra ve duy nhat tieu de.\n\nKey points:\n{key_points_text}"
        default_title = "Bai hoc"
    else:
        prompt = f"Create a short, engaging title (max 12 words) in English. Return ONLY the title.\n\nKey points:\n{key_points_text}"
        default_title = "Lesson"
    url = f"https://generativelanguage.googleapis.com/v1/models/{model_name}:generateContent?key={api_key}"
    payload = {"contents": [{"role": "user", "parts": [{"text": prompt}]}]}
    headers = {"Content-Type": "application/json"}
    try:
        r = requests.post(url, headers=headers, data=json.dumps(payload), timeout=60)
        r.raise_for_status()
        data = r.json()
        candidates = data.get("candidates", [])
        text_out = ""
        if candidates:
            parts = candidates[0].get("content", {}).get("parts", [])
            for p in parts:
                t = p.get("text", "")
                if t:
                    text_out += t
        title = (text_out or "").strip().split("\n")[0]
        title = re.sub(r"^[#\\s]+", "", title).strip()
        return title or default_title
    except Exception:
        return default_title


def generate_lesson_with_gemini(video_title: str, key_points: List[str], language: str, api_key: str, summary: Optional[str] = None) -> str:
    import requests
    print("⚡ Đang tạo bài học với Gemini...")
    model_name = 'gemini-2.0-flash'
    key_points_text = "\n".join([f"- {p}" for p in key_points])
    
    # Thêm summary nếu có (chế độ enrich)
    summary_section = ""
    if summary:
        summary_section = f"\n\nSUMMARY RÚT GỌN:\n{summary}\n"
    
    if language.startswith("vi"):
        title_instruction = f"Su dung tieu de nay va bat dau bai hoc bang heading cap 1 (#): {video_title}" if video_title else "Tao tieu de ngan gon, bat dau bai hoc bang heading cap 1 (#) voi tieu de do."
        prompt = f"""
Ban la mot chuyen gia giao duc. Tu cac key points duoc trich xuat tu mot video YouTube,
hay tao mot BAI HOC HOAN CHINH bang tieng Viet voi cau truc sau:

# TIEU DE BAI HOC
{title_instruction}

## MUC TIEU HOC TAP
[Liet ke 4-6 muc tieu cu the ma nguoi hoc se dat duoc]

## CAC KHAI NIEM CHINH
[Giai thich chi tiet cac khai niem quan trong, co dinh nghia, vi du minh hoa]

## NOI DUNG CHI TIET
[Trinh bay noi dung theo tung phan logic, co the chia thanh cac muc con:
- Phan 1: ...
- Phan 2: ...
Giu day du thong tin ky thuat, code, cong thuc neu co]

## VI DU MINH HOA
[Dua ra cac vi du cu the, de hieu de minh hoa cac khai niem]

## CAC BUOC THUC HIEN (neu co)
[Neu video co huong dan thuc hanh, liet ke chi tiet tung buoc]

## TIPS & LUU Y
[Cac meo, best practices, dieu can tranh]

## TOM TAT
[Tom tat 5-7 diem chinh can nho]

## CAU HOI ON TAP
[5-7 cau hoi giup nguoi hoc kiem tra kien thuc]

---

KEY POINTS TU VIDEO:
{key_points_text}
{summary_section}
Hay tao bai hoc CHI TIET, DE HIEU, CO CAU TRUC. Giu nguyen cac thuat ngu ky thuat quan trong.
Bai hoc phai DAY DU de nguoi doc co the hoc duoc kien thuc MA KHONG CAN XEM VIDEO.
"""
    else:
        title_instruction = f"Use this title and start the lesson with a level-1 heading (#): {video_title}" if video_title else "Create a short title and start the lesson with it as a level-1 heading (#)."
        prompt = f"""
You are an expert educator. From the key points extracted from a YouTube video,
create a COMPREHENSIVE LESSON in English with the following structure:

# LESSON TITLE
{title_instruction}

## LEARNING OBJECTIVES
[List 4-6 specific objectives learners will achieve]

## KEY CONCEPTS
[Explain important concepts in detail with definitions and examples]

## DETAILED CONTENT
[Present content in logical sections, can be divided into subsections:
- Part 1: ...
- Part 2: ...
Keep all technical information, code, formulas if any]

## EXAMPLES
[Provide specific, easy-to-understand examples to illustrate concepts]

## STEP-BY-STEP GUIDE (if applicable)
[If video has practical instructions, list detailed steps]

## TIPS & NOTES
[Tips, best practices, common mistakes to avoid]

## SUMMARY
[Summarize 5-7 key takeaways]

## REVIEW QUESTIONS
[5-7 questions to help learners test their knowledge]

---

KEY POINTS FROM VIDEO:
{key_points_text}
{summary_section}
Create a DETAILED, CLEAR, WELL-STRUCTURED lesson. Keep important technical terms.
The lesson must be COMPLETE so readers can learn WITHOUT WATCHING THE VIDEO.
"""
    url = f"https://generativelanguage.googleapis.com/v1/models/{model_name}:generateContent?key={api_key}"
    payload = {"contents": [{"role": "user", "parts": [{"text": prompt}]}]}
    headers = {"Content-Type": "application/json"}
    r = requests.post(url, headers=headers, data=json.dumps(payload), timeout=120)
    r.raise_for_status()
    data = r.json()
    lesson = ""
    candidates = data.get("candidates", [])
    if candidates:
        parts = candidates[0].get("content", {}).get("parts", [])
        for p in parts:
            t = p.get("text", "")
            if t:
                lesson += t
    if not lesson:
        raise RuntimeError(f"Phan hoi khong hop le tu Gemini: {data}")
    return lesson


def main() -> int:
    import time
    total_start = time.time()
    
    parser = argparse.ArgumentParser(description="Tao bai hoc tu YouTube bang Gemini AI")
    parser.add_argument("--url", required=True, help="URL hoac ID video YouTube")
    parser.add_argument("--language", "-l", default="en", help="Ngon ngu (en hoac vi)")
    parser.add_argument("--api-key", "-k", help="Gemini API key (hoac set bien moi truong GEMINI_API_KEY)")
    parser.add_argument("--output", "-o", help="File dau ra (neu khong chi dinh, chi in ra)")
    parser.add_argument("--transcript-json", help="Duong dan file JSON transcript neu co san")
    parser.add_argument("--max-points", type=int, default=50, help="So luong key points toi da (mac dinh 50)")
    parser.add_argument("--num-keywords", type=int, default=30, help="So luong keywords trích xuất bởi KeyBERT (mac dinh 30)")
    parser.add_argument("--keybert", action="store_true", help="Su dung KeyBERT de trich xuat key points (mac dinh)")
    parser.add_argument("--no-keybert", action="store_true", help="Su dung phuong phap heuristic thay vi KeyBERT")
    parser.add_argument("--enrich", action="store_true", help="Tao summary cuc bo bang BARTpho (VI) hoac mT5 (EN)")
    parser.add_argument("--save-keywords", help="Luu keywords ra file text (vd: keywords.txt)")
    args = parser.parse_args()

    api_key = args.api_key or os.getenv("GEMINI_API_KEY") or DEFAULT_GEMINI_API_KEY
    if not api_key:
        print("Thiếu Gemini API key! Set GEMINI_API_KEY hoặc --api-key.")
        return 1

    print("=" * 70)
    print("TAO BAI HOC TU YOUTUBE BANG GEMINI AI")
    print("=" * 70)
    print()
    
    # Hiển thị cảnh báo nếu dùng các tính năng chậm
    if args.enrich:
        print("⚠️  CHẾ ĐỘ ENRICH: Có thể mất 3-10 phút (tải + chạy BARTpho/mT5)")
    if not args.no_keybert:
        print("⚠️  KEYBERT: Lần đầu tải model ~120MB, mất 1-3 phút")
    if not args.transcript_json:
        print("⚠️  Có thể dùng ASR nếu không có phụ đề (chậm hơn)")
    print()

    try:
        # Bước 1: Lấy video ID
        video_id = extract_video_id(args.url)
        
        # Bước 2 & 3: Lấy transcript (youtube-transcript-api hoặc ASR fallback)
        if args.transcript_json and os.path.isfile(args.transcript_json):
            transcript = load_transcript_from_json(args.transcript_json)
        else:
            try:
                transcript = get_transcript(video_id, args.language)
            except Exception as e:
                print(f"❌ Lỗi transcript: {e}")
                print("➡ Thử nhận diện giọng nói từ audio (ASR fallback)...")
                transcript = fetch_transcript_with_asr(video_id, args.language)

        # Bước 4: Trích xuất key points
        keywords_data = None
        if args.no_keybert:
            print("📌 Sử dụng phương pháp heuristic\n")
            key_points = extract_key_points_heuristic(transcript, args.max_points)
        else:
            print("📌 Sử dụng KeyBERT\n")
            key_points, keywords_data = extract_key_points_keybert(transcript, args.max_points, args.num_keywords)
            
            # Lưu keywords ra file nếu được yêu cầu
            if args.save_keywords and keywords_data:
                from datetime import datetime
                with open(args.save_keywords, "w", encoding="utf-8") as f:
                    f.write("=" * 70 + "\n")
                    f.write(f"KEYWORDS TRÍCH XUẤT TỪ VIDEO (Tổng: {len(keywords_data)})\n")
                    f.write("=" * 70 + "\n\n")
                    for i, (keyword, score) in enumerate(keywords_data, 1):
                        f.write(f"{i:3d}. {keyword:40s} (điểm: {score:.4f})\n")
                    f.write("\n" + "=" * 70 + "\n")
                    f.write(f"Tổng số keywords: {len(keywords_data)}\n")
                    f.write(f"Ngày tạo: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
                print(f"💾 Đã lưu keywords vào: {args.save_keywords}\n")
        
        # Bước 5: (Tùy chọn) Tạo summary cục bộ nếu có --enrich
        summary = None
        if args.enrich:
            print("🌟 Chế độ ENRICH: Tạo summary cục bộ\n")
            summary = generate_summary_local(transcript, args.language)
        
        # Bước 6: Gọi Gemini
        # 6a. Tạo tiêu đề
        title = generate_title_with_gemini(key_points, args.language, api_key)
        
        # 6b. Tạo bài giảng đầy đủ (với summary nếu có)
        lesson = generate_lesson_with_gemini(title, key_points, args.language, api_key, summary)

        print("=" * 70)
        print("BAI HOC HOAN CHINH")
        print("=" * 70)
        print()
        print(lesson)
        print()
        print("=" * 70)

        if args.output:
            with open(args.output, "w", encoding="utf-8") as f:
                f.write(lesson)
            print(f"\nĐã lưu bài học vào: {args.output}")
        
        # Hiển thị tổng thời gian
        total_elapsed = time.time() - total_start
        print(f"\n⏱️  Tổng thời gian: {total_elapsed:.1f}s ({total_elapsed/60:.1f} phút)")
        return 0
    except Exception as e:
        print(f"\n❌ Lỗi: {e}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
