import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import axios from 'axios';
import ts from 'typescript';

function session(adapter, baseURL) {
    let stored = 'refresh-a';
    let cleared = 0;
    const redirects = [];
    const state = {
        accessToken: 'expired',
        sessionError: null,
        setAccessToken: (value) => {
            state.accessToken = value;
        },
        setSessionError: (value) => {
            state.sessionError = value;
        },
        clear: async () => {
            cleared++;
            stored = null;
            state.accessToken = null;
        },
    };
    const client = axios.create({ adapter });
    client.isAxiosError = axios.isAxiosError;
    client.create = (config) => axios.create({ ...config, adapter });
    const source = readFileSync(
        new URL('../resources/js/spa/lib/axios.ts', import.meta.url),
        'utf8',
    ).replaceAll(
        'import.meta.env.VITE_API_URL',
        JSON.stringify(baseURL) ?? 'undefined',
    );
    const output = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            esModuleInterop: true,
        },
    }).outputText;
    const exports = {};
    vm.runInNewContext(output, {
        exports,
        require: (name) =>
            name === 'axios'
                ? {
                      __esModule: true,
                      default: client,
                      AxiosError: axios.AxiosError,
                  }
                : {
                      getStoredRefreshToken: () => stored,
                      useAuthStore: { getState: () => state },
                  },
        window: { location: { assign: (url) => redirects.push(url) } },
    });

    return {
        ...exports,
        state,
        redirects,
        get cleared() {
            return cleared;
        },
        switchAccount() {
            stored = 'refresh-b';
            state.accessToken = 'account-b';
        },
    };
}

function response(config, data = {}, status = 200) {
    const result = { config, data, status, statusText: '', headers: {} };

    if (status >= 400) {
        throw new axios.AxiosError('Request failed', '', config, null, result);
    }

    return result;
}

for (const status of [0, 429, 500, 503]) {
    test(`refresh failure ${status} preserves login and exposes a recoverable message`, async () => {
        const s = session(async (config) => {
            if (config.url.endsWith('/api/auth/refresh')) {
                if (!status) {
                    throw new axios.AxiosError(
                        'Network Error',
                        'ERR_NETWORK',
                        config,
                    );
                }

                return response(config, {}, status);
            }

            return response(config, {}, 401);
        });
        await assert.rejects(s.api.get('/api/protected'));
        assert.equal(s.cleared, 0);
        assert.equal(s.redirects.length, 0);
        assert.ok(s.state.sessionError);
    });
}

test('expired access is refreshed once for concurrent requests using a relative URL', async () => {
    let refreshes = 0;
    const s = session(async (config) => {
        if (config.url.endsWith('/api/auth/refresh')) {
            assert.equal(config.url, '/api/auth/refresh');
            refreshes++;
            await new Promise((resolve) => setTimeout(resolve, 10));

            return response(config, { access_token: 'fresh' });
        }

        return response(
            config,
            {},
            config.headers.Authorization === 'Bearer fresh' ? 200 : 401,
        );
    });
    await Promise.all([s.api.get('/api/a'), s.api.get('/api/b')]);
    assert.equal(refreshes, 1);
    assert.equal(s.state.accessToken, 'fresh');
});

for (const status of [401, 403]) {
    test(`refresh rejection ${status} clears the session once`, async () => {
        const s = session(
            async (config) => response(config, {}, status),
            'https://example.invalid',
        );
        await assert.rejects(s.refreshSession());
        assert.equal(s.cleared, 1);
    });
}

test('transient failure can be retried without logging in again', async () => {
    let down = true;
    const s = session(async (config) =>
        response(config, { access_token: 'fresh' }, down ? 503 : 200),
    );
    await assert.rejects(s.refreshSession());
    down = false;
    await s.refreshSession();
    assert.equal(s.state.accessToken, 'fresh');
    assert.equal(s.state.sessionError, null);
    assert.equal(s.cleared, 0);
});

test('refresh timeout preserves the saved session', async () => {
    const s = session(async (config) => {
        assert.equal(config.timeout, 15000);

        throw new axios.AxiosError('Timeout', 'ECONNABORTED', config);
    });
    await assert.rejects(s.refreshSession());
    assert.equal(s.cleared, 0);
    assert.ok(s.state.sessionError);
});

test('malformed successful refresh does not replace access or log out the user', async () => {
    const s = session(async (config) => response(config, {}));
    await assert.rejects(s.refreshSession());
    assert.equal(s.state.accessToken, 'expired');
    assert.equal(s.cleared, 0);
    assert.ok(s.state.sessionError);
});

test('refresh uses the same configured API origin as other requests', async () => {
    const s = session(async (config) => {
        assert.equal(config.baseURL, 'https://example.invalid');
        assert.equal(config.url, '/api/auth/refresh');

        return response(config, { access_token: 'fresh' });
    }, 'https://example.invalid');
    await s.refreshSession();
});

test('permission failures do not trigger refresh or erase the session', async () => {
    const s = session(async (config) => {
        assert.equal(config.url, '/api/protected');

        return response(config, {}, 403);
    });
    await assert.rejects(s.api.get('/api/protected'));
    assert.equal(s.cleared, 0);
    assert.equal(s.state.sessionError, null);
});

test('a rejected retried request is not repeated indefinitely', async () => {
    let refreshes = 0;
    const s = session(async (config) => {
        if (config.url === '/api/auth/refresh') {
            refreshes++;

            return response(config, { access_token: 'fresh' });
        }

        return response(config, {}, 401);
    });
    await assert.rejects(s.api.get('/api/protected'));
    assert.equal(refreshes, 1);
});

test('late 401 from an old access token reuses the already refreshed access token', async () => {
    let refreshes = 0;
    let release;
    const delayed = new Promise((resolve) => {
        release = resolve;
    });
    const s = session(async (config) => {
        if (config.url.endsWith('/api/auth/refresh')) {
            refreshes++;

            return response(config, { access_token: 'fresh' });
        }

        if (config.headers.Authorization === 'Bearer fresh') {
            return response(config);
        }

        if (config.url === '/api/slow') {
            await delayed;
        }

        return response(config, {}, 401);
    }, 'https://example.invalid');
    const slow = s.api.get('/api/slow');
    await s.api.get('/api/fast');
    release();
    await slow;
    assert.equal(refreshes, 1);
});

for (const status of [200, 401, 503]) {
    test(`old refresh result ${status} cannot overwrite or clear a newer login`, async () => {
        let release;
        const delayed = new Promise((resolve) => {
            release = resolve;
        });
        const s = session(async (config) => {
            await delayed;

            return response(config, { access_token: 'account-a' }, status);
        });
        const pending = s.refreshSession();
        s.switchAccount();
        release();
        await assert.rejects(pending);
        assert.equal(s.state.accessToken, 'account-b');
        assert.equal(s.cleared, 0);
        assert.equal(s.state.sessionError, null);
    });
}
