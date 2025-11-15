#!/usr/bin/env python3
"""
Lấy transcript YouTube đơn giản - không cần AI
Usage: python simple_transcript.py --url <youtube_url>
"""

import argparse
import re
from urllib.parse import urlparse, parse_qs
from youtube_transcript_api import YouTubeTranscriptApi

def extract_video_id(url_or_id: str) -> str:
    if re.fullmatch(r"[a-zA-Z0-9_-]{11}", url_or_id):
        return url_or_id
    
    try:
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
    except Exception:
        pass
    
    raise ValueError("Could not extract a valid YouTube video ID")

def get_transcript(video_id: str, language: str = "en"):
    """Lấy transcript từ YouTube"""
    langs = [language, "vi", "vi-VN", "en", "en-US", "en-GB"]
    api = YouTubeTranscriptApi()
    
    try:
        fetched = api.fetch(video_id, languages=langs)
        raw_entries = fetched.to_raw_data()
        text = " ".join(e.get("text", "") for e in raw_entries if e.get("text"))
        return re.sub(r"\s+", " ", text).strip()
    except Exception as e:
        raise RuntimeError(f"Không thể lấy transcript: {e}")

def main():
    parser = argparse.ArgumentParser(description="Lấy transcript từ YouTube")
    parser.add_argument("--url", required=True, help="YouTube URL")
    parser.add_argument("--language", "-l", default="en", help="Ngôn ngữ (en, vi)")
    parser.add_argument("--output", "-o", help="File đầu ra (tùy chọn)")
    args = parser.parse_args()
    
    try:
        video_id = extract_video_id(args.url)
        print(f"Video ID: {video_id}")
        print("Đang lấy transcript...")
        
        transcript = get_transcript(video_id, args.language)
        
        if args.output:
            with open(args.output, "w", encoding="utf-8") as f:
                f.write(transcript)
            print(f"\n✓ Đã lưu vào: {args.output}")
            print(f"Số từ: {len(transcript.split())}")
        else:
            print("\n=== TRANSCRIPT ===\n")
            print(transcript)
            print(f"\n\nTổng số từ: {len(transcript.split())}")
            
    except Exception as e:
        print(f"Lỗi: {e}")
        return 1
    
    return 0

if __name__ == "__main__":
    exit(main())
