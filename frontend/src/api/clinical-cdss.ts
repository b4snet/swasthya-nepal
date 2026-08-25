import { api, type RequestOptions } from './client';

const opt = (facilityId?: string | null): RequestOptions => ({ facilityId });

export interface DrugInteractionResult {
  id: string;
  severity: 'critical' | 'major' | 'moderate';
  medicationA: { id: string; name: string };
  medicationB: { id: string; name: string };
  description: string;
  clinicalEffect: string | null;
  recommendation: string | null;
}

export interface InteractionCheckResponse {
  interactions: DrugInteractionResult[];
  hasCritical: boolean;
  hasMajor: boolean;
  count: number;
}

export const drugInteractionApi = {
  /**
   * Check a list of medication IDs for known interactions.
   * Returns interactions grouped by severity.
   */
  check: (medicationIds: string[], facilityId?: string | null) =>
    api.request<InteractionCheckResponse>('/api/v1/drug-interactions/check', {
      method: 'POST',
      body: { medicationIds },
      ...opt(facilityId),
    }),

  /** List all interaction rules for the tenant. */
  list: (facilityId?: string | null) =>
    api.request<DrugInteractionResult[]>('/api/v1/drug-interactions', opt(facilityId)),

  /** Create a new interaction rule. */
  create: (data: {
    medicationAId: string;
    medicationBId: string;
    severity: string;
    description: string;
    clinicalEffect?: string;
    recommendation?: string;
  }, facilityId?: string | null) =>
    api.request<{ id: string; severity: string; description: string }>(
      '/api/v1/drug-interactions',
      { method: 'POST', body: data, ...opt(facilityId) },
    ),
};
