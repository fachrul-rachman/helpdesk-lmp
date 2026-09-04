import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

function worker() {
    const listeners = {};
    const displayed = [];
    const opened = [];
    const cache = new Map();
    const context = {
        URL,
        Response,
        self: {
            location: { origin: 'https://helpdesk.test' },
            addEventListener: (name, handler) => {
                listeners[name] = handler;
            },
            skipWaiting: async () => {},
            clients: {
                claim: async () => {},
                matchAll: async () => [],
                openWindow: async (url) => opened.push(url),
            },
            registration: {
                showNotification: async (title, options) =>
                    displayed.push({ title, ...options }),
                getNotifications: async () => [],
            },
        },
        caches: {
            open: async () => ({
                match: async (key) => cache.get(key)?.clone(),
                put: async (key, value) => cache.set(key, value),
                delete: async (key) => cache.delete(key),
            }),
        },
    };
    vm.runInNewContext(
        readFileSync(
            new URL('../public/helpdesk-push-sw.js', import.meta.url),
            'utf8',
        ),
        context,
    );
    async function emit(name, event) {
        let pending;
        listeners[name]({
            ...event,
            waitUntil: (promise) => {
                pending = promise;
            },
        });
        await pending;
    }
    return { emit, displayed, opened };
}

test('push is shown only for the bound account and never contains customer text', async () => {
    const w = worker();
    const payload = {
        user_id: 'pic-1',
        title: 'Ticket T26-001',
        body: 'Pesan baru dari customer.',
        url: '/app/pic/tickets/123',
        tag: 'ticket-123-message',
        content: 'PRIVATE',
    };
    await w.emit('push', { data: { json: () => payload } });
    assert.equal(w.displayed.length, 0);
    await w.emit('message', {
        data: { type: 'PUSH_ACCOUNT', userId: 'pic-1' },
        ports: [],
    });
    await w.emit('push', { data: { json: () => payload } });
    assert.equal(w.displayed.length, 1);
    assert.ok(!JSON.stringify(w.displayed).includes('PRIVATE'));
    await w.emit('message', {
        data: { type: 'PUSH_ACCOUNT', userId: null },
        ports: [],
    });
    await w.emit('push', { data: { json: () => payload } });
    assert.equal(w.displayed.length, 1);
});

test('notification clicks cannot open external URLs or another account ticket', async () => {
    const w = worker();
    await w.emit('message', {
        data: { type: 'PUSH_ACCOUNT', userId: 'pic-1' },
        ports: [],
    });
    for (const url of [
        'https://evil.test/',
        '//evil.test/app/pic/tickets/1',
        '/admin',
        '/app/pic/tickets/1/../../login',
    ]) {
        await w.emit('notificationclick', {
            notification: { close() {}, data: { url, user_id: 'pic-1' } },
        });
    }
    assert.equal(w.opened.length, 0);
    await w.emit('notificationclick', {
        notification: {
            close() {},
            data: { url: '/app/pic/tickets/123', user_id: 'pic-2' },
        },
    });
    assert.equal(w.opened.length, 0);
    await w.emit('notificationclick', {
        notification: {
            close() {},
            data: { url: '/app/pic/tickets/123', user_id: 'pic-1' },
        },
    });
    assert.deepEqual(w.opened, ['https://helpdesk.test/app/pic/tickets/123']);
});
