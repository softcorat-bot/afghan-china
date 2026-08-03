/*
 * Afghan China MIS — deployment settings.
 *
 * This file is NOT bundled. It ships as-is next to index.html, so after
 * uploading the app you can point it at your server by editing this one line
 * in cPanel's File Manager. Nothing has to be rebuilt.
 *
 * Set it to the address of the Laravel API, with no trailing slash and no
 * "/api" on the end — the app appends that itself.
 *
 *   window.__API_URL__ = 'https://api.your-domain.com'
 *
 * Leave it empty to fall back to the address baked in at build time
 * (VITE_API_URL), and then to http://localhost:8000 for local development.
 */
window.__API_URL__ = ''
