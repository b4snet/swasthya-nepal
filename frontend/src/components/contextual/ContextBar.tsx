/**
 * ContextBar — Global Context Strip (Phase 116)
 *
 * Answers: "WHAT CRITICAL ITEMS EXIST RIGHT NOW?"
 *
 * A persistent horizontal strip between the header and content
 * that surfaces system-wide critical and attention items.
 *
 * Not a dashboard. Not a notification list.
 * It is a surgical signal layer — critical things that need
 * attention RIGHT NOW, derived from canonical domain state.
 *
 * Design constraints:
 * - Light-only, calm, clinical
 * - Shows maximum 3-5 items to avoid noise
 * - Each item is actionable (click → workspace)
 * - Accessibility: role="complementary", aria-label
 * - Responsive: horizontal on desktop, stacked on mobile
 * - No animation except subtle entrance
 */

import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTenant } from '../../context/TenantContext';
import { appointmentsApi } from '../../api/endpoints';
import type { Appointment } from '../../api/types';
import {
  Clock,
  Users,
  ArrowRight,
} from 'lucide-react';
import './context-bar.css';

/* ────────────────────────────────────────────────────────────────────
   TYPES
   ──────────────────────────────────────────────────────────────────── */

interface ContextBarItem {
  key: string;
  label: string;
  detail: string;
  icon: React.ReactNode;
  urgency: 'attention' | 'warning' | 'critical';
  to: string;
  count?: number;
}

/* ────────────────────────────────────────────────────────────────────
   MAIN CONTEXT BAR
   ──────────────────────────────────────────────────────────────────── */

export function ContextBar() {
  const navigate = useNavigate();
  const { selectedFacilityId } = useTenant();
  const [appts, setAppts] = useState<Appointment[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    appointmentsApi.list({
      date: new Date().toISOString().split('T')[0],
      facilityId: selectedFacilityId,
    }).then((data) => {
      if (!cancelled) {
        setAppts(Array.isArray(data) ? data : []);
        setLoading(false);
      }
    }).catch(() => {
      if (!cancelled) {
        setAppts([]);
        setLoading(false);
      }
    });
    return () => { cancelled = true; };
  }, [selectedFacilityId]);

  // Derive context items from canonical state
  const items: ContextBarItem[] = useMemo(() => {
    const result: ContextBarItem[] = [];
    const now = new Date();

    // Overdue appointments (past start time, not checked in)
    const overdue = appts.filter((a) => {
      if (a.status !== 'booked') return false;
      const startsAt = new Date(a.startsAt);
      return startsAt < now;
    });

    if (overdue.length > 0) {
      result.push({
        key: 'overdue-appointments',
        label: 'Overdue',
        detail: `${overdue.length} appointment${overdue.length !== 1 ? 's' : ''} past start time`,
        icon: <Clock size={14} />,
        urgency: 'warning',
        to: '/clinical/appointments',
        count: overdue.length,
      });
    }

    // Patients waiting (checked in but not yet in consultation)
    const waiting = appts.filter((a) => a.status === 'checked_in');
    if (waiting.length > 0) {
      result.push({
        key: 'waiting-patients',
        label: 'Waiting',
        detail: `${waiting.length} patient${waiting.length !== 1 ? 's' : ''} in queue`,
        icon: <Users size={14} />,
        urgency: 'attention',
        to: '/clinical/queue',
        count: waiting.length,
      });
    }

    // Active consultations (patients currently being seen)
    const active = appts.filter((a) => a.status === 'in_consultation');
    if (active.length > 3) {
      result.push({
        key: 'active-consultations',
        label: 'Active',
        detail: `${active.length} consultations in progress`,
        icon: <Users size={14} />,
        urgency: 'normal' as any,
        to: '/clinical/encounters',
        count: active.length,
      });
    }

    // Limit to top 4 most urgent
    return result.slice(0, 4);
  }, [appts]);

  // Don't render if no items
  if (loading || items.length === 0) return null;

  return (
    <div className="context-bar" role="complementary" aria-label="Active clinical context">
      <div className="context-bar__items">
        {items.map((item) => (
          <button
            key={item.key}
            type="button"
            className={`context-bar__item context-bar__item--${item.urgency}`}
            onClick={() => navigate(item.to)}
            aria-label={`${item.label}: ${item.detail}`}
            data-testid={`context-bar-${item.key}`}
          >
            <span className="context-bar__icon">{item.icon}</span>
            <div className="context-bar__text">
              <span className="context-bar__label">{item.label}</span>
              <span className="context-bar__detail">{item.detail}</span>
            </div>
            {item.count != null && (
              <span className="context-bar__count">{item.count}</span>
            )}
            <ArrowRight size={12} className="context-bar__arrow" />
          </button>
        ))}
      </div>
    </div>
  );
}

export default ContextBar;
