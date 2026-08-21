import { useState, useEffect, useMemo } from 'react';
import { useTenant } from '../context/TenantContext';
import { doctorScheduleApi } from '../api/endpoints';
import { Alert, Button, Dialog, EmptyState, ErrorState, Input, StatusChip } from '../components/ui';
import { ApiError } from '../api/client';
import type { Staff } from '../api/types';
import './physician-scheduling.css';

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

interface DaySchedule {
  dayOfWeek: number;
  dayName: string;
  date: string;
  templates: Array<{ id: string; startsAt: string; endsAt: string; slotMinutes: number; capacity: number }>;
  exceptions: Array<{ id: string; reason: string; status: string }>;
  isAvailable: boolean;
}

interface WeeklySchedule {
  staffId: string;
  staffName: string;
  weekStart: string;
  weekEnd: string;
  days: Record<string, DaySchedule>;
}

export function PhysicianSchedulingPage() {
  const { organizationId, selectedFacilityId } = useTenant();
  const [doctors, setDoctors] = useState<Staff[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedDoctor, setSelectedDoctor] = useState<Staff | null>(null);
  const [weekStart, setWeekStart] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() - d.getDay());
    return d.toISOString().split('T')[0];
  });
  const [schedule, setSchedule] = useState<WeeklySchedule | null>(null);
  const [scheduleLoading, setScheduleLoading] = useState(false);
  const [filterDept, setFilterDept] = useState('');
  const [editDay, setEditDay] = useState<number | null>(null);
  const [editSlots, setEditSlots] = useState<Array<{ startsAt: string; endsAt: string; slotMinutes: number; capacity: number }>>([]);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  // Load doctors
  useEffect(() => {
    if (!organizationId) return;
    setLoading(true);
    doctorScheduleApi.listDoctors(organizationId, selectedFacilityId)
      .then(data => { setDoctors(Array.isArray(data) ? data : []); setLoading(false); })
      .catch(err => { setError(err instanceof ApiError ? err.message : 'Failed to load doctors'); setLoading(false); });
  }, [organizationId, selectedFacilityId]);

  // Load weekly schedule when doctor is selected
  useEffect(() => {
    if (!selectedDoctor) { setSchedule(null); return; }
    setScheduleLoading(true);
    doctorScheduleApi.weeklySchedule(selectedDoctor.id, weekStart)
      .then(data => { setSchedule(data as unknown as WeeklySchedule); setScheduleLoading(false); })
      .catch(err => { setScheduleLoading(false); setError(err instanceof ApiError ? err.message : 'Failed to load schedule'); });
  }, [selectedDoctor, weekStart]);

  const filteredDoctors = useMemo(() => {
    let list = doctors;
    if (filterDept) {
      list = list.filter(d => d.departmentId === filterDept);
    }
    return list;
  }, [doctors, filterDept]);

  const departments = useMemo(() => {
    const map = new Map<string, string>();
    doctors.forEach(d => {
      if (d.departmentId && d.department?.name) {
        map.set(d.departmentId, d.department.name);
      }
    });
    return Array.from(map.entries());
  }, [doctors]);

  const navigateWeek = (dir: number) => {
    const d = new Date(weekStart);
    d.setDate(d.getDate() + dir * 7);
    setWeekStart(d.toISOString().split('T')[0]);
  };

  const openEditDay = (day: number) => {
    if (!schedule) return;
    const dayData = schedule.days[day.toString()];
    setEditSlots(dayData.templates.length > 0
      ? dayData.templates.map(t => ({ startsAt: t.startsAt, endsAt: t.endsAt, slotMinutes: t.slotMinutes, capacity: t.capacity }))
      : [{ startsAt: '09:00', endsAt: '13:00', slotMinutes: 15, capacity: 4 }]
    );
    setEditDay(day);
  };

  const addSlot = () => {
    setEditSlots(prev => [...prev, { startsAt: '14:00', endsAt: '17:00', slotMinutes: 15, capacity: 4 }]);
  };

  const removeSlot = (idx: number) => {
    setEditSlots(prev => prev.filter((_, i) => i !== idx));
  };

  const updateSlot = (idx: number, field: string, value: string | number) => {
    setEditSlots(prev => prev.map((s, i) => i === idx ? { ...s, [field]: value } : s));
  };

  const saveSchedule = async () => {
    if (!selectedDoctor || !organizationId || editDay === null) return;
    setSaving(true);
    setSaveError(null);
    try {
      // Build schedule array: just the edited day
      const schedulePayload = [{
        dayOfWeek: editDay,
        slots: editSlots,
      }];
      await doctorScheduleApi.updateWeeklySchedule(organizationId, selectedDoctor.id, schedulePayload as unknown as Record<string, unknown>[]);
      // Reload
      const data = await doctorScheduleApi.weeklySchedule(selectedDoctor.id, weekStart);
      setSchedule(data as unknown as WeeklySchedule);
      setEditDay(null);
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : 'Failed to save schedule');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <div className="phys-sched"><div className="phys-sched__header"><h2>Physician Scheduling</h2></div><div className="phys-sched__loading">Loading doctors…</div></div>;
  }

  if (error && doctors.length === 0) {
    return <ErrorState error={error} onRetry={() => window.location.reload()} />;
  }

  return (
    <div className="phys-sched">
      <div className="phys-sched__header">
        <div>
          <h2>Physician Scheduling</h2>
          <p className="phys-sched__subtitle">Manage doctor availability, weekly schedules, and appointment capacity</p>
        </div>
      </div>

      <div className="phys-sched__layout">
        {/* Doctor sidebar */}
        <aside className="phys-sched__sidebar">
          <div className="phys-sched__sidebar-header">
            <h3>Doctors ({filteredDoctors.length})</h3>
            {departments.length > 1 && (
              <select className="phys-sched__filter" value={filterDept} onChange={e => setFilterDept(e.target.value)}>
                <option value="">All Departments</option>
                {departments.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
              </select>
            )}
          </div>

          {filteredDoctors.length === 0 ? (
            <EmptyState title="No doctors found" body="Add staff members with doctor designations." />
          ) : (
            <div className="phys-sched__doctor-list">
              {filteredDoctors.map(doc => (
                <button
                  key={doc.id}
                  className={`phys-sched__doctor-card ${selectedDoctor?.id === doc.id ? 'phys-sched__doctor-card--active' : ''}`}
                  onClick={() => setSelectedDoctor(doc)}
                >
                  <div className="phys-sched__doctor-avatar" style={{ background: doc.specialty ? stringToColor(doc.specialty) : '#64748b' }}>
                    {doc.fullName?.charAt(0) ?? '?'}
                  </div>
                  <div className="phys-sched__doctor-info">
                    <span className="phys-sched__doctor-name">{doc.fullName}</span>
                    <span className="phys-sched__doctor-specialty">{doc.specialty || doc.designation || 'General'}</span>
                    <span className="phys-sched__doctor-dept">{doc.department?.name ?? '—'}</span>
                  </div>
                  <div className="phys-sched__doctor-status">
                    <StatusChip tone={doc.acceptsNewPatients !== false ? 'success' : 'neutral'} label={doc.acceptsNewPatients !== false ? 'Accepting' : 'Closed'} />
                  </div>
                </button>
              ))}
            </div>
          )}
        </aside>

        {/* Schedule main area */}
        <main className="phys-sched__main">
          {!selectedDoctor ? (
            <div className="phys-sched__empty">
              <EmptyState title="Select a doctor" body="Choose a doctor from the sidebar to view and manage their schedule." />
            </div>
          ) : scheduleLoading ? (
            <div className="phys-sched__loading">Loading schedule…</div>
          ) : schedule ? (
            <>
              {/* Week navigation */}
              <div className="phys-sched__week-nav">
                <Button variant="ghost" onClick={() => navigateWeek(-1)}>← Previous</Button>
                <div className="phys-sched__week-info">
                  <h3>{selectedDoctor.fullName}'s Schedule</h3>
                  <span>{schedule.weekStart} — {schedule.weekEnd}</span>
                </div>
                <Button variant="ghost" onClick={() => navigateWeek(1)}>Next →</Button>
              </div>

              {/* Doctor profile header */}
              <div className="phys-sched__profile">
                <div className="phys-sched__profile-avatar" style={{ background: selectedDoctor.specialty ? stringToColor(selectedDoctor.specialty) : '#64748b' }}>
                  {selectedDoctor.fullName?.charAt(0)}
                </div>
                <div className="phys-sched__profile-details">
                  <h3>{selectedDoctor.fullName}</h3>
                  <span>{selectedDoctor.specialty || selectedDoctor.designation || 'General'}</span>
                  {selectedDoctor.consultationFee != null && <span className="phys-sched__fee">Fee: Rs. {Number(selectedDoctor.consultationFee).toLocaleString()}</span>}
                  {selectedDoctor.consultationDurationMinutes && <span className="phys-sched__duration">{selectedDoctor.consultationDurationMinutes} min slots</span>}
                </div>
              </div>

              {/* Weekly calendar grid */}
              <div className="phys-sched__calendar">
                {Object.values(schedule.days).map(day => (
                  <div
                    key={day.dayOfWeek}
                    className={`phys-sched__day ${day.isAvailable ? 'phys-sched__day--available' : 'phys-sched__day--unavailable'} ${day.exceptions.length > 0 ? 'phys-sched__day--exception' : ''}`}
                  >
                    <div className="phys-sched__day-header">
                      <span className="phys-sched__day-name">{DAY_NAMES[day.dayOfWeek].slice(0, 3)}</span>
                      <span className="phys-sched__day-date">{day.date.slice(5)}</span>
                    </div>

                    <div className="phys-sched__day-body">
                      {day.exceptions.length > 0 ? (
                        <div className="phys-sched__exception-badge">
                          <span>Exception</span>
                          {day.exceptions.map(ex => <span key={ex.id} className="phys-sched__exception-reason">{ex.reason || 'Unavailable'}</span>)}
                        </div>
                      ) : day.templates.length > 0 ? (
                        <div className="phys-sched__slots">
                          {day.templates.map(t => (
                            <div key={t.id} className="phys-sched__slot">
                              <span className="phys-sched__slot-time">{t.startsAt}–{t.endsAt}</span>
                              <span className="phys-sched__slot-meta">{t.slotMinutes}m × {t.capacity}</span>
                            </div>
                          ))}
                        </div>
                      ) : (
                        <div className="phys-sched__off-day">Off</div>
                      )}
                    </div>

                    <button className="phys-sched__day-edit" onClick={() => openEditDay(day.dayOfWeek)}>
                      Edit
                    </button>
                  </div>
                ))}
              </div>
            </>
          ) : null}
        </main>
      </div>

      {/* Edit day dialog */}
      {editDay !== null && (
        <Dialog
          open={true}
          onClose={() => setEditDay(null)}
          title={`Edit Schedule — ${DAY_NAMES[editDay]}`}
          footer={
            <>
              <Button variant="ghost" onClick={() => setEditDay(null)}>Cancel</Button>
              <Button onClick={() => void saveSchedule()} loading={saving}>Save Schedule</Button>
            </>
          }
        >
          <div className="phys-sched__edit-slots">
            {saveError && <Alert tone="danger">{saveError}</Alert>}
            {editSlots.map((slot, idx) => (
              <div key={idx} className="phys-sched__edit-slot">
                <div className="phys-sched__edit-slot-fields">
                  <Input label="Start" type="time" value={slot.startsAt} onChange={e => updateSlot(idx, 'startsAt', e.target.value)} />
                  <Input label="End" type="time" value={slot.endsAt} onChange={e => updateSlot(idx, 'endsAt', e.target.value)} />
                  <Input label="Slot (min)" type="number" value={slot.slotMinutes} onChange={e => updateSlot(idx, 'slotMinutes', parseInt(e.target.value) || 15)} min={5} max={120} />
                  <Input label="Capacity" type="number" value={slot.capacity} onChange={e => updateSlot(idx, 'capacity', parseInt(e.target.value) || 1)} min={1} max={50} />
                </div>
                {editSlots.length > 1 && (
                  <Button variant="ghost" onClick={() => removeSlot(idx)}>Remove</Button>
                )}
              </div>
            ))}
            <Button variant="ghost" onClick={addSlot}>+ Add Time Block</Button>
          </div>
        </Dialog>
      )}
    </div>
  );
}

/** Deterministic color from string */
function stringToColor(str: string): string {
  let hash = 0;
  for (let i = 0; i < str.length; i++) {
    hash = str.charCodeAt(i) + ((hash << 5) - hash);
  }
  const hue = Math.abs(hash) % 360;
  return `hsl(${hue}, 55%, 45%)`;
}
