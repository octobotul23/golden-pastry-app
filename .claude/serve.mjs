// Minimal static server for previewing the imported design project.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(process.cwd());
const PORT = Number(process.env.PORT) || 8099;

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.woff2': 'font/woff2',
  '.md': 'text/markdown; charset=utf-8',
};

// Landing page: list whatever .dc.html artboards are present. Useful during a
// partial import, when the usual entry page may not be on disk yet.
function index() {
  const pages = fs.readdirSync(ROOT).filter((f) => f.endsWith('.dc.html')).sort();
  const items = pages.length
    ? pages.map((p) => `<li><a href="/${encodeURIComponent(p)}">${p}</a></li>`).join('')
    : '<li><em>no .dc.html pages imported yet</em></li>';
  return `<!doctype html><meta charset="utf-8"><title>Lab App v2</title>
<style>body{font:16px/1.6 system-ui;margin:3rem auto;max-width:40rem}li{margin:.2rem 0}</style>
<h1>Lab App v2</h1><p>${pages.length} artboard(s)</p><ul>${items}</ul>`;
}

http.createServer((req, res) => {
  let rel;
  try {
    rel = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  } catch {
    res.writeHead(400).end('bad url');
    return;
  }

  if (rel === '/') {
    const entry = path.join(ROOT, 'Intrare.dc.html');
    if (!fs.existsSync(entry)) {
      res.writeHead(200, { 'content-type': MIME['.html'] }).end(index());
      return;
    }
    rel = '/Intrare.dc.html';
  }

  const abs = path.join(ROOT, rel);
  // keep the server confined to the project directory
  if (abs !== ROOT && !abs.startsWith(ROOT + path.sep)) {
    res.writeHead(403).end('forbidden');
    return;
  }
  fs.readFile(abs, (err, buf) => {
    if (err) {
      console.log('404 ' + rel);
      res.writeHead(404, { 'content-type': 'text/plain' }).end('not found: ' + rel);
      return;
    }
    console.log('200 ' + rel + ' (' + buf.length + 'B)');
    res.writeHead(200, {
      'content-type': MIME[path.extname(abs).toLowerCase()] || 'application/octet-stream',
      'cache-control': 'no-store',
    }).end(buf);
  });
}).listen(PORT, () => console.log('serving ' + ROOT + ' on http://localhost:' + PORT));
