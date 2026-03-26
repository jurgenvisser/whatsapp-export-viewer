# Deploy to Cloudflare Workers

## 1) Install Wrangler (if needed)
```bash
npm install -g wrangler
```

## 2) Login
```bash
npx wrangler login
```

## 3) Deploy
From project root:
```bash
npx wrangler deploy
```

## Notes
- Worker entry: `worker.js`
- Static assets directory: `public/`
- Main page served at `/` from `public/index.html`
- Existing PHP file is not used in deployment.

## Updating `public/index.html` after PHP changes
If you still edit `index.php` locally and want to refresh static output:
```bash
php index.php > public/index.html
```
Then deploy again:
```bash
npx wrangler deploy
```
