// Dev only: started by `shopify app dev` (see shopify.web.toml). Runs `php artisan serve` on the port the CLI assigns.
const { spawn } = require('node:child_process');
const port = process.env.PORT || process.env.BACKEND_PORT || '8010';
const child = spawn('php', ['artisan', 'serve', '--host', '127.0.0.1', '--port', String(port)], { stdio: 'inherit', cwd: __dirname, shell: process.platform === 'win32' });
child.on('exit', (code) => process.exit(code ?? 0));
