"""Browser and concurrent HTTP acceptance against a disposable, local-only fixture."""
import base64
import concurrent.futures
import hashlib
import io
import json
import os
from pathlib import Path
import statistics
import shutil
import subprocess
import sys
import tarfile
import time
import urllib.parse
import urllib.request
import http.cookiejar

REPO = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO / '.codex-tmp/browser-deps'))
from playwright.sync_api import sync_playwright

fixture = Path(sys.argv[1]).resolve()
fixture.relative_to(REPO / 'storage/framework/acceptance')
meta = json.loads((fixture / 'environment.json').read_text())
base = 'http://127.0.0.1:8198'
output = REPO / 'storage/logs' / ('browser-focused' if os.environ.get('QA_ROUTES') else 'browser-acceptance')
output.mkdir(exist_ok=True)
deps = json.loads((REPO / 'tools/browser-dependencies.json').read_text())['axe']
axe_path = REPO / '.codex-tmp/axe.min.js'
axe_path.parent.mkdir(parents=True, exist_ok=True)
if not axe_path.exists():
    data = urllib.request.urlopen(deps['url'], timeout=30).read()
    assert 'sha512-' + base64.b64encode(hashlib.sha512(data).digest()).decode() == deps['integrity']
    with tarfile.open(fileobj=io.BytesIO(data), mode='r:gz') as archive:
        axe_path.write_bytes(archive.extractfile('package/axe.min.js').read())
env = dict(os.environ, APP_ENV='acceptance', APP_URL=base, DB_CONNECTION='sqlite', DB_URL='',
           DB_DATABASE=str(fixture / 'database.sqlite'), CORPMS_ACCEPTANCE_ROOT=str(fixture),
           AI_BASE_URL='', AI_API_KEY='', QUEUE_CONNECTION='sync', MAIL_MAILER='array')
php = os.environ.get('PHP_BINARY') or shutil.which('php') or r'C:\OSPanel\modules\PHP-8.3\PHP\php.exe'
php_options = ['-d', 'opcache.enable_cli=1']
if os.name == 'nt':
    php_options += ['-d', 'zend_extension=php_opcache.dll']
log = (output / 'server.log').open('w')
server = subprocess.Popen([php, *php_options, '-S', '127.0.0.1:8198', '-t', str(REPO / 'public'), str(REPO / 'tools/acceptance-router.php')],
                          cwd=REPO, env=env, stdout=log, stderr=log,
                          creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
report = {'fixture': {'employees': meta['employees'], 'tasks': meta['tasks']}, 'axe_version': deps['version'],
          'pages': [], 'failures': []}
routes = ['/dashboard', '/employees', '/tasks', '/documents', '/crm/clients', '/finance/accounts',
          '/accounting/chart', '/inventory', '/purchases', '/projects', '/bi', '/payroll', '/reports', '/settings', '/my-schedule', '/ai']
if os.environ.get('QA_ROUTES'):
    routes = os.environ['QA_ROUTES'].split(',')
try:
    for _ in range(60):
        try:
            if urllib.request.urlopen(base + '/login', timeout=1).status == 200:
                break
        except Exception:
            time.sleep(.2)
    with sync_playwright() as pw:
        executable = os.environ.get('BROWSER_EXECUTABLE')
        if not executable and os.name == 'nt':
            executable = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
        browser = pw.chromium.launch(executable_path=executable, headless=True)
        context = browser.new_context(viewport={'width': 1440, 'height': 1000}, color_scheme='light')
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto(base + '/login')
        page.locator('input[name=email]').fill(meta['email'])
        page.locator('input[name=password]').fill(meta['password'])
        page.locator('button[type=submit]').click()
        page.wait_for_url('**/dashboard')
        for width, height, label in [(1440, 1000, 'desktop'), (390, 844, 'mobile'), (1440, 1000, 'dark')]:
            page.add_init_script("localStorage.setItem('crm-theme', '" + ('dark' if label == 'dark' else 'light') + "')")
            page.set_viewport_size({'width': width, 'height': height})
            for route in routes:
                response = page.goto(base + route, wait_until='networkidle')
                page.add_script_tag(path=str(axe_path))
                results = page.evaluate("""async () => { const r = await axe.run(document, {runOnly: {type:'tag', values:['wcag2a','wcag2aa','wcag21aa']}}); return r.violations.map(v => ({id:v.id,impact:v.impact,description:v.description,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary})).slice(0,8)})); }""")
                overflow = page.evaluate('document.documentElement.scrollWidth > innerWidth + 2')
                overflow_nodes = page.evaluate("""() => [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > innerWidth+2 && getComputedStyle(e).position !== 'fixed').slice(0,12).map(e => ({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right}))""") if overflow else []
                if overflow:
                    overflow_nodes += page.evaluate("""() => [...document.querySelectorAll('.table-responsive')].map(e=>({cls:e.className,client:e.clientWidth,scroll:e.scrollWidth,overflow:getComputedStyle(e).overflowX,root:document.documentElement.scrollWidth}))""")
                name = route.strip('/').replace('/', '-')
                page.screenshot(path=str(output / f'{label}-{name}.png'), full_page=True)
                item = {'route': route, 'viewport': label, 'status': response.status, 'overflow': overflow, 'overflow_nodes': overflow_nodes, 'accessibility': results}
                report['pages'].append(item)
                if response.status != 200 or overflow or results:
                    report['failures'].append(item)
        # A real validation error and keyboard focus must remain visible to users.
        page.set_viewport_size({'width': 1440, 'height': 1000})
        page.goto(base + '/settings')
        page.keyboard.press('Tab')
        report['keyboard_focusable'] = page.evaluate('document.activeElement !== document.body')
        anonymous = browser.new_context(viewport={'width':390,'height':844})
        login_page = anonymous.new_page()
        login_page.goto(base + '/login')
        login_page.locator('input[name=email]').fill(meta['email'])
        login_page.locator('input[name=password]').fill('incorrect-fixture-password')
        login_page.locator('button[type=submit]').click()
        report['invalid_login_feedback'] = login_page.get_by_text('Неверный email или пароль.', exact=True).is_visible()
        anonymous.close()
        report['javascript_errors'] = errors
        report['browser_cookies'] = context.cookies()
        browser.close()
    cookies = report.pop('browser_cookies')
    cookie_header = '; '.join(f"{c['name']}={c['value']}" for c in cookies)
    def request_sample(index):
        route = ['/dashboard', '/tasks', '/employees', '/finance/accounts', '/payroll'][index % 5]
        start = time.perf_counter()
        request = urllib.request.Request(base + route, headers={'Cookie': cookie_header})
        try:
            response = urllib.request.urlopen(request, timeout=30)
            response.read()
            return {'route': route, 'status': response.status, 'ms': round((time.perf_counter() - start)*1000, 2)}
        except Exception as error:
            return {'route': route, 'status': 0, 'ms': round((time.perf_counter() - start)*1000, 2), 'error': type(error).__name__}
    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
        samples = list(executor.map(request_sample, range(200 if '--load' in sys.argv else 20)))
    elapsed = time.perf_counter() - start
    values = sorted(s['ms'] for s in samples)
    report['load'] = {'requests': len(samples), 'concurrency': 4, 'server_workers': 1,
                      'elapsed_s': round(elapsed, 2), 'p50_ms': statistics.median(values),
                      'p95_ms': values[int(len(values)*.95)-1], 'p99_ms': values[int(len(values)*.99)-1],
                      'errors': sum(s['status'] != 200 for s in samples), 'requests_per_second': round(len(samples)/elapsed, 2)}
    report['load_samples'] = samples
    try:
        urllib.request.urlopen(urllib.request.Request(base + '/login', data=b'email=csrf%40example.test&password=x'), timeout=10)
        report['csrf_rejected'] = False
    except urllib.error.HTTPError as error:
        report['csrf_rejected'] = error.code == 419
    report['ok'] = not report['failures'] and not errors and report['keyboard_focusable'] and report['load']['errors'] == 0 and report['invalid_login_feedback'] and report['csrf_rejected']
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
    (output / 'report.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
print(json.dumps({'pages': len(report['pages']), 'failures': len(report['failures']),
                  'javascript_errors': report.get('javascript_errors'), 'load': report.get('load')}, ensure_ascii=False))
sys.exit(0 if report.get('ok') else 1)
