import assert from 'node:assert/strict';
import test from 'node:test';

import {
    periodRange,
    reportTicketUrl,
    validRange,
} from '../resources/js/spa/features/spv/reportFilters.ts';

test('report periods follow Jakarta calendar boundaries', () => {
    const midnight = new Date('2026-09-30T17:00:00Z');
    assert.deepEqual(periodRange('month', midnight), {
        start_date: '2026-10-01',
        end_date: '2026-10-01',
    });
    assert.deepEqual(periodRange('week', midnight), {
        start_date: '2026-09-25',
        end_date: '2026-10-01',
    });
    assert.deepEqual(periodRange('previous', midnight), {
        start_date: '2026-09-01',
        end_date: '2026-09-30',
    });
    assert.deepEqual(
        periodRange('previous', new Date('2024-03-01T00:00:00Z')),
        { start_date: '2024-02-01', end_date: '2024-02-29' },
    );
    assert.deepEqual(periodRange('year', midnight), {
        start_date: '2026-01-01',
        end_date: '2026-10-01',
    });
});

test('invalid and excessive date ranges cannot be submitted', () => {
    assert.equal(validRange({ start_date: '', end_date: '2026-10-20' }), false);
    assert.equal(
        validRange({ start_date: '2026-10-20', end_date: '2026-10-01' }),
        false,
    );
    assert.equal(
        validRange({ start_date: '2024-01-01', end_date: '2026-10-01' }),
        false,
    );
    assert.equal(
        validRange({ start_date: '2026-10-01', end_date: '2026-10-20' }),
        true,
    );
});

test('drilldowns preserve dates, division, and exact location values', () => {
    const url = reportTicketUrl(
        {
            start_date: '2026-10-01',
            end_date: '2026-10-20',
            division_id: 'services',
        },
        { dimension: 'site', value: 'Site Utara & Selatan' },
    );
    const params = new URL(url, 'https://example.invalid').searchParams;
    assert.equal(params.get('report'), '1');
    assert.equal(params.get('division_id'), 'services');
    assert.equal(params.get('start_date'), '2026-10-01');
    assert.equal(params.get('value'), 'Site Utara & Selatan');
});
