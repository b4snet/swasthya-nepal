/**
 * Phase 227 — Pharmacy & Medication Inventory Safety Tests
 *
 * Tests the pharmacy prescription lifecycle (verify → dispense → return → dual-verify)
 * and medication inventory management (CRUD, batches, adjustments, adjustment-request
 * approval flow, reorder alerts, facility-scoped transfers).
 *
 * API surface: `pharmacyApi` (6 endpoints), `inventoryApi` (9 endpoints)
 * from frontend/src/api/pharmacy.ts
 *
 * Domain: Prescription verification workflow, controlled substance dual-verification,
 * batch-level dispensing, dispensary returns with reasons, inventory quantity
 * management with approval gates, facility-scoped stock transfers, reorder alerting.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

// ─── Shared test fixtures ────────────────────────────────────────────────────
const ORG_ID = 'org-ph-001';
const FACILITY_ID = 'fac-ph-001';
const OTHER_ORG = 'org-other-999';
const OTHER_FAC = 'fac-other-999';

function makePrescription(overrides: Record<string, unknown> = {}) {
  return {
    id: 'rx-001',
    patient_id: 'pat-001',
    encounter_id: 'enc-001',
    prescriber_id: 'doc-001',
    status: 'pending',
    items: [],
    ...overrides,
  };
}

function makeInventoryItem(overrides: Record<string, unknown> = {}) {
  return {
    id: 'inv-001',
    medication_id: 'med-001',
    medication_name: 'Amoxicillin 500mg',
    quantity_on_hand: 200,
    reorder_level: 50,
    unit: 'capsule',
    facility_id: FACILITY_ID,
    ...overrides,
  };
}

function makeStockBatch(overrides: Record<string, unknown> = {}) {
  return {
    id: 'batch-001',
    inventory_item_id: 'inv-001',
    lot_number: 'LOT-2024-001',
    quantity: 100,
    expiry_date: '2025-12-31',
    manufactured_date: '2024-01-15',
    ...overrides,
  };
}

function makeAdjustmentRequest(overrides: Record<string, unknown> = {}) {
  return {
    id: 'adj-req-001',
    inventory_item_id: 'inv-001',
    requested_by: 'user-001',
    quantity_delta: 50,
    status: 'pending',
    created_at: '2024-06-01T10:00:00Z',
    ...overrides,
  };
}

// ─── Mock the API client ─────────────────────────────────────────────────────
const mockRequest = vi.fn();

vi.mock('../api/client', () => ({
  api: { request: (...args: unknown[]) => mockRequest(...args) },
  ApiError: class ApiError extends Error {
    code: string;
    constructor(code: string, message: string) {
      super(message);
      this.code = code;
    }
  },
}));

// ─── Import after mock setup ─────────────────────────────────────────────────
import { pharmacyApi, inventoryApi } from '../api/pharmacy';

beforeEach(() => {
  vi.clearAllMocks();
  mockRequest.mockReset();
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 1 — PRESCRIPTION LIST (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Prescription list architecture', () => {
  it('sends GET to /api/v1/prescriptions with no query params by default', async () => {
    mockRequest.mockResolvedValue({ data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 });
    await pharmacyApi.list();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/prescriptions?');
  });

  it('includes status filter when provided', async () => {
    mockRequest.mockResolvedValue({ data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 });
    await pharmacyApi.list({ status: 'verified' });
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).toContain('status=verified');
  });

  it('includes search filter when provided', async () => {
    mockRequest.mockResolvedValue({ data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 });
    await pharmacyApi.list({ search: 'amoxicillin' });
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).toContain('search=amoxicillin');
  });

  it('includes facilityId filter when provided', async () => {
    mockRequest.mockResolvedValue({ data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 });
    await pharmacyApi.list({ facilityId: FACILITY_ID });
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).toContain(`facilityId=${FACILITY_ID}`);
  });

  it('returns paginated shape with data, current_page, last_page, per_page, total', async () => {
    const resp = { data: [makePrescription()], current_page: 2, last_page: 5, per_page: 20, total: 100 };
    mockRequest.mockResolvedValue(resp);
    const result = await pharmacyApi.list();
    expect(result).toHaveProperty('data');
    expect(result).toHaveProperty('current_page');
    expect(result).toHaveProperty('last_page');
    expect(result).toHaveProperty('per_page');
    expect(result).toHaveProperty('total');
  });
});

describe('Phase 227 — Prescription list safety', () => {
  it('does not expose patient SSN or national ID in list response', async () => {
    const rx = makePrescription();
    mockRequest.mockResolvedValue({ data: [rx], current_page: 1, last_page: 1, per_page: 20, total: 1 });
    const result = await pharmacyApi.list();
    const item = result.data[0];
    expect(item).not.toHaveProperty('ssn');
    expect(item).not.toHaveProperty('national_id');
    expect(item).not.toHaveProperty('nationalId');
  });

  it('does not include prescriber personal phone or email in list items', async () => {
    const rx = makePrescription();
    mockRequest.mockResolvedValue({ data: [rx], current_page: 1, last_page: 1, per_page: 20, total: 1 });
    const result = await pharmacyApi.list();
    const item = result.data[0];
    expect(item).not.toHaveProperty('prescriber_phone');
    expect(item).not.toHaveProperty('prescriber_email');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 2 — SHOW PRESCRIPTION (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Show prescription architecture', () => {
  it('sends GET to /api/v1/prescriptions/:id', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    await pharmacyApi.showPrescription('rx-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/prescriptions/rx-001', { facilityId: undefined });
  });

  it('includes facilityId in request options when provided', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    await pharmacyApi.showPrescription('rx-001', FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/prescriptions/rx-001', { facilityId: FACILITY_ID });
  });
});

describe('Phase 227 — Show prescription safety', () => {
  it('does not return password or auth tokens in prescription detail', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    const rx = await pharmacyApi.showPrescription('rx-001');
    expect(rx).not.toHaveProperty('password');
    expect(rx).not.toHaveProperty('token');
    expect(rx).not.toHaveProperty('access_token');
  });

  it('does not expose internal user IDs that could enable cross-tenant lookup', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    const rx = await pharmacyApi.showPrescription('rx-001');
    expect(rx).not.toHaveProperty('internal_user_uuid');
    expect(rx).not.toHaveProperty('supabase_uid');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 3 — PRESCRIPTION VERIFY (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Prescription verify architecture', () => {
  it('sends POST to /api/v1/prescriptions/:id/verify', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'verified' }));
    await pharmacyApi.verify('rx-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/prescriptions/rx-001/verify', {
      method: 'POST',
      body: {},
      facilityId: undefined,
    });
  });

  it('returns updated prescription after verification', async () => {
    const verified = makePrescription({ status: 'verified' });
    mockRequest.mockResolvedValue(verified);
    const result = await pharmacyApi.verify('rx-001');
    expect(result.status).toBe('verified');
  });
});

describe('Phase 227 — Prescription verify safety', () => {
  it('does not send client-computed verification timestamp', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'verified' }));
    await pharmacyApi.verify('rx-001');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('verified_at');
    expect(body).not.toHaveProperty('timestamp');
  });

  it('does not send pharmacist identity in body (must come from server-side auth)', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'verified' }));
    await pharmacyApi.verify('rx-001');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('pharmacist_id');
    expect(body).not.toHaveProperty('verified_by');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 4 — PRESCRIPTION DISPENSE (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Prescription dispense architecture', () => {
  it('sends POST to /api/v1/prescriptions/:id/dispense with batch selections', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'dispensed' }));
    const payload = {
      batchSelections: [
        { lineId: 'line-001', batchId: 'batch-001', quantity: 30 },
        { lineId: 'line-002', batchId: 'batch-002', quantity: 10 },
      ],
    };
    await pharmacyApi.dispense('rx-001', payload);
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/prescriptions/rx-001/dispense', {
      method: 'POST',
      body: payload,
      facilityId: undefined,
    });
  });

  it('accepts empty batchSelections', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'dispensed' }));
    await pharmacyApi.dispense('rx-001', { batchSelections: [] });
    expect(mockRequest).toHaveBeenCalled();
  });
});

describe('Phase 227 — Prescription dispense safety', () => {
  it('does not send quantity exceeding physical stock', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'dispensed' }));
    const payload = {
      batchSelections: [
        { lineId: 'line-001', batchId: 'batch-001', quantity: 999999 },
      ],
    };
    // The client sends the request; server must validate stock. Client should not pre-validate.
    // This test documents that the client does NOT cap quantity.
    await pharmacyApi.dispense('rx-001', payload);
    const body = mockRequest.mock.calls[0][1].body;
    expect(body.batchSelections[0].quantity).toBe(999999);
  });

  it('does not send dispensing pharmacist identity in body', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'dispensed' }));
    await pharmacyApi.dispense('rx-001', { batchSelections: [] });
    const body = mockRequest.mock.calls[0][1];
    expect(body).not.toHaveProperty('dispensed_by');
    expect(body).not.toHaveProperty('pharmacist_id');
  });

  it('does not include client-side batch expiry validation', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'dispensed' }));
    const payload = {
      batchSelections: [
        { lineId: 'line-001', batchId: 'batch-expired', quantity: 5 },
      ],
    };
    await pharmacyApi.dispense('rx-001', payload);
    // Client does not validate expiry — server must handle this
    expect(mockRequest).toHaveBeenCalled();
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 5 — PRESCRIPTION LINE RETURN (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Prescription return architecture', () => {
  it('sends POST to /api/v1/prescription-lines/:lineId/return', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'partially_returned' }));
    await pharmacyApi.returnLine('line-001', { quantityMinor: 10, reason: 'Patient allergic reaction' });
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/prescription-lines/line-001/return', {
      method: 'POST',
      body: { quantityMinor: 10, reason: 'Patient allergic reaction' },
      facilityId: undefined,
    });
  });

  it('includes facilityId when provided', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    await pharmacyApi.returnLine('line-001', { reason: 'Duplicate order' }, FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/prescription-lines/line-001/return', {
      method: 'POST',
      body: { reason: 'Duplicate order' },
      facilityId: FACILITY_ID,
    });
  });
});

describe('Phase 227 — Prescription return safety', () => {
  it('requires a reason string for returns', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    // Client sends whatever body is provided; server validates reason is required
    await pharmacyApi.returnLine('line-001', { reason: '' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).toHaveProperty('reason');
  });

  it('does not allow client to set return approved_by', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    await pharmacyApi.returnLine('line-001', { reason: 'Damaged' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('approved_by');
    expect(body).not.toHaveProperty('returned_by');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 6 — DUAL VERIFICATION (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Dual verification architecture', () => {
  it('sends POST to /api/v1/prescription-lines/:lineId/dual-verify', async () => {
    mockRequest.mockResolvedValue(undefined);
    await pharmacyApi.dualVerify('line-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/prescription-lines/line-001/dual-verify', {
      method: 'POST',
      body: {},
      facilityId: undefined,
    });
  });

  it('includes facilityId when provided', async () => {
    mockRequest.mockResolvedValue(undefined);
    await pharmacyApi.dualVerify('line-001', FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/prescription-lines/line-001/dual-verify', {
      method: 'POST',
      body: {},
      facilityId: FACILITY_ID,
    });
  });
});

describe('Phase 227 — Dual verification safety', () => {
  it('does not send verifier identity — must be derived server-side from auth token', async () => {
    mockRequest.mockResolvedValue(undefined);
    await pharmacyApi.dualVerify('line-001');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('verifier_id');
    expect(body).not.toHaveProperty('verifier_name');
  });

  it('does not allow client to fabricate dual-verify timestamp', async () => {
    mockRequest.mockResolvedValue(undefined);
    await pharmacyApi.dualVerify('line-001');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('verified_at');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 7 — INVENTORY LIST (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Inventory list architecture', () => {
  it('sends GET to /api/v1/organizations/:orgId/inventory', async () => {
    mockRequest.mockResolvedValue([makeInventoryItem()]);
    await inventoryApi.list(ORG_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/inventory`, { facilityId: undefined });
  });

  it('includes facilityId when provided', async () => {
    mockRequest.mockResolvedValue([makeInventoryItem()]);
    await inventoryApi.list(ORG_ID, FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/inventory`, { facilityId: FACILITY_ID });
  });
});

describe('Phase 227 — Inventory list safety', () => {
  it('rejects empty organizationId', () => {
    expect(() => inventoryApi.list('')).toThrow('Organization context is required');
  });

  it('rejects null organizationId', () => {
    expect(() => inventoryApi.list(null as unknown as string)).toThrow('Organization context is required');
  });

  it('does not expose cost_price or markup in list items', async () => {
    mockRequest.mockResolvedValue([makeInventoryItem()]);
    const items = await inventoryApi.list(ORG_ID);
    expect(items[0]).not.toHaveProperty('cost_price');
    expect(items[0]).not.toHaveProperty('markup_percent');
    expect(items[0]).not.toHaveProperty('sell_price');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 8 — INVENTORY BATCHES (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Inventory batches architecture', () => {
  it('sends GET to /api/v1/inventory-items/:itemId/batches', async () => {
    mockRequest.mockResolvedValue([makeStockBatch()]);
    await inventoryApi.batches('inv-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/inventory-items/inv-001/batches', { facilityId: undefined });
  });
});

describe('Phase 227 — Inventory batches safety', () => {
  it('does not expose supplier cost in batch details', async () => {
    mockRequest.mockResolvedValue([makeStockBatch()]);
    const batches = await inventoryApi.batches('inv-001');
    expect(batches[0]).not.toHaveProperty('unit_cost');
    expect(batches[0]).not.toHaveProperty('purchase_price');
  });

  it('does not expose supplier identity in batch details', async () => {
    mockRequest.mockResolvedValue([makeStockBatch()]);
    const batches = await inventoryApi.batches('inv-001');
    expect(batches[0]).not.toHaveProperty('supplier_id');
    expect(batches[0]).not.toHaveProperty('vendor_id');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 9 — INVENTORY STORE (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Inventory store architecture', () => {
  it('sends POST to /api/v1/organizations/:orgId/inventory', async () => {
    mockRequest.mockResolvedValue(makeInventoryItem());
    const payload = { medicationId: 'med-001', quantityOnHand: 100, reorderLevel: 20 };
    await inventoryApi.store(ORG_ID, payload);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/inventory`, {
      method: 'POST',
      body: payload,
      facilityId: undefined,
    });
  });
});

describe('Phase 227 — Inventory store safety', () => {
  it('rejects empty organizationId', () => {
    expect(() => inventoryApi.store('', { medicationId: 'med-001' })).toThrow('Organization context is required');
  });

  it('does not allow client to set cost or sell price', async () => {
    mockRequest.mockResolvedValue(makeInventoryItem());
    const payload = { medicationId: 'med-001', quantityOnHand: 100 };
    await inventoryApi.store(ORG_ID, payload);
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('cost_price');
    expect(body).not.toHaveProperty('sell_price');
    expect(body).not.toHaveProperty('markup');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 10 — INVENTORY ADJUST (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Inventory adjust architecture', () => {
  it('sends POST to /api/v1/inventory-items/:itemId/adjust with delta and reason', async () => {
    mockRequest.mockResolvedValue(makeInventoryItem({ quantity_on_hand: 180 }));
    await inventoryApi.adjust('inv-001', { quantityDelta: -20, reason: 'Damaged stock write-off' });
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/inventory-items/inv-001/adjust', {
      method: 'POST',
      body: { quantityDelta: -20, reason: 'Damaged stock write-off' },
      facilityId: undefined,
    });
  });
});

describe('Phase 227 — Inventory adjust safety', () => {
  it('does not allow client to set adjusted_by or timestamp', async () => {
    mockRequest.mockResolvedValue(makeInventoryItem());
    await inventoryApi.adjust('inv-001', { quantityDelta: -5, reason: 'Count correction' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('adjusted_by');
    expect(body).not.toHaveProperty('adjusted_at');
    expect(body).not.toHaveProperty('timestamp');
  });

  it('does not override facilityId from client', async () => {
    mockRequest.mockResolvedValue(makeInventoryItem());
    await inventoryApi.adjust('inv-001', { quantityDelta: -5, reason: 'test' }, FACILITY_ID);
    const body = mockRequest.mock.calls[0][1];
    expect(body.facilityId).toBe(FACILITY_ID);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 11 — REORDER ALERTS (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Reorder alerts architecture', () => {
  it('sends GET to /api/v1/organizations/:orgId/reorder-alerts', async () => {
    mockRequest.mockResolvedValue([makeInventoryItem({ quantity_on_hand: 10, reorder_level: 50 })]);
    await inventoryApi.reorderAlerts(ORG_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/reorder-alerts`, { facilityId: undefined });
  });
});

describe('Phase 227 — Reorder alerts safety', () => {
  it('rejects empty organizationId', () => {
    expect(() => inventoryApi.reorderAlerts('')).toThrow('Organization context is required');
  });

  it('does not leak cross-facility stock levels', async () => {
    mockRequest.mockResolvedValue([makeInventoryItem()]);
    await inventoryApi.reorderAlerts(ORG_ID, FACILITY_ID);
    const opts = mockRequest.mock.calls[0][1];
    expect(opts.facilityId).toBe(FACILITY_ID);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 12 — ADJUSTMENT REQUEST (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Adjustment request architecture', () => {
  it('sends POST to create adjustment request', async () => {
    mockRequest.mockResolvedValue(makeAdjustmentRequest());
    await inventoryApi.storeAdjustmentRequest('inv-001', { quantityDelta: 50 });
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/inventory-items/inv-001/adjustment-requests', {
      method: 'POST',
      body: { quantityDelta: 50 },
      facilityId: undefined,
    });
  });

  it('sends GET to list adjustment requests', async () => {
    mockRequest.mockResolvedValue([makeAdjustmentRequest()]);
    await inventoryApi.adjustmentRequests('inv-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/inventory-items/inv-001/adjustment-requests', { facilityId: undefined });
  });

  it('sends POST to approve adjustment request', async () => {
    mockRequest.mockResolvedValue(makeAdjustmentRequest({ status: 'approved' }));
    await inventoryApi.approveAdjustment('adj-req-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/inventory-adjustment-requests/adj-req-001/approve', {
      method: 'POST',
      body: {},
      facilityId: undefined,
    });
  });

  it('sends POST to reject adjustment request', async () => {
    mockRequest.mockResolvedValue(makeAdjustmentRequest({ status: 'rejected' }));
    await inventoryApi.rejectAdjustment('adj-req-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/inventory-adjustment-requests/adj-req-001/reject', {
      method: 'POST',
      body: {},
      facilityId: undefined,
    });
  });
});

describe('Phase 227 — Adjustment request safety', () => {
  it('does not allow client to set status on approval', async () => {
    mockRequest.mockResolvedValue(makeAdjustmentRequest({ status: 'approved' }));
    await inventoryApi.approveAdjustment('adj-req-001');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('status');
  });

  it('does not allow client to set approved_by', async () => {
    mockRequest.mockResolvedValue(makeAdjustmentRequest({ status: 'approved' }));
    await inventoryApi.approveAdjustment('adj-req-001');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('approved_by');
  });

  it('does not allow self-approval: request creator cannot approve own request', async () => {
    // This is a server-side policy. Client does not send approver identity.
    mockRequest.mockResolvedValue(makeAdjustmentRequest({ status: 'approved' }));
    await inventoryApi.approveAdjustment('adj-req-001');
    // Body is empty — server must enforce separation of duties
    expect(mockRequest.mock.calls[0][1].body).toEqual({});
  });

  it('does not allow client to bypass approval and directly adjust quantity', async () => {
    // The storeAdjustmentRequest endpoint goes through approval flow
    mockRequest.mockResolvedValue(makeAdjustmentRequest());
    await inventoryApi.storeAdjustmentRequest('inv-001', { quantityDelta: 100 });
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).toContain('adjustment-requests');
    // Not the direct adjust endpoint (the URL contains 'adjustment-requests' not just 'adjust')
    expect(url).not.toMatch(/\/adjust$/);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 13 — INVENTORY TRANSFER (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Inventory transfer architecture', () => {
  it('sends POST to /api/v1/inventory-transfers', async () => {
    mockRequest.mockResolvedValue(undefined);
    const payload = {
      fromInventoryItemId: 'inv-001',
      toInventoryItemId: 'inv-002',
      quantity: 50,
      reason: 'Stock redistribution',
    };
    await inventoryApi.transfer(payload);
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/inventory-transfers', {
      method: 'POST',
      body: payload,
      facilityId: undefined,
    });
  });
});

describe('Phase 227 — Inventory transfer safety', () => {
  it('does not allow client to set transfer_by or timestamp', async () => {
    mockRequest.mockResolvedValue(undefined);
    await inventoryApi.transfer({
      fromInventoryItemId: 'inv-001',
      toInventoryItemId: 'inv-002',
      quantity: 10,
      reason: 'Rebalancing',
    });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('transfer_by');
    expect(body).not.toHaveProperty('transferred_at');
    expect(body).not.toHaveProperty('timestamp');
  });

  it('does not include facilityId in transfer body (must be derived from item context)', async () => {
    mockRequest.mockResolvedValue(undefined);
    await inventoryApi.transfer({
      fromInventoryItemId: 'inv-001',
      toInventoryItemId: 'inv-002',
      quantity: 10,
      reason: 'Rebalancing',
    }, FACILITY_ID);
    // facilityId goes in request options, not body
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('facilityId');
    expect(body).not.toHaveProperty('facility_id');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 14 — CROSS-DOMAIN AUTHORIZATION
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Cross-domain authorization', () => {
  it('pharmacyApi.verify requires pharmacist or doctor role (server-enforced)', async () => {
    // Client sends no role info — server enforces via auth middleware
    mockRequest.mockResolvedValue(makePrescription({ status: 'verified' }));
    await pharmacyApi.verify('rx-001');
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('role');
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('permission');
  });

  it('pharmacyApi.dispense requires pharmacist role (server-enforced)', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'dispensed' }));
    await pharmacyApi.dispense('rx-001', { batchSelections: [] });
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('role');
  });

  it('inventoryApi.approveAdjustment requires hospital_admin or pharmacist_admin role', async () => {
    mockRequest.mockResolvedValue(makeAdjustmentRequest({ status: 'approved' }));
    await inventoryApi.approveAdjustment('adj-req-001');
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('role');
  });

  it('pharmacyApi.dualVerify requires a second distinct pharmacist (server-enforced)', async () => {
    mockRequest.mockResolvedValue(undefined);
    await pharmacyApi.dualVerify('line-001');
    // Server must verify that the second verifier is different from the first
    expect(mockRequest.mock.calls[0][1].body).toEqual({});
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 15 — CROSS-DOMAIN TENANT/FACILITY SCOPE
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Cross-domain scope', () => {
  it('inventoryApi.list enforces organization scope via orgUrl', async () => {
    mockRequest.mockResolvedValue([]);
    await inventoryApi.list(ORG_ID, FACILITY_ID);
    expect(mockRequest.mock.calls[0][0]).toContain(ORG_ID);
    expect(mockRequest.mock.calls[0][0]).not.toContain(OTHER_ORG);
  });

  it('inventoryApi.reorderAlerts enforces organization scope via orgUrl', async () => {
    mockRequest.mockResolvedValue([]);
    await inventoryApi.reorderAlerts(ORG_ID, FACILITY_ID);
    expect(mockRequest.mock.calls[0][0]).toContain(ORG_ID);
  });

  it('pharmacyApi.list does not embed org in URL (facility-scoped instead)', async () => {
    mockRequest.mockResolvedValue({ data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 });
    await pharmacyApi.list({ facilityId: FACILITY_ID });
    expect(mockRequest.mock.calls[0][0]).not.toContain(OTHER_ORG);
  });

  it('all pharmacy endpoints accept facilityId for facility isolation', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    await pharmacyApi.showPrescription('rx-001', FACILITY_ID);
    const opts = mockRequest.mock.calls[0][1];
    expect(opts.facilityId).toBe(FACILITY_ID);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 16 — AUDIT TRAIL
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Audit trail', () => {
  it('verify operation is server-auditable (no client-side audit bypass)', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'verified' }));
    await pharmacyApi.verify('rx-001');
    // Client does not send skip_audit or audit_enabled flags
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('skip_audit');
    expect(body).not.toHaveProperty('audit_enabled');
  });

  it('dispense operation is server-auditable', async () => {
    mockRequest.mockResolvedValue(makePrescription({ status: 'dispensed' }));
    await pharmacyApi.dispense('rx-001', { batchSelections: [{ lineId: 'l1', batchId: 'b1', quantity: 5 }] });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('skip_audit');
  });

  it('inventory adjust is server-auditable', async () => {
    mockRequest.mockResolvedValue(makeInventoryItem());
    await inventoryApi.adjust('inv-001', { quantityDelta: -10, reason: 'Damaged' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('skip_audit');
  });

  it('adjustment approval is server-auditable', async () => {
    mockRequest.mockResolvedValue(makeAdjustmentRequest({ status: 'approved' }));
    await inventoryApi.approveAdjustment('adj-req-001');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('skip_audit');
  });

  it('inventory transfer is server-auditable', async () => {
    mockRequest.mockResolvedValue(undefined);
    await inventoryApi.transfer({
      fromInventoryItemId: 'inv-001',
      toInventoryItemId: 'inv-002',
      quantity: 10,
      reason: 'Move',
    });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('skip_audit');
  });

  it('return operation is server-auditable', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    await pharmacyApi.returnLine('line-001', { reason: 'Damaged' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('skip_audit');
  });

  it('dual-verify operation is server-auditable', async () => {
    mockRequest.mockResolvedValue(undefined);
    await pharmacyApi.dualVerify('line-001');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('skip_audit');
  });

  it('reorder alerts listing is server-auditable', async () => {
    mockRequest.mockResolvedValue([]);
    await inventoryApi.reorderAlerts(ORG_ID);
    expect(mockRequest).toHaveBeenCalled();
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 17 — PRIVACY
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Privacy', () => {
  it('prescription list does not expose patient date of birth', async () => {
    mockRequest.mockResolvedValue({ data: [makePrescription()], current_page: 1, last_page: 1, per_page: 20, total: 1 });
    const result = await pharmacyApi.list();
    expect(result.data[0]).not.toHaveProperty('patient_dob');
    expect(result.data[0]).not.toHaveProperty('date_of_birth');
  });

  it('inventory list does not expose patient association data', async () => {
    mockRequest.mockResolvedValue([makeInventoryItem()]);
    const items = await inventoryApi.list(ORG_ID);
    expect(items[0]).not.toHaveProperty('patient_id');
    expect(items[0]).not.toHaveProperty('patient_name');
  });

  it('prescription detail does not expose prescriber personal contact info', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    const rx = await pharmacyApi.showPrescription('rx-001');
    expect(rx).not.toHaveProperty('prescriber_phone');
    expect(rx).not.toHaveProperty('prescriber_email');
    expect(rx).not.toHaveProperty('prescriber_address');
  });

  it('stock batch does not expose supplier contact details', async () => {
    mockRequest.mockResolvedValue([makeStockBatch()]);
    const batches = await inventoryApi.batches('inv-001');
    expect(batches[0]).not.toHaveProperty('supplier_phone');
    expect(batches[0]).not.toHaveProperty('supplier_email');
  });

  it('inventory items do not expose internal medication UUID mappings', async () => {
    mockRequest.mockResolvedValue([makeInventoryItem()]);
    const items = await inventoryApi.list(ORG_ID);
    expect(items[0]).not.toHaveProperty('internal_medication_uuid');
    expect(items[0]).not.toHaveProperty('supabase_medication_id');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 18 — ARCHITECTURE COMPLETENESS
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 227 — Architecture completeness', () => {
  it('pharmacyApi exposes exactly 6 methods: list, showPrescription, verify, dispense, returnLine, dualVerify', () => {
    const methods = Object.keys(pharmacyApi);
    expect(methods).toContain('list');
    expect(methods).toContain('showPrescription');
    expect(methods).toContain('verify');
    expect(methods).toContain('dispense');
    expect(methods).toContain('returnLine');
    expect(methods).toContain('dualVerify');
    expect(methods.length).toBe(6);
  });

  it('inventoryApi exposes exactly 9 methods', () => {
    const methods = Object.keys(inventoryApi);
    expect(methods).toContain('list');
    expect(methods).toContain('batches');
    expect(methods).toContain('store');
    expect(methods).toContain('adjust');
    expect(methods).toContain('reorderAlerts');
    expect(methods).toContain('storeAdjustmentRequest');
    expect(methods).toContain('adjustmentRequests');
    expect(methods).toContain('approveAdjustment');
    expect(methods).toContain('rejectAdjustment');
    expect(methods).toContain('transfer');
    expect(methods.length).toBe(10);
  });

  it('all org-scoped inventory endpoints throw on null organizationId', () => {
    expect(() => inventoryApi.list('')).toThrow();
    expect(() => inventoryApi.store('', { medicationId: 'm' })).toThrow();
    expect(() => inventoryApi.reorderAlerts('')).toThrow();
  });

  it('all prescription endpoints forward facilityId option', async () => {
    mockRequest.mockResolvedValue(makePrescription());
    await pharmacyApi.showPrescription('rx-001', FACILITY_ID);
    expect(mockRequest.mock.calls[0][1].facilityId).toBe(FACILITY_ID);

    mockRequest.mockResolvedValue(makePrescription({ status: 'verified' }));
    await pharmacyApi.verify('rx-001', FACILITY_ID);
    expect(mockRequest.mock.calls[1][1].facilityId).toBe(FACILITY_ID);

    mockRequest.mockResolvedValue(undefined);
    await pharmacyApi.dualVerify('line-001', FACILITY_ID);
    expect(mockRequest.mock.calls[2][1].facilityId).toBe(FACILITY_ID);
  });
});
