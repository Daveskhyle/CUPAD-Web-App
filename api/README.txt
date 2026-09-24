CUPAD Unified API v1.1.0
==========================

Upload the CONTENTS of this package into:
public_html/api/

Test:
GET https://cupad.name.ng/api/v1/health

Authenticated API test:
GET https://cupad.name.ng/api/v1/test
Header: X-API-Key: <your API key>

Clients:
GET /api/v1/clients?limit=20&offset=0
Header: X-API-Key: <your API key>

Search:
GET /api/v1/clients?q=NAME&limit=20
Header: X-API-Key: <your API key>

Mobile login:
POST /api/v1/auth/login
JSON: {"username":"...","password":"..."}
Returns a JWT. Use Authorization: Bearer <JWT> for /me and mobile-only portfolio access.

ChatGPT:
Use openapi.json as the Action/API schema. Give ChatGPT a dedicated API key rather than the database password.
The API does not expose arbitrary SQL.

IMPORTANT:
- Do not commit config.php to GitHub.
- Rotate database credentials if they have been exposed.
- Configure OPENAI_API_KEY as a server environment variable if using v1/ai.php.
