#!/usr/bin/env python3
"""Isolated real browser editor + real PHP renderer; only HTTP transport is replaced."""
import argparse
import json
from pathlib import Path
import subprocess
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = (ROOT/'public/forum-assets/forum.js').read_text().split('function list(items,',1)[0]
SCRIPT += "\nwindow.mediaDemo=m=>{add(app,richBody(m),attachmentList(m.attachments))};me={csrf_token:'test'};add(app,form([['body','Сообщение','textarea']],'Отправить',async d=>{window.submitted=d;const r=await f('/content/preview','POST',d);add(app,richBody({...d,body_html:r.html}))}));"
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
        # Real browser multipart and attachment picker; transport is isolated.
        reset();source('File message','plain')
        page.evaluate("""()=>{window.fetch=async(url,options={})=>{
          if(url.endsWith('/attachments')&&options.method==='POST'){
            const file=options.body.get('file');window.uploaded={name:file.name,body:await file.text(),csrf:options.headers['X-CSRF-Token']};
            return {ok:true,json:async()=>({data:{id:'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',filename:file.name,size_bytes:file.size,download_url:'/api/v1/forum/attachments/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/download'}})};
          }
          return {ok:true,json:async()=>await window.localPreview(options.body)};
        }}""")
        page.locator('input[type=file]').set_input_files({'name':'report.txt','mimeType':'text/plain','buffer':b'Attachment browser test'})
        click('Загрузить файлы');expect(page.get_by_role('link',name='report.txt')).to_be_visible()
        assert page.evaluate('window.uploaded')=={'name':'report.txt','body':'Attachment browser test','csrf':'test'}
        click('Отправить');expect(page.locator('#app > .rich-content')).to_have_text('File message')
        assert page.evaluate('window.submitted.attachment_ids')==['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']
        reset();page.evaluate("""()=>{const id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',v={url:'/api/v1/forum/attachments/'+id+'/image/thumbnail',width:240,height:120,mime:'image/png'};window.mediaDemo({body:'image{'+id+'} image{bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb}',body_format:'plain',attachments:[{id,filename:'photo.png',mime:'image/png',size_bytes:100,download_url:'/api/v1/forum/attachments/'+id+'/download',variants:{thumbnail:v,medium:{...v,width:960,url:v.url.replace('thumbnail','medium')},large:{...v,width:1600,url:v.url.replace('thumbnail','large')}}}]})}""")
        expect(page.locator('#app > .rich-content img')).to_have_count(1)
        assert 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' in page.locator('#app > .rich-content').inner_text()
        expect(page.locator('.media-gallery img')).to_have_count(1)
        assert '960w' in page.locator('.media-gallery img').get_attribute('srcset')
        page.locator('.media-gallery button').click();expect(page.locator('dialog.media-lightbox')).to_be_visible()
        assert page.locator('dialog img').get_attribute('src').endswith('/image/large')
        page.keyboard.press('Escape');expect(page.locator('dialog.media-lightbox')).to_have_count(0)
        assert not errors,errors
        browser.close()
    print('PASS real browser editor + PHP renderer: Markdown, XSS, WYSIWYG bold, submission, conversion, opaque code and escapes')


if __name__=='__main__':main()
