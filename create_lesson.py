#!/usr/bin/env python3
"""
Tạo bài học hoàn chỉnh từ YouTube video
Không cần xem video, vẫn học được đầy đủ kiến thức!
"""

import sys
import argparse
from quickstart import (
    extract_video_id,
    fetch_transcript_text,
    summarize_text,
)


def create_comprehensive_lesson(
    video_url: str,
    language: str = "en",
    output_file: str = None,
):
    """
    Tạo bài học hoàn chỉnh từ YouTube video
    
    Args:
        video_url: URL hoặc ID của video YouTube
        language: Ngôn ngữ (vi hoặc en)
        output_file: File đầu ra (nếu None, in ra console)
    """
    print("=" * 70)
    print("TẠO BÀI HỌC HOÀN CHỈNH TỪ YOUTUBE VIDEO")
    print("=" * 70)
    
    # Bước 1: Lấy video ID
    try:
        video_id = extract_video_id(video_url)
        print(f"✓ Video ID: {video_id}")
    except ValueError as e:
        print(f"✗ Lỗi: {e}")
        return False
    
    # Bước 2: Lấy transcript
    print(f"⏳ Đang lấy transcript (ngôn ngữ: {language})...")
    try:
        transcript = fetch_transcript_text(video_id, language)
        word_count = len(transcript.split())
        print(f"✓ Đã lấy được {word_count} từ")
    except Exception as e:
        print(f"✗ Không thể lấy transcript: {e}")
        return False
    
    # Bước 3: Tạo bài học
    print("\n⏳ Đang tạo bài học hoàn chỉnh...")
    print("   (Quá trình này có thể mất 5-15 phút...)\n")
    
    try:
        lesson = summarize_text(
            transcript,
            model_name="sshleifer/distilbart-cnn-12-6",
            min_length=150,
            max_length=400,
            chunk_words=600,
            combine=True,
            mode="lesson",
            language=language,
        )
    except Exception as e:
        print(f"✗ Lỗi khi tạo bài học: {e}")
        return False
    
    # Bước 4: Lưu hoặc in kết quả
    if output_file:
        try:
            with open(output_file, "w", encoding="utf-8") as f:
                f.write(lesson)
            print("\n" + "=" * 70)
            print("✓ HOÀN THÀNH!")
            print("=" * 70)
            print(f"Bài học đã được lưu vào: {output_file}")
            print(f"Mở file để xem nội dung đầy đủ")
        except Exception as e:
            print(f"✗ Không thể lưu file: {e}")
            return False
    else:
        print("\n" + "=" * 70)
        print("BÀI HỌC HOÀN CHỈNH")
        print("=" * 70 + "\n")
        print(lesson)
    
    return True


def main():
    parser = argparse.ArgumentParser(
        description="Tạo bài học hoàn chỉnh từ YouTube video"
    )
    parser.add_argument(
        "--url",
        required=True,
        help="URL hoặc ID của video YouTube"
    )
    parser.add_argument(
        "--language", "-l",
        default="en",
        choices=["en", "vi"],
        help="Ngôn ngữ bài học (en hoặc vi)"
    )
    parser.add_argument(
        "--output", "-o",
        help="File đầu ra (nếu không chỉ định, in ra console)"
    )
    
    args = parser.parse_args()
    
    success = create_comprehensive_lesson(
        args.url,
        args.language,
        args.output
    )
    
    sys.exit(0 if success else 1)


if __name__ == "__main__":
    main()
