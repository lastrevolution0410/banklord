<?php
// Guides and changelog on the website: every doc renders, links stay inside, nothing unescaped.
declare(strict_types=1);
require_once __DIR__.'/../backend/src/Version.php';
require_once __DIR__.'/../backend/src/DocsPage.php';
use Banklord\DocsPage;
$fail=0;$ok=function(bool $c,string $m) use(&$fail){ if(!$c){$fail++; echo "FAIL: $m\n";} };
$root=__DIR__.'/../backend';
foreach (DocsPage::PAGES as $p) foreach (['','.en'] as $sfx) {
    $page=DocsPage::resolve($p.$sfx);$md=DocsPage::source($root,$page['file']);
    $ok($md!==null,"source $p$sfx");if ($md===null) continue;
    $html=DocsPage::render($md,$page,'1.0');
    $ok(!str_contains($html,'<script') && !preg_match('/\son\w+=/i',$html),"no script in $p$sfx");
    $ok(!preg_match('~href="[^"#]*\.md(?:#|")~',$html) || str_contains($html,'blob/main/'),"internal .md links rewritten in $p$sfx");
    // Every in-page anchor link has a matching heading.
    preg_match_all('/href="#([^"]+)"/',$html,$a);preg_match_all('/ id="([^"]+)"/',$html,$ids);
    foreach ($a[1] as $x) $ok(in_array($x,$ids[1],true),"anchor #$x exists in $p$sfx");
}
$ok(DocsPage::resolve('../config')===null && DocsPage::resolve('nope')===null && DocsPage::resolve('mitglieder.de')===null,'unknown pages rejected');
$ok(DocsPage::resolve('faq','en-US,en;q=0.9')['file']==='faq.en' && DocsPage::resolve('faq','de-DE,en;q=0.5')['file']==='faq' && DocsPage::resolve('faq.en','de')['file']==='faq.en','language choice');
$x=DocsPage::markdown("# T <b>\n\n[x](javascript:alert(1)) [y\"](faq.md#Häufig) <script>alert(1)</script> `<i>` **<u>**\n\n| a | `b|c` |\n|---|---|\n| <img src=x onerror=1> | 2 |");
$ok(!str_contains($x,'<script') && !str_contains($x,'<img') && !str_contains($x,'javascript:') && !str_contains($x,'<u>'),'raw HTML and javascript: links neutralised');
$ok(str_contains($x,'href="faq#häufig"') && str_contains($x,'<code>b|c</code>') && str_contains($x,'<code>&lt;i&gt;</code>'),'doc links, code in tables');
$ok(DocsPage::href('../SECURITY.md')==='https://github.com/lastrevolution0410/banklord/blob/main/SECURITY.md' && DocsPage::href('../../etc/x')===null,'repo links go to GitHub, no traversal');
// Pictures: every one used in a guide exists and is served; nothing outside docs/assets.
foreach (glob(__DIR__.'/../docs/*.md') as $f) {
    preg_match_all('/!\[[^\]]*\]\(([^)]+)\)/',file_get_contents($f),$im);
    foreach ($im[1] as $src) { $ok(preg_match('~^assets/([A-Za-z0-9_-]+\.(?:webp|png))$~',$src,$mm) && DocsPage::image($root,$mm[1])!==null,"picture $src in ".basename($f)); }
}
$ok(DocsPage::image($root,'../config.php')===null && DocsPage::image($root,'x.svg')===null,'only plain webp/png names');
$h=DocsPage::markdown('![Alt "x"](assets/skizze-bank.webp) ![b](https://evil.example/x.png)');
$ok(str_contains($h,'<img src="img/skizze-bank.webp" alt="Alt &quot;x&quot;"') && !str_contains($h,'<img src="https://evil'),'local pictures only');
echo $fail?"FAILED: docs pages ($fail)\n":"PASS: guides and changelog on the website (render, links, escaping)\n";
if ($fail) exit(1);
