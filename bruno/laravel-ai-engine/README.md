# Laravel AI Engine Bruno Collection

This collection covers the package HTTP APIs that are exposed through `routes/api.php` and `routes/node-api.php`, plus the admin operational POST/JSON endpoints from `routes/admin.php`.

Environment notes:
- `bearerToken` is used for package API routes that run behind token-authenticated middleware in the host app.
- `nodeToken` and `userToken` are used for `/api/ai-engine/*` federation requests.
- `csrfToken` and `webCookie` are required for the `Admin` folder because those routes use the Laravel `web` stack.
- `filePath` and `audioPath` must be absolute local paths before sending multipart requests.

Pure HTML admin dashboard/index pages are intentionally not included as Bruno requests.

Current app-facing capability requests are grouped under `V1 Agent`, `V1 Files`, `V1 Vector Stores`, `V1 Health`, `AI Catalog`, and `V1 Generate`.
