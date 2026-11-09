#!/usr/bin/env python3
"""APPROXIMATE PHP syntax audit (NOT php -l). The build sandbox has no PHP binary and no network, so this is a hand-written tokenizer.
Detects: unterminated strings/comments/heredocs, unbalanced (), [], {}, and two operands side by side
(string/number/variable/name directly followed by string/number/variable/name) which is how a broken quote like 'a'sync'b' shows up.
It can miss real errors and can false-positive on exotic syntax. Treat a PASS as 'no obvious breakage', never as proof. Run `php -l` on a real PHP install."""
import sys, os, re
KW_OK_BEFORE = {'as','instanceof','and','or','xor','else','return','echo','print','new','case','yield','throw','clone','include','require','include_once','require_once','use','extends','implements','insteadof','fn','function','static','const','public','private','protected','abstract','final','readonly','global','namespace','if','elseif','while','for','foreach','switch','match','do','try','catch','finally','declare','class','interface','trait','enum','goto','continue','break','default','var','list','array','isset','unset','empty','exit','die'}
def lex(src):
    i, n, out, line = 0, len(src), [], 1
    problems = []
    def add(kind, val, ln): out.append((kind, val, ln))
    # inline HTML until <?php
    while i < n:
        j = src.find('<?', i)
        if j < 0: break
        line += src.count('\n', i, j)
        i = j
        if src.startswith('<?php', i): i += 5
        elif src.startswith('<?=', i): i += 3; add('id', 'echo', line)
        else: i += 2
        while i < n:
            c = src[i]
            if c == '\n': line += 1; i += 1; continue
            if c in ' \t\r': i += 1; continue
            if src.startswith('?>', i):
                add('p', ';', line); i += 2
                if i < n and src[i] == '\n': i += 1; line += 1
                break
            if c == '#' and not src.startswith('#[', i) or src.startswith('//', i):
                m = re.compile(r'[^\n]*?(?=\?>|\n|$)').match(src, i); i = m.end(); continue
            if src.startswith('#[', i):   # attribute: skip to ]
                j2 = src.find(']', i); i = j2 + 1 if j2 > 0 else n; continue
            if src.startswith('/*', i):
                j2 = src.find('*/', i + 2)
                if j2 < 0: problems.append((line, 'unterminated comment')); i = n; break
                line += src.count('\n', i, j2); i = j2 + 2; continue
            if c == "'":
                k = i + 1
                while k < n and src[k] != "'": k += 2 if src[k] == '\\' else 1
                if k >= n: problems.append((line, 'unterminated single-quoted string')); i = n; break
                add('str', src[i:k+1], line); line += src.count('\n', i, k); i = k + 1; continue
            if c == '"' or c == '`':
                k = i + 1; depth = 0
                while k < n:
                    ch = src[k]
                    if ch == '\\': k += 2; continue
                    if ch == '{' and k + 1 < n and src[k+1] == '$': depth += 1; k += 2; continue
                    if depth and ch == '}': depth -= 1; k += 1; continue
                    if depth and ch in '\'"':   # quote inside {$a['k']}
                        q = ch; k += 1
                        while k < n and src[k] != q: k += 2 if src[k] == '\\' else 1
                        k += 1; continue
                    if ch == c and not depth: break
                    k += 1
                if k >= n: problems.append((line, 'unterminated double-quoted string')); i = n; break
                add('str', src[i:k+1], line); line += src.count('\n', i, k); i = k + 1; continue
            m = re.compile(r'<<<[ \t]*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n').match(src, i)
            if m:
                tag = m.group(2)
                e = re.compile(r'^[ \t]*' + tag + r'\b', re.M).search(src, m.end())
                if not e: problems.append((line, 'unterminated heredoc ' + tag)); i = n; break
                add('str', 'heredoc', line); line += src.count('\n', i, e.end()); i = e.end(); continue
            m = re.compile(r'\$[A-Za-z_\x80-\uffff][A-Za-z0-9_\x80-\uffff]*').match(src, i)
            if m: add('var', m.group(), line); i = m.end(); continue
            m = re.compile(r'(?:0[xX][0-9a-fA-F_]+|0[bB][01_]+|(?:\d[\d_]*)?\.?\d[\d_]*(?:[eE][+-]?\d+)?)').match(src, i)
            if m and m.group() and (c.isdigit() or (c == '.' and i + 1 < n and src[i+1].isdigit())): add('num', m.group(), line); i = m.end(); continue
            m = re.compile(r'\\?[A-Za-z_\x80-\uffff][A-Za-z0-9_\x80-\uffff]*(?:\\[A-Za-z_][A-Za-z0-9_]*)*').match(src, i)
            if m: add('id', m.group(), line); i = m.end(); continue
            add('p', c, line); i += 1
    return out, problems
def check(path):
    src = open(path, encoding='utf-8', errors='replace').read()
    toks, probs = lex(src)
    st = {'(': 0, '[': 0, '{': 0}; pairs = {')': '(', ']': '[', '}': '{'}
    prev = None
    for kind, val, ln in toks:
        if kind == 'p':
            if val in st: st[val] += 1
            elif val in pairs:
                st[pairs[val]] -= 1
                if st[pairs[val]] < 0: probs.append((ln, 'unexpected ' + val)); st[pairs[val]] = 0
        if prev:
            pk, pv, pl = prev
            cstart = kind in ('str', 'num', 'var') or (kind == 'id' and val.lower() not in KW_OK_BEFORE)
            if pk in ('str', 'num', 'var') and cstart:
                probs.append((ln, 'two operands side by side: %s then %s' % (pv[:30], val[:30])))
            elif pk == 'id' and pv.lower() not in KW_OK_BEFORE and kind in ('str', 'num'):
                probs.append((ln, 'name directly followed by literal: %s then %s' % (pv[:30], val[:30])))
        prev = (kind, val, ln)
    for k, v in st.items():
        if v: probs.append((0, 'unbalanced %s (%+d)' % (k, v)))
    return probs
if __name__ == '__main__':
    root = sys.argv[1] if len(sys.argv) > 1 else '.'
    bad = n = 0
    for dp, dn, fn in os.walk(root):
        for f in sorted(fn):
            if f.endswith('.php'):
                n += 1; p = os.path.join(dp, f)
                for ln, m in check(p): bad += 1; print('%s:%s: %s' % (os.path.relpath(p, root), ln, m))
    print('checked %d php files, %d finding(s)' % (n, bad)); sys.exit(1 if bad else 0)
