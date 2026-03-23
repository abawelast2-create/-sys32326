"""
مصدّر الكود الكامل — يطبع شجرة المشروع + محتوى كل ملف في ملف Markdown واحد
"""
import os
import glob
import re

# المجلدات والملفات المستبعدة
SKIP_DIRS = {
    '.git', 'node_modules', '__pycache__', '.vscode', '.idea',
    'vendor', 'cache', 'storage', '.cache', '.tmp'
}

SKIP_FILES = {'export_all.py'}

# الامتدادات الثنائية (binary) — لن يُقرأ محتواها
BINARY_EXT = {
    '.png', '.jpg', '.jpeg', '.gif', '.ico', '.svg', '.webp', '.bmp',
    '.woff', '.woff2', '.ttf', '.eot', '.otf',
    '.zip', '.tar', '.gz', '.rar', '.7z',
    '.pdf', '.doc', '.docx', '.xls', '.xlsx',
    '.mp3', '.mp4', '.wav', '.ogg', '.webm',
    '.exe', '.dll', '.so', '.pyc', '.pyo',
    '.lock', '.map'
}

# الحد الأقصى لحجم الملف (500 كيلوبايت)
MAX_FILE_SIZE = 500 * 1024


def get_next_version(base_dir):
    """يبحث عن آخر إصدار All_NNN.md ويعيد الرقم التالي + يحذف القديم"""
    pattern = os.path.join(base_dir, 'All_*.md')
    existing = sorted(glob.glob(pattern))

    if not existing:
        # لا يوجد إصدار سابق — أيضاً تحقق من All.md بدون رقم
        plain = os.path.join(base_dir, 'All.md')
        if os.path.exists(plain):
            os.remove(plain)
        return 1, None

    # استخرج أعلى رقم
    max_num = 0
    for f in existing:
        m = re.search(r'All_(\d+)\.md$', os.path.basename(f))
        if m:
            max_num = max(max_num, int(m.group(1)))

    # احذف جميع الإصدارات القديمة
    for f in existing:
        os.remove(f)

    return max_num + 1, existing


def should_skip_dir(dirname):
    return dirname in SKIP_DIRS or dirname.startswith('.')


def build_tree(root_dir, prefix=''):
    """يبني شجرة المجلدات كنص"""
    lines = []
    entries = sorted(os.listdir(root_dir))
    dirs = [e for e in entries if os.path.isdir(os.path.join(root_dir, e)) and not should_skip_dir(e)]
    files = [e for e in entries if os.path.isfile(os.path.join(root_dir, e))]

    all_items = dirs + files
    for i, item in enumerate(all_items):
        is_last = (i == len(all_items) - 1)
        connector = '└── ' if is_last else '├── '
        full_path = os.path.join(root_dir, item)

        if os.path.isdir(full_path):
            lines.append(f'{prefix}{connector}{item}/')
            extension = '    ' if is_last else '│   '
            lines.extend(build_tree(full_path, prefix + extension))
        else:
            lines.append(f'{prefix}{connector}{item}')

    return lines


def get_language(ext):
    """يعيد اسم اللغة لتلوين الكود في Markdown"""
    lang_map = {
        '.py': 'python', '.php': 'php', '.js': 'javascript',
        '.ts': 'typescript', '.css': 'css', '.html': 'html',
        '.htm': 'html', '.json': 'json', '.xml': 'xml',
        '.sql': 'sql', '.sh': 'bash', '.ps1': 'powershell',
        '.bat': 'batch', '.cmd': 'batch', '.yml': 'yaml',
        '.yaml': 'yaml', '.md': 'markdown', '.txt': 'text',
        '.env': 'ini', '.ini': 'ini', '.conf': 'ini',
        '.htaccess': 'apache', '.nginx': 'nginx',
    }
    return lang_map.get(ext.lower(), '')


def collect_files(root_dir, output_filename):
    """يجمع كل الملفات مع مساراتها"""
    file_list = []
    for dirpath, dirnames, filenames in os.walk(root_dir):
        # استبعد المجلدات المخفية والمحظورة
        dirnames[:] = [d for d in sorted(dirnames) if not should_skip_dir(d)]

        for fname in sorted(filenames):
            if fname in SKIP_FILES or fname == output_filename:
                continue

            full_path = os.path.join(dirpath, fname)
            rel_path = os.path.relpath(full_path, root_dir).replace('\\', '/')
            file_list.append((rel_path, full_path))

    return file_list


def main():
    base_dir = os.path.dirname(os.path.abspath(__file__))
    project_name = os.path.basename(base_dir)

    # حدد رقم الإصدار
    version, deleted = get_next_version(base_dir)
    version_str = f'{version:03d}'
    output_filename = f'All_{version_str}.md'
    output_path = os.path.join(base_dir, output_filename)

    print(f'[*] Project: {project_name}')
    if deleted:
        for d in deleted:
            print(f'[-] Deleted: {os.path.basename(d)}')
    print(f'[+] Creating: {output_filename}')
    print()

    # — بناء الشجرة —
    tree_lines = build_tree(base_dir)

    # — جمع الملفات —
    files = collect_files(base_dir, output_filename)

    total_files = len(files)
    print(f'[i] Files: {total_files}')

    # — كتابة الملف —
    with open(output_path, 'w', encoding='utf-8') as out:
        # العنوان
        out.write(f'# 📦 {project_name} — الكود الكامل\n\n')
        out.write(f'**الإصدار:** `{version_str}`  \n')
        out.write(f'**عدد الملفات:** {total_files}  \n')
        out.write(f'**تاريخ التصدير:** {__import__("datetime").datetime.now().strftime("%Y-%m-%d %H:%M:%S")}  \n\n')
        out.write('---\n\n')

        # الشجرة
        out.write('## 🌳 هيكل المشروع\n\n')
        out.write('```\n')
        out.write(f'{project_name}/\n')
        for line in tree_lines:
            out.write(line + '\n')
        out.write('```\n\n')
        out.write('---\n\n')

        # محتوى الملفات
        out.write('## 📄 محتوى الملفات\n\n')

        for idx, (rel_path, full_path) in enumerate(files, 1):
            _, ext = os.path.splitext(full_path)
            file_size = os.path.getsize(full_path)

            out.write(f'### {idx}. `{rel_path}`\n\n')

            # ملف ثنائي
            if ext.lower() in BINARY_EXT:
                size_kb = file_size / 1024
                out.write(f'> ⚡ ملف ثنائي ({ext}) — {size_kb:.1f} KB\n\n')
                out.write('---\n\n')
                print(f'  [{idx}/{total_files}] {rel_path} (binary, skipped)')
                continue

            # ملف كبير جداً
            if file_size > MAX_FILE_SIZE:
                size_kb = file_size / 1024
                out.write(f'> ⚠️ ملف كبير ({size_kb:.0f} KB) — تم تجاوزه\n\n')
                out.write('---\n\n')
                print(f'  [{idx}/{total_files}] {rel_path} (too large, skipped)')
                continue

            # اقرأ المحتوى
            try:
                with open(full_path, 'r', encoding='utf-8', errors='replace') as f:
                    content = f.read()
            except Exception as e:
                out.write(f'> ❌ خطأ في القراءة: `{e}`\n\n')
                out.write('---\n\n')
                print(f'  [{idx}/{total_files}] {rel_path} (error)')
                continue

            lang = get_language(ext)
            out.write(f'```{lang}\n')
            out.write(content)
            if not content.endswith('\n'):
                out.write('\n')
            out.write('```\n\n')
            out.write('---\n\n')

            print(f'  [{idx}/{total_files}] {rel_path} OK')

    final_size = os.path.getsize(output_path)
    size_mb = final_size / (1024 * 1024)
    print()
    print(f'[OK] Done: {output_filename} ({size_mb:.2f} MB)')


if __name__ == '__main__':
    main()
