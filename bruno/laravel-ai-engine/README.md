# Laravel AI Engine Bruno Collection

This collection covers the package HTTP APIs that are exposed through `routes/api.php`, `routes/chat.php`, `routes/node-api.php`, and `routes/auth.php`, plus the admin operational POST/JSON endpoints from `routes/admin.php`.

Environment notes:
- `bearerToken` is used for package API routes that run behind token-authenticated middleware in the host app.
- `nodeToken` and `userToken` are used for `/api/ai-engine/*` federation requests.
- `csrfToken` and `webCookie` are required for the `AI Chat` and `Admin` folders because those routes use the Laravel `web` stack.
- `filePath` and `audioPath` must be absolute local paths before sending multipart requests.

Pure HTML view routes such as the demo pages in `routes/web.php` and the admin dashboard/index pages are intentionally not included as Bruno requests.
