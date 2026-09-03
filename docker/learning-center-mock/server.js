// Mock of the Learning Center credentials contract for development and CI.
// Serves the fixture files that are the contract's source of truth
// (api/tests/Fixtures/learning-center/credentials), keyed by member.subject.
// A bearer token is required but not verified: token verification belongs to
// the real provider. MOCK_DELAY_MS adds latency for INCIDENT-001.
'use strict';

const fs = require('fs');
const http = require('http');
const path = require('path');

const port = Number(process.env.PORT || 3005);
const delayMs = Number(process.env.MOCK_DELAY_MS || 0);
const fixturesDir = process.env.FIXTURES_DIR || '/fixtures';

function loadFixtures() {
    const bySubject = new Map();
    let errors = { 401: { error: 'unauthorized' }, 403: { error: 'forbidden' }, 404: { error: 'member not found' } };

    for (const file of fs.readdirSync(fixturesDir)) {
        if (!file.endsWith('.json')) continue;
        const data = JSON.parse(fs.readFileSync(path.join(fixturesDir, file), 'utf8'));
        if (data.errors) {
            errors = { ...errors, ...data.errors };
        } else if (data.member && data.member.subject) {
            bySubject.set(data.member.subject, data);
        }
    }

    return { bySubject, errors };
}

const { bySubject, errors } = loadFixtures();
const route = /^\/v1\/members\/([^/]+)\/credentials$/;

function send(res, status, body) {
    const json = JSON.stringify(body);
    res.writeHead(status, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(json) });
    res.end(json);
}

const server = http.createServer((req, res) => {
    const respond = () => {
        if (req.method === 'GET' && req.url === '/health') {
            return send(res, 200, { status: 'ok', subjects: bySubject.size, delayMs });
        }

        const match = req.method === 'GET' && route.exec(req.url.split('?')[0]);
        if (!match) {
            return send(res, 404, { error: 'not found' });
        }

        const auth = req.headers.authorization || '';
        if (!auth.startsWith('Bearer ') || auth.length < 8) {
            return send(res, 401, errors[401]);
        }

        const subject = decodeURIComponent(match[1]);
        const fixture = bySubject.get(subject);
        if (!fixture) {
            return send(res, 404, errors[404]);
        }

        return send(res, 200, { ...fixture, as_of: new Date().toISOString() });
    };

    if (delayMs > 0) {
        setTimeout(respond, delayMs);
    } else {
        respond();
    }
});

server.listen(port, () => {
    console.log(`learning-center mock on :${port}, ${bySubject.size} subjects, delay ${delayMs} ms`);
});
