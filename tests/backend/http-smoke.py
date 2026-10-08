#!/usr/bin/env python3
"""Starts an isolated ThinkPHP HTTP server and tests real request/file boundaries."""
import json, os, pathlib, secrets, socket, subprocess, tempfile, time, urllib.error, urllib.request
root=pathlib.Path(__file__).resolve().parents[2]
checks=0

def check(value,label):
    global checks
    if not value: raise RuntimeError(label)
    checks+=1

with tempfile.TemporaryDirectory(prefix='yuzhou-http-') as tmp:
    env=os.environ.copy()
    env.update(APP_ENV='test',DB_DRIVER='sqlite',DB_SQLITE_PATH=tmp+'/test.sqlite',STORAGE_PATH=tmp+'/files',ALLOW_TEST_PAYMENTS='1',SEED_PASSWORD=secrets.token_urlsafe(24),CORS_ORIGINS='https://allowed.example',VR_BASE_URL='https://www.ourideas.top/')
    init=subprocess.run(['php','backend/bin/init.php','--seed'],cwd=root,env=env,capture_output=True,text=True)
    check(init.returncode==0,'Isolated schema and synthetic seed initialize')
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    log=open(tmp+'/http.log','w')
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','backend/public','backend/public/router.php'],cwd=root,env=env,stdout=log,stderr=log)
    def request(path,method='GET',body=None,token=None,headers=None):
        req_headers=dict(headers or {})
        if isinstance(body,dict): body=json.dumps(body).encode();req_headers['Content-Type']='application/json'
        if token: req_headers['Authorization']='Bearer '+token
        req=urllib.request.Request(f'http://127.0.0.1:{port}'+path,data=body,method=method,headers=req_headers)
        try: response=urllib.request.urlopen(req,timeout=5)
        except urllib.error.HTTPError as e: response=e
        data=response.read()
        return response.status,response.headers,data
    def parsed(path,**kwargs):
        status,headers,raw=request(path,**kwargs)
        return status,headers,json.loads(raw)
    try:
        for _ in range(50):
            try:
                status,headers,data=parsed('/api/v1/health',headers={'Content-Type':'application/json'})
                break
            except (urllib.error.URLError,ConnectionError): time.sleep(.1)
        check(status==200 and data['code']==0 and data['data']['framework']=='ThinkPHP 5.1.41 LTS','Real ThinkPHP handles empty GET with JSON header')
        status,headers,bootstrap=parsed('/api/v1/bootstrap',headers={'Origin':'https://allowed.example'})
        check(status==200 and bootstrap['data']['is_test_environment'] and bootstrap['data']['vr_base_url']=='https://www.ourideas.top/' and headers.get('Access-Control-Allow-Origin')=='https://allowed.example','Bootstrap explicitly marks test and exposes configured VR URL')
        status,headers,data=parsed('/api/v1/bootstrap',headers={'Origin':'https://not-allowed.example'})
        check(headers.get('Access-Control-Allow-Origin') is None,'CORS excludes unconfigured origin')
        status,headers,data=parsed('/api/v1/auth/login',method='POST',body={'username':'consumer.demo','password':env['SEED_PASSWORD']})
        check(status==200 and data['code']==0,'Synthetic account authenticates over HTTP')
        token=data['data']['token']
        status,headers,data=parsed('/api/v1/auth/register',method='POST',body={'username':'http.outsider','password':secrets.token_urlsafe(18)})
        outsider=data['data']['token']
        boundary='YuzhouTestBoundary'+secrets.token_hex(8)
        pdf=b'%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n'
        body=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="test-contract.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode()+pdf+f'\r\n--{boundary}--\r\n'.encode())
        status,headers,data=parsed('/api/v1/uploads',method='POST',body=body,token=token,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
        check(status==200 and data['data']['private'] and data['data']['mime']=='application/pdf','Real multipart PDF is stored privately')
        path=data['data']['url']
        status,headers,raw=request(path,token=token)
        check(status==200 and raw==pdf and headers.get('Content-Type')=='application/pdf' and 'sandbox' in headers.get('Content-Security-Policy',''),'Owner downloads exact private PDF with sandbox')
        status,headers,raw=request(path)
        check(status==403,'Anonymous private download is denied')
        status,headers,raw=request(path,token=outsider)
        check(status==403,'Different consumer private download is denied')
        status,headers,raw=request(path,token=token,headers={'Range':'bytes=0-7'})
        check(status==206 and raw==pdf[:8],'Authorized private file supports bounded range')
        status,headers,data=parsed('/api/v1/auth/login',method='POST',body=b'',headers={'Content-Type':'application/json'})
        check(status==422,'Empty JSON POST remains rejected')
        status,headers,data=parsed('/api/v1/health',body=b'{invalid',headers={'Content-Type':'application/json'})
        check(status==422,'Malformed JSON remains rejected')
        status,headers,raw=request('/.env')
        check(status==404,'Environment file is outside public document root')
        status,headers,data=parsed('/api/v1/auth/sms',method='POST',body={'phone':'synthetic-test'})
        check(status==503 and data['code']!=0,'Unconfigured SMS returns explicit service error')
    finally:
        server.terminate();server.wait(timeout=5);log.close()
print(f'HTTP backend checks: {checks} passed.')
