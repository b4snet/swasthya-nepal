import { describe, expect, it } from 'vitest';
import { timelineSummary } from './PatientProfilePage';

describe('timelineSummary', () => {
  it('passes plain strings through', () => {
    expect(timelineSummary('registered')).toBe('registered');
  });

  it('flattens structured objects', () => {
    expect(timelineSummary({ mrn: 'MRN-123' })).toBe('MRN-123');
    expect(timelineSummary({ changed: ['phone', 'address'] })).toBe('phone · address');
  });

  it('joins arrays', () => {
    expect(timelineSummary(['a', 'b'])).toBe('a, b');
  });

  it('handles null and empty', () => {
    expect(timelineSummary(null)).toBe('');
    expect(timelineSummary({})).toBe('');
  });
});
