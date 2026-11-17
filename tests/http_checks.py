#!/usr/bin/env python3
"""HTTP integration checks. Refuses unmarked or non-loopback fixtures."""
import http.cookiejar
import json
import pathlib
import re
import secrets
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

marker = pathlib.Path(sys.argv[1]).resolve()
config = json.loads(marker.read_text())
root = pathlib.Path(config['root']).resolve()
assert root.parent == marker.parent and config['db'].startswith('yfr_wave1_')
assert str(root).startswith(('/tmp/yfr-wave1-', '/private/tmp/yfr-wave1-'))
php = config.get('php', shutil.which('php'))
wp = config.get('wp', shutil.which('wp'))


def cli(*args):
    result = subprocess.run([php, wp, f'--path={root}', *args], text=True,
                            capture_output=True, check=True)
    return result.stdout.strip()


assert cli('config', 'get', 'DB_NAME') == config['db']
base = cli('option', 'get', 'home').rstrip('/')
assert urllib.parse.urlparse(base).hostname in ('127.0.0.1', 'localhost')
suffix = secrets.token_hex(4)
password = 'YfrHTTP-fixture-only-2026!'
seed = cli('eval', f'''
$owner=wp_insert_user(['user_login'=>'yfr_http_owner_{suffix}','user_email'=>'yfr-http-owner-{suffix}@example.test','user_pass'=>'{password}','role'=>'subscriber']);
$other=wp_insert_user(['user_login'=>'yfr_http_other_{suffix}','user_email'=>'yfr-http-other-{suffix}@example.test','user_pass'=>'{password}','role'=>'subscriber']);
$editor=wp_insert_user(['user_login'=>'yfr_http_editor_{suffix}','user_email'=>'yfr-http-editor-{suffix}@example.test','user_pass'=>'{password}','role'=>'editor']);
$admin=wp_insert_user(['user_login'=>'yfr_http_admin_{suffix}','user_email'=>'yfr-http-admin-{suffix}@example.test','user_pass'=>'{password}','role'=>'administrator']);
$page=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'YFR HTTP {suffix}','post_content'=>'[yfr_requests]']);
echo wp_json_encode(['owner'=>$owner,'other'=>$other,'editor'=>$editor,'admin'=>$admin,'page'=>$page,'url'=>get_permalink($page)]);
''')
data = json.loads(seed)
assert all(isinstance(data[k], int) and data[k] > 0 for k in ('owner', 'other', 'editor', 'admin', 'page'))
page = data['url']
passed = 0


def check(condition, name):
    global passed
    assert condition, name
    passed += 1


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def browser():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


def request(client, url, values=None):
    body = None if values is None else urllib.parse.urlencode(values).encode()
    try:
        response = client.open(urllib.request.Request(url, body), timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode('utf-8', 'replace')


def login(role):
    client = browser()
    request(client, base + '/wp-login.php')
    code, headers, _ = request(client, base + '/wp-login.php', {
        'log': f'yfr_http_{role}_{suffix}', 'pwd': password,
        'wp-submit': 'Log In', 'redirect_to': page, 'testcookie': '1',
    })
    check(code == 302, f'{role} login')
    return client


anon = browser()
code, headers, body = request(anon, page)
check(code == 200 and 'Log in to suggest' in body and 'name="yfr_nonce"' not in body, 'anonymous form boundary')
check('no-cache' in headers.get('Cache-Control', ''), 'no-cache sent before rendering')
check(request(anon, base + '/wp-admin/admin-post.php?action=yfr_submit')[0] == 405, 'GET cannot mutate')
check(request(anon, base + '/wp-admin/admin-post.php', {'action': 'yfr_submit'})[0] == 403, 'anonymous POST denied')
owner = login('owner')
_, _, body = request(owner, page)
nonce = re.search(r'name="yfr_nonce" value="([^"]+)"', body).group(1)
payload = {'action': 'yfr_submit', 'yfr_nonce': nonce, 'yfr_page': data['page'],
           'kind': 'integration', 'title': 'HTTP private token ' + suffix,
           'description': '<p>Owner-only idea for a useful integration.</p><script>alert(1)</script>',
           'reference_url': 'https://example.test/reference',
           'post_author': data['other'], 'post_status': 'publish', '_yfr_state': 'released',
           'redirect_to': 'https://example.test/unsafe'}
bad = dict(payload, yfr_nonce='invalid')
check(request(owner, base + '/wp-admin/admin-post.php', bad)[0] == 403, 'invalid nonce denied')
bad = dict(payload)
bad.pop('title')
bad['title[]'] = 'Invalid array'
check(request(owner, base + '/wp-admin/admin-post.php', bad)[0] == 400, 'array field rejected')
code, headers, _ = request(owner, base + '/wp-admin/admin-post.php', payload)
location = headers.get('Location', '')
check(code == 303 and location.startswith(base) and '#yfr-requests' in location, 'POST redirects to local result')
post_id = int(urllib.parse.parse_qs(urllib.parse.urlparse(location).query)['yfr_request'][0])
record = json.loads(cli('eval', f'$p=get_post({post_id}); echo wp_json_encode(["author"=>(int)$p->post_author,"status"=>$p->post_status,"state"=>get_post_meta($p->ID,"_yfr_state",true),"content"=>$p->post_content]);'))
check(record['author'] == data['owner'] and record['status'] == 'pending' and record['state'] == 'under_review', 'forged authority ignored')
check('<script' not in record['content'], 'script removed before storage')
code, _, body = request(owner, location)
check(code == 200 and payload['title'] in body and 'awaiting moderation' in body, 'owner sees confirmation and pending detail')
_, _, body = request(owner, page)
check(payload['title'] in body, 'owner personal list')
code, headers, _ = request(owner, base + '/wp-admin/admin-post.php', payload)
check(code == 303 and 'yfr_notice=error' in headers.get('Location', ''), 'repeat submission throttled')
_, _, body = request(owner, headers['Location'])
check('wait one minute' in body, 'throttle explained')
for role in ('other', 'editor'):
    client = login(role)
    _, _, body = request(client, page)
    check(payload['title'] not in body, f'{role} personal list isolated')
    _, _, body = request(client, page + '&yfr_request=' + str(post_id))
    check(payload['title'] not in body and 'Request unavailable' in body, f'{role} detail isolated')
    code, _, body = request(client, base + f'/wp-admin/post.php?post={post_id}&action=edit')
    check(code in (403, 500) and 'not allowed to edit' in body and payload['title'] not in body, f'{role} native editor denied')

# Core preview/search/REST routes must not expose a pending or approved request.
for status in ('pending', 'publish'):
    cli('post', 'update', str(post_id), f'--post_status={status}')
    for path in (f'/?p={post_id}', f'/?post_type=yfr_request&p={post_id}',
                 f'/?post_type=yfr_request&p={post_id}&preview=true',
                 '/?s=' + urllib.parse.quote(suffix), '/?feed=rss2',
                 '/?rest_route=/wp/v2/yfr_request',
                 '/?rest_route=/wp/v2/search&search=' + suffix):
        _, _, body = request(anon, base + path)
        check(payload['title'] not in body, f'{status} core boundary {path}')
    other = login('other')
    _, _, body = request(other, page + '&yfr_request=' + str(post_id))
    check(payload['title'] not in body, f'{status} unrelated detail denied')

admin = login('admin')
code, _, body = request(admin, base + f'/wp-admin/post.php?post={post_id}&action=edit')
check(code == 200 and 'yfr_details_nonce' in body, 'moderator editor available')
native_nonce = re.search(r'name="_wpnonce" value="([^"]+)"', body).group(1)
details_nonce = re.search(r'name="yfr_details_nonce" value="([^"]+)"', body).group(1)
native_author = re.search(r'name="post_author" value="([^"]+)"', body).group(1)
code, _, _ = request(admin, base + '/wp-admin/post.php', {
    'action': 'editpost', 'post_ID': post_id, 'post_type': 'yfr_request',
    '_wpnonce': native_nonce, 'yfr_details_nonce': details_nonce,
    'post_title': payload['title'], 'content': record['content'], 'post_author': native_author,
    'post_status': 'publish', 'publish': 'Update', 'yfr_kind': 'integration',
    'yfr_state': 'planned', 'yfr_reference_url': 'https://example.test/reference',
})
check(code == 302, 'native moderation save redirects')
check(cli('post', 'meta', 'get', str(post_id), '_yfr_state') == 'planned', 'native roadmap save persisted')
_, _, body = request(owner, page + '&yfr_request=' + str(post_id))
check('Planned' in body and 'Published' in body, 'owner sees moderation result')

# The native taxonomy dropdowns must filter data, not only retain a selection.
filters = json.loads(cli('eval', f'''
$category=wp_insert_term('HTTP Category {suffix}','yfr_category');
$product=wp_insert_term('HTTP Product {suffix}','yfr_product');
$posts=[];
foreach(['both'=>'planned','category'=>'planned','product'=>'planned','review'=>'under_review','neither'=>'planned'] as $label=>$state){{
 $id=wp_insert_post(['post_type'=>'yfr_request','post_status'=>'pending','post_author'=>{data['owner']},'post_title'=>'Filter {suffix} '.$label,'post_content'=>'Synthetic filter request content.','meta_input'=>['_yfr_state'=>$state]]);
 $posts[$label]=$id;
 if(in_array($label,['both','category','review'],true))wp_set_object_terms($id,[(int)$category['term_id']],'yfr_category');
 if(in_array($label,['both','product','review'],true))wp_set_object_terms($id,[(int)$product['term_id']],'yfr_product');
}}
echo wp_json_encode(['category'=>get_term($category['term_id'])->slug,'product'=>get_term($product['term_id'])->slug,'posts'=>$posts]);
'''))
list_base = base + '/wp-admin/edit.php?' + urllib.parse.urlencode({'post_type': 'yfr_request', 's': 'Filter ' + suffix})


def filtered_rows(values):
    code, _, html = request(admin, list_base + '&' + urllib.parse.urlencode(values))
    check(code == 200, 'admin filter request succeeds')
    return set(map(int, re.findall(r'<tr[^>]+id="post-(\d+)"', html)))


ids = filters['posts']
check(filtered_rows({'yfr_category': filters['category']}) == {ids['both'], ids['category'], ids['review']}, 'category dropdown restricts actual rows')
check(filtered_rows({'yfr_product': filters['product']}) == {ids['both'], ids['product'], ids['review']}, 'product dropdown restricts actual rows')
check(filtered_rows({'yfr_category': filters['category'], 'yfr_product': filters['product'], 'yfr_state_filter': 'planned'}) == {ids['both']}, 'category, product and roadmap filters intersect')
check(filtered_rows({'yfr_category': '0', 'yfr_product': '0', 'yfr_state_filter': ''}) == set(ids.values()), 'reset dropdowns restore all matching rows')
print(f'PASS HTTP {passed} assertions ({base})')
