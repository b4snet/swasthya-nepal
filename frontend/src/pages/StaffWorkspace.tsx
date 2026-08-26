import { useCallback, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider';
import { useAccess } from '../auth/useAccess';
import { useTenant } from '../context/TenantContext';
import { useFetch } from '../hooks/useFetch';
import {
  appointmentsApi,
  encountersApi,
  referralsApi,
} from '../api/endpoints';
import { hrApi } from '../api/endpoints';
import type {
  Appointment,
  Encounter,
  Referral,
  Roster,
  AttendanceRecord,
} from '../api/types';
import { Alert, Button, Card, EmptyState } from '../components/ui';
import './staff-workspace.css';

/* ─── Status helpers ─── */

const STATUS_COLORS: Record<string, { color: string; bg: string }> = {
  booked: { color: '#3b82f6', bg: '#eff6ff' },
  checked_in: { color: '#f59e0b', bg: '#fffbeb' },
  in_consultation: { color: '#8b5cf6', bg: '#f5f3ff' },
  completed: { color: '#10b981', bg: '#ecfdf5' },
  cancelled: { color: '#6b7280', bg: '#f3f4f6' },
  no_show: { color: '#ef4444', bg: '#fee2e2' },
  open: { color: '#3b82f6', bg: '#eff6ff' },
  in_progress: { color: '#f59e0b', bg: '#fffbeb' },
  signed: { color: '#10b981', bg: '#ecfdf5' },
  closed: { color: '#6b7280', bg: '#f3f4f6' },
  active: { color: '#10b981', bg: '#ecfdf5' },
  present: { color: '#10b981', bg: '#ecfdf5' },
  pending: { color: '#f59e0b', bg: '#fffbeb' },
  scheduled: { color: '#3b82f6', bg: '#eff6ff' },
  confirmed: { color: '#10b981', bg: '#ecfdf5' },
  accepted: { color: '#10b981', bg: '#ecfdf5' },
};

function StatusBadge({ status }: { status: string }) {
  const c = STATUS_COLORS[status] ?? { color: '#6b7280', bg: '#f3f4f6' };
  return (
    <span
      className="sw-status-badge"
      style={{ color: c.color, backgroundColor: c.bg }}
    >
      {status.replace(/_/g, ' ')}
    </span>
  );
}

/* ─── Time helpers ─── */

function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleTimeString(undefined, {
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return iso;
  }
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return iso;
  }
}

function isToday(dateStr: string | null | undefined): boolean {
  if (!dateStr) return false;
  try {
    const d = new Date(dateStr);
    const now = new Date();
    return (
      d.getFullYear() === now.getFullYear() &&
      d.getMonth() === now.getMonth() &&
      d.getDate() === now.getDate()
    );
  } catch {
    return false;
  }
}

function isUpcoming(dateStr: string | null | undefined): boolean {
  if (!dateStr) return false;
  try {
    return new Date(dateStr) > new Date();
  } catch {
    return false;
  }
}

/* ─── Workspace sections ─── */

interface WorkspaceSection {
  id: string;
  label: string;
  count: number;
  roles: string[];
}

function buildSections(
  hasRole: (r: string) => boolean,
  counts: Record<string, number>,
): WorkspaceSection[] {
  const ALL = [] as string[];
  const CLINICAL = ['doctor', 'nurse', 'hospital_admin', 'org_admin', 'superadmin'];
  const DOCTOR = ['doctor', 'hospital_admin', 'org_admin', 'superadmin'];

  const sections: WorkspaceSection[] = [
    { id: 'today', label: "Today's Overview", count: counts.today ?? 0, roles: ALL },
    { id: 'appointments', label: 'Appointments', count: counts.appointments ?? 0, roles: ALL },
    { id: 'encounters', label: 'Active Encounters', count: counts.encounters ?? 0, roles: DOCTOR },
    { id: 'patients', label: 'My Patients', count: counts.patients ?? 0, roles: CLINICAL },
    { id: 'referrals', label: 'Referrals', count: counts.referrals ?? 0, roles: DOCTOR },
    { id: 'shifts', label: 'Shifts & Attendance', count: counts.shifts ?? 0, roles: ALL },
  ];

  return sections.filter(
    (s) => s.roles.length === 0 || s.roles.some((r) => hasRole(r)),
  );
}

/* ─── Today Overview Section ─── */

function TodayOverview({
  appointments,
  encounters,
  referrals,
  attendance,
  navigate,
}: {
  appointments: Appointment[];
  encounters: Encounter[];
  referrals: Referral[];
  attendance: AttendanceRecord[];
  navigate: ReturnType<typeof useNavigate>;
}) {
  const todayAppts = useMemo(
    () => appointments.filter((a) => isToday(a.startsAt)),
    [appointments],
  );
  const upcomingAppts = useMemo(
    () => appointments.filter((a) => isUpcoming(a.startsAt) && !isToday(a.startsAt)).slice(0, 5),
    [appointments],
  );
  const activeEncounters = useMemo(
    () => encounters.filter((e) => e.status === 'open' || e.status === 'in_progress'),
    [encounters],
  );
  const todayAttendance = useMemo(
    () => attendance.filter((a) => isToday(a.attendanceDate)),
    [attendance],
  );

  return (
    <div className="sw-section">
      <div className="sw-section-header">
        <h3>Today's Overview</h3>
        <span className="sw-date">{formatDate(new Date().toISOString())}</span>
      </div>

      <div className="sw-overview-grid">
        <Card className="sw-stat-card">
          <div className="sw-stat-value" style={{ color: '#3b82f6' }}>{todayAppts.length}</div>
          <div className="sw-stat-label">Today's Appointments</div>
          <div className="sw-stat-sub">
            {todayAppts.filter((a) => a.status === 'checked_in' || a.status === 'in_consultation').length} in progress
          </div>
        </Card>

        <Card className="sw-stat-card">
          <div className="sw-stat-value" style={{ color: '#8b5cf6' }}>{activeEncounters.length}</div>
          <div className="sw-stat-label">Active Encounters</div>
          <div className="sw-stat-sub">
            {activeEncounters.filter((e) => e.status === 'in_progress').length} in progress
          </div>
        </Card>

        <Card className="sw-stat-card">
          <div className="sw-stat-value" style={{ color: '#f59e0b' }}>
            {todayAttendance.length > 0 ? '✓' : '—'}
          </div>
          <div className="sw-stat-label">Attendance</div>
          <div className="sw-stat-sub">
            {todayAttendance.length > 0
              ? todayAttendance[0].clockInAt
                ? `Clocked in ${formatTime(todayAttendance[0].clockInAt)}`
                : 'Clocked in'
              : 'Not clocked in'}
          </div>
        </Card>

        <Card className="sw-stat-card">
          <div className="sw-stat-value" style={{ color: '#10b981' }}>{referrals.length}</div>
          <div className="sw-stat-label">Active Referrals</div>
          <div className="sw-stat-sub">
            {referrals.filter((r) => r.status === 'pending' || r.status === 'pending_acceptance').length} pending
          </div>
        </Card>
      </div>

      {todayAppts.length > 0 && (
        <Card className="sw-section-card">
          <h4>Today's Schedule</h4>
          <div className="sw-appointment-list">
            {todayAppts.map((apt) => (
              <div
                key={apt.id}
                className="sw-appointment-row"
                onClick={() => navigate(`/clinical/appointments/${apt.id}`)}
              >
                <div className="sw-apt-time">{formatTime(apt.startsAt)}</div>
                <div className="sw-apt-info">
                  <span className="sw-apt-patient">
                    {apt.patient?.fullName ?? 'Unknown Patient'}
                  </span>
                  <span className="sw-apt-type">{apt.appointmentType}</span>
                </div>
                <StatusBadge status={apt.status} />
              </div>
            ))}
          </div>
        </Card>
      )}

      {upcomingAppts.length > 0 && (
        <Card className="sw-section-card">
          <h4>Upcoming</h4>
          <div className="sw-appointment-list">
            {upcomingAppts.map((apt) => (
              <div
                key={apt.id}
                className="sw-appointment-row"
                onClick={() => navigate(`/clinical/appointments/${apt.id}`)}
              >
                <div className="sw-apt-time">{formatDate(apt.startsAt)}</div>
                <div className="sw-apt-info">
                  <span className="sw-apt-patient">
                    {apt.patient?.fullName ?? 'Unknown Patient'}
                  </span>
                  <span className="sw-apt-type">{apt.appointmentType}</span>
                </div>
                <StatusBadge status={apt.status} />
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}

/* ─── Appointments Section ─── */

function AppointmentsSection({
  appointments,
  navigate,
}: {
  appointments: Appointment[];
  navigate: ReturnType<typeof useNavigate>;
}) {
  const [filter, setFilter] = useState<string>('all');
  const today = useMemo(() => new Date().toISOString().slice(0, 10), []);

  const filtered = useMemo(() => {
    let result = [...appointments].sort(
      (a, b) => new Date(b.startsAt).getTime() - new Date(a.startsAt).getTime(),
    );
    if (filter === 'today') {
      result = result.filter((a) => isToday(a.startsAt));
    } else if (filter === 'upcoming') {
      result = result.filter((a) => isUpcoming(a.startsAt));
    } else if (filter !== 'all') {
      result = result.filter((a) => a.status === filter);
    }
    return result;
  }, [appointments, filter, today]);

  return (
    <div className="sw-section">
      <div className="sw-section-header">
        <h3>Appointments</h3>
        <div className="sw-filters">
          {['all', 'today', 'upcoming', 'booked', 'checked_in', 'completed'].map((f) => (
            <button
              key={f}
              className={`sw-filter-btn ${filter === f ? 'sw-filter-btn--active' : ''}`}
              onClick={() => setFilter(f)}
            >
              {f === 'all' ? 'All' : f.replace(/_/g, ' ')}
            </button>
          ))}
        </div>
      </div>

      {filtered.length === 0 ? (
        <EmptyState title="No appointments" body="No appointments match this filter." />
      ) : (
        <div className="sw-table">
          <div className="sw-table-header">
            <span>Date/Time</span>
            <span>Patient</span>
            <span>Type</span>
            <span>Provider</span>
            <span>Status</span>
          </div>
          {filtered.map((apt) => (
            <div
              key={apt.id}
              className="sw-table-row"
              onClick={() => navigate(`/clinical/appointments/${apt.id}`)}
            >
              <span className="sw-cell-muted">
                {isToday(apt.startsAt) ? 'Today ' : ''}{formatTime(apt.startsAt)}
              </span>
              <span className="sw-cell-name">{apt.patient?.fullName ?? '—'}</span>
              <span>{apt.appointmentType}</span>
              <span>{apt.provider?.fullName ?? '—'}</span>
              <StatusBadge status={apt.status} />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ─── Encounters Section ─── */

function EncountersSection({
  encounters,
  navigate,
}: {
  encounters: Encounter[];
  navigate: ReturnType<typeof useNavigate>;
}) {
  const filtered = useMemo(
    () =>
      encounters
        .filter((e) => e.status === 'open' || e.status === 'in_progress')
        .sort((a, b) => new Date(b.startedAt).getTime() - new Date(a.startedAt).getTime()),
    [encounters],
  );

  return (
    <div className="sw-section">
      <div className="sw-section-header">
        <h3>Active Encounters</h3>
      </div>

      {filtered.length === 0 ? (
        <EmptyState
          title="No active encounters"
          body="Active encounters for your patients appear here."
        />
      ) : (
        <div className="sw-table">
          <div className="sw-table-header">
            <span>Patient</span>
            <span>Type</span>
            <span>Provider</span>
            <span>Started</span>
            <span>Status</span>
          </div>
          {filtered.map((enc) => (
            <div
              key={enc.id}
              className="sw-table-row"
              onClick={() => navigate(`/clinical/encounters/${enc.id}`)}
            >
              <span className="sw-cell-name">{enc.patient?.fullName ?? '—'}</span>
              <span>{enc.type}</span>
              <span>{enc.provider?.fullName ?? '—'}</span>
              <span className="sw-cell-muted">{formatTime(enc.startedAt)}</span>
              <StatusBadge status={enc.status} />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ─── Patients Section ─── */

function PatientsSection({
  appointments,
  encounters,
  navigate,
}: {
  appointments: Appointment[];
  encounters: Encounter[];
  navigate: ReturnType<typeof useNavigate>;
}) {
  const patients = useMemo(() => {
    const map = new Map<string, { id: string; name: string; source: string; status: string; date: string }>();
    for (const apt of appointments) {
      if (apt.patient) {
        map.set(apt.patient.id, {
          id: apt.patient.id,
          name: apt.patient.fullName,
          source: 'Appointment',
          status: apt.status,
          date: apt.startsAt,
        });
      }
    }
    for (const enc of encounters) {
      if (enc.patient) {
        const existing = map.get(enc.patient.id);
        if (existing) {
          existing.source = 'Encounter';
          existing.status = enc.status;
          existing.date = enc.startedAt;
        } else {
          map.set(enc.patient.id, {
            id: enc.patient.id,
            name: enc.patient.fullName,
            source: 'Encounter',
            status: enc.status,
            date: enc.startedAt,
          });
        }
      }
    }
    return Array.from(map.values()).sort(
      (a, b) => new Date(b.date).getTime() - new Date(a.date).getTime(),
    );
  }, [appointments, encounters]);

  return (
    <div className="sw-section">
      <div className="sw-section-header">
        <h3>My Patients</h3>
        <span className="sw-count">{patients.length} patients</span>
      </div>

      {patients.length === 0 ? (
        <EmptyState
          title="No patients"
          body="Patients from your appointments and encounters appear here."
        />
      ) : (
        <div className="sw-patient-grid">
          {patients.map((p) => (
            <div
              key={p.id}
              className="sw-patient-card"
              onClick={() => navigate(`/clinical/patients/${p.id}`)}
            >
              <div className="sw-patient-avatar">
                {p.name.split(' ').map((w) => w[0]).join('').slice(0, 2)}
              </div>
              <div className="sw-patient-info">
                <span className="sw-patient-name">{p.name}</span>
                <span className="sw-patient-meta">{p.source}</span>
              </div>
              <StatusBadge status={p.status} />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ─── Referrals Section ─── */

function ReferralsSection({
  referrals,
  navigate,
}: {
  referrals: Referral[];
  navigate: ReturnType<typeof useNavigate>;
}) {
  return (
    <div className="sw-section">
      <div className="sw-section-header">
        <h3>Referrals</h3>
        <span className="sw-count">{referrals.length}</span>
      </div>

      {referrals.length === 0 ? (
        <EmptyState title="No referrals" body="Active referrals appear here." />
      ) : (
        <div className="sw-table">
          <div className="sw-table-header">
            <span>Patient</span>
            <span>Specialty</span>
            <span>Reason</span>
            <span>Status</span>
          </div>
          {referrals.map((r) => (
            <div
              key={r.id}
              className="sw-table-row"
              onClick={() => navigate(`/clinical/patients/${r.patientId}`)}
            >
              <span className="sw-cell-name">{(r as any).patient?.fullName ?? '—'}</span>
              <span>{r.specialty ?? '—'}</span>
              <span className="sw-cell-muted">{r.reason}</span>
              <StatusBadge status={r.status} />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/* ─── Shifts & Attendance Section ─── */

function ShiftsSection({
  roster,
  attendance,
}: {
  roster: Roster[];
  attendance: AttendanceRecord[];
}) {
  const today = useMemo(() => new Date().toISOString().slice(0, 10), []);

  const todayRoster = useMemo(
    () => roster.filter((r) => r.rosterDate === today),
    [roster, today],
  );

  const todayAttendance = useMemo(
    () => attendance.filter((a) => a.attendanceDate === today),
    [attendance, today],
  );

  const recentAttendance = useMemo(
    () =>
      [...attendance]
        .sort((a, b) => new Date(b.attendanceDate).getTime() - new Date(a.attendanceDate).getTime())
        .slice(0, 7),
    [attendance],
  );

  return (
    <div className="sw-section">
      <div className="sw-section-header">
        <h3>Shifts & Attendance</h3>
      </div>

      <div className="sw-shift-grid">
        <Card className="sw-section-card">
          <h4>Today's Shift</h4>
          {todayRoster.length === 0 ? (
            <EmptyState title="No shift assigned" body="No shift is scheduled for today." />
          ) : (
            <div className="sw-shift-list">
              {todayRoster.map((r) => (
                <div key={r.id} className="sw-shift-item">
                  <div className="sw-shift-type">{r.shiftType ?? r.shiftTemplateId}</div>
                  <StatusBadge status={r.status} />
                  {r.notes && <div className="sw-shift-notes">{r.notes}</div>}
                </div>
              ))}
            </div>
          )}
        </Card>

        <Card className="sw-section-card">
          <h4>Today's Attendance</h4>
          {todayAttendance.length === 0 ? (
            <EmptyState title="No attendance" body="No attendance record for today." />
          ) : (
            <div className="sw-attendance-list">
              {todayAttendance.map((a) => (
                <div key={a.id} className="sw-attendance-item">
                  <div className="sw-attendance-times">
                    <span>In: {formatTime(a.clockInAt)}</span>
                    <span>Out: {a.clockOutAt ? formatTime(a.clockOutAt) : '—'}</span>
                  </div>
                  <StatusBadge status={a.status} />
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      {recentAttendance.length > 0 && (
        <Card className="sw-section-card">
          <h4>Recent Attendance (7 days)</h4>
          <div className="sw-table">
            <div className="sw-table-header">
              <span>Date</span>
              <span>Clock In</span>
              <span>Clock Out</span>
              <span>Status</span>
            </div>
            {recentAttendance.map((a) => (
              <div key={a.id} className="sw-table-row">
                <span>{formatDate(a.attendanceDate)}</span>
                <span>{formatTime(a.clockInAt)}</span>
                <span>{a.clockOutAt ? formatTime(a.clockOutAt) : '—'}</span>
                <StatusBadge status={a.status} />
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}

/* ─── Main StaffWorkspace Component ─── */

export function StaffWorkspace() {
  const navigate = useNavigate();
  const { selectedFacilityId } = useTenant();
  const { hasAnyRole } = useAccess();
  const { user } = useAuth();
  const [error, setError] = useState<string | null>(null);
  const [activeSection, setActiveSection] = useState('today');

  // Data fetching from existing APIs
  const todayStr = useMemo(() => new Date().toISOString().slice(0, 10), []);

  const appointmentsAll = useFetch(
    () => appointmentsApi.list({ facilityId: selectedFacilityId }),
    [selectedFacilityId],
  );

  const roster = useFetch(
    () => hrApi.rosters(selectedFacilityId, todayStr),
    [selectedFacilityId, todayStr],
  );

  const attendance = useFetch(
    () => hrApi.attendance(selectedFacilityId),
    [selectedFacilityId],
  );

  const referrals = useFetch(
    () => referralsApi.list({ facilityId: selectedFacilityId, status: 'pending' }),
    [selectedFacilityId],
  );

  // Active encounters for today's patients
  const encounters = useFetch(async () => {
    const todayAppts = await appointmentsApi.list({ date: todayStr, facilityId: selectedFacilityId });
    const patientIds = new Set(
      (todayAppts as Appointment[])
        .map((a) => a.patientId)
        .filter(Boolean),
    );

    const allEncs: Array<{ id: string; type: string; status: string; providerName: string; serviceName: string; startedAt: string }> = [];
    for (const pid of patientIds) {
      try {
        const encs = await encountersApi.forPatient(pid, selectedFacilityId);
        allEncs.push(...encs);
      } catch {
        // Skip patients with no encounters
      }
    }
    return allEncs;
  }, [selectedFacilityId, todayStr]);

  const counts = useMemo(
    () => ({
      today: (appointmentsAll.data as Appointment[] | undefined)?.filter((a) => isToday(a.startsAt)).length ?? 0,
      appointments: (appointmentsAll.data as Appointment[] | undefined)?.length ?? 0,
      encounters: (encounters.data as Array<{ status: string }> | undefined)?.filter(
        (e) => e.status === 'open' || e.status === 'in_progress',
      ).length ?? 0,
      patients: new Set([
        ...((appointmentsAll.data as Appointment[] | undefined)?.map((a) => a.patientId).filter(Boolean) ?? []),
        ...((encounters.data as Array<{ patientId?: string }> | undefined)?.map((e) => e.patientId).filter(Boolean) ?? []),
      ]).size,
      referrals: (referrals.data as unknown as { data?: unknown[] } | undefined)?.data?.length ?? 0,
      shifts: (roster.data as Roster[] | undefined)?.length ?? 0,
    }),
    [appointmentsAll.data, encounters.data, referrals.data, roster.data],
  );

  const sections = useMemo(
    () => buildSections((r: string) => hasAnyRole(r as any), counts),
    [hasAnyRole, counts],
  );

  const handleRefresh = useCallback(() => {
    setError(null);
    appointmentsAll.refresh();
    encounters.refresh();
    referrals.refresh();
    roster.refresh();
    attendance.refresh();
  }, [appointmentsAll, encounters, referrals, roster, attendance]);

  const staffName = (user as any)?.staffName || (user as any)?.email?.split('@')[0] || 'Staff';

  return (
    <div className="page sw-workspace">
      <header className="sw-workspace-header">
        <div className="sw-workspace-title-group">
          <div className="sw-avatar">
            {staffName.split(' ').map((w: string) => w[0]).join('').slice(0, 2).toUpperCase()}
          </div>
          <div>
            <h1 className="sw-workspace-title">My Work</h1>
            <p className="sw-workspace-subtitle">
              {staffName} — {formatDate(todayStr)}
            </p>
          </div>
        </div>
        <Button variant="ghost" size="sm" onClick={handleRefresh}>
          Refresh
        </Button>
      </header>

      {error && <Alert tone="danger">{error}</Alert>}

      {/* Section tabs */}
      <div className="sw-tabs" role="tablist">
        {sections.map((s) => (
          <button
            key={s.id}
            role="tab"
            aria-selected={activeSection === s.id}
            className={`sw-tab ${activeSection === s.id ? 'sw-tab--active' : ''}`}
            onClick={() => setActiveSection(s.id)}
          >
            {s.label}
            {s.count > 0 && <span className="sw-tab-count">{s.count}</span>}
          </button>
        ))}
      </div>

      {/* Section content */}
      <div className="sw-section-content">
        {activeSection === 'today' && (
          <TodayOverview
            appointments={(appointmentsAll.data as Appointment[]) ?? []}
            encounters={(encounters.data as unknown as Encounter[]) ?? []}
            referrals={(referrals.data as unknown as { data?: Referral[] } | null)?.data ?? []}
            attendance={(attendance.data as any[]) ?? []}
            navigate={navigate}
          />
        )}

        {activeSection === 'appointments' && (
          <AppointmentsSection
            appointments={(appointmentsAll.data as Appointment[]) ?? []}
            navigate={navigate}
          />
        )}

        {activeSection === 'encounters' && (
          <EncountersSection
            encounters={(encounters.data as unknown as Encounter[]) ?? []}
            navigate={navigate}
          />
        )}

        {activeSection === 'patients' && (
          <PatientsSection
            appointments={(appointmentsAll.data as Appointment[]) ?? []}
            encounters={(encounters.data as unknown as Encounter[]) ?? []}
            navigate={navigate}
          />
        )}

        {activeSection === 'referrals' && (
          <ReferralsSection
            referrals={(referrals.data as unknown as { data?: Referral[] } | null)?.data ?? []}
            navigate={navigate}
          />
        )}

        {activeSection === 'shifts' && (
          <ShiftsSection
            roster={(roster.data as Roster[]) ?? []}
            attendance={(attendance.data as any[]) ?? []}
          />
        )}
      </div>
    </div>
  );
}

export default StaffWorkspace;
