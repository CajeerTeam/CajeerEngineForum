#!/usr/bin/env python3
"""Isolated real browser editor + real PHP renderer; only HTTP transport is replaced."""
import argparse
import json
from pathlib import Path
import subprocess
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = (ROOT/'public/forum-assets/forum.js').read_text().split('function list(items,',1)[0]
SCRIPT += "\nme={csrf_token:'test'};add(app,form([['body','Сообщение','textarea']],'Отправить',async d=>{window.submitted=d;const r=await f('/content/preview','POST',d);add(app,richBody({...d,body_html:r.html}))}));"
PHP = r'''require 'core/Forum/ForumException.php';require 'core/Forum/Content/ForumContentRenderer.php';$d=json_decode(stream_get_contents(STDIN),true);$r=\CajeerEngine\Forum\Content\ForumContentRenderer::source($d['body'],$d['body_format'],true);echo json_encode(['data'=>\CajeerEngine\Forum\Content\ForumContentRenderer::render($r,$d['body_format'])],JSON_THROW_ON_ERROR);'''


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium')
    parser.add_argument('--executable',help='Optional installed browser path')
    args=parser.parse_args()
    with sync_playwright() as p:
        options={'executable_path':args.executable} if args.executable else {}
        if args.browser=='chromium': options['args']=['--no-sandbox']
        browser=getattr(p,args.browser).launch(**options)
        page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.expose_function('localPreview',lambda d:json.loads(subprocess.check_output(['php','-r',PHP],input=d.encode(),cwd=ROOT)))
        def reset():
            page.set_content('<div id="notice"></div><div id="app"></div>')
            page.evaluate('window.fetch=async (url,options)=>({ok:true,json:async()=>await window.localPreview(options.body)})')
            page.add_script_tag(content='(()=>{'+SCRIPT+'})()')
        def click(name): page.get_by_role('button',name=name,exact=True).click()
        def source(value,format):
            page.locator('[name=body_format]').select_option(format)
            page.locator('[name=body]').fill(value)
        reset();source('**Rich preview** <img src=x onerror=alert(1)>','markdown');click('Предпросмотр')
        expect(page.locator('.rich-preview strong')).to_have_text('Rich preview')
        assert page.locator('.rich-preview img').count()==0
        click('Отправить');expect(page.locator('#app > .rich-content strong')).to_have_text('Rich preview')
        assert page.evaluate('window.submitted.body_format')=='markdown'
        reset();click('Визуальный редактор');visual=page.locator('.rich-visual');expect(visual).to_be_visible()
        visual.fill('Visual text');visual.press('Control+a');click('Жирный');click('Отправить')
        expect(page.locator('#app > .rich-content strong')).to_have_text('Visual text')
        assert page.evaluate('window.submitted.body_format')=='bbcode'
        ref='@{aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa}'
        reset();source('**Bold**\n\n```php\n'+ref+'\n```\n\\'+ref,'markdown')
        page.once('dialog',lambda d:d.accept());click('Визуальный редактор')
        expect(page.locator('.rich-visual strong')).to_have_text('Bold');click('Исходный текст')
        body=page.locator('[name=body]').input_value()
        assert '[b]Bold[/b]' in body and '[code=php]' in body and '\\'+ref in body
        click('Предпросмотр');expect(page.locator('.rich-preview pre code')).to_have_text(ref)
        assert page.locator('.rich-preview .content-reference').count()==0
        assert not errors,errors
        browser.close()
    print('PASS real browser editor + PHP renderer: Markdown, XSS, WYSIWYG bold, submission, conversion, opaque code and escapes')


if __name__=='__main__':main()
